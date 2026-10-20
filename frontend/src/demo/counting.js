// Zählen im Demo-Backend: Vormerkungen aus Meldungen und Zählliste des Tages. Gleiche Regeln wie api/lib/Counting.php.
import { DemoError, now } from './backend.js'

export const REASONS = { missing: 'Fehlteil gemeldet', wrong_quantity: 'Restmenge stimmt nicht', wrong_location: 'Lagerort stimmt nicht' }
export const DEFAULT_SIZE = 5

const st8 = (db) => (db.counting ??= { requests: [], daily: {}, size: null, nextRequest: 1 })
const size = (db) => st8(db).size ?? DEFAULT_SIZE
const round2 = (v) => Math.round(v * 100) / 100
const natural = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), 'de', { numeric: true }) // wie strnatcasecmp

// Kästchen einer Auftragsposition: über das Lot, sonst der Lagerort-Code aus den Remarks (wie Counting::locationOfItem)
function locationOfItem(db, itemId) {
  for (const o of db.orders) {
    const x = o.items.find((i) => i.id === itemId)
    if (!x) continue
    const lot = db.inventory.find((i) => i.id === x.inventory_id)
    if (lot?.location_id) return lot.location_id
    const code = String(x.remarks ?? '').trim().toUpperCase()
    return code ? db.locations.find((l) => l.code.toUpperCase() === code)?.id ?? null : null
  }
  return null
}

export function requestFromReport(db, itemId, type, reportId, userId) {
  if (!REASONS[type]) return
  const loc = locationOfItem(db, itemId)
  if (!loc) return
  const c = st8(db)
  c.requests.push({ id: c.nextRequest++, location_id: loc, reason: type, report_id: reportId, user_id: userId, created_at: now(), done_at: null })
}

export function markDone(db, locationId) {
  const t = now()
  for (const r of st8(db).requests) if (r.location_id === locationId && !r.done_at) r.done_at = t
}

export function openRequests(db, locationId = null) {
  const out = {}
  const rows = st8(db).requests.filter((r) => !r.done_at && (!locationId || r.location_id === locationId))
    .sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id - b.id)
  for (const r of rows) {
    (out[r.location_id] ??= []).push({ reason: r.reason, label: REASONS[r.reason] ?? r.reason, by: db.users.find((u) => u.id === r.user_id)?.display_name ?? null, at: r.created_at })
  }
  return out
}

const lastCounted = (db, id) => db.stocktakes.filter((s) => s.location_id === id && s.status === 'abgeschlossen')
  .reduce((m, s) => (m === null || s.closed_at > m ? s.closed_at : m), null)

function pick(db, day, n) {
  const first = new Map()
  for (const r of st8(db).requests.filter((x) => !x.done_at)) {
    const f = first.get(r.location_id)
    if (!f) first.set(r.location_id, { id: r.location_id, at: r.created_at, rid: r.id })
    else { if (r.created_at < f.at) f.at = r.created_at; if (r.id < f.rid) f.rid = r.id }
  }
  const chosen = [...first.values()].sort((a, b) => a.at.localeCompare(b.at) || a.rid - b.rid).map((x) => x.id).slice(0, n)
  if (chosen.length < n) {
    const byLoc = new Map()
    for (const i of db.inventory) {
      if (!(i.qty > 0) || !i.location_id) continue
      byLoc.set(i.location_id, (byLoc.get(i.location_id) ?? 0) + i.qty * i.price)
    }
    const rows = [...byLoc].map(([id, value]) => ({ id, code: db.locations.find((l) => l.id === id)?.code ?? '', value, last: lastCounted(db, id) }))
    rows.sort((a, b) => {
      if ((a.last === null) !== (b.last === null)) return a.last === null ? -1 : 1
      return String(a.last ?? '').localeCompare(String(b.last ?? '')) || round2(b.value) - round2(a.value) || natural(a.code, b.code)
    })
    for (const r of rows) {
      if (chosen.length >= n) break
      if (!chosen.includes(r.id)) chosen.push(r.id)
    }
  }
  st8(db).daily = { [day]: chosen } // nur der heutige Tag wird gebraucht
  return chosen
}

function describe(db, ids, requests, day) {
  return ids.map((id) => {
    const l = db.locations.find((x) => x.id === id)
    if (!l) return null
    const lots = db.inventory.filter((i) => i.location_id === id)
    const last = lastCounted(db, id)
    const open = db.stocktakes.filter((s) => s.location_id === id && s.status === 'offen').sort((a, b) => b.id - a.id)[0]
    const reqs = requests[id] ?? []
    return {
      location_id: id, code: l.code, lots: lots.length, parts: lots.reduce((s, i) => s + i.qty, 0), value: round2(lots.reduce((s, i) => s + i.qty * i.price, 0)),
      last_counted: last, requests: reqs,
      why: reqs.length ? 'vorgemerkt' : last === null ? 'nie gezählt' : 'lange nicht gezählt',
      open_stocktake: open ? open.id : null,
      done: last !== null && last.slice(0, 10) === day && !open,
    }
  }).filter(Boolean)
}

export function today(db) {
  const day = now().slice(0, 10)
  const n = size(db)
  const ids = st8(db).daily[day] ?? pick(db, day, n)
  const requests = openRequests(db)
  const extra = Object.keys(requests).map(Number).filter((id) => !ids.includes(id))
  return { day, size: n, items: describe(db, ids, requests, day), extra: describe(db, extra, requests, day) }
}

export function route(db, method, parts, body, requireUser, log) {
  if (parts[0] !== 'stocktakes') return undefined
  if (method === 'GET' && parts[1] === 'today') { requireUser(); return today(db) }
  if (method === 'POST' && parts[1] === 'daily-size') {
    const me = requireUser()
    if (!['lehrer', 'admin'].includes(me.role)) throw new DemoError('Nur für Lehrer', 403)
    const n = Math.max(1, Math.min(30, Math.trunc(Number(body.size ?? DEFAULT_SIZE)) || 0))
    st8(db).size = n
    log(me.id, 'count_daily_size', 'inventur', { anzahl: n })
    return { size: n }
  }
  return undefined
}
