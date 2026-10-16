// Dev-Bereich für die Demo, entspricht api/lib/Dev.php.
// Kein Dev-Code: Zugang hat, wer angemeldet ist und die Dev-Rolle hat (Lehrer immer).
import { DemoError, KEY } from './backend.js'

const TABLES = ['users', 'log', 'locations', 'inventory', 'orders', 'transfers', 'stocktakes']
const TABLE_LABEL = { log: 'activity_log' }
const isStaff = (u) => ['lehrer', 'admin'].includes(u.role)

const dev = (db) => (db.dev ??= { access: [] })

export const devAllowed = (db, u) => isStaff(u) || dev(db).access.includes(u.id)
export const revokeDev = (db, id) => { dev(db).access = dev(db).access.filter((x) => x !== id) }
const status = (db, u) => ({ allowed: devAllowed(db, u) })

function requireDev(db, u) {
  if (!devAllowed(db, u)) throw new DemoError('Kein Zugang zum Dev-Bereich', 403)
}

function system(db) {
  let bytes = null
  try { bytes = (localStorage.getItem(KEY) ?? '').length * 2 } catch { /* egal */ }
  return {
    app: { version: '0.4', server_time: new Date().toLocaleString('de-DE'), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, test_mode: true },
    php: null,
    db: { driver: 'demo (Browser)', version: 'localStorage', size_bytes: bytes, prefix: '–', host: null, name: KEY },
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
  switch (`${method} ${b}`) {
    case 'GET status': return status(db, u)
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
