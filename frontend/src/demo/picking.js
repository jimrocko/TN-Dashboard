// Pickliste für die Demo, gleiche Regeln wie api/lib/Picking.php (Nachbau des Python-Originals).
import { DemoError, now } from './backend.js'
import { naturalCmp } from './rules.js'
import { requestFromReport } from './counting.js'

export const OPEN = ['Bezahlt', 'Verpackt']
const ISSUES = ['missing', 'wrong_location', 'wrong_quantity', 'wrong_item', 'other']
const BO_COLORS = {
  'dark stone gray': 'Dark Bluish Gray', 'medium stone gray': 'Light Bluish Gray', 'light stone gray': 'Very Light Bluish Gray',
  transparent: 'Trans-Clear', 'transparent clear': 'Trans-Clear', 'transparent light blue': 'Trans-Light Blue',
  'transparent dark blue': 'Trans-Dark Blue', 'transparent red': 'Trans-Red', 'transparent green': 'Trans-Green',
  'transparent yellow': 'Trans-Yellow', 'transparent orange': 'Trans-Orange', 'transparent neon green': 'Trans-Neon Green',
  'transparent neon orange': 'Trans-Neon Orange', 'transparent bright green': 'Trans-Bright Green',
  'transparent purple': 'Trans-Purple', 'transparent pink': 'Trans-Dark Pink',
}

const pk = (db) => (db.pick ??= { progress: {}, events: [], claims: {}, reports: [], groups: [], state: {}, revision: 0, nextEvent: 1, nextReport: 1, nextGroup: 1 })
const userName = (db, id) => db.users.find((u) => u.id === id)?.display_name ?? null
const trim = (v) => String(v ?? '').trim()

export function conditionLabel(v) {
  const u = trim(v).toUpperCase()
  if (['N', 'NEW', 'NEU'].includes(u) || u.startsWith('NEW')) return 'Neu'
  if (['U', 'USED', 'GEBRAUCHT'].includes(u) || u.startsWith('USED')) return 'Gebraucht'
  return trim(v)
}
const conditionCode = (v) => {
  const u = trim(v).toUpperCase()
  if (u === 'N' || u.startsWith('NEW') || u === 'NEU') return 'N'
  if (u === 'U' || u.startsWith('USED') || u === 'GEBRAUCHT') return 'U'
  return ''
}
function looksLikeLocation(v) {
  v = trim(v)
  if (!v) return false
  if (v.toLowerCase() === 'regal') return true
  const simple = '[A-Za-z]+[0-9]+'
  const plate = '[A-Za-z]+-[A-Za-z]+[0-9]+'
  return new RegExp(`^(?:${simple}|${plate}|(?:${simple}|${plate})\\s+(?:${simple}|${plate}))$`).test(v)
}

// BrickOwl-Farbe als BrickLink-Farbe anzeigen, wie brickLinkColor() in Picking.php (nur Anzeige und Bild)
function brickLinkColor(db, boName) {
  const name = BO_COLORS[trim(boName).toLowerCase()] ?? trim(boName)
  if (!name) return { id: null, name: '' }
  const low = name.toLowerCase()
  const r = db.inventory.find((x) => String(x.color_name ?? '').toLowerCase() === low && x.color_id !== null && x.color_id !== undefined)
    ?? (db.inventory_history ?? []).find((x) => String(x.color_name ?? '').toLowerCase() === low && x.color_id !== null && x.color_id !== undefined)
  return r ? { id: r.color_id, name: r.color_name } : { id: null, name }
}

function state(db, orderId) {
  const s = pk(db).state[orderId]
  return { revision: s?.revision ?? 0, completed: !!s?.completed }
}
function bump(db, orderId) {
  const p = pk(db)
  const s = (p.state[orderId] ??= { revision: 0, completed: false })
  s.revision++
  p.revision++
  return s.revision
}
function itemOrder(db, itemId) {
  for (const o of db.orders) {
    const it = o.items.find((x) => x.id === itemId)
    if (it) return { order: o, item: it }
  }
  throw new DemoError('Position nicht gefunden', 400)
}
const locCode = (db, id) => db.locations.find((l) => l.id === id)?.code ?? null

