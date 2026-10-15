// Lager, Aufträge, Umlagern, Inventur und Analyse für die Demo, entspricht api/lib/Shop.php.
import { DemoError, now } from './backend.js'

const ORDER_STATUSES = ['Neu', 'Bezahlt', 'Verpackt', 'Versandt', 'Abgeschlossen']
const ORDER_ACTIVE = ['Neu', 'Bezahlt', 'Verpackt']

const fmt = (d) => {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}
const ago = (days) => fmt(new Date(Date.now() - days * 86400000 - Math.floor(Math.random() * 6 * 3600000)))
const like = (v, term) => String(v ?? '').toLowerCase().includes(term.toLowerCase())
const round = (n, d = 2) => Math.round(n * 10 ** d) / 10 ** d

export function seedData(seed) {
  const locations = seed.locations.map((l, i) => ({ id: i + 1, ...l }))
  const locId = Object.fromEntries(locations.map((l) => [l.code, l.id]))
  const inventory = seed.inventory.map((p, i) => ({
    id: i + 1, item_type: p.item_type, item_no: p.item_no, color_id: p.color_id, color_name: p.color_name,
    name: p.name, category: p.category, location_id: locId[p.location], qty: p.qty, price: p.price,
    updated_at: ago(p.updated_days_ago),
  }))
  let itemId = 1
  const orders = seed.orders.map((o, i) => ({
    id: i + 1, order_no: o.order_no, buyer: o.buyer, status: o.status, ordered_at: ago(o.days_ago),
    total: round(o.items.reduce((s, it) => s + it.qty * it.price, 0)),
    items: o.items.map((it) => {
      const p = seed.inventory[it.lot]
      return { id: itemId++, inventory_id: it.lot + 1, item_no: p.item_no, color_name: p.color_name, name: p.name, qty: it.qty, price: it.price }
    }),
  }))
  const db = { locations, inventory, orders, transfers: [], stocktakes: [], nextLot: inventory.length + 1, nextTransfer: 1, nextStocktake: 1, nextStItem: 1 }
  for (const s of seed.stocktakes) createStocktake(db, locId[s.location])
  return db
}

const loc = (db, id) => db.locations.find((l) => l.id === id)
const lotRow = (db, i) => {
  const l = loc(db, i.location_id)
  return { ...i, location_code: l?.code ?? null, location_kind: l?.kind ?? null }
}

export function dashboard(db) {
  return {
    inventar: {
      total_parts: db.inventory.reduce((s, i) => s + i.qty, 0),
      lots: db.inventory.length,
      value: round(db.inventory.reduce((s, i) => s + i.qty * i.price, 0)),
    },
    auftraege: { active: db.orders.filter((o) => ORDER_ACTIVE.includes(o.status)).length },
    umlagern: { suggestions: suggestions(db).length },
    inventur: { open: db.stocktakes.filter((s) => s.status === 'offen').length },
    analyse: { last_run: new Date().toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) },
    lagerorte: { count: db.locations.length },
  }
}

function inventory(db, f) {
  const q = f.q ?? ''
  let rows = db.inventory.map((i) => lotRow(db, i)).filter((r) =>
    (!q || [r.item_no, r.name, r.color_name, r.category, r.location_code].some((v) => like(v, q)))
    && (!f.location || r.location_id === Number(f.location))
    && (!f.category || r.category === f.category))
  const sorts = {
    qty: (a, b) => b.qty - a.qty,
    location: (a, b) => String(a.location_code).localeCompare(b.location_code) || a.item_no.localeCompare(b.item_no),
    updated: (a, b) => b.updated_at.localeCompare(a.updated_at),
    item: (a, b) => a.item_no.localeCompare(b.item_no) || a.color_name.localeCompare(b.color_name),
  }
  rows.sort(sorts[f.sort] ?? sorts.item)
  return rows.slice(0, Math.max(1, Math.min(Number(f.limit) || 500, 2000)))
}

