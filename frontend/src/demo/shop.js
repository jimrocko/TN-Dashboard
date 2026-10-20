// Lager, Aufträge, Umlagern, Inventur und Analyse für die Demo, entspricht api/lib/Shop.php.
import { markDone, openRequests } from './counting.js'
import { DemoError, now } from './backend.js'
import { isMixedBox } from '../utils/areas.js'

const ORDER_STATUSES = ['Neu', 'Bezahlt', 'Verpackt', 'Versandt', 'Abgeschlossen']
const ORDER_ACTIVE = ['Neu', 'Bezahlt', 'Verpackt']

const fmt = (d) => {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}
const ago = (days) => fmt(new Date(Date.now() - days * 86400000 - Math.floor(Math.random() * 6 * 3600000)))
const like = (v, term) => String(v ?? '').toLowerCase().includes(term.toLowerCase())
// Natürliche Reihenfolge für Lagerort-Codes: N4 vor N150, Plate2 vor Plate10
const natural = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), 'de', { numeric: true })
const round = (n, d = 2) => Math.round(n * 10 ** d) / 10 ** d

const DEMO_LOT_BASE = 300000001
// Zustand bei BrickLink (N = neu, U = gebraucht), wie BrickLinkSync::conditionFor()
const conditionFor = (code) => (/^(N|FN)\d+$/i.test(code) ? 'N' : 'U')

/**
 * Was sich in BrickLink ändern muss (Lagerort steht in den Remarks), wie BrickLinkSync::changes() in PHP.
 * Einträge: update (lot_id, qty = +/- Menge, remarks, delete), upload (neues Lot), fehlt (Lot ohne BrickLink-Lot-ID).
 */
function blChanges(lot, existing, qty, toCode) {
  const upd = (row, set) => (row.bl_lot_id
    ? { type: 'update', lot_id: row.bl_lot_id, ...set }
    : { type: 'fehlt', item_no: row.item_no, color_name: row.color_name })
  const full = qty === lot.qty
  if (existing) return [upd(existing, { qty }), upd(lot, full ? { delete: true } : { qty: -qty })]
  if (full) return [upd(lot, { remarks: toCode })]
  return [
    upd(lot, { qty: -qty }),
    { type: 'upload', item_type: lot.item_type, item_no: lot.item_no, color_id: lot.color_id, qty, price: lot.price,
      condition: lot.bl_condition ?? 'U', remarks: toCode },
  ]
}

export function seedData(seed) {
  const locations = seed.locations.map((l, i) => ({ id: i + 1, ...l }))
  const locId = Object.fromEntries(locations.map((l) => [l.code, l.id]))
  const inventory = seed.inventory.map((p, i) => ({
    id: i + 1, item_type: p.item_type, item_no: p.item_no, color_id: p.color_id, color_name: p.color_name,
    name: p.name, category: p.category, location_id: locId[p.location], qty: p.qty, price: p.price,
    new_or_used: p.new_or_used ?? conditionFor(p.location), description: null, updated_at: ago(p.updated_days_ago),
    // Erfundene BrickLink-Lot-IDs, gleiche Rechnung wie api/lib/DemoData.php (bl_inventory_id = bl_lot_id, new_or_used = bl_condition)
    bl_lot_id: DEMO_LOT_BASE + i, bl_inventory_id: DEMO_LOT_BASE + i, bl_condition: p.new_or_used ?? conditionFor(p.location),
  }))
  let itemId = 1
  const orders = seed.orders.map((o, i) => {
    const sum = o.items.reduce((s, it) => s + it.qty * it.price, 0)
    return {
      id: i + 1, platform: o.platform ?? 'TN', order_no: o.order_no, buyer: o.customer ?? o.buyer, status: o.status, platform_status: null,
      ordered_at: ago(o.days_ago), total: round(sum), currency: 'EUR', shipping: shippingOf(o, sum),
      items: o.items.map((it) => {
        const p = seed.inventory[it.lot]
        return {
          id: itemId++, inventory_id: it.lot + 1, item_type: p.item_type, item_no: p.item_no, color_id: p.color_id, color_name: p.color_name,
          name: p.name, qty: it.qty, price: it.price, new_or_used: p.new_or_used ?? '', remarks: p.location, ext_lot_id: null,
          image_url: imageUrl(p.item_type, p.item_no, p.color_id),
        }
      }),
    }
  })
  const db = { locations, inventory, orders, transfers: [], stocktakes: [], nextLot: inventory.length + 1, nextTransfer: 1, nextStocktake: 1, nextStItem: 1 }
  for (const s of seed.stocktakes) createStocktake(db, locId[s.location])
  return db
}

/** Versanddaten im Format der Pickliste, wie DemoData::shipping() */
function shippingOf(o, itemsTotal) {
  const s = o.shipping
  if (!s) return {}
  return { shipping_method: s.method, shipping_cost_minor: Math.round(s.cost * 100), grand_total_minor: Math.round((itemsTotal + s.cost) * 100), recipient_address: s.address }
}