export function openOrders(db) {
  const p = pk(db)
  return db.orders.filter((o) => OPEN.includes(o.status))
    .sort((a, b) => (a.ordered_at < b.ordered_at ? -1 : a.ordered_at > b.ordered_at ? 1 : a.id - b.id))
    .map((o) => {
      const picked = o.items.filter((x) => p.progress[x.id]?.picked)
      const ids = [...new Set(picked.map((x) => p.progress[x.id].user_id))]
      const pickers = ids.map((id) => ({ id, name: userName(db, id) })).filter((x) => x.name).sort((a, b) => (a.name < b.name ? -1 : 1))
      const st = state(db, o.id)
      return {
        id: o.id, platform: o.platform, order_no: o.order_no, buyer_name: o.buyer || null, status: o.platform_status || o.status,
        date_ordered: o.ordered_at, positions: o.items.length, parts: o.items.reduce((s, x) => s + x.qty, 0), picked_positions: picked.length,
        percent: o.items.length ? Math.round((100 * picked.length) / o.items.length) : 0,
        picker_names: pickers.map((x) => x.name), picker_ids: pickers.map((x) => x.id), completed: st.completed, revision: st.revision,
      }
    })
}

const invRow = (db, i) => ({ id: i.id, bl_inventory_id: i.bl_inventory_id ?? null, remarks: locCode(db, i.location_id), quantity: i.qty, new_or_used: i.new_or_used ?? '', unit_price: i.price, current: true })

/** Lots aus dem Bestand: gleiches Teil, gleiche Farbe (BrickOwl: Farbname), gleicher Zustand. */
function candidates(db, row) {
  const cond = conditionCode(row.new_or_used)
  if (row.platform === 'BrickOwl') {
    const color = (BO_COLORS[trim(row.color_name).toLowerCase()] ?? trim(row.color_name)).toLowerCase()
    const match = (x) => x.item_no === row.item_no && String(x.color_name ?? '').toLowerCase() === color && (!cond || String(x.new_or_used ?? '').toUpperCase() === cond)
    const byId = new Map()
    for (const h of (db.inventory_history ?? []).filter(match)) byId.set(`b${h.bl_inventory_id}`, { id: null, bl_inventory_id: h.bl_inventory_id, remarks: h.location_code, quantity: h.qty, new_or_used: h.new_or_used, unit_price: h.price, current: false })
    for (const i of db.inventory.filter(match)) byId.set(i.bl_inventory_id !== null && i.bl_inventory_id !== undefined ? `b${i.bl_inventory_id}` : `i${i.id}`, invRow(db, i))
    let cands = [...byId.values()]
    const price = Number(row.price)
    if (price > 0 && cands.length > 1) {
      const exact = []
      const near = []
      for (const c of cands) {
        const diff = Math.abs(Number(c.unit_price) - price)
        if (diff <= 0.0006) exact.push(c)
        else if (diff <= Math.max(0.003, price * 0.05)) near.push(c)
      }
      if (exact.length) cands = exact
      else if (near.length === 1) cands = near
    }
    return cands
  }
  const raw = trim(row.new_or_used).toUpperCase()
  return db.inventory.filter((i) => i.item_no === row.item_no && (row.color_id === null ? i.color_id === null : i.color_id === row.color_id)
    && (!['N', 'U'].includes(raw) || String(i.new_or_used ?? '').toUpperCase() === raw)).sort((a, b) => a.id - b.id).map((i) => invRow(db, i))
}