function meta(db) {
  return {
    categories: [...new Set(db.inventory.map((i) => i.category))].sort(),
    locations: db.locations.map((l) => {
      const lots = db.inventory.filter((i) => i.location_id === l.id)
      return { ...l, lots: lots.length, qty: lots.reduce((s, i) => s + i.qty, 0) }
    }).sort((a, b) => a.code.localeCompare(b.code)),
  }
}

function orders(db, status, term) {
  return db.orders
    .filter((o) => (status === 'aktiv' ? ORDER_ACTIVE.includes(o.status) : !status || o.status === status)
      && (!term || like(o.order_no, term) || like(o.buyer, term)))
    .map(({ items, ...o }) => ({ ...o, positions: items.length, parts: items.reduce((s, x) => s + x.qty, 0) }))
    .sort((a, b) => b.ordered_at.localeCompare(a.ordered_at))
}

function order(db, id) {
  const o = db.orders.find((x) => x.id === id)
  if (!o) return null
  const items = o.items.map((x) => {
    const lot = db.inventory.find((i) => i.id === x.inventory_id)
    return { ...x, location_code: lot ? loc(db, lot.location_id)?.code ?? null : null, stock: lot?.qty ?? null }
  }).sort((a, b) => String(a.location_code).localeCompare(String(b.location_code)) || a.item_no.localeCompare(b.item_no))
  return { ...o, items }
}

function suggestions(db) {
  const shelf = db.inventory.filter((i) => loc(db, i.location_id)?.kind === 'regal').sort((a, b) => b.qty - a.qty)
  const byKey = {}
  for (const s of shelf) byKey[`${s.item_no}|${s.color_id}`] ??= s
  return db.inventory
    .filter((i) => loc(db, i.location_id)?.kind === 'mischkiste')
    .map((i) => lotRow(db, i))
    .sort((a, b) => a.location_code.localeCompare(b.location_code) || a.item_no.localeCompare(b.item_no))
    .flatMap((m) => {
      const t = byKey[`${m.item_no}|${m.color_id}`]
      return t ? [{ lot: m, to_location_id: t.location_id, to_location_code: loc(db, t.location_id).code, target_qty: t.qty }] : []
    })
}

function transfer(db, lotId, toId, qty, userId) {
  const lot = db.inventory.find((i) => i.id === lotId)
  if (!lot) throw new DemoError('Los nicht gefunden', 404)
  const to = loc(db, toId)
  if (!to) throw new DemoError('Ziel-Lagerort nicht gefunden', 404)
  if (lot.location_id === toId) throw new DemoError('Quelle und Ziel sind gleich', 400)
  if (!(qty >= 1 && qty <= lot.qty)) throw new DemoError(`Menge muss zwischen 1 und ${lot.qty} liegen`, 400)
  const t = now()
  const from = loc(db, lot.location_id)?.code ?? null
  const existing = db.inventory.find((i) => i.location_id === toId && i.item_no === lot.item_no && i.color_id === lot.color_id)
  if (existing) {
    existing.qty += qty
    existing.updated_at = t
  } else if (qty === lot.qty) {
    lot.location_id = toId
    lot.updated_at = t
  } else {
    db.inventory.push({ ...lot, id: db.nextLot++, location_id: toId, qty, updated_at: t })
  }
  if (existing || qty < lot.qty) {
    if (qty === lot.qty) db.inventory = db.inventory.filter((i) => i.id !== lotId)
    else { lot.qty -= qty; lot.updated_at = t }
  }
  db.transfers.push({ id: db.nextTransfer++, item_no: lot.item_no, color_name: lot.color_name, name: lot.name,
    from_code: from, to_code: to.code, qty, user_id: userId, created_at: t })
  return { item_no: lot.item_no, color: lot.color_name, qty, from, to: to.code }
}

function stocktakeRow(db, s) {
  const l = loc(db, s.location_id)
  return {
    id: s.id, status: s.status, created_at: s.created_at, closed_at: s.closed_at,
    location_code: l.code, location_name: l.name,
    positions: s.items.length,
    counted: s.items.filter((x) => x.counted !== null).length,
    deviations: s.items.filter((x) => x.counted !== null && x.counted !== x.expected).length,
    closed_by_name: s.closed_by_name ?? null,
  }
}