/** Bild ohne API direkt vom BrickLink-Bildserver, wie Sync::imageUrl() */
export function imageUrl(type, no, colorId) {
  if (!no) return null
  const t = String(type).toUpperCase()
  if ((t === 'P' || t === 'PART') && colorId !== null && colorId !== undefined) return `https://img.bricklink.com/ItemImage/PN/${colorId}/${encodeURIComponent(no)}.png`
  const letter = { MINIFIG: 'M', M: 'M', SET: 'S', S: 'S', GEAR: 'G', BOOK: 'B' }[t]
  return letter ? `https://img.bricklink.com/ItemImage/${letter}N/0/${encodeURIComponent(no)}.png` : null
}

// Schnelles Nachschlagen (über 1.000 Kästchen, mehrere tausend Lots): Index je Lagerort-Liste.
const locIndex = new WeakMap()
const loc = (db, id) => {
  let m = locIndex.get(db.locations)
  if (!m || m.size !== db.locations.length) locIndex.set(db.locations, (m = new Map(db.locations.map((l) => [l.id, l]))))
  return m.get(id)
}
/** Lots und Teile je Lagerort in einem Durchlauf: Map(location_id → { lots, qty }) */
const totalsByLocation = (db) => {
  const m = new Map()
  for (const i of db.inventory) {
    const t = m.get(i.location_id) ?? { lots: 0, qty: 0 }
    t.lots++; t.qty += i.qty
    m.set(i.location_id, t)
  }
  return m
}
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
    && (!f.id || r.id === Number(f.id))
    && (!f.category || r.category === f.category))
  const sorts = {
    qty: (a, b) => b.qty - a.qty,
    location: (a, b) => natural(a.location_code, b.location_code) || a.item_no.localeCompare(b.item_no),
    updated: (a, b) => b.updated_at.localeCompare(a.updated_at),
    item: (a, b) => a.item_no.localeCompare(b.item_no) || a.color_name.localeCompare(b.color_name),
  }
  rows.sort(sorts[f.sort] ?? sorts.item)
  return rows.slice(0, Math.max(1, Math.min(Number(f.limit) || 500, 2000)))
}

function meta(db) {
  // Zahlen je Kategorie für die Kategorien-Ansicht im Inventar (wie Shop::meta)
  const cats = new Map()
  for (const i of db.inventory) {
    const c = cats.get(i.category) ?? { category: i.category, lots: 0, qty: 0, value: 0 }
    c.lots++; c.qty += i.qty; c.value += i.qty * i.price
    cats.set(i.category, c)
  }
  const stats = [...cats.values()].sort((a, b) => (a.category < b.category ? -1 : a.category > b.category ? 1 : 0))
    .map((c) => ({ ...c, value: Math.round(c.value * 100) / 100 }))
  return {
    categories: stats.map((c) => c.category),
    category_stats: stats,
    locations: (() => {
      const t = totalsByLocation(db)
      return db.locations.map((l) => ({ ...l, lots: t.get(l.id)?.lots ?? 0, qty: t.get(l.id)?.qty ?? 0 }))
        .sort((a, b) => natural(a.code, b.code))
    })(),
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
    return { ...x, location_code: lot ? loc(db, lot.location_id)?.code ?? x.remarks ?? null : x.remarks ?? null, stock: lot?.qty ?? null }
  }).sort((a, b) => natural(a.location_code, b.location_code) || a.item_no.localeCompare(b.item_no))
  return { ...o, items }
}

// Bereich eines Kästchens (G, H, N, FG, FN, FT, PLATE); sonst der Code selbst – wie Shop::areaOf
const areaOf = (code) => (/^(FG|FN|FT|Plate|G|H|N)\d+$/i.exec(code)?.[1] ?? code).toUpperCase()
const SLOT_MAX_LOTS = 3
const byteCmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0)

/**
 * Umlagerungsvorschläge, gleiche Regeln und Reihenfolge wie Shop::suggestions():
 *  1. 'falsch' – gleiches Teil + Farbe im selben Bereich in mehreren Kästchen → ins Kästchen mit der größten Menge
 *  2. 'farben' – gleiches Teil in anderen Farben verstreut → in ein Kästchen, das nur dieses Teil enthält
 *     (Ziel höchstens SLOT_MAX_LOTS Lots; Quelle enthält auch andere Teile)
 *  3. 'art' – gleiche Teile-Art (Kategorie) verstreut → in ein Kästchen, das nur diese Art enthält (gleiche Grenzen)
 *  4. 'bereich' – neu in G/FG bzw. gebraucht in N/FN → in den richtigen Bereich (gleiche Farbe, sonst gleiches Teil)
 *  5. 'mischkiste' – G-Kästchen mit mehr als 3 Lots: die größten bleiben, Rest zum Teil, zur Art oder ins nächste leere Kästchen
 *  6. 'rest' – Kästchen mit nur einem kleinen Rest → zum gleichen Teil oder zur gleichen Art
 *  7. 'trennen' – Verwechselbares im selben Kästchen → das kleinere Lot woandershin
 * Nie in ein Kästchen mit etwas Verwechselbarem (confusable).
 */