function enrich(db, d) {
  let inv = null
  let hist = null
  const isBo = d.platform === 'BrickOwl'
  if (!isBo && d.inventory_id !== null && d.inventory_id !== undefined) {
    const i = db.inventory.find((x) => x.id === d.inventory_id)
    inv = i ? invRow(db, i) : null
  }
  if (!isBo && !inv && d.ext_lot_id !== null && d.ext_lot_id !== undefined) {
    const h = (db.inventory_history ?? []).find((x) => x.bl_inventory_id === d.ext_lot_id)
    hist = h ? { remarks: h.location_code, quantity: h.qty, new_or_used: h.new_or_used } : null
    if (d.inventory_id === null || d.inventory_id === undefined) {
      const i = db.inventory.find((x) => x.bl_inventory_id === d.ext_lot_id)
      if (i) { inv = invRow(db, i); hist = null }
    }
  }
  const cands = inv ? [inv] : candidates(db, d)
  const distinct = (f, map = (v) => v) => [...new Set(cands.map((x) => map(trim(x[f]))).filter((v) => v !== ''))]
  let location = trim(d.remarks)
  if (!location && inv) location = trim(inv.remarks)
  if (!location && hist) location = trim(hist.remarks)
  if (!location && cands.length) { const l = distinct('remarks'); if (l.length === 1) location = l[0] }
  let cond = trim(d.new_or_used)
  if (!cond && inv) cond = trim(inv.new_or_used)
  if (!cond && hist) cond = trim(hist.new_or_used)
  if (!cond && cands.length) { const c = distinct('new_or_used', (v) => v.toUpperCase()); if (c.length === 1) cond = c[0] }
  if (isBo) {
    const bl = brickLinkColor(db, d.color_name)
    d = { ...d, platform_color_name: d.color_name, color_name: bl.name, color_id: bl.id }
  }
  let img = trim(d.image_url)
  const type = trim(d.item_type).toUpperCase()
  if (!img && ['P', 'PART'].includes(type) && d.item_no && d.color_id !== null && d.color_id !== undefined) {
    img = `https://img.bricklink.com/ItemImage/PN/${d.color_id}/${encodeURIComponent(d.item_no)}.png`
  }
  let rest = 0
  if (inv) rest = Number(inv.quantity)
  else if (cands.length) {
    const locs = distinct('remarks')
    const conds = [...new Set(cands.map((x) => conditionCode(x.new_or_used)).filter(Boolean))]
    if (locs.length <= 1 && conds.length <= 1) for (const c of cands) if (!isBo || c.current) rest += Number(c.quantity)
  }
  return { ...d, location, remaining_stock: rest, condition: conditionLabel(cond), new_or_used: cond, image_url: img }
}

function sortItems(db, items) {
  const map = new Map()
  pk(db).groups.slice().sort((a, b) => a.sort_order - b.sort_order || a.id - b.id).forEach((g, i) => {
    const k = trim(g.location_group).toLowerCase()
    if (!map.has(k)) map.set(k, i)
  })
  const rank = (loc) => {
    const key = trim(loc).toLowerCase()
    let best = null
    for (const [area, r] of map) {
      if (area && key.startsWith(area)) {
        if (!best || area.length > best[0] || (area.length === best[0] && r < best[1])) best = [area.length, r]
      }
    }
    return best ? best[1] : map.size + 1000
  }
  const ranked = items.map((it) => [rank(it.location), it])
  ranked.sort((a, b) => a[0] - b[0] || naturalCmp(trim(a[1].location), trim(b[1].location)))
  return ranked.map((x) => x[1])
}

export function detail(db, id) {
  const o = db.orders.find((x) => x.id === id)
  if (!o) return null
  const p = pk(db)
  const st = state(db, id)
  const order = {
    id: o.id, platform: o.platform, order_no: o.order_no, buyer_name: o.buyer || null, status: o.platform_status || o.status,
    date_ordered: o.ordered_at, grand_total: o.total, currency_code: o.currency || 'EUR', completed: st.completed, revision: st.revision,
  }
  // Meldungen sehen alle sofort, mit Namen des Meldenden (wie Picking.php)
  const reports = p.reports.filter((r) => r.order_id === id && r.status === 'OPEN')
  let items = o.items.slice().sort((a, b) => a.id - b.id).map((x) => {
    const pr = p.progress[x.id]
    const cl = p.claims[x.id]
    const it = enrich(db, {
      id: x.id, inventory_id: x.inventory_id ?? null, item_type: x.item_type, item_no: x.item_no, color_id: x.color_id ?? null, color_name: x.color_name,
      item_name: x.name, quantity: x.qty, price: x.price, new_or_used: x.new_or_used ?? '', remarks: x.remarks ?? null, ext_lot_id: x.ext_lot_id ?? null,
      image_url: x.image_url ?? null, platform: o.platform, picked: !!pr?.picked, picker_id: pr?.user_id ?? null, picked_at: pr?.picked_at ?? null,
      picker_name: pr?.user_id ? userName(db, pr.user_id) : null, claim_picker_id: cl?.user_id ?? null, claim_picker_name: cl ? userName(db, cl.user_id) : null,
    })
    delete it.platform
    it.reports = reports.filter((r) => r.order_item_id === x.id).map(({ id: rid, order_item_id, issue_type, details, missing_quantity, found_location, actual_quantity, created_at, user_id }) =>
      ({ id: rid, order_item_id, issue_type, details, missing_quantity, found_location, actual_quantity, created_at, user_id, user_name: userName(db, user_id) }))
    it.report_count = it.reports.length
    return it
  })
  items = sortItems(db, items)
  const names = []
  for (const it of items) if (it.picked && it.picker_name && !names.includes(it.picker_name)) names.push(it.picker_name)
  return { order, items, picker_names: names, shipping: o.shipping ?? {}, sender: ['TN Lego-Shop (Beispiel)', 'Musterstraße 1', '12345 Musterstadt'] }
}