function createStocktake(db, locationId) {
  if (!loc(db, locationId)) throw new DemoError('Lagerort nicht gefunden', 404)
  if (db.stocktakes.some((s) => s.location_id === locationId && s.status === 'offen')) {
    throw new DemoError('Für diesen Lagerort gibt es schon eine offene Prüfung', 409)
  }
  const s = {
    id: db.nextStocktake++, location_id: locationId, status: 'offen', created_at: now(), closed_at: null,
    items: db.inventory.filter((i) => i.location_id === locationId)
      .map((i) => ({ id: db.nextStItem++, inventory_id: i.id, item_no: i.item_no, color_name: i.color_name, name: i.name, expected: i.qty, counted: null }))
      .sort((a, b) => a.item_no.localeCompare(b.item_no) || a.color_name.localeCompare(b.color_name)),
  }
  db.stocktakes.push(s)
  return s
}

function analysis(db) {
  const group = (key) => {
    const m = {}
    for (const i of db.inventory) {
      const g = (m[key(i)] ??= { label: key(i), lots: 0, qty: 0, value: 0 })
      g.lots++; g.qty += i.qty; g.value += i.qty * i.price
    }
    return Object.values(m).map((g) => ({ ...g, value: round(g.value, 3) })).sort((a, b) => b.qty - a.qty)
  }
  const byLoc = db.locations.map((l) => {
    const lots = db.inventory.filter((i) => i.location_id === l.id)
    return { label: l.code, kind: l.kind, lots: lots.length, qty: lots.reduce((s, i) => s + i.qty, 0) }
  }).sort((a, b) => b.qty - a.qty)
  return {
    by_category: group((i) => i.category),
    by_location: byLoc.filter((l) => l.kind === 'regal').slice(0, 10),
    mixed_bins: byLoc.filter((l) => l.kind === 'mischkiste'),
    orders_by_status: ORDER_STATUSES.map((s) => ({ label: s, count: db.orders.filter((o) => o.status === s).length })),
    low_stock: db.inventory.filter((i) => loc(db, i.location_id)?.kind === 'regal' && i.qty < 20)
      .sort((a, b) => a.qty - b.qty).slice(0, 10).map((i) => lotRow(db, i)),
    activity_by_participant: db.users.filter((u) => u.role === 'teilnehmer').map((u) => ({
      label: u.display_name,
      count: db.log.filter((a) => a.user_id === u.id && !['login', 'logout'].includes(a.action)).length,
    })).sort((a, b) => b.count - a.count),
    generated_at: now(),
  }
}