function suggestions(db) {
  const all = db.inventory.map((i) => lotRow(db, i)).filter((l) => l.location_code).sort((a, b) => a.id - b.id)
  const out = []
  const moved = new Set()
  // Geplanter Inhalt je Kästchen (wie $in in Shop::suggestions): location_id → Map(Lot-ID → Lot)
  const inBox = new Map()
  for (const l of all) { if (!inBox.has(l.location_id)) inBox.set(l.location_id, new Map()); inBox.get(l.location_id).set(l.id, l) }
  const add = (lot, t, reason, extra = {}, merge = false) => {
    out.push({ lot, to_location_id: t.location_id, to_location_code: t.location_code, target_qty: t.qty, reason, ...extra })
    moved.add(lot.id)
    for (const m of inBox.values()) m.delete(lot.id)
    if (!merge) { if (!inBox.has(t.location_id)) inBox.set(t.location_id, new Map()); inBox.get(t.location_id).set(lot.id, lot) }
  }
  const clash = (lot, loc) => [...(inBox.get(loc)?.values() ?? [])].some((x) => x.id !== lot.id && confusable(lot, x))
  const cmpCode = (a, b) => natural(a.location_code, b.location_code) || a.id - b.id

  // 1. Falsch einsortiert
  const byCombo = new Map()
  for (const l of all) {
    const k = `${areaOf(l.location_code)}|${l.item_no}|${l.color_id}`
    if (!byCombo.has(k)) byCombo.set(k, [])
    byCombo.get(k).push(l)
  }
  for (const k of [...byCombo.keys()].sort(byteCmp)) {
    const lots = byCombo.get(k)
    if (lots.length < 2) continue
    lots.sort((a, b) => b.qty - a.qty || cmpCode(a, b))
    // Lots im selben Kästchen wie das Ziel bleiben liegen (echter Bestand: gleiches Teil mit anderem Preis/Zustand)
    for (const l of lots.slice(1)) if (l.location_id !== lots[0].location_id) add(l, lots[0], 'falsch', {}, true)
  }

  // 2. Farben zusammenlegen
  const planned = new Map() // Lot-ID → Ziel-Kästchen der Farben-Vorschläge (für Schritt 3)
  const boxLots = new Map()
  const boxItems = new Map()
  for (const l of all) {
    if (moved.has(l.id)) continue
    boxLots.set(l.location_id, (boxLots.get(l.location_id) ?? 0) + 1)
    const m = boxItems.get(l.location_id) ?? new Map()
    m.set(l.item_no, (m.get(l.item_no) ?? 0) + 1)
    boxItems.set(l.location_id, m)
  }
  const cnt = (loc, item) => boxItems.get(loc)?.get(item) ?? 0
  const purity = (loc, item) => cnt(loc, item) / Math.max(1, boxLots.get(loc) ?? 0)
  const byPart = new Map()
  for (const l of all) {
    if (moved.has(l.id)) continue
    const k = `${areaOf(l.location_code)}|${l.item_no}`
    if (!byPart.has(k)) byPart.set(k, [])
    byPart.get(k).push(l)
  }
  for (const k of [...byPart.keys()].sort(byteCmp)) {
    const lots = byPart.get(k)
    const item = lots[0].item_no
    const boxes = new Map()
    for (const l of lots) if (!boxes.has(l.location_id)) boxes.set(l.location_id, l)
    if (boxes.size < 2) continue
    const targets = [...boxes.values()].sort((a, b) => purity(b.location_id, item) - purity(a.location_id, item)
      || cnt(b.location_id, item) - cnt(a.location_id, item) || cmpCode(a, b))
    const sources = [...lots].sort(cmpCode)
    for (const t of targets) {
      const tid = t.location_id
      if (purity(tid, item) < 1) continue // Ziel enthält nur dieses Teil (sauberer Slot)
      for (const src of sources) {
        if ((boxLots.get(tid) ?? 0) >= SLOT_MAX_LOTS) break
        const sid = src.location_id
        if (moved.has(src.id) || sid === tid) continue
        const pureSource = cnt(sid, item) === (boxLots.get(sid) ?? 0)
        if (pureSource || purity(sid, item) >= purity(tid, item)) continue
        if (clash(src, tid)) continue // z. B. Light Gray zu Light Bluish Gray
        const colors = lots.filter((x) => x.location_id === tid && !moved.has(x.id)).map((x) => x.color_name)
        add(src, t, 'farben', { target_colors: colors })
        planned.set(src.id, tid)
        boxLots.set(tid, (boxLots.get(tid) ?? 0) + 1); boxItems.get(tid).set(item, cnt(tid, item) + 1)
        boxLots.set(sid, boxLots.get(sid) - 1); boxItems.get(sid).set(item, cnt(sid, item) - 1)
      }
    }
  }

  // 3. Gleiche Teile-Art (Kategorie): in ein Kästchen, das nur diese Art enthält
  const catLots = new Map()
  const catCount = new Map()
  const namesIn = new Map()
  for (const l of all) {
    if (moved.has(l.id) && !planned.has(l.id)) continue // zusammengeführt (falsch)
    const loc = planned.get(l.id) ?? l.location_id
    catLots.set(loc, (catLots.get(loc) ?? 0) + 1)
    const m = catCount.get(loc) ?? new Map()
    m.set(l.category, (m.get(l.category) ?? 0) + 1)
    catCount.set(loc, m)
    namesIn.set(loc, [...(namesIn.get(loc) ?? []), l.name])
  }
  const ccnt = (loc, cat) => catCount.get(loc)?.get(cat) ?? 0
  const catPure = (loc, cat) => (catLots.get(loc) ?? 0) > 0 && ccnt(loc, cat) === catLots.get(loc)
  const byCat = new Map()
  for (const l of all) {
    if (moved.has(l.id)) continue
    const k = `${areaOf(l.location_code)}|${l.category}`
    if (!byCat.has(k)) byCat.set(k, [])
    byCat.get(k).push(l)
  }
  for (const k of [...byCat.keys()].sort(byteCmp)) {
    const lots = byCat.get(k)
    const cat = lots[0].category
    const boxes = new Map()
    for (const l of lots) if (!boxes.has(l.location_id)) boxes.set(l.location_id, l)
    if (boxes.size < 2) continue
    const targets = [...boxes.values()].filter((b) => catPure(b.location_id, cat))
      .sort((a, b) => ccnt(b.location_id, cat) - ccnt(a.location_id, cat) || cmpCode(a, b))
    const sources = [...lots].sort(cmpCode)
    for (const t of targets) {
      const tid = t.location_id
      for (const src of sources) {
        if ((catLots.get(tid) ?? 0) >= SLOT_MAX_LOTS) break
        const sid = src.location_id
        if (moved.has(src.id) || sid === tid || catPure(sid, cat)) continue
        if (clash(src, tid)) continue // z. B. Plate 1 x 4 zu Plate 1 x 5
        add(src, t, 'art', { target_parts: [...new Set(namesIn.get(tid) ?? [])] })
        catLots.set(tid, (catLots.get(tid) ?? 0) + 1); catCount.get(tid).set(cat, ccnt(tid, cat) + 1); namesIn.get(tid).push(src.name)
        catLots.set(sid, catLots.get(sid) - 1); catCount.get(sid).set(cat, ccnt(sid, cat) - 1)
      }
    }
  }

  // Ab hier rechnen 4–7 auf dem geplanten Inhalt (inBox)
  const codes = new Map([...db.locations].sort((a, b) => a.id - b.id).map((l) => [l.id, l.code]))
  const byArea = new Map()
  for (const [id, c] of codes) { const a = areaOf(c); if (!byArea.has(a)) byArea.set(a, []); byArea.get(a).push(id) }
  for (const ids of byArea.values()) ids.sort((a, b) => natural(codes.get(a), codes.get(b)) || a - b)
  const areaOrder = [...byArea.keys()] // Reihenfolge wie PHP: Bereiche in der Reihenfolge, in der sie unter den Lagerorten auftauchen
  const num = (loc) => Number(/(\d+)$/.exec(codes.get(loc) ?? '')?.[1] ?? 0)
  const box = (loc, qty = 0) => ({ location_id: loc, location_code: codes.get(loc) ?? '', qty })
  const lotsIn = (b) => [...(inBox.get(b)?.values() ?? [])]
  const targetFor = (lot, area, from, steps) => {
    const boxes = byArea.get(area) ?? []
    if (steps.includes('a')) {
      let best = null
      for (const b of boxes) {
        if (b === from) continue
        for (const x of lotsIn(b)) {
          if (x.item_no !== lot.item_no || x.color_id !== lot.color_id) continue
          if (best === null || x.qty > best.qty) best = { loc: b, qty: x.qty }
        }
      }
      if (best) return [box(best.loc, best.qty), true]
    }
    const pickBy = (score) => {
      let best = null
      for (const b of boxes) {
        const lots = lotsIn(b)
        if (b === from || lots.length >= SLOT_MAX_LOTS || !lots.length) continue
        const v = score(lots)
        if (v <= 0 || clash(lot, b)) continue
        if (best === null || v > best[1]) best = [b, v]
      }
      return best?.[0] ?? null
    }
    if (steps.includes('b')) {
      const b = pickBy((lots) => lots.reduce((q, x) => q + (x.item_no === lot.item_no ? x.qty : 0), 0))
      if (b !== null) return [box(b), false]
    }
    if (steps.includes('c')) {
      const b = pickBy((lots) => (lots.every((x) => x.category === lot.category) ? lots.length : 0))
      if (b !== null) return [box(b), false]
    }
    if (steps.includes('d')) {
      let best = null
      for (const b of boxes) {
        if (b === from || lotsIn(b).length) continue
        const d = Math.abs(num(b) - num(from))
        if (best === null || d < best[1]) best = [b, d]
      }
      if (best) return [box(best[0]), false]
    }
    return null
  }
  const place = (lot, hit, reason, extra = {}) => { if (hit) add(lot, hit[0], reason, extra, hit[1]) }

  // 4. Falscher Bereich: neu in G/FG, gebraucht in N/FN
  const other = { N: { G: 'N', FG: 'FN' }, U: { N: 'G', FN: 'FG' } }
  for (const l of all) {
    if (moved.has(l.id)) continue
    const cond = String(l.new_or_used ?? '').toUpperCase()
    const to = other[cond]?.[areaOf(l.location_code)]
    if (!to) continue
    place(l, targetFor(l, to, l.location_id, 'ab'), 'bereich', { condition: cond })
  }

  // 5. Mischkisten aufteilen: die 3 größten Lots bleiben
  for (const area of areaOrder) {
    for (const b of byArea.get(area)) {
      if (!/^G\d+$/i.test(codes.get(b)) || lotsIn(b).length <= SLOT_MAX_LOTS) continue
      const lots = lotsIn(b).sort((x, y) => y.qty - x.qty || x.id - y.id)
      for (const l of lots.slice(SLOT_MAX_LOTS)) {
        if (moved.has(l.id)) continue
        place(l, targetFor(l, area, b, 'abcd'), 'mischkiste', { from_mixed: codes.get(b) })
      }
    }
  }

  // 6. Fast leere Kästchen leeren
  for (const area of areaOrder) {
    for (const b of byArea.get(area)) {
      const lots = lotsIn(b)
      if (lots.length !== 1) continue
      const l = lots[0]
      if (moved.has(l.id) || String(l.item_type).toUpperCase() !== 'PART' || l.qty > REST_MAX_QTY) continue
      place(l, targetFor(l, area, b, 'abc'), 'rest')
    }
  }

  // 7. Verwechselbares trennen
  for (const area of areaOrder) {
    for (const b of byArea.get(area)) {
      for (let guard = 0; guard < 50; guard++) {
        const lots = lotsIn(b).sort((x, y) => x.id - y.id)
        let done = true
        outer: for (let i = 0; i < lots.length; i++) {
          for (const y of lots.slice(i + 1)) {
            const x = lots[i]
            if (!confusable(x, y)) continue
            let mover = y.qty <= x.qty ? y : x
            if (moved.has(mover.id)) mover = mover === y ? x : y
            if (moved.has(mover.id)) continue
            const keep = mover === y ? x : y
            const hit = targetFor(mover, area, b, 'abcd')
            if (!hit) continue
            place(mover, hit, 'trennen', { confused_with: `${keep.item_no} ${keep.color_name ?? ''}`.trim() })
            done = false
            break outer
          }
        }
        if (done) break
      }
    }
  }
  return out
}

