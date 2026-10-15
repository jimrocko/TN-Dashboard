// Demo-Backend: ersetzt die PHP-API im Browser, damit die App ohne Server ausprobiert werden kann.
// Daten liegen nur im Browser (localStorage, sonst im Arbeitsspeicher).
// Gleiche Beispieldaten und gleiche Regeln wie api/lib/Shop.php.
import seed from '../../../api/sql/demo-data.json'
import * as shop from './shop.js'

const KEY = 'tn-dashboard-demo-v2'

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
    nextLog: 1,
    session: null,
    users: [
      { id: 1, username: 'lehrer', display_name: 'Herr Lehrer', password: 'lehrer123', role: 'lehrer', created_at: t, last_seen: null },
      { id: 2, username: 'max', display_name: 'Max Mustermann', password: 'max12345', role: 'teilnehmer', created_at: t, last_seen: null },
      { id: 3, username: 'erika', display_name: 'Erika Musterfrau', password: 'erika123', role: 'teilnehmer', created_at: t, last_seen: null },
    ],
    log: [],
    ...shop.seedData(seed),
  }
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

const pub = (u) => ({ id: u.id, username: u.username, display_name: u.display_name, role: u.role, last_seen: u.last_seen })
const isStaff = (u) => ['lehrer', 'admin'].includes(u.role)

function log(db, userId, action, module = null, details = null) {
  db.log.push({ id: db.nextLog++, user_id: userId, action, module, details: details && Object.keys(details).length ? details : null, created_at: now() })
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
  const u = db.users.find((x) => x.id === db.session)
  if (u) u.last_seen = now()
  return u ?? null
}
function requireUser(db) {
  const u = current(db)
  if (!u) throw new DemoError('Nicht angemeldet', 401)
  return u
}

export async function handle(method, route, params = {}, body = {}) {
  const db = load()
  try {
    return dispatch(db, method, route, params, body ?? {})
  } finally {
    save(db)
  }
}

function dispatch(db, method, route, params, body) {
  const parts = route.split('/')
  switch (`${method} ${route}`) {
    case 'POST auth/login': {
      const u = db.users.find((x) => x.username === String(body.username ?? '').trim())
      if (!u || u.password !== body.password) throw new DemoError('Benutzername oder Passwort falsch', 401)
      db.session = u.id
      log(db, u.id, 'login')
      return { user: pub(u) }
    }
    case 'POST auth/logout': {
      const u = current(db)
      if (u) log(db, u.id, 'logout')
      db.session = null
      return { ok: true }
    }
    case 'GET auth/me': {
      const u = current(db)
      return { user: u ? pub(u) : null }
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
          .map((u) => ({ ...pub(u), activity_count: db.log.filter((r) => r.user_id === u.id).length }))
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
  const result = shop.route(db, method, parts, params, body, () => requireUser(db), (...a) => log(db, ...a))
  if (result !== undefined) return result
  if (method === 'GET' && parts[0] === 'bl' && parts[1] === 'item') {
    requireUser(db)
    throw new DemoError('In der Demo gibt es keine BrickLink-Verbindung. Auf dem Schulserver kommt sie aus der .env.', 503)
  }
  throw new DemoError('Nicht gefunden', 404)
}