/** Liefert die Antwort für Shop-Routen oder undefined, wenn die Route nicht hierher gehört. */
export function route(db, method, parts, params, body, requireUser, log) {
  const [a, b, c] = parts
  const id = Number(b)
  const isId = /^\d+$/.test(b ?? '')

  if (method === 'POST' && a === 'demo' && b === 'seed') throw new DemoError('Die Demo hat bereits Beispieldaten', 409)
  if (method === 'GET' && a === 'inventory' && !b) { requireUser(); return { items: inventory(db, params) } }
  if (method === 'GET' && a === 'inventory' && b === 'meta') { requireUser(); return meta(db) }
  if (method === 'GET' && a === 'search') {
    requireUser()
    const term = String(params.q ?? '').trim()
    if (!term) return { parts: [], locations: [], orders: [] }
    return {
      parts: inventory(db, { q: term, limit: 50 }),
      locations: db.locations.filter((l) => like(l.code, term) || like(l.name, term)).slice(0, 20),
      orders: orders(db, null, term),
    }
  }
  if (method === 'GET' && a === 'orders' && !b) { requireUser(); return { orders: orders(db, params.status, params.q) } }
  if (method === 'GET' && a === 'orders' && isId && !c) {
    requireUser()
    const o = order(db, id)
    if (!o) throw new DemoError('Auftrag nicht gefunden', 404)
    return { order: o }
  }
  if (method === 'POST' && a === 'orders' && isId && c === 'status') {
    const me = requireUser()
    if (!ORDER_STATUSES.includes(body.status)) throw new DemoError('Unbekannter Status', 400)
    const o = db.orders.find((x) => x.id === id)
    if (!o) throw new DemoError('Auftrag nicht gefunden', 404)
    o.status = body.status
    log(me.id, 'order_status', 'auftraege', { auftrag: o.order_no, status: body.status })
    return { order: order(db, id) }
  }
  if (method === 'GET' && a === 'transfers' && b === 'suggestions') { requireUser(); return { suggestions: suggestions(db) } }
  if (method === 'GET' && a === 'transfers' && !b) {
    requireUser()
    return {
      transfers: [...db.transfers].reverse().slice(0, Number(params.limit) || 30)
        .map((t) => ({ ...t, display_name: db.users.find((u) => u.id === t.user_id)?.display_name })),
    }
  }
  if (method === 'POST' && a === 'transfers' && !b) {
    const me = requireUser()
    const r = transfer(db, Number(body.lot_id), Number(body.to_location_id), Number(body.qty), me.id)
    log(me.id, 'transfer', 'umlagern', { teil: `${r.item_no} ${r.color}`, menge: r.qty, von: r.from, nach: r.to })
    return { transfer: r }
  }
  if (method === 'GET' && a === 'stocktakes' && !b) {
    requireUser()
    const rows = db.stocktakes.map((s) => stocktakeRow(db, s))
    rows.sort((x, y) => (x.status === 'offen' ? 0 : 1) - (y.status === 'offen' ? 0 : 1) || y.created_at.localeCompare(x.created_at))
    return { stocktakes: rows }
  }
  if (method === 'POST' && a === 'stocktakes' && !b) {
    const me = requireUser()
    const s = createStocktake(db, Number(body.location_id))
    log(me.id, 'stocktake_created', 'inventur', { lagerort: loc(db, s.location_id).code })
    return { stocktake: { ...stocktakeRow(db, s), items: s.items } }
  }
  const st = isId && a === 'stocktakes' ? db.stocktakes.find((s) => s.id === id) : null
  if (method === 'GET' && a === 'stocktakes' && isId && !c) {
    requireUser()
    if (!st) throw new DemoError('Prüfung nicht gefunden', 404)
    return { stocktake: { ...stocktakeRow(db, st), items: st.items } }
  }
  if (method === 'POST' && a === 'stocktakes' && isId && c === 'count') {
    requireUser()
    if (!st || st.status !== 'offen') throw new DemoError('Prüfung ist nicht mehr offen', 409)
    const counted = body.counted === null || body.counted === '' || body.counted === undefined ? null : Math.trunc(Number(body.counted))
    if (counted !== null && counted < 0) throw new DemoError('Menge darf nicht negativ sein', 400)
    const it = st.items.find((x) => x.id === Number(body.item_id))
    if (it) it.counted = counted
    return { ok: true }
  }
  if (method === 'POST' && a === 'stocktakes' && isId && c === 'close') {
    const me = requireUser()
    if (!st) throw new DemoError('Prüfung nicht gefunden', 404)
    if (st.status !== 'offen') throw new DemoError('Prüfung ist bereits abgeschlossen', 409)
    const t = now()
    const corrections = []
    for (const it of st.items) {
      if (it.counted === null || it.counted === it.expected) continue
      const lot = db.inventory.find((i) => i.id === it.inventory_id)
      if (!lot) continue
      const diff = it.counted - it.expected
      lot.qty = Math.max(0, lot.qty + diff)
      lot.updated_at = t
      corrections.push({ item_no: it.item_no, color: it.color_name, diff })
    }
    Object.assign(st, { status: 'abgeschlossen', closed_at: t, closed_by_name: me.display_name })
    const code = loc(db, st.location_id).code
    log(me.id, 'stocktake_closed', 'inventur', { lagerort: code, korrekturen: corrections.length })
    return { location: code, corrections }
  }
  if (method === 'GET' && a === 'analysis') { requireUser(); return analysis(db) }
  return undefined
}