// Verwechselbar (wie Shop::confusable): ähnliche Farbe beim gleichen Teil, gleiche Grundnummer (3626b/3626c),
// gleicher Name bis auf einen Zusatz, gleiche Form in ähnlicher Größe (jede Zahl höchstens 2 auseinander). Nur Teile.
const REST_MAX_QTY = 3
const CONFUSABLE_COLORS = [['light gray', 'light bluish gray'], ['dark gray', 'dark bluish gray'], ['tan', 'dark tan'], ['white', 'very light gray'], ['black', 'dark bluish gray']]
const normName = (n) => String(n ?? '').toLowerCase().replace(/\s+/g, ' ').trim()
export function confusable(a, b) {
  if (String(a.item_type ?? 'PART').toUpperCase() !== 'PART' || String(b.item_type ?? 'PART').toUpperCase() !== 'PART') return false
  if (a.item_no === b.item_no) {
    const x = String(a.color_name ?? '').trim().toLowerCase()
    const y = String(b.color_name ?? '').trim().toLowerCase()
    return CONFUSABLE_COLORS.some(([p, q]) => (x === p && y === q) || (x === q && y === p))
  }
  const m1 = /^(\d+)[a-z]?$/i.exec(String(a.item_no))
  const m2 = /^(\d+)[a-z]?$/i.exec(String(b.item_no))
  if (m1 && m2 && m1[1] === m2[1]) return true
  const na = normName(a.name)
  const nb = normName(b.name)
  if (!na || !nb) return false
  if (na.startsWith(nb + ' ') || nb.startsWith(na + ' ')) return true
  if (na.replace(/\d+/g, '#') !== nb.replace(/\d+/g, '#')) return false
  const xa = na.match(/\d+/g) ?? []
  const xb = nb.match(/\d+/g) ?? []
  if (!xa.length) return na === nb
  return xa.every((v, i) => Math.abs(Number(v) - Number(xb[i])) <= 2)
}