export function complete(db, id) {
  const o = db.orders.find((x) => x.id === id)
  if (!o) throw new DemoError('Bestellung nicht gefunden', 404)
  const p = pk(db)
  if (!o.items.length || o.items.some((x) => !p.progress[x.id]?.picked)) {
    throw new DemoError('Zum Abschließen müssen Positionen vorhanden und vollständig gepickt sein.', 409)
  }
  bump(db, id)
  p.state[id].completed = true
  return { completed: true, revision: state(db, id).revision }
}

export function reopen(db, id) {
  if (!state(db, id).completed) throw new DemoError('Bestellung ist nicht abgeschlossen.', 409)
  pk(db).state[id].completed = false
  return { completed: false, revision: bump(db, id) }
}

export function toggle(db, itemId, user, want = null) {
  const { order } = itemOrder(db, itemId)
  const p = pk(db)
  // Gewünschter Zustand mitgeschickt und schon so: nichts ändern (wie Picking::toggle)
  if (want !== null && !!p.progress[itemId]?.picked === want) {
    const c = p.progress[itemId]
    return { item_id: itemId, picked: want, picker_id: want ? c.user_id : null, picker_name: want ? userName(db, c.user_id) : null, picked_at: want ? c.picked_at : null, order_id: order.id, unchanged: true }
  }
  if (state(db, order.id).completed) throw new DemoError("Bestellung ist erledigt. Bitte zuerst 'Bestellung ändern' wählen.", 400)
  const claim = p.claims[itemId]
  if (claim && claim.user_id !== user.id) throw new DemoError(`Diese Position ist für ${userName(db, claim.user_id) ?? 'einen anderen Picker'} vorgemerkt.`, 403)
  const cur = p.progress[itemId]
  let neu = 1
  if (cur?.picked) {
    if (cur.user_id !== user.id) {
      throw new DemoError(`Diese Position wurde von ${userName(db, cur.user_id) ?? 'einem anderen Picker'} gepickt und kann nur von diesem Picker zurückgesetzt werden.`, 403)
    }
    neu = 0
  }
  // Vormerkung bleibt beim Picken/Enticken bestehen, nur „Markieren“ entfernt sie (wie Picking.php)
  const t = now()
  p.progress[itemId] = { picked: neu, user_id: neu ? user.id : null, picked_at: neu ? t : null }
  p.events.push({ id: p.nextEvent++, order_item_id: itemId, user_id: user.id, action: neu ? 'picked' : 'reopened', created_at: t })
  bump(db, order.id)
  return { item_id: itemId, picked: !!neu, picker_id: neu ? user.id : null, picker_name: neu ? user.display_name : null, picked_at: neu ? t : null, order_id: order.id }
}

export function claim(db, itemId, user, want = null) {
  const { order } = itemOrder(db, itemId)
  const p = pk(db)
  if (want !== null && (p.claims[itemId]?.user_id === user.id) === want) {
    return { item_id: itemId, claimed: want, picker_id: want ? user.id : null, picker_name: want ? user.display_name : null, unchanged: true }
  }
  if (state(db, order.id).completed) throw new DemoError("Bestellung ist erledigt. Bitte zuerst 'Bestellung ändern' wählen.", 400)
  const owner = p.claims[itemId]
  if (!owner && p.progress[itemId]?.picked) throw new DemoError('Position ist bereits gepickt', 400)
  if (owner) {
    if (owner.user_id !== user.id) throw new DemoError(`Diese Position ist bereits für ${userName(db, owner.user_id) ?? 'einen anderen Picker'} vorgemerkt.`, 409)
    delete p.claims[itemId]
    bump(db, order.id)
    return { item_id: itemId, claimed: false, picker_id: null, picker_name: null }
  }
  const t = now()
  p.claims[itemId] = { order_id: order.id, user_id: user.id, claimed_at: t }
  bump(db, order.id)
  return { item_id: itemId, claimed: true, picker_id: user.id, picker_name: user.display_name, claimed_at: t }
}

