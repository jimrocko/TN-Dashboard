// Dev-Bereich für die Demo, entspricht api/lib/Dev.php.
// Die Demo gilt immer als Testbetrieb: Solange kein Code gesetzt ist, lautet der Dev-Code 0000.
import { DemoError } from './backend.js'

const TEST_PIN = '0000'
const LOCK_MS = 15 * 60 * 1000 // automatische Sperre nach 15 Minuten ohne Aktivität (wie dev_lock_minutes)
const TABLES = ['users', 'log', 'locations', 'inventory', 'orders', 'transfers', 'stocktakes']
const TABLE_LABEL = { log: 'activity_log' }
const isStaff = (u) => ['lehrer', 'admin'].includes(u.role)

// Ein Hash ist in der Demo nicht nötig (Daten liegen nur in diesem Browser); der Code wird trotzdem nie angezeigt.
const dev = (db) => (db.dev ??= { access: [], pin: null, version: 0, unlocked: null, tries: 0, lockedUntil: 0 })

export const devAllowed = (db, u) => isStaff(u) || dev(db).access.includes(u.id)
function unlocked(db) {
  const d = dev(db)
  if (d.unlocked !== d.version + 1) return false
  if (Date.now() - (d.seen ?? 0) >= LOCK_MS) { d.unlocked = null; return false }
  return true
}
const touch = (db) => { dev(db).seen = Date.now() }

/** Beim Abmelden und Anmelden: Dev-Bereich wieder sperren. */
export function devLock(db) {
  const d = dev(db)
  d.unlocked = null
  d.tries = 0
}

function status(db, u) {
  const d = dev(db)
  const open = unlocked(db)
  if (open) touch(db)
  return {
    allowed: devAllowed(db, u), unlocked: open, expires_in: open ? LOCK_MS / 1000 : 0, lock_minutes: LOCK_MS / 60000,
    pin_set: d.pin !== null, test_mode: true,
    can_set_pin: isStaff(u), locked_for: Math.max(0, Math.ceil((d.lockedUntil - Date.now()) / 1000)),
  }
}

function requireDev(db, u) {
  if (!devAllowed(db, u)) throw new DemoError('Kein Zugang zum Dev-Bereich', 403)
  if (!unlocked(db)) throw new DemoError('Dev-Bereich ist gesperrt. Bitte Dev-Code eingeben.', 423)
  touch(db)
}

function system(db) {
  let bytes = null
  try { bytes = (localStorage.getItem('tn-dashboard-demo-v2') ?? '').length * 2 } catch { /* egal */ }
  return {
    app: { version: '0.4', server_time: new Date().toLocaleString('de-DE'), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, test_mode: true },
    php: null,
    db: { driver: 'demo (Browser)', version: 'localStorage', size_bytes: bytes, prefix: '–', host: null, name: 'tn-dashboard-demo-v2' },
    services: { bricklink_configured: false, bricklink_cache_ttl: 0, brickognize_url: 'https://api.brickognize.com/predict/' },
    env: { file: null, fields: [] },
    session: { lifetime: 0, id_prefix: 'demo', https: location.protocol === 'https:' },
    request: { ip: null, user_agent: navigator.userAgent },
  }
}

function table(db, name, page, per) {
  if (!TABLES.includes(name)) throw new DemoError('Unbekannte Tabelle', 404)
  per = Math.max(1, Math.min(Number(per) || 50, 200))
  page = Math.max(1, Number(page) || 1)
  const all = [...db[name]].reverse()
  const rows = all.slice((page - 1) * per, page * per).map((r) => {
    const o = {}
    for (const [k, v] of Object.entries(r)) {
      if (k === 'password') o[k] = '••••••'
      else if (v !== null && typeof v === 'object') o[k] = Array.isArray(v) ? `[${v.length} Einträge]` : JSON.stringify(v)
      else o[k] = v
    }
    return o
  })
  return { name, total: all.length, page, per, columns: rows[0] ? Object.keys(rows[0]) : [], rows }
}