// Ordnungsgrad, ein Eintrag pro Tag (wie Shop::sortProgress). Die Demo bekommt beim ersten Mal erfundene frühere Stände,
// damit die Entwicklung (+/−) zu sehen ist.
function sortProgress(db, open) {
  const lots = db.inventory.filter((i) => loc(db, i.location_id)).length
  const day = now().slice(0, 10)
  const back = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10) }
  if (!db.sortProgress) {
    db.sortProgress = {}
    if (import.meta.env.MODE !== 'tutorial') {
      db.sortProgress[back(14)] = { lots, open_count: Math.min(lots, open + 70) }
      db.sortProgress[back(7)] = { lots, open_count: Math.min(lots, open + 32) }
    }
  }
  db.sortProgress[day] = { lots, open_count: open }
  const from = back(60)
  const history = Object.keys(db.sortProgress).filter((d) => d >= from).sort().map((d) => ({ day: d, ...db.sortProgress[d] }))
  return { lots, open, history }
}

const GONE = 'Dieses Lot wurde gerade von jemand anderem umgelagert. Die Liste wird neu geladen.'
function transfer(db, lotId, toId, qty, userId, expectLoc = null, expectQty = null) {
  const lot = db.inventory.find((i) => i.id === lotId)
  if (!lot) throw new DemoError(GONE, 409)
  // Stand wie auf dem Bildschirm? Sonst hat inzwischen jemand anders gebucht (wie Shop::transfer)
  if ((expectLoc !== null && lot.location_id !== expectLoc) || (expectQty !== null && lot.qty !== expectQty)) throw new DemoError(GONE, 409)
  const to = loc(db, toId)
  if (!to) throw new DemoError('Ziel-Lagerort nicht gefunden', 404)
  if (lot.location_id === toId) throw new DemoError('Quelle und Ziel sind gleich', 400)
  if (!(qty >= 1 && qty <= lot.qty)) throw new DemoError(`Menge muss zwischen 1 und ${lot.qty} liegen`, 400)
  const t = now()
  const from = loc(db, lot.location_id)?.code ?? null
  const existing = db.inventory.find((i) => i.location_id === toId && i.item_no === lot.item_no && i.color_id === lot.color_id)
  const bl = blChanges(lot, existing, qty, to.code)
  if (existing) {
    existing.qty += qty
    existing.updated_at = t
  } else if (qty === lot.qty) {
    lot.location_id = toId
    lot.updated_at = t
  } else {
    // Neues Lot bekommt seine BrickLink-Lot-ID erst beim nächsten Import
    db.inventory.push({ ...lot, id: db.nextLot++, location_id: toId, qty, updated_at: t, bl_lot_id: null, bl_inventory_id: null })
  }
  if (existing || qty < lot.qty) {
    if (qty === lot.qty) db.inventory = db.inventory.filter((i) => i.id !== lotId)
    else { lot.qty -= qty; lot.updated_at = t }
  }
  db.transfers.push({ id: db.nextTransfer++, item_no: lot.item_no, color_name: lot.color_name, name: lot.name,
    from_code: from, to_code: to.code, qty, user_id: userId, created_at: t, bl_changes: bl, bl_done_at: null })
  return { item_no: lot.item_no, color: lot.color_name, qty, from, to: to.code }
}