export function report(db, itemId, userId, input) {
  const type = trim(input.issue_type)
  if (!ISSUES.includes(type)) throw new DemoError('Meldegrund ungültig', 400)
  const details = trim(input.details) || null
  if (details && details.length > 500) throw new DemoError('Die Notiz darf höchstens 500 Zeichen enthalten.', 400)
  const { order } = itemOrder(db, itemId)
  const p = pk(db)
  const actual = input.actual_quantity === null || input.actual_quantity === undefined || input.actual_quantity === '' || Number.isNaN(Number(input.actual_quantity)) ? null : Math.max(0, Math.trunc(Number(input.actual_quantity)))
  const t = now()
  const r = {
    id: p.nextReport++, order_id: order.id, order_item_id: itemId, user_id: userId, issue_type: type, details,
    missing_quantity: Math.max(1, Math.trunc(Number(input.missing_quantity)) || 1), found_location: trim(input.found_location).slice(0, 64) || null,
    actual_quantity: actual, status: 'OPEN', created_at: t,
  }
  p.reports.push(r)
  // Fehlteil, falsche Restmenge oder falscher Lagerort → Kästchen zum Zählen vormerken (wie Counting::requestFromReport)
  requestFromReport(db, itemId, type, r.id, userId)
  bump(db, order.id)
  const count = p.reports.filter((x) => x.order_item_id === itemId && x.status === 'OPEN').length
  return { id: r.id, item_id: itemId, issue_type: type, report_count: count, created_at: t, order_id: order.id }
}

export function resolve(db, reportId) {
  const r = pk(db).reports.find((x) => x.id === reportId && x.status === 'OPEN')
  if (!r) throw new DemoError('Meldung nicht gefunden', 400)
  r.status = 'RESOLVED'
  bump(db, r.order_id)
  return { id: reportId, resolved: true }
}

export const locationOrder = (db) => pk(db).groups.slice().sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)

export function saveLocationOrder(db, groups) {
  const seen = new Set()
  const clean = []
  for (const g of groups) {
    const v = trim(g)
    if (v && !seen.has(v.toLowerCase())) { seen.add(v.toLowerCase()); clean.push(v.slice(0, 64)) }
  }
  const p = pk(db)
  p.groups = clean.map((v, i) => ({ id: p.nextGroup++, location_group: v, sort_order: (i + 1) * 10 }))
  return locationOrder(db)
}

export function availableLocations(db) {
  const vals = new Set()
  for (const i of db.inventory) { const c = locCode(db, i.location_id); if (c) vals.add(trim(c)) }
  for (const h of db.inventory_history ?? []) if (h.location_code) vals.add(trim(h.location_code))
  for (const o of db.orders) for (const x of o.items) if (x.remarks && looksLikeLocation(x.remarks)) vals.add(trim(x.remarks))
  vals.delete('')
  return [...vals].sort(naturalCmp)
}

export function resetTestData(db) {
  const p = pk(db)
  const out = { ok: true, deleted_events: p.events.length, deleted_progress: Object.keys(p.progress).length, deleted_reports: p.reports.length, deleted_claims: Object.keys(p.claims).length }
  p.events = []; p.progress = {}; p.reports = []; p.claims = {}
  for (const s of Object.values(p.state)) s.completed = false
  p.revision++
  return out
}

const dayStr = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const ts = (s) => new Date(String(s).replace(' ', 'T')).getTime()