async function test(db, name) {
  const t0 = performance.now()
  let ok = true
  let detail
  try {
    if (name === 'db_read') detail = `${db.users.length} Benutzer gelesen`
    else if (name === 'db_write') {
      localStorage.setItem('tn-selftest', 'ok')
      if (localStorage.getItem('tn-selftest') !== 'ok') throw new Error('Wert kam nicht zurück')
      localStorage.removeItem('tn-selftest')
      detail = 'Schreiben, Lesen und Löschen im Browser-Speicher ok'
    } else if (name === 'session') detail = 'Demo-Sitzung aktiv'
    else if (name === 'bricklink') throw new Error('In der Demo gibt es keine BrickLink-Verbindung')
    else if (name === 'brickognize') {
      await fetch('https://api.brickognize.com/health/', { method: 'GET', mode: 'no-cors' })
      detail = 'api.brickognize.com erreichbar'
    } else if (name === 'tmp_write') throw new Error('Nur auf dem Server (Foto-Uploads)')
    else throw new DemoError('Unbekannter Test', 404)
  } catch (e) {
    if (e instanceof DemoError) throw e
    ok = false
    detail = e.message === 'Failed to fetch' ? 'Nicht erreichbar (Internet oder Sperre im Browser)' : e.message
  }
  return { name, ok, ms: Math.round(performance.now() - t0), detail }
}

/** Antwort für Dev-Routen oder undefined. Kann ein Promise liefern (Tests). */
export function route(db, method, parts, params, body, me, log) {
  const [a, b, c] = parts
  if (a === 'users' && /^\d+$/.test(b ?? '') && c === 'dev' && method === 'POST') {
    if (!isStaff(me())) throw new DemoError('Nur für Lehrer', 403)
    const target = db.users.find((u) => u.id === Number(b))
    if (!target) throw new DemoError('Benutzer nicht gefunden', 404)
    const d = dev(db)
    d.access = d.access.filter((id) => id !== target.id)
    if (body.enabled) d.access.push(target.id)
    log(me().id, body.enabled ? 'dev_granted' : 'dev_revoked', null, { username: target.username })
    return { ok: true }
  }
  if (a !== 'dev') return undefined
  const u = me()
  const d = dev(db)
  switch (`${method} ${b}`) {
    case 'GET status': return status(db, u)
    case 'POST unlock': {
      if (!devAllowed(db, u)) throw new DemoError('Kein Zugang zum Dev-Bereich', 403)
      if (d.lockedUntil > Date.now()) throw new DemoError(`Zu viele Fehlversuche. Bitte ${status(db, u).locked_for} Sekunden warten.`, 429)
      const ok = String(body.pin ?? '').trim() === (d.pin ?? TEST_PIN)
      log(u.id, ok ? 'dev_unlock' : 'dev_unlock_failed')
      if (ok) { d.unlocked = d.version + 1; d.tries = 0; touch(db); return status(db, u) }
      if (++d.tries >= 5) { d.tries = 0; d.lockedUntil = Date.now() + 60000 }
      throw new DemoError('Falscher Dev-Code', 401)
    }
    case 'POST lock': d.unlocked = null; return status(db, u)
    case 'POST pin': {
      if (!isStaff(u)) throw new DemoError('Nur für Lehrer', 403)
      if (d.pin !== null && !unlocked(db)) throw new DemoError('Erst mit dem alten Dev-Code entsperren', 423)
      const pin = String(body.pin ?? '').trim()
      if (!/^\d{4,8}$/.test(pin)) throw new DemoError('Der Dev-Code muss aus 4 bis 8 Ziffern bestehen', 400)
      d.pin = pin
      d.version++
      d.unlocked = d.version + 1
      touch(db)
      log(u.id, 'dev_pin_changed')
      return status(db, u)
    }
    case 'GET system': requireDev(db, u); return system(db)
    case 'GET tables':
      requireDev(db, u)
      return { tables: TABLES.map((n) => ({ name: TABLE_LABEL[n] ?? n, key: n, table: n, rows: db[n].length })) }
    case 'GET table': requireDev(db, u); return table(db, params.name, params.page, params.per)
    case 'POST test': {
      requireDev(db, u)
      return test(db, String(body.name ?? '')).then((r) => { log(u.id, 'dev_test', null, { test: r.name, ok: r.ok }); return r })
    }
  }
  throw new DemoError('Nicht gefunden', 404)
}