/** Laufende Übertragung für die Antwort (wie BrickLinkSync::openExport) */
const exportRow = (e) => ({ id: e.id, transfer_ids: e.transfer_ids, stocktake_ids: e.stocktake_ids ?? [], created_at: e.created_at })

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
      .map((i) => ({ id: db.nextStItem++, inventory_id: i.id, item_type: i.item_type, item_no: i.item_no, color_id: i.color_id, color_name: i.color_name, name: i.name, expected: i.qty, counted: null }))
      .sort((a, b) => a.item_no.localeCompare(b.item_no) || a.color_name.localeCompare(b.color_name)),
  }
  db.stocktakes.push(s)
  return s
}

// Entspricht Shop::location()
function location(db, code) {
  const l = db.locations.find((x) => x.code.toUpperCase() === String(code).toUpperCase())
  if (!l) return null
  const lots = inventory(db, { location: l.id, limit: 2000 })
  const st = db.stocktakes.filter((s) => s.location_id === l.id)
    .sort((a, b) => (a.status === 'offen' ? 0 : 1) - (b.status === 'offen' ? 0 : 1) || b.created_at.localeCompare(a.created_at))[0]
  const picks = db.orders.filter((o) => ORDER_ACTIVE.includes(o.status))
    .sort((a, b) => a.ordered_at.localeCompare(b.ordered_at))
    .flatMap((o) => o.items.filter((x) => db.inventory.find((i) => i.id === x.inventory_id)?.location_id === l.id)
      .map((x) => ({ id: o.id, order_no: o.order_no, status: o.status, item_type: x.item_type, item_no: x.item_no, color_id: x.color_id, color_name: x.color_name, name: x.name, qty: x.qty })))
  const transfers = db.transfers.filter((t) => t.from_code === l.code || t.to_code === l.code).reverse().slice(0, 15)
    .map((t) => ({ ...t, display_name: db.users.find((u) => u.id === t.user_id)?.display_name }))
  const same = db.locations.filter((x) => x.kind === l.kind).map((x) => x.code).sort(natural)
  const pos = same.indexOf(l.code)
  return {
    ...l, lots,
    stocktake: st ? { id: st.id, status: st.status, created_at: st.created_at, closed_at: st.closed_at } : null,
    count_requests: openRequests(db, l.id)[l.id] ?? [],
    picks, transfers,
    suggestions: suggestions(db).filter((s) => s.lot.location_id === l.id || s.to_location_id === l.id),
    prev: pos > 0 ? same[pos - 1] : null,
    next: pos < same.length - 1 ? same[pos + 1] : null,
  }
}

