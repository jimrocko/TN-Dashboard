// Demo-Backend: ersetzt die PHP-API im Browser, damit die App ohne Server ausprobiert werden kann.
// Daten liegen nur im Browser (localStorage, sonst im Arbeitsspeicher).
// Gleiche Beispieldaten und gleiche Regeln wie api/lib/Shop.php.
import seed from '../../../api/sql/demo-data.json'
import * as shop from './shop.js'
import { predict } from '../utils/brickognize.js'
import * as devRoutes from './dev.js'

// Version erhöhen, wenn sich die Beispieldaten ändern: alte Browser-Daten werden dann verworfen.
// Das Tutorial hat eigene Daten, damit es die normale Demo nicht verändert.
export const KEY = import.meta.env.MODE === 'tutorial' ? 'tn-dashboard-tutorial-v2' : 'tn-dashboard-demo-v9'

export class DemoError extends Error {
  constructor(message, status) {
    super(message)
    this.status = status
  }
}

export const now = () => {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

function fresh() {
  const t = now()
  return {
    nextUser: 4,
    nextLog: 1000,
    session: null,
    users: [
      { id: 1, username: 'lehrer', display_name: 'Herr Lehrer', password: 'lehrer123', role: 'lehrer', created_at: t, last_seen: null },
      { id: 2, username: 'max', display_name: 'Max Mustermann', password: 'max12345', role: 'teilnehmer', created_at: t, last_seen: null },
      { id: 3, username: 'erika', display_name: 'Erika Musterfrau', password: 'erika123', role: 'teilnehmer', created_at: t, last_seen: null },
    ],
    log: import.meta.env.MODE === 'tutorial' ? [] : sampleHistory(),
    ...shop.seedData(seed),
  }
}

// Erfundene frühere Arbeit von Max und Erika, damit Bestenliste und Abzeichen in der Demo nicht leer sind
// (nicht im Tutorial, dort beginnt „Meine Aktivitäten“ leer).
function sampleHistory() {
  const rows = []
  const at = (daysAgo, hour, min) => {
    const d = new Date(); d.setDate(d.getDate() - daysAgo); d.setHours(hour, min, 0, 0)
    const p = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`
  }
  const add = (user, action, module, details, daysAgo, hour, min) => rows.push({ user_id: user, action, module, details, created_at: at(daysAgo, hour, min) })
  const plan = [
    // [Nutzer, Tage zurück, Umlagerungen, gepackte Aufträge, Inventuren, frühe Stunde?]
    [2, 9, 4, 1, 0], [2, 6, 5, 2, 1], [2, 2, 3, 1, 0],
    [3, 12, 2, 2, 1], [3, 8, 1, 3, 0], [3, 5, 2, 2, 1, true], [3, 3, 1, 1, 1], [3, 1, 0, 2, 0],
  ]
  for (const [u, days, tr, picks, st, early] of plan) {
    let m = 0
    const h = early ? 7 : 10
    for (let i = 0; i < tr; i++) add(u, 'transfer', 'umlagern', { beispiel: true }, days, h, (m += 4))
    for (let i = 0; i < picks; i++) add(u, 'order_status', 'auftraege', { status: 'Verpackt', beispiel: true }, days, h + 1, (m += 3) % 60)
    for (let i = 0; i < st; i++) add(u, 'stocktake_closed', 'inventur', { beispiel: true }, days, h + 2, (m += 5) % 60)
  }
  return rows.sort((a, b) => a.created_at.localeCompare(b.created_at)).map((r, i) => ({ id: i + 1, ...r }))
}

let memory = null
function load() {
  try {
    const raw = localStorage.getItem(KEY)
    if (raw) return JSON.parse(raw)
  } catch { /* Speicher nicht verfügbar */ }
  return memory ?? (memory = fresh())
}
function save(db) {
  memory = db
  try { localStorage.setItem(KEY, JSON.stringify(db)) } catch { /* nur im Arbeitsspeicher */ }
}

const pub = (u, db) => ({ id: u.id, username: u.username, display_name: u.display_name, role: u.role, last_seen: u.last_seen, can_dev: devRoutes.devAllowed(db, u) })
const isStaff = (u) => ['lehrer', 'admin'].includes(u.role)

function log(db, userId, action, module = null, details = null) {
  db.log.push({ id: db.nextLog++, user_id: userId, action, module, details: details && Object.keys(details).length ? details : null, created_at: now() })
}

/** Zahlen je Nutzer für Abzeichen und Bestenliste (gleiche Regeln wie ActivityLog::stats in PHP) */
function userStats(db, since) {
  const map = { transfer: 'transfers', stocktake_closed: 'stocktakes', recognize: 'scans', bricklink_export: 'exports', search: 'searches', order_status: 'orders' }
  const out = {}
  for (const r of db.log) {
    if (r.action === 'login' || r.action === 'logout' || (since && r.created_at < since)) continue
    const s = (out[r.user_id] ??= { total: 0, picks: 0, orders: 0, transfers: 0, stocktakes: 0, scans: 0, exports: 0, searches: 0, early: 0, days: new Set() })
    s.total++
    if (map[r.action]) s[map[r.action]]++
    if (r.action === 'order_status' && r.details?.status === 'Verpackt') s.picks++
    if (r.created_at.slice(11, 13) < '08') s.early++
    s.days.add(r.created_at.slice(0, 10))
  }
  for (const s of Object.values(out)) s.days = s.days.size
  return out
}

function activityRows(db, userId, limit) {
  return db.log
    .filter((r) => userId === null || r.user_id === userId)
    .map((r) => {
      const u = db.users.find((x) => x.id === r.user_id)
      return { ...r, username: u?.username, display_name: u?.display_name }
    })
    .sort((a, b) => b.id - a.id)
    .slice(0, limit)
}

function current(db) {
  // Gelöschte Konten (active === false) gelten als abgemeldet, wie auth.php mit active = 1
  const u = db.users.find((x) => x.id === db.session && x.active !== false)
  if (u) u.last_seen = now()
  return u ?? null
}
function requireUser(db) {
  const u = current(db)
  if (!u) throw new DemoError('Nicht angemeldet', 401)
  return u
}

export async function handle(method, route, params = {}, body = {}) {
  if (method === 'POST' && route === 'recognize') return recognize(body)
  const db = load()
  try {
    return await dispatch(db, method, route, params, body ?? {})
  } finally {
    save(db)
  }
}

// Teileerkennung: in der Demo direkt beim Erkennungsdienst, danach mit den Demo-Lagerdaten verknüpft.
async function recognize(form) {
  const me = requireUser(load())
  const image = form?.get?.('image')
  if (!image) throw new DemoError('Kein Foto empfangen', 400)
  let items
  try { items = await predict(image) } catch (e) { throw new DemoError(e.message, 502) }
  const db = load()
  const lots = shop.lotsByItem(db, items.filter((i) => i.type === 'part').map((i) => i.id))
  for (const it of items) it.lots = it.type === 'part' ? lots[it.id] ?? [] : []
  log(db, me.id, 'recognize', 'scanner', items.length
    ? { teil: items[0].id, name: items[0].name, sicherheit: `${Math.round(items[0].score * 100)} %` }
    : { ergebnis: 'nichts erkannt' })
  save(db)
  return { items }
}

function dispatch(db, method, route, params, body) {
  const parts = route.split('/')
  switch (`${method} ${route}`) {
    case 'POST auth/login': {
      const u = db.users.find((x) => x.username === String(body.username ?? '').trim() && x.active !== false)
      if (!u || u.password !== body.password) throw new DemoError('Benutzername oder Passwort falsch', 401)
      db.session = u.id
      log(db, u.id, 'login')
      return { user: pub(u, db) }
    }
    case 'POST auth/logout': {
      const u = current(db)
      if (u) log(db, u.id, 'logout')
      db.session = null
      return { ok: true }
    }
    case 'GET auth/me': {
      const u = current(db)
      return { user: u ? pub(u, db) : null }
    }
    case 'GET dashboard': {
      const me = requireUser(db)
      return {
        ...shop.dashboard(db),
        bricklink: { configured: false },
        recent: activityRows(db, isStaff(me) ? null : me.id, 8),
        demo_seedable: false,
      }
    }
    case 'POST activity': {
      const u = requireUser(db)
      if (!body.action) throw new DemoError('action fehlt', 400)
      log(db, u.id, String(body.action).slice(0, 64), body.module ?? null, body.details ?? null)
      return { ok: true }
    }
    case 'GET achievements': {
      // wie in PHP (ActivityLog::stats + Route achievements): eigene Zahlen und Bestenliste der Teilnehmer
      const me = requireUser(db)
      const d = new Date()
      const pad = (n) => String(n).padStart(2, '0')
      const ymd = (x) => `${x.getFullYear()}-${pad(x.getMonth() + 1)}-${pad(x.getDate())}`
      const monday = new Date(d); monday.setDate(d.getDate() - ((d.getDay() + 6) % 7))
      const since = { month: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`, week: ymd(monday) }[params.period] ?? null
      const total = userStats(db, null)
      const all = since ? userStats(db, since) : total
      const board = db.users.filter((u) => u.role === 'teilnehmer' && u.active !== false)
        .sort((a, b) => a.display_name.localeCompare(b.display_name))
        .map((u) => ({ id: u.id, name: u.display_name, stats: all[u.id] ?? null, all: total[u.id] ?? null }))
      return { me: total[me.id] ?? null, board }
    }
    case 'GET activity': {
      const me = requireUser(db)
      let userId = params.user ? Number(params.user) : null
      if (!isStaff(me)) userId = me.id
      return { activity: activityRows(db, userId, Number(params.limit) || 200) }
    }
    case 'GET users': {
      const me = requireUser(db)
      if (!isStaff(me)) throw new DemoError('Nur für Lehrer', 403)
      const order = { admin: 0, lehrer: 1, teilnehmer: 2 }
      return {
        users: db.users
          .filter((u) => u.active !== false)
          .map((u) => ({ ...pub(u, db), dev_access: devRoutes.devAllowed(db, u), activity_count: db.log.filter((r) => r.user_id === u.id).length }))
          .sort((a, b) => order[a.role] - order[b.role] || a.display_name.localeCompare(b.display_name)),
      }
    }
    case 'POST users': {
      const me = requireUser(db)
      if (!isStaff(me)) throw new DemoError('Nur für Lehrer', 403)
      const username = String(body.username ?? '').trim().toLowerCase()
      const display = String(body.display_name ?? '').trim()
      const password = String(body.password ?? '')
      if (!/^[a-z0-9._-]{3,32}$/.test(username)) throw new DemoError('Benutzername: 3–32 Zeichen, nur a–z, 0–9, . _ -', 400)
      if (!display || password.length < 6) throw new DemoError('Name und Passwort (mind. 6 Zeichen) erforderlich', 400)
      if (db.users.some((u) => u.username === username)) throw new DemoError('Benutzername bereits vergeben', 409)
      db.users.push({ id: db.nextUser++, username, display_name: display, password, role: 'teilnehmer', created_at: now(), last_seen: null })
      log(db, me.id, 'user_created', null, { username, role: 'teilnehmer' })
      return { ok: true }
    }
  }
  if (method === 'POST' && parts[0] === 'users' && /^\d+$/.test(parts[1] ?? '') && parts[2] === 'delete') {
    // Konto löschen wie api/index.php: nur Lehrer, nur Teilnehmer; deaktivieren, Protokoll bleibt, Name wird frei
    const me = requireUser(db)
    if (!isStaff(me)) throw new DemoError('Nur für Lehrer', 403)
    const target = db.users.find((u) => u.id === Number(parts[1]) && u.active !== false)
    if (!target) throw new DemoError('Benutzer nicht gefunden', 404)
    if (target.id === me.id) throw new DemoError('Das eigene Konto kann nicht gelöscht werden', 400)
    if (target.role !== 'teilnehmer') throw new DemoError('Nur Teilnehmer-Konten können gelöscht werden', 403)
    const username = target.username
    target.active = false
    target.username = `${username}~${target.id}`
    devRoutes.revokeDev(db, target.id)
    log(db, me.id, 'user_deleted', null, { username })
    return { ok: true }
  }
  const devResult = devRoutes.route(db, method, parts, params, body, () => requireUser(db), (...a) => log(db, ...a))
  if (devResult !== undefined) return devResult
  const result = shop.route(db, method, parts, params, body, () => requireUser(db), (...a) => log(db, ...a))
  if (result !== undefined) return result
  if (method === 'GET' && parts[0] === 'bl' && parts[1] === 'item') {
    requireUser(db)
    throw new DemoError('In der Demo gibt es keine BrickLink-Verbindung. Auf dem Schulserver kommt sie aus der .env.', 503)
  }
  throw new DemoError('Nicht gefunden', 404)
}

/** Demo-Daten auf den Anfangszustand zurücksetzen (Dev-Bereich). Danach ist man abgemeldet. */
export function resetDemo() {
  memory = null
  try { localStorage.removeItem(KEY) } catch { /* egal */ }
}