/** Auswertung des Pick-Protokolls, wie Picking::stats(). */
export function stats(db, from, to, pickerId, platform) {
  const valid = (s) => s && !Number.isNaN(new Date(s).getTime())
  let dFrom = valid(from) ? String(from).slice(0, 10) : dayStr(new Date()).slice(0, 8) + '01'
  let dTo = valid(to) ? String(to).slice(0, 10) : dayStr(new Date())
  if (dTo < dFrom) [dFrom, dTo] = [dTo, dFrom]
  const start = `${dFrom} 00:00:00`
  const endD = new Date(`${dTo}T00:00:00`); endD.setDate(endD.getDate() + 1)
  const end = `${dayStr(endD)} 00:00:00`
  platform = trim(platform) || null
  pickerId = pickerId ? Number(pickerId) : null
  const p = pk(db)
  const itemInfo = new Map()
  for (const o of db.orders) for (const x of o.items) itemInfo.set(x.id, { order: o, item: x })
  const events = p.events.filter((e) => e.created_at >= start && e.created_at < end && itemInfo.has(e.order_item_id)
    && (!pickerId || e.user_id === pickerId) && (!platform || itemInfo.get(e.order_item_id).order.platform === platform))
    .map((e) => ({ ...e, picker_id: e.user_id, picker_name: userName(db, e.user_id), order_id: itemInfo.get(e.order_item_id).order.id, quantity: itemInfo.get(e.order_item_id).item.qty }))
  const picked = events.filter((e) => e.action === 'picked')
  const reopened = events.filter((e) => e.action === 'reopened')
  const touched = new Set(events.map((e) => e.order_id))
  const pickedEventsOf = (oid) => p.events.filter((e) => e.action === 'picked' && itemInfo.get(e.order_item_id)?.order.id === oid)
  const firstPick = (oid) => pickedEventsOf(oid).map((e) => e.created_at).sort()[0] ?? null
  const completed = new Map()
  for (const o of db.orders) {
    if (platform && o.platform !== platform) continue
    if (!o.items.length || o.items.some((x) => !p.progress[x.id]?.picked)) continue
    const at = o.items.map((x) => p.progress[x.id].picked_at).sort().at(-1)
    if (!at || !(start <= at && at < end)) continue
    if (pickerId && !pickedEventsOf(o.id).some((e) => e.user_id === pickerId)) continue
    const first = firstPick(o.id)
    completed.set(o.id, { order_id: o.id, completed_at: at, first_pick_at: first, duration_seconds: first ? Math.max(0, Math.round((ts(at) - ts(first)) / 1000)) : null })
  }
  const durations = [...completed.values()].map((c) => c.duration_seconds).filter((v) => v !== null)
  const pickers = new Map()
  const row = (id, name) => ({ picker_id: id, picker_name: name, positions: 0, parts: 0, corrections: 0, orders: new Set(), completed_orders: 0 })
  for (const e of events) {
    if (!e.picker_id) continue
    if (!pickers.has(e.picker_id)) pickers.set(e.picker_id, row(e.picker_id, e.picker_name || `Picker ${e.picker_id}`))
    const r = pickers.get(e.picker_id)
    r.orders.add(e.order_id)
    if (e.action === 'picked') { r.positions++; r.parts += e.quantity } else if (e.action === 'reopened') r.corrections++
  }
  if (!pickerId) {
    for (const u of db.users.filter((x) => x.active !== false).sort((a, b) => (a.display_name < b.display_name ? -1 : 1))) {
      if (!pickers.has(u.id)) pickers.set(u.id, row(u.id, u.display_name))
    }
  }
  for (const oid of completed.keys()) {
    for (const uid of new Set(pickedEventsOf(oid).map((e) => e.user_id))) if (pickers.has(uid)) pickers.get(uid).completed_orders++
  }
  const pickerRows = [...pickers.values()].map((r) => ({ ...r, orders: r.orders.size }))
    .sort((a, b) => b.positions - a.positions || b.parts - a.parts || (a.picker_name.toLowerCase() < b.picker_name.toLowerCase() ? -1 : a.picker_name.toLowerCase() > b.picker_name.toLowerCase() ? 1 : 0))
  const orders = [...new Set([...touched, ...completed.keys()])].map((oid) => {
    const o = db.orders.find((x) => x.id === oid)
    const c = completed.get(oid)
    return {
      id: oid, platform: o?.platform ?? null, order_no: o?.order_no ?? null, buyer_name: o?.buyer || null, status: o ? o.platform_status || o.status : null,
      date_ordered: o?.ordered_at ?? null, positions: o?.items.length ?? 0, parts: o?.items.reduce((s, x) => s + x.qty, 0) ?? 0,
      picked_positions: o?.items.filter((x) => p.progress[x.id]?.picked).length ?? 0,
      picker_names: [...new Set(pickedEventsOf(oid).map((e) => userName(db, e.user_id)).filter(Boolean))].sort(),
      first_pick_at: firstPick(oid), completed_at: c?.completed_at ?? null, duration_seconds: c?.duration_seconds ?? null, complete: !!c,
    }
  })
  const sc = (a, b) => (a < b ? 1 : a > b ? -1 : 0)
  orders.sort((a, b) => sc(String(a.first_pick_at ?? ''), String(b.first_pick_at ?? '')) || sc(String(a.platform), String(b.platform)) || sc(String(a.order_no), String(b.order_no)))
  return {
    period: { from: dFrom, to: dTo }, filters: { picker_id: pickerId, platform },
    summary: {
      orders: touched.size, completed_orders: completed.size, positions: picked.length, parts: picked.reduce((s, e) => s + e.quantity, 0),
      corrections: reopened.length, avg_duration_seconds: durations.length ? Math.round(durations.reduce((s, v) => s + v, 0) / durations.length) : null,
    },
    pickers: pickerRows,
    orders,
  }
}