// Entspricht Shop::part()
function part(db, no) {
  const lots = db.inventory.filter((i) => i.item_no === no).map((i) => lotRow(db, i))
    .sort((a, b) => a.color_name.localeCompare(b.color_name) || String(a.location_code).localeCompare(String(b.location_code)))
  const orders = db.orders.flatMap((o) => o.items.filter((x) => x.item_no === no).map((x) => ({
    id: o.id, order_no: o.order_no, buyer: o.buyer, status: o.status, ordered_at: o.ordered_at,
    color_name: x.color_name, qty: x.qty, active: ORDER_ACTIVE.includes(o.status) ? 1 : 0,
  }))).sort((a, b) => b.ordered_at.localeCompare(a.ordered_at)).slice(0, 30)
  const transfers = db.transfers.filter((t) => t.item_no === no).reverse().slice(0, 10)
    .map((t) => ({ ...t, display_name: db.users.find((u) => u.id === t.user_id)?.display_name }))
  return {
    item_no: lots[0]?.item_no ?? no, name: lots[0]?.name ?? null, category: lots[0]?.category ?? null,
    item_type: lots[0]?.item_type ?? 'PART', lots, orders, transfers,
  }
}

/** Lots zu Teil-Nummern, gruppiert (entspricht Shop::lotsByItem). */
export function lotsByItem(db, itemNos) {
  const out = {}
  for (const i of db.inventory) {
    if (itemNos.includes(i.item_no)) (out[i.item_no] ??= []).push(lotRow(db, i))
  }
  for (const k in out) out[k].sort((a, b) => a.color_name.localeCompare(b.color_name) || String(a.location_code).localeCompare(String(b.location_code)))
  return out
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
  const totals = totalsByLocation(db)
  const byLoc = db.locations.map((l) => ({ label: l.code, kind: l.kind, lots: totals.get(l.id)?.lots ?? 0, qty: totals.get(l.id)?.qty ?? 0 }))
    .sort((a, b) => b.qty - a.qty)
  return {
    by_category: group((i) => i.category),
    by_location: byLoc.filter((l) => l.kind === 'regal').slice(0, 10),
    mixed_bins: byLoc.filter((l) => isMixedBox(l.label, l.lots)).sort((a, b) => b.lots - a.lots || natural(a.label, b.label)).slice(0, 10),
    orders_by_status: ORDER_STATUSES.map((s) => ({ label: s, count: db.orders.filter((o) => o.status === s).length })),
    low_stock: db.inventory.filter((i) => loc(db, i.location_id)?.kind === 'regal' && i.qty < 20)
      .sort((a, b) => a.qty - b.qty).slice(0, 10).map((i) => lotRow(db, i)),
    activity_by_participant: db.users.filter((u) => u.role === 'teilnehmer' && u.active !== false).map((u) => ({
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
  if (method === 'GET' && a === 'location' && b) {
    requireUser()
    const l = location(db, decodeURIComponent(b))
    if (!l) throw new DemoError('Lagerort nicht gefunden', 404)
    return { location: l }
  }
  if (method === 'GET' && a === 'part' && b) {
    requireUser()
    const no = decodeURIComponent(b)
    if (!/^[A-Za-z0-9._-]{1,40}$/.test(no)) throw new DemoError('Ungültige Teil-Nr.', 400)
    return { part: part(db, no) }
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
  if (method === 'GET' && a === 'transfers' && b === 'suggestions') {
    requireUser()
    const sug = suggestions(db)
    return { suggestions: sug, progress: sortProgress(db, sug.length) }
  }
  if (method === 'GET' && a === 'transfers' && !b) {
    requireUser()
    return {
      transfers: [...db.transfers].reverse().slice(0, Number(params.limit) || 30)
        .map((t) => ({ ...t, display_name: db.users.find((u) => u.id === t.user_id)?.display_name })),
    }
  }
  if (method === 'POST' && a === 'transfers' && !b) {
    const me = requireUser()
    const num = (v) => (v === undefined || v === null ? null : Number(v))
    const r = transfer(db, Number(body.lot_id), Number(body.to_location_id), Number(body.qty), me.id, num(body.expect_location_id), num(body.expect_qty))
    log(me.id, 'transfer', 'umlagern', { teil: `${r.item_no} ${r.color}`, menge: r.qty, von: r.from, nach: r.to })
    return { transfer: r }
  }
  if (method === 'GET' && a === 'bricklink' && b === 'pending') {
    // wie in PHP: offene Umlagerungen aller Nutzer und die laufende Übertragung (gemeinsam, ohne Namen)
    requireUser()
    // dazu abgeschlossene Inventuren mit Korrekturen (wie BrickLinkSync::pendingStocktakes)
    const e = (db.blExports ?? []).find((x) => x.open)
    return {
      transfers: db.transfers.filter((t) => !t.bl_done_at && t.bl_changes)
        .map((t) => ({ id: t.id, item_no: t.item_no, color_name: t.color_name, name: t.name, qty: t.qty, created_at: t.created_at,
          from_code: t.from_code, to_code: t.to_code, changes: t.bl_changes })),
      stocktakes: db.stocktakes.filter((x) => !x.bl_done_at && x.bl_changes)
        .map((x) => ({ id: x.id, closed_at: x.closed_at, location_code: loc(db, x.location_id).code, corrections: x.bl_changes })),
      export: e ? exportRow(e) : null,
    }
  }
  if (method === 'GET' && a === 'bricklink' && b === 'stock') {
    // wie BrickLinkSync::stock(): ganzer Bestand, weggeräumte Lot-IDs, alle offenen Umlagerungen
    requireUser()
    const lots = db.inventory.map((i) => ({ bl_lot_id: i.bl_lot_id ?? null, item_type: i.item_type, item_no: i.item_no, color_id: i.color_id,
      qty: i.qty, price: i.price, bl_condition: i.bl_condition ?? 'U', code: loc(db, i.location_id)?.code ?? null, id: i.id }))
    lots.sort((x, y) => (x.bl_lot_id === null) - (y.bl_lot_id === null) || (x.bl_lot_id ?? 0) - (y.bl_lot_id ?? 0) || x.id - y.id)
    const have = new Set(lots.map((l) => l.bl_lot_id).filter(Boolean))
    const open = db.transfers.filter((t) => !t.bl_done_at && t.bl_changes)
    const deleted = new Set()
    for (const t of open) for (const c of t.bl_changes) if (c.type === 'update' && c.delete && !have.has(c.lot_id)) deleted.add(c.lot_id)
    const openSt = db.stocktakes.filter((x) => !x.bl_done_at && x.bl_changes)
    return { lots: lots.map(({ id, ...l }) => l), deleted: [...deleted], transfer_ids: open.map((t) => t.id), stocktake_ids: openSt.map((x) => x.id) }
  }
  if (method === 'POST' && a === 'bricklink' && b === 'start') {
    // Übertragung starten; immer nur eine offen (wie BrickLinkSync::start)
    const me = requireUser()
    db.blExports ??= []
    const clean = (a) => (a ?? []).map(Number).filter((i) => i > 0)
    const ids = clean(body.ids)
    const stIds = clean(body.stocktake_ids)
    if (!ids.length && !stIds.length) throw new DemoError('Keine offenen Änderungen', 400)
    if (db.blExports.some((x) => x.open)) throw new DemoError('Gerade läuft schon eine Übertragung. Bitte den kopierten Bestand nicht einfügen.', 409)
    // Sonst könnte der ganze Bestand die Minus-Mengen der fertigen Minifiguren wieder überschreiben (wie BrickLinkSync::start)
    if ((db.minifig?.builds ?? []).some((b) => b.status === 'export')) throw new DemoError('Gerade wird die Sammlung „Fertige Figuren“ im Minifig-Finder hochgeladen. Bitte die erst abschließen.', 409)
    const e = { id: db.blExports.length + 1, open: true, transfer_ids: ids, stocktake_ids: stIds, created_by: me.id, created_at: now() }
    db.blExports.push(e)
    log(me.id, 'bricklink_export_start', 'umlagern', { umlagerungen: ids.length, inventuren: stIds.length })
    return { export: exportRow(e) }
  }
  if (method === 'POST' && a === 'bricklink' && b === 'finish') {
    // Offene Übertragung abschließen (eingefügt oder verworfen); darf jeder
    const me = requireUser()
    const e = (db.blExports ?? []).find((x) => x.open && x.id === Number(body.id))
    if (!e) throw new DemoError('Diese Übertragung wurde schon abgeschlossen', 409)
    const t = now()
    let n = 0
    if (body.done) {
      const ids = new Set(e.transfer_ids)
      for (const x of db.transfers) if (!x.bl_done_at && ids.has(x.id)) { x.bl_done_at = t; n++ }
      const st = new Set(e.stocktake_ids ?? [])
      for (const x of db.stocktakes) if (!x.bl_done_at && st.has(x.id)) { x.bl_done_at = t; n++ }
    }
    Object.assign(e, { open: false, closed_at: t, state: body.done ? 'eingefuegt' : 'verworfen' })
    log(me.id, body.done ? 'bricklink_export' : 'bricklink_export_verworfen', 'umlagern', { aenderungen: n })
    return { done: n }
  }
  // Ollies Dashboard (gleicher Rechner auf dem Schulserver): in der Demo nicht vorhanden
  if (method === 'GET' && a === 'ollie' && b === 'status') {
    requireUser()
    return { configured: false, ok: false, lots: null, parts: null, ollie_synced_at: null, imported_at: null, error: null, demo: true }
  }
  if (method === 'POST' && a === 'ollie' && (b === 'import' || b === 'refresh')) {
    requireUser()
    throw new DemoError('In der Demo gibt es Ollies Dashboard nicht. Auf dem Schulserver kommt der Bestand mit diesem Knopf aus seiner Datenbank.', 503)
  }
  if (method === 'POST' && a === 'bricklink' && b === 'import') {
    requireUser()
    throw new DemoError('In der Demo gibt es keine BrickLink-Verbindung. Auf dem Schulserver kommt das Lager mit diesem Knopf aus dem BrickLink-Shop.', 503)
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
    // Korrekturen müssen noch zu BrickLink (wie Shop::closeStocktake)
    Object.assign(st, { status: 'abgeschlossen', closed_at: t, closed_by_name: me.display_name, bl_changes: corrections.length ? corrections : null, bl_done_at: null })
    markDone(db, st.location_id) // Vormerkungen zum Zählen erledigt (wie Counting::markDone)
    const code = loc(db, st.location_id).code
    log(me.id, 'stocktake_closed', 'inventur', { lagerort: code, korrekturen: corrections.length })
    return { location: code, corrections }
  }
  if (method === 'GET' && a === 'analysis') { requireUser(); return analysis(db) }
  return undefined
}