/** Routen der Pickliste im Demo-Backend (gleich wie api/index.php). */
export function route(db, method, parts, params, body, requireUser, log) {
  if (parts[0] !== 'picking') return undefined
  const [, b, c, d] = parts
  const me = requireUser()
  const isStaff = ['lehrer', 'admin'].includes(me.role)
  if (method === 'GET' && b === 'revision') return { revision: pk(db).revision }
  if (method === 'GET' && b === 'orders' && !c) return { orders: openOrders(db), revision: pk(db).revision }
  if (method === 'GET' && b === 'pickers') {
    return { pickers: db.users.filter((u) => u.active !== false).sort((x, y) => (x.role === y.role ? (x.display_name < y.display_name ? -1 : 1) : x.role < y.role ? 1 : -1)).map((u) => ({ id: u.id, name: u.display_name })) }
  }
  if (b === 'orders' && /^\d+$/.test(c ?? '')) {
    const id = Number(c)
    if (method === 'GET' && !d) {
      const r = detail(db, id)
      if (!r) throw new DemoError('Bestellung nicht gefunden', 404)
      return r
    }
    if (method === 'GET' && d === 'revision') return { revision: state(db, id).revision }
    if (method === 'POST' && (d === 'complete' || d === 'reopen')) {
      const r = d === 'complete' ? complete(db, id) : reopen(db, id)
      log(me.id, d === 'complete' ? 'pick_order_completed' : 'pick_order_reopened', 'pickliste', { auftrag: db.orders.find((o) => o.id === id)?.order_no })
      return r
    }
  }
  if (method === 'POST' && b === 'items' && /^\d+$/.test(c ?? '')) {
    const itemId = Number(c)
    const { order, item } = itemOrder(db, itemId)
    const details = { auftrag: order.order_no, teil: `${item.item_no} ${item.color_name}`.trim(), menge: item.qty }
    const want = (k) => (body?.[k] === undefined || body?.[k] === null ? null : !!body[k])
    if (d === 'toggle') { const r = toggle(db, itemId, me, want('picked')); if (!r.unchanged) log(me.id, r.picked ? 'pick_item' : 'pick_item_reset', 'pickliste', details); return r }
    if (d === 'claim') { const r = claim(db, itemId, me, want('claimed')); if (!r.unchanged) log(me.id, r.claimed ? 'pick_claim' : 'pick_unclaim', 'pickliste', details); return r }
    if (d === 'reports') { const r = report(db, itemId, me.id, body); log(me.id, 'pick_report', 'pickliste', { ...details, grund: r.issue_type }); return r }
  }
  if (method === 'POST' && b === 'reports' && /^\d+$/.test(c ?? '') && d === 'resolve') return resolve(db, Number(c))
  if (b === 'location-order' && !c) {
    if (method === 'GET') return { groups: locationOrder(db) }
    if (!isStaff) throw new DemoError('Nur für Lehrer', 403)
    const r = saveLocationOrder(db, Array.isArray(body.groups) ? body.groups : [])
    log(me.id, 'pick_location_order', 'pickliste', { bereiche: r.map((g) => g.location_group).join(', ') })
    return { groups: r }
  }
  if (method === 'GET' && b === 'location-order' && c === 'available') return { locations: availableLocations(db) }
  if (method === 'POST' && b === 'reset') {
    if (!isStaff) throw new DemoError('Nur für Lehrer', 403)
    if (body.confirmation !== 'RESET') throw new DemoError('Bitte mit RESET bestätigen', 400)
    const r = resetTestData(db)
    log(me.id, 'pick_reset', 'pickliste', r)
    return r
  }
  if (method === 'GET' && b === 'stats') return stats(db, params.date_from, params.date_to, params.picker_id, params.platform)
  throw new DemoError('Nicht gefunden', 404)
}
