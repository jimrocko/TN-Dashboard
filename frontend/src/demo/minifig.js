// Minifig-Finder für die Demo, gleiche Regeln wie api/lib/Minifig.php (Nachbau des Python-Originals).
// Statt BrickLink beantwortet ein erfundener Katalog (demo-data.json → minifigs) die Abrufe; jeder Abruf wird gezählt.
import seed from '../../../api/sql/demo-data.json'
import { DemoError, now } from './backend.js'
import { pyRound, cmpTuple, strCmp, kbnAdd } from './rules.js'
import { zip, buildBsx, parseBsx, unzip } from '../utils/files.js'
import { LIST_FILES, listCsv, listBsx, almostJointCsv, almostJointBsx, csv, eurCsv as eur } from '../utils/minifigExport.js'
export { LIST_FILES, listCsv, listBsx }

const CORE_PREFIXES = ['torso ', 'hips ', 'hips and ', 'legs ', 'minifigure, head', 'minifigure head', 'minifigure, hair', 'minifigure hair',
  'minifigure, headgear', 'minifigure headgear', 'minifigure, neckwear', 'minifigure neckwear']
const DAILY_BUDGET = 5000
const RESERVE = 500


const key = (no, color) => `${no}|${color}`
const isCore = (name) => { const v = String(name ?? '').trim().toLowerCase(); return CORE_PREFIXES.some((p) => v.startsWith(p)) }
const money = (v) => (v === null || v === undefined ? null : pyRound(v, 2))
const locCode = (db, id) => db.locations.find((l) => l.id === id)?.code ?? null

/** Zustand des Minifig-Finders in der Demo (Katalog, Supersets, Entscheidungen, Preise, API-Zähler). */
export function seedMinifig() {
  const t = now()
  const figs = seed.minifigs ?? []
  const loaded = new Set(seed.minifig_state?.subsets_loaded ?? [])
  const mf = { superset: {}, catalog: [], subsetState: {}, decisions: {}, prices: [], apiCalls: [], priceMeta: null }
  for (const [no, color] of seed.minifig_state?.scanned ?? []) {
    const resp = supersetsOf(figs, no, color)
    mf.superset[key(no, color)] = { item_no: no, color_id: color, fetched_at: t, response: resp }
    for (const e of resp[0]?.entries ?? []) {
      if (mf.catalog.some((c) => c.minifig_no === e.item.no)) continue
      const f = figs.find((x) => x.minifig_no === e.item.no)
      mf.catalog.push({ minifig_no: e.item.no, name: e.item.name, category_id: e.item.category_id, first_seen_at: t, last_seen_at: t,
        subset_fetched_at: loaded.has(e.item.no) ? t : null, subset: loaded.has(e.item.no) ? f.subset : null })
    }
  }
  for (const p of seed.price_guide ?? []) mf.prices.push({ item_type: p.item_type, item_no: p.item_no, color_id: p.color_id, new_or_used: 'U', currency: 'EUR', avg_price: p.avg_price, fetched_at: t })
  return mf
}

/** Supersets einer Teile-Variante aus dem erfundenen Katalog – gleiche Regel wie DemoData::supersetsOf(). */
export function supersetsOf(figs, no, color) {
  const entries = []
  for (const f of figs) {
    if (f.subset.some((g) => g.entries.some((e) => e.item.no === no && e.color_id === color))) {
      entries.push({ item: { no: f.minifig_no, name: f.name, type: 'MINIFIG', category_id: f.category_id }, quantity: 1, appears_as: 'A' })
    }
  }
  return entries.length ? [{ color_id: color, entries }] : []
}

const mfOf = (db) => (db.minifig ??= seedMinifig())

// ---------------------------------------------------------------- Mitgelieferter Figurenkatalog (wie Minifig::ensureBundledCatalog)
// Nur in privaten Builds steckt der Katalog gepackt in den Daten (seed.katalog_gz = api/sql/minifig-katalog.json.gz als Base64).
// Er bleibt im Arbeitsspeicher (zu groß für den Browser-Speicher) und ergänzt nur, was im Demo-Katalog fehlt.
let BASE = null
const gunzipText = (bytes) => new Response(new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'))).text()
function baseFrom(data) {
  return { catalog: (data.catalog ?? []).map((c) => ({ minifig_no: c.minifig_no, name: c.name, category_id: c.category_id, first_seen_at: c.first_seen_at,
    last_seen_at: c.last_seen_at, subset_fetched_at: c.subset_fetched_at, subset: JSON.parse(c.subset_json) })),
  superset: data.superset ?? [], prices: data.price_guide ?? [], meta: { quelle: data.quelle ?? '', stand: data.stand ?? '', figuren: (data.catalog ?? []).length } }
}
export async function loadBaseCatalog() {
  if (BASE || !seed.katalog_gz) return
  const bin = Uint8Array.from(atob(seed.katalog_gz), (c) => c.charCodeAt(0))
  BASE = baseFrom(JSON.parse(await gunzipText(bin)))
}
// Katalog in Einfüge-Reihenfolge (wie seq in Minifig.php): erst der Demo-Katalog, dann fehlende Figuren aus dem
// mitgelieferten Katalog. Hat eine Demo-Figur keine Stückliste, nimmt sie die aus dem mitgelieferten Katalog (Platz bleibt).
let merged = null
function catalogOf(mf) {
  if (!BASE) return mf.catalog
  if (merged && merged.src === mf.catalog && merged.len === mf.catalog.length) return merged.list
  const base = new Map(BASE.catalog.map((c) => [c.minifig_no, c]))
  const list = mf.catalog.map((c) => (!c.subset && base.has(c.minifig_no) ? base.get(c.minifig_no) : c))
  const own = new Set(mf.catalog.map((c) => c.minifig_no))
  for (const c of BASE.catalog) if (!own.has(c.minifig_no)) list.push(c)
  merged = { src: mf.catalog, len: mf.catalog.length, list }
  return list
}
const supersetCount = (mf) => Object.keys(mf.superset).length + (BASE ? BASE.superset.filter((r) => !mf.superset[key(r.item_no, r.color_id)]).length : 0)
const guidePrices = (mf) => (BASE ? [...BASE.prices, ...mf.prices] : mf.prices)

// ---------------------------------------------------------------- Bestand

// Quelle der Teile wie Minifig::stockSource(): nur Lager, Lager + eigene BSX-Dateien oder nur BSX-Dateien
export const SOURCES = ['bestand', 'beides', 'bsx']
const sourceOf = (db) => (SOURCES.includes(mfOf(db).source) ? mfOf(db).source : 'bestand')

// BSX-Dateien in ihrer Reihenfolge (sortOrder), wie Minifig::bsxRows: Remarks und Bemerkung getrennt, Dateiname in „file“
const bsxSorted = (mf) => [...(mf.bsx ?? [])].sort((a, b) => (a.sort_order ?? a.id) - (b.sort_order ?? b.id) || a.id - b.id)
function bsxRows(db) {
  const out = []
  bsxSorted(mfOf(db)).forEach((f, rank) => {
    f.items.forEach((r, n) => {
      if (r.quantity <= 0) return
      out.push({ inventory_id: BSX_BASE + f.id * 100000 + n, item_no: r.item_no, item_name: r.item_name, color_id: r.color_id,
        color_name: r.color_name, quantity: r.quantity, new_or_used: r.condition, remarks: r.remarks ?? '', description: r.comments ?? '', stock_room_id: '', file: f.name, _rank: rank + 1 })
    })
  })
  return out
}
const BSX_BASE = 800000000
const priorityOf = (db) => (mfOf(db).priority === 'bsx' ? 'bsx' : 'lager')

// Von gesammelten Figuren belegte Mengen je Lot (wie Minifig::reservedByBuilds)
function reservedByBuilds(db) {
  const out = new Map()
  for (const b of mfOf(db).builds ?? []) {
    if (b.status !== 'open' && b.status !== 'export') continue
    for (const l of b.lots) out.set(l.inventory_id, (out.get(l.inventory_id) ?? 0) + l.quantity)
  }
  return out
}

/** Alle Teile-Lots der gewählten Quelle, sortiert nach Teil, Farbe, Lot-Nr. (wie Minifig::partRows). */
function partRows(db) {
  const src = sourceOf(db)
  let rows = []
  if (src !== 'bsx') {
    rows = db.inventory.filter((i) => i.item_type === 'PART' && i.qty > 0)
      .map((i) => ({ inventory_id: i.bl_inventory_id ?? i.id, item_no: i.item_no, item_name: i.name, color_id: i.color_id, color_name: i.color_name,
        quantity: i.qty, new_or_used: i.new_or_used ?? '', remarks: locCode(db, i.location_id), description: i.description ?? null, stock_room_id: '', _rank: 0 }))
  }
  if (src !== 'bestand') rows = rows.concat(bsxRows(db))
  const used = reservedByBuilds(db)
  if (used.size) rows = rows.map((r) => ({ ...r, quantity: r.quantity - (used.get(r.inventory_id) ?? 0) })).filter((r) => r.quantity > 0)
  // Prio: Lager = Rang 0, BSX-Dateien 1, 2, …; bei „BSX zuerst“ das Lager ans Ende (wie Minifig::partRows)
  const bsxFirst = priorityOf(db) === 'bsx'
  const rank = (r) => (bsxFirst && r._rank === 0 ? Number.MAX_SAFE_INTEGER : r._rank)
  return rows.sort((a, b) => strCmp(a.item_no.toLowerCase(), b.item_no.toLowerCase()) || a.color_id - b.color_id || rank(a) - rank(b) || a.inventory_id - b.inventory_id)
    .map(({ _rank, ...r }) => r)
}

function variants(db, scope = 'core') {
  const m = new Map()
  for (const i of partRows(db)) {
    const k = key(i.item_no, i.color_id)
    const r = m.get(k) ?? { item_no: i.item_no, item_name: i.item_name, color_id: i.color_id, quantity: 0 }
    if (i.item_name > r.item_name) r.item_name = i.item_name
    r.quantity += i.quantity
    m.set(k, r)
  }
  const rows = [...m.values()]
  return scope === 'all' ? rows : rows.filter((r) => isCore(r.item_name))
}

function stock(db) {
  const out = new Map()
  for (const i of partRows(db)) out.set(key(i.item_no, i.color_id), (out.get(key(i.item_no, i.color_id)) ?? 0) + i.quantity)
  return out
}

function lotsOf(db) {
  const out = new Map()
  for (const r of partRows(db)) {
    const k = key(r.item_no, r.color_id)
    if (!out.has(k)) out.set(k, [])
    out.get(k).push(r)
  }
  return out
}

const bsxFiles = (db) => bsxSorted(mfOf(db)).map(({ id, name, lots, parts, created_at, uploaded_by }) => ({ id, name, lots, parts, created_at, uploaded_by }))

function addBsx(db, content, name, user) {
  const [rows] = parseBsx(content)
  const items = rows.filter((r) => r.item_type.toUpperCase() === 'P').map((r) => ({ item_no: r.item_no, item_name: r.item_name, color_id: Math.max(0, r.color_id),
    color_name: r.color_name, quantity: r.quantity, condition: r.condition, remarks: r.remarks ?? '', comments: r.comments ?? '', price: r.price ?? 0 }))
  if (!items.length) throw new DemoError('In der Datei sind keine Teile (nur Teile zählen für Minifiguren)', 422)
  const mf = mfOf(db)
  mf.bsx ??= []
  const id = (mf.bsx.at(-1)?.id ?? 0) + 1
  const f = { id, sort_order: Math.max(0, ...mf.bsx.map((x) => x.sort_order ?? x.id)) + 1, name: (name || 'datei.bsx').split(/[\\/]/).pop(), items, lots: items.length, parts: items.reduce((s, r) => s + r.quantity, 0), created_at: now(), uploaded_by: user.display_name }
  mf.bsx.push(f)
  return { id, name: f.name, lots: f.lots, parts: f.parts }
}

function prices(db) {
  const avg = (rows, withColor) => {
    const acc = new Map()
    for (const r of rows) {
      const k = withColor ? key(r.item_no, r.color_id) : r.item_no
      const a = acc.get(k) ?? { s: 0, e: 0, q: 0 }
      kbnAdd(a, r.qty * r.price)
      a.q += r.qty
      acc.set(k, a)
    }
    const out = new Map()
    for (const [k, a] of acc) if (a.q > 0) out.set(k, (a.s + a.e) / a.q)
    return out
  }
  const inv = [...db.inventory].sort((a, b) => a.id - b.id)
  const items = db.orders.flatMap((o) => o.items).sort((a, b) => a.id - b.id)
  const up = (v) => String(v ?? '').toUpperCase()
  const partCur = avg(inv.filter((i) => up(i.item_type) === 'PART' && i.qty > 0 && i.price > 0), true)
  const partHist = avg(items.filter((x) => up(x.item_type) === 'PART' && x.color_id !== null && x.color_id !== undefined && x.price > 0), true)
  const figCur = avg(inv.filter((i) => up(i.item_type) === 'MINIFIG' && i.qty > 0 && i.price > 0), false)
  const figHist = avg(items.filter((x) => ['MINIFIG', 'MINIFIGURE'].includes(up(x.item_type)) && x.price > 0), false)
  const guideParts = new Map()
  const guideFigs = new Map()
  for (const r of guidePrices(mfOf(db)).filter((x) => x.currency === 'EUR').sort((a, b) => strCmp(a.fetched_at, b.fetched_at))) {
    if (!(r.avg_price > 0)) continue
    const t = up(r.item_type)
    if (['PART', 'P'].includes(t)) guideParts.set(key(r.item_no, r.color_id), r.avg_price)
    else if (['MINIFIG', 'MINIFIGURE', 'M'].includes(t)) guideFigs.set(r.item_no, r.avg_price)
  }
  const merge = (guide, cur, hist) => {
    const out = new Map()
    for (const k of new Set([...hist.keys(), ...cur.keys(), ...guide.keys()])) {
      out.set(k, guide.has(k) ? { price: guide.get(k), source: 'Price Guide Cache' }
        : cur.has(k) ? { price: cur.get(k), source: 'aktueller Shoppreis' } : { price: hist.get(k), source: 'Verkaufshistorie' })
    }
    return out
  }
  return { parts: merge(guideParts, partCur, partHist), minifigs: merge(guideFigs, figCur, figHist) }
}

// ---------------------------------------------------------------- Stücklisten auswerten

export function groups(raw) {
  const out = []
  if (!Array.isArray(raw)) return out
  let outer = raw
  if (outer.length && outer.every((x) => x && typeof x === 'object' && !Array.isArray(x))) outer = outer.map((x) => [x])
  for (let matching of outer) {
    if (matching && typeof matching === 'object' && !Array.isArray(matching)) matching = [matching]
    if (!Array.isArray(matching)) continue
    const alts = []
    for (const entry of matching) {
      if (!entry || typeof entry !== 'object') continue
      for (const e of entry.entries ?? []) {
        if (!e || typeof e !== 'object') continue
        const item = e.item ?? {}
        if (String(item.type ?? '').toUpperCase() !== 'PART') continue
        const no = String(item.no ?? '').trim()
        const qty = Math.trunc(Number(e.quantity ?? 0))
        if (no && e.color_id !== null && e.color_id !== undefined && qty > 0) alts.push({ item_no: no, item_name: String(item.name ?? ''), color_id: Number(e.color_id), quantity: qty })
      }
    }
    if (alts.length) out.push(alts)
  }
  return out
}

function chooseRequirements(gs, st) {
  const req = []
  const missing = []
  for (const alts of gs) {
    let best = null
    for (const r of alts) {
      const have = st.get(key(r.item_no, r.color_id)) ?? 0
      const builds = r.quantity ? Math.floor(have / r.quantity) : 0
      if (!best || cmpTuple([builds, have], [best[0], best[1]]) > 0) best = [builds, have, r]
    }
    const chosen = { ...best[2], have: best[1] }
    req.push(chosen)
    if (best[0] <= 0) missing.push(chosen)
  }
  return [req, missing]
}

function canAllocate(gs, remaining) {
  const maxq = (alts) => Math.max(...alts.map((r) => r.quantity))
  const ordered = [...gs].sort((a, b) => a.length - b.length || maxq(b) - maxq(a))
  let best = null
  let bestSlack = -1
  // Nur geänderte Mengen merken (statt den ganzen Bestand je Schritt zu kopieren), Ergebnis wie im Original
  const search = (i, temp, chosen, slacks) => {
    if (i >= ordered.length) {
      const score = slacks.length ? Math.min(...slacks) : 0
      if (best === null || score > bestSlack) { best = chosen; bestSlack = score }
      return
    }
    const cands = []
    for (const r of ordered[i]) {
      const k = key(r.item_no, r.color_id)
      const have = (temp.has(k) ? temp.get(k) : remaining.get(k)) ?? 0
      if (r.quantity <= 0 || have < r.quantity) continue
      cands.push([have - r.quantity, have, r])
    }
    cands.sort((a, b) => cmpTuple([b[0], b[1]], [a[0], a[1]]))
    for (const [slack, have, r] of cands) {
      const next = new Map(temp)
      next.set(key(r.item_no, r.color_id), have - r.quantity)
      search(i + 1, next, [...chosen, { ...r, have_before_reservation: have }], [...slacks, slack])
    }
  }
  search(0, new Map(), [], [])
  return [best !== null, best ?? [], bestSlack]
}

// Wie oft lässt sich die Figur bauen? Jede Kopie vom Restbestand abziehen (wie maxBuildableCopies in Minifig.php, Standalone 0.1.7)
function maxBuildableCopies(gs, st, hardCap = 1000) {
  if (gs.length && gs.every((alts) => alts.length === 1)) {
    const totals = new Map()
    for (const [r] of gs) totals.set(key(r.item_no, r.color_id), (totals.get(key(r.item_no, r.color_id)) ?? 0) + r.quantity)
    const limits = [...totals].filter(([, q]) => q > 0).map(([k, q]) => Math.floor((st.get(k) ?? 0) / q))
    return limits.length ? Math.min(...limits) : 0
  }
  const remaining = new Map(st)
  let count = 0
  while (count < Math.max(1, hardCap)) {
    const [ok, req] = canAllocate(gs, remaining)
    if (!ok) break
    for (const r of req) remaining.set(key(r.item_no, r.color_id), (remaining.get(key(r.item_no, r.color_id)) ?? 0) - r.quantity)
    count++
  }
  return count
}

function missingAfterReservations(gs, remaining) {
  const temp = new Map(remaining)
  const missing = []
  for (const alts of [...gs].sort((a, b) => a.length - b.length)) {
    const cands = alts.map((r) => {
      const have = temp.get(key(r.item_no, r.color_id)) ?? 0
      return [have >= r.quantity && r.quantity > 0 ? 1 : 0, have - r.quantity, have, r]
    })
    cands.sort((a, b) => cmpTuple([b[0], b[1], b[2]], [a[0], a[1], a[2]]))
    const [ok, , have, sel] = cands[0]
    if (ok) temp.set(key(sel.item_no, sel.color_id), have - sel.quantity)
    else missing.push({ ...sel, have })
  }
  return missing
}

const pick = (best, p, row) => !best || (cmpTuple(p, best[0]) || strCmp(String(row.minifig_no), String(best[1].minifig_no))) > 0

function jointPlan(cands, st) {
  const remaining = new Map(st)
  let pending = [...cands]
  const selected = []
  const sourceByNo = new Map()
  const prio = (row, slack) => [row.economic_advantage !== null ? 1 : 0, row.economic_advantage ?? 0, row.figure_value !== null ? 1 : 0, row.figure_value ?? 0, slack, -row.required_groups]
  while (pending.length) {
    let best = null
    for (const row of pending) {
      const [ok, req, slack] = canAllocate(row.groups, remaining)
      if (!ok) continue
      const p = prio(row, slack)
      if (pick(best, p, row)) best = [p, row, req, slack]
    }
    if (!best) break
    const [, row, req, slack] = best
    for (const r of req) remaining.set(key(r.item_no, r.color_id), (remaining.get(key(r.item_no, r.color_id)) ?? 0) - r.quantity)
    sourceByNo.set(String(row.minifig_no), row)
    selected.push({ minifig_no: row.minifig_no, name: row.name, required_groups: row.required_groups, completeness: 100,
      buildable_qty: 1, individual_buildable_qty: row.buildable_qty || 1, figure_value: row.figure_value,
      parts_value: row.parts_value, economic_advantage: row.economic_advantage, price_coverage_pct: row.price_coverage_pct, allocation_slack: slack, reserved: [...req] })
    pending = pending.filter((r) => r.minifig_no !== row.minifig_no)
  }
  // Runde 2: zusätzliche Kopien aus dem echten Restbestand, erst wenn jede Figur ihre erste hat
  const byNo = new Map(selected.map((r) => [String(r.minifig_no), r]))
  while (selected.length) {
    let best = null
    for (const no of byNo.keys()) {
      const [ok, req, slack] = canAllocate(sourceByNo.get(no).groups, remaining)
      if (!ok) continue
      const p = prio(sourceByNo.get(no), slack)
      if (!best || (cmpTuple(p, best[0]) || strCmp(no, best[1])) > 0) best = [p, no, req, slack]
    }
    if (!best) break
    const [, no, req, slack] = best
    for (const r of req) remaining.set(key(r.item_no, r.color_id), (remaining.get(key(r.item_no, r.color_id)) ?? 0) - r.quantity)
    const out = byNo.get(no)
    out.buildable_qty = (out.buildable_qty || 1) + 1
    out.allocation_slack = Math.min(out.allocation_slack || slack, slack)
    out.reserved.push(...req)
  }
  const sel = new Set(selected.map((r) => r.minifig_no))
  const conflicts = cands.filter((r) => !sel.has(r.minifig_no)).map((row) => ({
    minifig_no: row.minifig_no, name: row.name, required_groups: row.required_groups, figure_value: row.figure_value, parts_value: row.parts_value,
    economic_advantage: row.economic_advantage, price_coverage_pct: row.price_coverage_pct,
    missing_after_reservations: missingAfterReservations(row.groups, remaining).slice(0, 5),
  }))
  return [selected, conflicts]
}

function remainingAfterJoint(st, joint) {
  const out = new Map(st)
  for (const f of joint) for (const r of f.reserved) out.set(key(r.item_no, r.color_id), Math.max(0, (out.get(key(r.item_no, r.color_id)) ?? 0) - r.quantity))
  return out
}

function almostJointPlan(cands, residual) {
  const remaining = new Map(residual)
  let pending = [...cands]
  const selected = []
  const alloc = (row) => {
    if ((row.missing ?? []).length !== 1) return null
    const miss = row.missing[0]
    const mk = key(miss.item_no, miss.color_id)
    const totals = new Map()
    const details = new Map()
    let skipped = false
    for (const r of row.requirements) {
      const k = key(r.item_no, r.color_id)
      if (!skipped && k === mk && r.quantity === miss.quantity) { skipped = true; continue }
      totals.set(k, (totals.get(k) ?? 0) + r.quantity)
      details.set(k, r)
    }
    if (!skipped) return null
    const slacks = []
    const reserved = []
    for (const [k, q] of totals) {
      const have = remaining.get(k) ?? 0
      if (q <= 0 || have < q) return null
      slacks.push(have - q)
      reserved.push({ ...details.get(k), quantity: q, have_before_reservation: have })
    }
    return [reserved, slacks.length ? Math.min(...slacks) : 0, miss]
  }
  const prio = (row, slack) => [row.economic_advantage !== null ? 1 : 0, row.economic_advantage ?? 0, row.figure_value !== null ? 1 : 0, row.figure_value ?? 0,
    row.completion_cost !== null ? -row.completion_cost : -999999, slack]
  while (pending.length) {
    let best = null
    for (const row of pending) {
      const a = alloc(row)
      if (!a) continue
      const p = prio(row, a[1])
      if (pick(best, p, row)) best = [p, row, ...a]
    }
    if (!best) break
    const [, row, reserved, slack, miss] = best
    for (const r of reserved) remaining.set(key(r.item_no, r.color_id), (remaining.get(key(r.item_no, r.color_id)) ?? 0) - r.quantity)
    selected.push({ minifig_no: row.minifig_no, name: row.name, required_groups: row.required_groups, completeness: row.completeness, figure_value: row.figure_value,
      parts_value: row.parts_value, completion_cost: row.completion_cost, economic_advantage: row.economic_advantage, price_coverage_pct: row.price_coverage_pct,
      missing: [miss], allocation_slack: slack, reserved })
    pending = pending.filter((r) => r.minifig_no !== row.minifig_no)
  }
  const sel = new Set(selected.map((r) => r.minifig_no))
  return [selected, cands.filter((r) => !sel.has(r.minifig_no))]
}

const copyLots = (lots) => new Map([...lots].map(([k, ls]) => [k, ls.map((l) => ({ ...l, remaining_quantity: l.quantity }))]))
const colorName = (ls) => ls?.find((l) => l.color_name)?.color_name ?? ''
const source = (l, take, withLot) => ({ inventory_id: l.inventory_id, quantity: take, ...(withLot ? { lot_quantity: l.quantity } : {}),
  remarks: l.remarks ?? '', description: l.description ?? '', stock_room_id: '', new_or_used: l.new_or_used ?? '', ...(l.file !== undefined ? { file: l.file } : {}) })
const urls = (no) => ({ catalog_url: `https://www.bricklink.com/v2/catalog/catalogitem.page?M=${encodeURIComponent(no)}`,
  image_url: `https://img.bricklink.com/ItemImage/MN/0/${encodeURIComponent(no)}.png` })

function consumeFrom(rem, r, collect, withLot) {
  const k = key(r.item_no, r.color_id)
  const need = Math.max(0, r.quantity)
  let still = need
  const sources = []
  const ls = rem.get(k) ?? []
  const before = ls.reduce((s, l) => s + Math.max(0, l.remaining_quantity), 0)
  for (const l of ls) {
    const av = Math.max(0, l.remaining_quantity)
    if (av <= 0 || still <= 0) continue
    const take = Math.min(av, still)
    l.remaining_quantity = av - take
    still -= take
    if (collect) sources.push(source(l, take, withLot))
  }
  return { k, need, still, sources, before, ls }
}

function attachPickLists(selected, lots) {
  const rem = copyLots(lots)
  return selected.map((fig) => ({ ...fig, pick_list: fig.reserved.map((r) => {
    const c = consumeFrom(rem, r, true, false)
    return { item_no: r.item_no, item_name: r.item_name || r.item_no, color_id: r.color_id, color_name: colorName(c.ls), quantity: c.need,
      stock_before_figure: c.before, sources: c.sources }
  }) }))
}

function attachAlmostJointPickLists(primary, selected, lots) {
  const rem = copyLots(lots)
  for (const f of primary) for (const r of f.reserved) consumeFrom(rem, r, false, true)
  return selected.map((fig) => {
    const list = fig.reserved.map((r) => {
      const c = consumeFrom(rem, r, true, true)
      return { item_no: String(r.item_no), item_name: r.item_name || String(r.item_no), color_id: r.color_id, color_name: colorName(c.ls), quantity: c.need,
        available: Math.max(0, c.need - c.still), shortfall: c.still, stock_before_figure: c.before, sources: c.sources }
    })
    for (const m of fig.missing) {
      const need = Math.max(0, m.quantity)
      const have = Math.max(0, m.have ?? 0)
      list.push({ item_no: String(m.item_no), item_name: m.item_name || String(m.item_no), color_id: m.color_id, color_name: colorName(rem.get(key(m.item_no, m.color_id))),
        quantity: need, available: have, shortfall: Math.max(1, need - have), sources: [] })
    }
    return { ...fig, pick_list: list, ...urls(String(fig.minifig_no)) }
  })
}

function individualPick(req, lots) {
  return req.map((r) => {
    const k = key(r.item_no, r.color_id)
    const need = Math.max(0, r.quantity)
    let still = need
    const sources = []
    for (const l of lots.get(k) ?? []) {
      const av = Math.max(0, l.quantity)
      if (av <= 0 || still <= 0) continue
      const take = Math.min(av, still)
      still -= take
      sources.push(source(l, take, true))
    }
    return { item_no: String(r.item_no), item_name: r.item_name || String(r.item_no), color_id: r.color_id, color_name: colorName(lots.get(k)),
      quantity: need, available: Math.max(0, need - still), shortfall: still, sources }
  })
}

function priceEnrich(row, req, pr) {
  let known = 0
  let priced = 0
  let total = 0
  let missCost = 0
  let missComplete = true
  const missDetails = []
  const missKeys = new Map(row.missing.map((m) => [key(m.item_no, m.color_id), m]))
  for (const r of req) {
    const k = key(r.item_no, r.color_id)
    total += r.quantity
    const ref = pr.parts.get(k)
    if (ref && ref.price > 0) {
      known += Math.min(r.quantity, Math.max(0, r.have ?? r.quantity)) * ref.price
      priced += r.quantity
    }
    if (missKeys.has(k)) {
      const m = missKeys.get(k)
      const short = Math.max(0, m.quantity - (m.have ?? 0))
      let est = null
      let src = null
      if (ref && ref.price > 0) { est = short * ref.price; missCost += est; src = ref.source } else missComplete = false
      missDetails.push({ ...m, shortfall: short, estimated_unit_price: ref ? money(ref.price) : null, estimated_missing_cost: money(est), price_source: src })
    }
  }
  const figRef = pr.minifigs.get(String(row.minifig_no))
  const figVal = figRef ? figRef.price : null
  const complete = priced === total && (!row.missing_count || missComplete)
  const input = known + (row.missing_count ? missCost : 0)
  const adv = figVal !== null && complete ? figVal - input : null
  return {
    ...row, missing: row.missing_count ? missDetails : row.missing, parts_value: money(known),
    price_coverage_pct: total ? pyRound((100 * priced) / total) : 0,
    completion_cost: row.missing_count && missComplete ? money(missCost) : !row.missing_count ? 0 : null,
    figure_value: money(figVal), figure_price_source: figRef?.source ?? null, economic_advantage: money(adv), economic_advantage_before_fees: money(adv),
  }
}

function missingFrequency(almost) {
  const g = new Map()
  for (const row of almost) {
    for (const m of row.missing) {
      const k = key(m.item_no, m.color_id)
      if (!g.has(k)) g.set(k, { item_no: m.item_no, item_name: m.item_name || m.item_no, color_id: m.color_id, figures_affected: 0, total_shortfall: 0,
        estimated_unit_price: m.estimated_unit_price ?? null, price_source: m.price_source ?? null })
      const e = g.get(k)
      e.figures_affected++
      e.total_shortfall += m.shortfall ?? 0
      if (e.estimated_unit_price === null && (m.estimated_unit_price ?? null) !== null) { e.estimated_unit_price = m.estimated_unit_price; e.price_source = m.price_source }
    }
  }
  const rows = [...g.values()].map((r) => ({ ...r, estimated_total_cost: r.estimated_unit_price !== null ? money(r.estimated_unit_price * r.total_shortfall) : null }))
  rows.sort((a, b) => b.figures_affected - a.figures_affected || b.total_shortfall - a.total_shortfall || strCmp(String(a.item_no), String(b.item_no)))
  return rows
}

const bomPartCount = (gs) => gs.reduce((t, alts) => { const qs = alts.map((r) => r.quantity).filter((q) => q > 0); return t + (qs.length ? Math.min(...qs) : 0) }, 0)

export function analyze(db, limit = 500) {
  const mf = mfOf(db)
  const st = stock(db)
  const lots = lotsOf(db)
  const pr = prices(db)
  const rows = []
  const buildableInternal = []
  let underMin = 0
  // Reihenfolge wie im Original: so, wie die Figuren in den Katalog kamen
  for (const fig of catalogOf(mf).filter((c) => c.subset)) {
    const gs = groups(fig.subset)
    if (!gs.length) continue
    const bom = bomPartCount(gs)
    if (bom < 4) { underMin++; continue }
    // Wie Standalone 0.1.7: erst prüfen, ob eine Kopie wirklich aufgeht (Alternativen per Rückverfolgung)
    const [oneOk, allocated] = canAllocate(gs, st)
    let req, missing, present, buildQty
    if (oneOk) {
      req = allocated.map((r) => ({ ...r, have: st.get(key(r.item_no, r.color_id)) ?? 0 }))
      missing = []
      present = gs.length
      buildQty = maxBuildableCopies(gs, st)
    } else {
      [req, missing] = chooseRequirements(gs, st)
      present = gs.length - missing.length
      buildQty = 0
    }
    let row = { minifig_no: fig.minifig_no, name: fig.name || fig.minifig_no, required_groups: gs.length, present_groups: present, bom_part_count: bom,
      missing_count: missing.length, missing: missing.slice(0, 5), buildable_qty: buildQty,
      completeness: pyRound((100 * present) / gs.length) }
    row = priceEnrich(row, req, pr)
    row._requirements = req
    rows.push(row)
    if (row.buildable_qty > 0) buildableInternal.push({ ...row, groups: gs })
  }
  rows.sort((a, b) => cmpTuple([b.buildable_qty > 0, -b.missing_count, b.completeness], [a.buildable_qty > 0, -a.missing_count, a.completeness]))
  const almostAll = rows.filter((r) => r.buildable_qty === 0 && r.missing_count === 1)
  const almostRaw = almostAll.filter((r) => r.completion_cost !== null)
  const almostInternal = almostRaw.map((r) => ({ ...r, requirements: r._requirements }))
  const finish = ({ _requirements, ...r }) => ({ ...r, pick_list: individualPick(_requirements, lots), ...urls(String(r.minifig_no)) })
  const buildable = rows.filter((r) => r.buildable_qty > 0).map(finish)
  const almost = almostRaw.map(finish)
  const [jointRaw, conflicts] = jointPlan(buildableInternal, st)
  const residual = remainingAfterJoint(st, jointRaw)
  const excl = Object.entries(mf.decisions).filter(([, d]) => d.decision === 'exclude')
  const exclSet = new Set(excl.map(([no]) => no))
  const [ajRaw, ajBlocked] = almostJointPlan(almostInternal.filter((r) => !exclSet.has(String(r.minifig_no))), residual)
  const byNo = new Map(almost.map((r) => [r.minifig_no, r]))
  const excluded = excl.filter(([no]) => byNo.has(no)).map(([no, d]) => ({ minifig_no: no, name: byNo.get(no).name, reason: d.reason || 'not_build', decided_at: d.decided_at }))
  const joint = attachPickLists(jointRaw, lots)
  const almostJoint = attachAlmostJointPickLists(jointRaw, ajRaw, lots)
  const freq = missingFrequency(almost)
  // Python-Wahrheitswerte: 0 zählt wie „nicht vorhanden“ (x or 0)
  const or = (v, d) => (v ? v : d)
  const byValue = [...buildable].sort((a, b) => cmpTuple([b.figure_value !== null, or(b.figure_value, 0), or(b.parts_value, 0)], [a.figure_value !== null, or(a.figure_value, 0), or(a.parts_value, 0)]))
  const byAdv = [...buildable].sort((a, b) => cmpTuple([b.economic_advantage !== null, or(b.economic_advantage, -999999)], [a.economic_advantage !== null, or(a.economic_advantage, -999999)]))
  const byPot = [...almost].sort((a, b) => cmpTuple([b.economic_advantage !== null, or(b.economic_advantage, -999999), -or(b.completion_cost, 999999)],
    [a.economic_advantage !== null, or(a.economic_advantage, -999999), -or(a.completion_cost, 999999)]))
  const s = (x) => x.slice(0, limit)
  return {
    buildable: s(buildable), buildable_by_value: s(byValue), buildable_by_advantage: s(byAdv), almost_by_potential: s(byPot),
    missing_frequency: s(freq), joint_buildable: s(joint), joint_conflicts: s(conflicts), joint_buildable_count: joint.reduce((t, r) => t + (r.buildable_qty || 1), 0), joint_buildable_distinct_count: joint.length, joint_conflict_count: conflicts.length,
    almost_joint_buildable: s(almostJoint), almost_joint_buildable_count: almostJoint.length, almost_joint_blocked_count: ajBlocked.length,
    almost_joint_excluded: excluded, almost_joint_excluded_count: excluded.length, individual_buildable_count: buildable.length, almost_count: almost.length,
    almost_without_cost_count: almostAll.length - almost.length, under_min_parts_count: underMin, almost: s(almost), all_count: rows.length,
    ...statusCounts(db),
  }
}

// ---------------------------------------------------------------- Stand, Budget, Demo-„API“

function statusCounts(db) {
  const mf = mfOf(db)
  const t = now()
  const states = Object.values(mf.subsetState)
  return {
    scanned_variants: supersetCount(mf), candidate_minifigs: catalogOf(mf).length, cached_subsets: catalogOf(mf).filter((c) => c.subset).length,
    subset_permanent_failed: states.filter((s) => s.status === 'permanent').length,
    subset_retry_waiting: states.filter((s) => s.status === 'retry' && s.next_retry_at > t).length,
    subset_retry_ready: states.filter((s) => s.status === 'retry' && (!s.next_retry_at || s.next_retry_at <= t)).length,
  }
}

function usage24h(db) {
  const since = new Date(Date.now() - 86400000)
  const p = (n) => String(n).padStart(2, '0')
  const sinceStr = `${since.getFullYear()}-${p(since.getMonth() + 1)}-${p(since.getDate())} ${p(since.getHours())}:${p(since.getMinutes())}:${p(since.getSeconds())}`
  const byType = {}
  for (const c of mfOf(db).apiCalls) if (c.created_at >= sinceStr) byType[c.call_type] = (byType[c.call_type] ?? 0) + 1
  return { total: Object.values(byType).reduce((s, v) => s + v, 0), by_type: byType }
}
export const apiUsage = usage24h

function budget(db) {
  const u = usage24h(db)
  return { api_usage_24h: u.total, api_usage_24h_by_type: u.by_type, safe_available_calls: Math.max(0, DAILY_BUDGET - RESERVE - u.total),
    api_daily_budget: DAILY_BUDGET, api_reserved_calls: RESERVE }
}

function refreshStatus(db) {
  const keys = new Set(variants(db).map((v) => key(v.item_no, v.color_id)))
  const stamps = Object.values(mfOf(db).superset).filter((s) => keys.has(key(s.item_no, s.color_id))).map((s) => s.fetched_at).sort()
  return { refreshable_variants: stamps.length, oldest_checked_at: stamps[0] ?? null, latest_checked_at: stamps.at(-1) ?? null }
}

function priceStatus(db) {
  const mf = mfOf(db)
  const eur = mf.prices.filter((p) => p.currency === 'EUR')
  return { price_guide_part_count: eur.filter((p) => ['PART', 'P'].includes(String(p.item_type).toUpperCase())).length,
    price_guide_minifig_count: eur.filter((p) => ['MINIFIG', 'MINIFIGURE', 'M'].includes(String(p.item_type).toUpperCase())).length,
    price_guide_import_at: mf.priceMeta?.imported_at ?? null, price_guide_import_filename: mf.priceMeta?.filename ?? null, price_guide_import_rows: mf.priceMeta?.row_count ?? 0 }
}

export function status(db) {
  const v = variants(db)
  const r = statusCounts(db)
  return {
    ...r, eligible_core_variants: v.length, remaining_core_variants: Math.max(0, v.length - r.scanned_variants),
    remaining_subsets: Math.max(0, r.candidate_minifigs - r.cached_subsets),
    inventory_parts: db.inventory.reduce((s, i) => s + i.qty, 0), inventory_lots: db.inventory.length,
    inventory_value: Math.round(db.inventory.reduce((s, i) => s + i.qty * i.price, 0) * 100) / 100,
    inventory_import_at: null, bricklink_configured: true, demo_api: true, source: sourceOf(db), priority: priorityOf(db), bsx_files: bsxFiles(db), katalog: BASE?.meta ?? null,
    ...refreshStatus(db), ...budget(db), ...priceStatus(db),
  }
}

/** Erfundener BrickLink-Abruf: zählt wie ein echter Abruf, Antwort aus dem Demo-Katalog. */
function fakeApi(db, type, endpoint, fn) {
  mfOf(db).apiCalls.push({ api: 'bricklink', call_type: type, endpoint, status: 200, created_at: now() })
  return fn()
}

function saveSuperset(db, no, color, data) {
  const mf = mfOf(db)
  const t = now()
  const cands = new Map()
  for (const g of data) for (const e of g.entries ?? []) if (String(e.item?.type).toUpperCase() === 'MINIFIG' && e.item.no) cands.set(e.item.no, [e.item.name ?? '', e.item.category_id ?? null])
  const known = new Set(mf.catalog.filter((c) => cands.has(c.minifig_no)).map((c) => c.minifig_no))
  mf.superset[key(no, color)] = { item_no: no, color_id: color, fetched_at: t, response: data }
  for (const [fno, [name, cat]] of cands) {
    const c = mf.catalog.find((x) => x.minifig_no === fno)
    if (c) { if (name) c.name = name; c.category_id = cat ?? c.category_id; c.last_seen_at = t } else {
      mf.catalog.push({ minifig_no: fno, name, category_id: cat, first_seen_at: t, last_seen_at: t, subset_fetched_at: null, subset: null })
    }
  }
  const neu = [...cands.keys()].filter((n) => !known.has(n)).sort(strCmp)
  return { candidate_hits: cands.size, new_minifigs: neu.map((n) => ({ minifig_no: n, name: cands.get(n)[0] || n })) }
}

export function scan(db, batch) {
  batch = Math.min(Math.max(1, Math.min(batch, 1000)), budget(db).safe_available_calls)
  const all = variants(db)
  const mf = mfOf(db)
  const todo = all.filter((v) => !mf.superset[key(v.item_no, v.color_id)]).slice(0, batch)
  let found = 0
  for (const v of todo) {
    const data = fakeApi(db, 'catalog_supersets', `/items/part/${v.item_no}/supersets?color_id=${v.color_id}`, () => supersetsOf(seed.minifigs, v.item_no, v.color_id))
    found += saveSuperset(db, v.item_no, v.color_id, data).candidate_hits
  }
  const c = statusCounts(db)
  return { ...c, api_calls: todo.length, errors: 0, candidate_hits: found, scope: 'core', eligible_variants: all.length,
    remaining_variants: Math.max(0, all.length - c.scanned_variants), ...budget(db) }
}

export function refreshCatalog(db, batch) {
  batch = Math.min(Math.max(1, Math.min(batch, 1000)), budget(db).safe_available_calls)
  const byKey = new Map(variants(db).map((v) => [key(v.item_no, v.color_id), v]))
  const rows = Object.values(mfOf(db).superset).sort((a, b) => strCmp(a.fetched_at, b.fetched_at) || strCmp(a.item_no.toLowerCase(), b.item_no.toLowerCase()) || a.color_id - b.color_id)
  const todo = rows.map((r) => byKey.get(key(r.item_no, r.color_id))).filter(Boolean).slice(0, batch)
  let hits = 0
  const neu = new Map()
  for (const v of todo) {
    const data = fakeApi(db, 'catalog_supersets', `/items/part/${v.item_no}/supersets?color_id=${v.color_id}`, () => supersetsOf(seed.minifigs, v.item_no, v.color_id))
    const s = saveSuperset(db, v.item_no, v.color_id, data)
    hits += s.candidate_hits
    for (const f of s.new_minifigs) neu.set(f.minifig_no, f)
  }
  const c = statusCounts(db)
  return { ...c, api_calls: todo.length, errors: 0, candidate_hits: hits, new_minifig_count: neu.size, new_minifigs: [...neu.values()].sort((a, b) => strCmp(a.minifig_no, b.minifig_no)),
    remaining_subsets: Math.max(0, c.candidate_minifigs - c.cached_subsets), ...refreshStatus(db), ...budget(db) }
}

export function fetchSubsets(db, batch) {
  const req = Math.max(1, Math.min(batch, 1000))
  const b = budget(db)
  const limit = Math.min(req, b.safe_available_calls)
  const mf = mfOf(db)
  const t = now()
  const todo = mf.catalog.filter((c) => !c.subset && (!mf.subsetState[c.minifig_no] || (mf.subsetState[c.minifig_no].status === 'retry'
    && (!mf.subsetState[c.minifig_no].next_retry_at || mf.subsetState[c.minifig_no].next_retry_at <= t))))
    .sort((x, y) => strCmp(y.last_seen_at, x.last_seen_at) || strCmp(x.minifig_no, y.minifig_no)).slice(0, limit)
  let loaded = 0
  let perm = 0
  for (const fig of todo) {
    const f = fakeApi(db, 'catalog_subsets', `/items/minifig/${fig.minifig_no}/subsets`, () => seed.minifigs.find((x) => x.minifig_no === fig.minifig_no))
    if (!f) {
      mf.subsetState[fig.minifig_no] = { status: 'permanent', attempts: 1, last_status_code: 404, last_attempt_at: t, next_retry_at: null }
      perm++
      continue
    }
    fig.subset = f.subset
    fig.subset_fetched_at = t
    delete mf.subsetState[fig.minifig_no]
    loaded++
  }
  const c = statusCounts(db)
  return { ...c, api_calls: todo.length, errors: perm, loaded_subsets: loaded, permanent_failed_this_run: perm, deferred_this_run: 0, stopped_early: false,
    already_running: false, requested_batch_size: req, effective_call_limit: limit, remaining_subsets: Math.max(0, c.candidate_minifigs - c.cached_subsets), ...b }
}

export function decide(db, no, action, reason) {
  no = String(no ?? '').trim()
  action = String(action ?? '').trim().toLowerCase()
  if (!no) throw new DemoError('minifig_no fehlt', 400)
  if (!['exclude', 'allow'].includes(action)) throw new DemoError('action muss exclude oder allow sein', 400)
  reason = String(reason || 'not_build').trim().toLowerCase()
  if (!['parts_value', 'missing_cost', 'not_build'].includes(reason)) reason = 'not_build'
  const mf = mfOf(db)
  delete mf.decisions[no]
  if (action === 'exclude') mf.decisions[no] = { decision: 'exclude', reason, decided_at: now() }
  const r = analyze(db, 10000)
  return { ok: true, minifig_no: no, action, reason: action === 'exclude' ? reason : '', almost_joint_buildable_count: r.almost_joint_buildable_count,
    almost_joint_blocked_count: r.almost_joint_blocked_count, almost_joint_excluded_count: r.almost_joint_excluded_count }
}

// ---------------------------------------------------------------- Exporte (im Browser erzeugt)

function priceExport(db, r) {
  const parts = new Map()
  for (const row of [...r.buildable, ...r.almost]) for (const p of row.pick_list) if (String(p.item_no).trim()) parts.set(`${String(p.item_no).toLowerCase()}\u0000${String(p.color_id).padStart(10, '0')}`, p)
  const items = [...catalogOf(mfOf(db))].sort((a, b) => strCmp(a.minifig_no.toLowerCase(), b.minifig_no.toLowerCase()))
    .map((f) => ({ ItemTypeID: 'M', ItemID: f.minifig_no, ItemName: f.name || f.minifig_no, ColorID: '0', ColorName: '', Qty: '1', Price: '0', Condition: 'U', Status: 'I' }))
  for (const k of [...parts.keys()].sort(strCmp)) {
    const p = parts.get(k)
    items.push({ ItemTypeID: 'P', ItemID: p.item_no, ItemName: p.item_name, ColorID: String(p.color_id), ColorName: p.color_name, Qty: '1', Price: '0', Condition: 'U', Status: 'I' })
  }
  return buildBsx(items)
}

function priceImport(db, content, apply, filename) {
  const [rows, currency] = parseBsx(content)
  if (currency !== 'EUR') throw new DemoError(`Die BrickStore-Datei hat Währung ${currency}; erwartet wird EUR`, 400)
  const grouped = new Map()
  for (const r of rows) {
    if (r.price <= 0) continue
    const k = [r.item_type, r.item_no, r.color_id, r.condition].join('\u0000')
    if (!grouped.has(k)) grouped.set(k, [])
    grouped.get(k).push(r.price)
  }
  const conflict = (p) => Math.max(...p) - Math.min(...p) > 0.001
  const conflicts = [...grouped.values()].filter(conflict).length
  if (!apply) return { kind: 'guide', currency: 'EUR', rows: rows.length, combinations: grouped.size, usable_combinations: grouped.size - conflicts, conflicts }
  const mf = mfOf(db)
  const t = now()
  let imported = 0
  for (const [k, p] of grouped) {
    if (conflict(p)) continue
    const [type, no, color, cond] = k.split('\u0000')
    mf.prices = mf.prices.filter((x) => !(x.item_type === type && x.item_no === no && x.color_id === Number(color) && x.new_or_used === cond && x.currency === 'EUR'))
    mf.prices.push({ item_type: type, item_no: no, color_id: Number(color), new_or_used: cond, currency: 'EUR', avg_price: p.reduce((s, v) => s + v, 0) / p.length, fetched_at: t })
    imported++
  }
  mf.priceMeta = { imported_at: t, filename: String(filename ?? '').slice(0, 120), row_count: imported }
  return { kind: 'guide', currency: 'EUR', rows: rows.length, combinations: grouped.size, imported_combinations: imported, conflicts, applied: true, imported_at: t }
}

const file = (name, type, data) => ({ __file: { name, type, data } })

/** Routen des Minifig-Finders im Demo-Backend (gleich wie api/index.php). */
// ---------------------------------------------------------------- Fertige Figuren (gemeinsame Sammlung, wie Minifig.php)

const exportActive = (db) => (mfOf(db).builds ?? []).some((b) => b.status === 'export')
export const buildExportActive = exportActive
const userName = (db, id) => db.users.find((u) => u.id === id)?.display_name ?? null

function builds(db) {
  const list = (mfOf(db).builds ?? []).filter((b) => b.status === 'open' || b.status === 'export')
  const ex = list.find((b) => b.status === 'export')
  return {
    builds: list.map((b) => ({ id: b.id, minifig_no: b.minifig_no, name: b.name, qty: b.qty, status: b.status, created_at: b.created_at,
      user_name: userName(db, b.user_id), lots: b.lots, parts: b.lots.reduce((t, l) => t + l.quantity, 0) })),
    export: ex ? { by: userName(db, ex.export_by), at: ex.export_at } : null,
    transfer_open: (db.blExports ?? []).some((x) => x.open),
  }
}

function addBuild(db, no, qty, lots, me) {
  no = String(no ?? '').trim()
  if (!no || !(qty >= 1)) throw new DemoError('Figur oder Anzahl fehlt', 422)
  const want = new Map()
  for (const l of lots ?? []) {
    const id = Number(l.inventory_id)
    const n = Number(l.quantity)
    if (id > 0 && n > 0) want.set(id, (want.get(id) ?? 0) + n)
  }
  if (!want.size) throw new DemoError('Keine Teile angegeben', 422)
  const avail = new Map(partRows(db).map((r) => [r.inventory_id, r]))
  const stored = []
  for (const [id, n] of want) {
    const r = avail.get(id)
    if (!r || r.quantity < n) throw new DemoError('Ein Teil ist nicht mehr in dieser Menge da (vielleicht hat jemand anderes es gerade gesammelt). Die Liste wird neu berechnet.', 409)
    const l = { inventory_id: id, quantity: n, item_no: r.item_no, item_name: r.item_name, color_id: r.color_id, color_name: r.color_name, remarks: r.remarks ?? '', condition: r.new_or_used ?? '' }
    if (id >= BSX_BASE) Object.assign(l, { kind: 'bsx', bsx_id: Math.floor((id - BSX_BASE) / 100000), line: (id - BSX_BASE) % 100000, file: r.file ?? '' })
    else {
      const inv = db.inventory.find((i) => i.bl_inventory_id === id) ?? db.inventory.find((i) => i.id === id && (i.bl_inventory_id === null || i.bl_inventory_id === undefined))
      Object.assign(l, { kind: 'bestand', local_id: inv?.id ?? null, bl_lot_id: inv?.bl_inventory_id ?? null })
    }
    stored.push(l)
  }
  const mf = mfOf(db)
  mf.builds ??= []
  const name = catalogOf(mf).find((c) => c.minifig_no === no)?.name || no
  const b = { id: (mf.builds.at(-1)?.id ?? 0) + 1, minifig_no: no, name, qty, lots: stored, status: 'open', user_id: me.id, created_at: now() }
  mf.builds.push(b)
  return { id: b.id, minifig_no: no, name, qty, parts: [...want.values()].reduce((t, n) => t + n, 0) }
}

function exportLots(db) {
  const agg = new Map()
  for (const b of mfOf(db).builds ?? []) {
    if (b.status !== 'export') continue
    for (const l of b.lots) {
      if (agg.has(l.inventory_id)) agg.get(l.inventory_id).quantity += l.quantity
      else agg.set(l.inventory_id, { ...l })
    }
  }
  return [...agg.values()]
}

function buildExportFiles(db) {
  let xml = ''
  const noLot = []
  const files = new Map()
  for (const l of exportLots(db)) {
    if (l.kind === 'bsx') {
      const f = files.get(l.bsx_id) ?? { id: l.bsx_id, name: l.file ?? '', lots: 0, parts: 0 }
      f.lots++
      f.parts += l.quantity
      files.set(l.bsx_id, f)
    } else if (l.bl_lot_id) xml += `<ITEM><LOTID>${l.bl_lot_id}</LOTID><QTY>-${l.quantity}</QTY></ITEM>\n`
    else noLot.push(l)
  }
  const b = builds(db)
  return { xml: xml ? `<INVENTORY>\n${xml}</INVENTORY>\n` : '', bricklink_lots: (xml.match(/<ITEM>/g) ?? []).length, without_lot: noLot,
    bsx_files: [...files.values()], export_by: b.export?.by ?? null, export_at: b.export?.at ?? null, ...b }
}

function bsxMinus(db, items, fileId) {
  const out = items.map((r) => ({ ...r }))
  for (const l of exportLots(db)) {
    if (l.kind !== 'bsx' || l.bsx_id !== fileId || !out[l.line]) continue
    out[l.line].quantity = Math.max(0, out[l.line].quantity - l.quantity)
  }
  return out
}

function finishBuildExport(db, done, applyBsx) {
  if (!exportActive(db)) throw new DemoError('Dieser Abschluss wurde schon beendet.', 409)
  const mf = mfOf(db)
  const figs = mf.builds.filter((b) => b.status === 'export').reduce((t, b) => t + b.qty, 0)
  let parts = 0
  let lotsChanged = 0
  const files = []
  if (done) {
    for (const l of exportLots(db)) {
      if (l.kind !== 'bestand') continue
      const inv = (l.local_id ? db.inventory.find((i) => i.id === l.local_id) : null) ?? (l.bl_lot_id ? db.inventory.find((i) => i.bl_inventory_id === l.bl_lot_id) : null)
      if (!inv) continue
      parts += Math.min(l.quantity, inv.qty)
      inv.qty -= l.quantity
      inv.updated_at = now()
      if (inv.qty <= 0) db.inventory = db.inventory.filter((i) => i !== inv)
      lotsChanged++
    }
    for (const fid of [...new Set((applyBsx ?? []).map(Number))]) {
      const f = (mf.bsx ?? []).find((x) => x.id === fid)
      if (!f) continue
      f.items = bsxMinus(db, f.items, fid)
      const alive = f.items.filter((r) => r.quantity > 0)
      f.lots = alive.length
      f.parts = alive.reduce((t, r) => t + r.quantity, 0)
      files.push(fid)
    }
    for (const b of mf.builds) if (b.status === 'export') { b.status = 'done'; b.done_at = now() }
  } else {
    for (const b of mf.builds) if (b.status === 'export') { b.status = 'open'; b.export_by = null; b.export_at = null }
  }
  return { done, figures: figs, parts, lots: lotsChanged, bsx_files: files }
}

function importTransfer(db, me, log, p) {
  if (!p || p.format !== 'tn-dashboard-minifig-transfer' || !p.demo) throw new DemoError('Keine gültige Minifig-Transferdatei (in der Demo nur Dateien aus der Demo).', 400)
  const mf = mfOf(db)
  Object.assign(mf, { superset: p.demo.superset, catalog: p.demo.catalog, subsetState: p.demo.subsetState, decisions: p.demo.decisions, prices: p.price_guide })
  const imported = { minifig_catalog: mf.catalog.length, minifig_superset_cache: Object.keys(mf.superset).length, price_guide: mf.prices.length }
  log(me.id, 'minifig_transfer_import', 'minifig', imported)
  return { ok: true, version: 1, exported_at: p.exported_at, imported }
}

export async function route(db, method, parts, params, body, requireUser, log, devAllowed) {
  if (parts[0] !== 'minifig') return undefined
  const me = requireUser()
  await loadBaseCatalog()
  const r2 = parts.slice(1).join('/')
  const needDev = () => { if (!devAllowed(me)) throw new DemoError('Kein Zugang zum Dev-Bereich', 403) }
  if (method === 'GET' && r2 === 'status') return status(db)
  if (method === 'GET' && r2 === 'results') return analyze(db, Math.max(1, Math.min(10000, Number(params.limit) || 500)))
  if (method === 'POST' && ['scan', 'catalog-refresh', 'subsets'].includes(r2)) {
    needDev()
    const batch = Number(body.batch_size) || 25
    const r = r2 === 'scan' ? scan(db, batch) : r2 === 'catalog-refresh' ? refreshCatalog(db, batch) : fetchSubsets(db, batch)
    log(me.id, `minifig_${r2.replace('-', '_')}`, 'minifig', { abrufe: r.api_calls })
    return r
  }
  if (method === 'POST' && r2 === 'source') {
    if (!SOURCES.includes(body.source)) throw new DemoError('Unbekannte Quelle', 400)
    mfOf(db).source = body.source
    log(me.id, 'minifig_source', 'minifig', { quelle: body.source })
    return { source: body.source }
  }
  if (method === 'POST' && r2 === 'priority') {
    if (!['lager', 'bsx'].includes(body.priority)) throw new DemoError('Unbekannte Reihenfolge', 422)
    const mf = mfOf(db)
    mf.priority = body.priority
    ;(body.bsx_order ?? []).map(Number).forEach((id, i) => { const f = (mf.bsx ?? []).find((x) => x.id === id); if (f) f.sort_order = i + 1 })
    log(me.id, 'minifig_priority', 'minifig', { zuerst: body.priority })
    return { priority: body.priority, bsx_files: bsxFiles(db) }
  }
  if (method === 'GET' && r2 === 'builds') return builds(db)
  if (method === 'POST' && r2 === 'builds') {
    const r = addBuild(db, body.minifig_no, Number(body.qty) || 1, body.lots, me)
    log(me.id, 'minifig_build_add', 'minifig', { figur: r.minifig_no, menge: r.qty, teile: r.parts })
    return r
  }
  if (method === 'POST' && /^builds\/\d+\/delete$/.test(r2)) {
    const mf = mfOf(db)
    const b = (mf.builds ?? []).find((x) => x.id === Number(parts[2]) && x.status === 'open')
    if (!b) throw new DemoError('Figur nicht (mehr) in der Sammlung oder gerade im Abschluss', 409)
    mf.builds.splice(mf.builds.indexOf(b), 1)
    log(me.id, 'minifig_build_delete', 'minifig', { figur: b.minifig_no, menge: b.qty })
    return { ok: true }
  }
  if (method === 'POST' && r2 === 'builds/start') {
    const mf = mfOf(db)
    if (!exportActive(db)) {
      if ((db.blExports ?? []).some((x) => x.open)) throw new DemoError('Gerade läuft eine BrickLink-Übertragung vom Umlagern. Bitte die erst abschließen („Ja, ist eingefügt“ oder „Verwerfen“).', 409)
      const open = (mf.builds ?? []).filter((b) => b.status === 'open')
      if (!open.length) throw new DemoError('In der Sammlung sind keine Figuren.', 400)
      for (const b of open) Object.assign(b, { status: 'export', export_by: me.id, export_at: now() })
      const r = buildExportFiles(db)
      log(me.id, 'minifig_build_export_start', 'minifig', { figuren: r.builds.length, lots: r.bricklink_lots })
      return r
    }
    return buildExportFiles(db)
  }
  if (method === 'GET' && r2 === 'builds/bsx') {
    const f = (mfOf(db).bsx ?? []).find((x) => x.id === Number(params.id))
    if (!f) throw new DemoError('BSX-Datei nicht gefunden', 404)
    const items = bsxMinus(db, f.items, f.id).filter((r) => r.quantity > 0).map((r) => ({ ItemTypeID: 'P', ItemID: r.item_no, ItemName: r.item_name, ColorID: String(r.color_id),
      ColorName: r.color_name, Qty: String(r.quantity), Price: String(r.price ?? 0), Condition: r.condition || 'U', Status: 'I', Remarks: r.remarks ?? '', Comments: r.comments ?? '' }))
    return file(`${f.name.replace(/\.bsx$/i, '')}-nach-figuren.bsx`, 'application/xml', buildBsx(items))
  }
  if (method === 'POST' && r2 === 'builds/finish') {
    const r = finishBuildExport(db, !!body.done, body.apply_bsx)
    log(me.id, r.done ? 'minifig_build_export_done' : 'minifig_build_export_verworfen', 'minifig', { figuren: r.figures, teile: r.parts, lots: r.lots, bsx: r.bsx_files.length })
    return r
  }
  if (method === 'GET' && r2 === 'bsx') return { files: bsxFiles(db) }
  if (method === 'POST' && r2 === 'bsx') {
    const f = body?.get?.('file')
    if (!f) throw new DemoError('Keine Datei übertragen', 400)
    let r
    try { r = addBsx(db, await f.text(), f.name, me) } catch (e) { throw e instanceof DemoError ? e : new DemoError(e.message, 422) }
    log(me.id, 'minifig_bsx_add', 'minifig', { datei: r.name, lots: r.lots })
    return r
  }
  if (method === 'POST' && /^bsx\/\d+\/delete$/.test(r2)) {
    const mf = mfOf(db)
    const id = Number(parts[2])
    const f = (mf.bsx ?? []).find((x) => x.id === id)
    if (!f) throw new DemoError('Datei nicht gefunden', 404)
    mf.bsx = mf.bsx.filter((x) => x.id !== id)
    log(me.id, 'minifig_bsx_delete', 'minifig', { datei: f.name })
    return { ok: true }
  }
  if (method === 'POST' && r2 === 'decision') {
    const r = decide(db, body.minifig_no, body.action, body.reason)
    log(me.id, 'minifig_decision', 'minifig', { figur: r.minifig_no, aktion: r.action, grund: r.reason })
    return r
  }
  if (method === 'GET' && r2 === 'export') {
    const kind = LIST_FILES[params.kind] ? params.kind : 'all'
    const r = analyze(db, 10000)
    return params.format === 'bsx' ? file(`minifig-${LIST_FILES[kind]}.bsx`, 'application/xml', listBsx(kind, r))
      : file(`minifig-${LIST_FILES[kind]}.csv`, 'text/csv;charset=utf-8', listCsv(kind, r))
  }
  if (method === 'GET' && r2 === 'export-all') {
    const fmt = params.format === 'bsx' ? 'bsx' : 'csv'
    const r = analyze(db, 10000)
    const files = Object.fromEntries(Object.entries(LIST_FILES).map(([kind, name]) => [`${name}.${fmt}`, fmt === 'bsx' ? listBsx(kind, r) : listCsv(kind, r)]))
    return file(`minifig-listen-${fmt}.zip`, 'application/zip', zip(files))
  }
  if (method === 'GET' && r2 === 'almost-joint-export') {
    const r = analyze(db, 10000)
    return params.format === 'bsx' ? file('minifig-fehlteile.bsx', 'application/xml', almostJointBsx(r)) : file('minifig-fehlteile.csv', 'text/csv;charset=utf-8', almostJointCsv(r))
  }
  if (method === 'GET' && r2 === 'prices/status') {
    const r = analyze(db, 10000)
    const rank = [...r.buildable, ...r.almost]
    return { ...priceStatus(db), rank_rows: rank.length, rank_figures_priced: rank.filter((x) => x.figure_value !== null).length, almost_rows: r.almost.length,
      almost_missing_priced: r.almost.filter((x) => x.completion_cost !== null).length, full_part_coverage: rank.filter((x) => x.price_coverage_pct === 100).length,
      candidate_minifigs: r.candidate_minifigs }
  }
  if (method === 'GET' && r2 === 'prices/export') return file('minifig-preispaket.bsx', 'application/xml', priceExport(db, analyze(db, 10000)))
  if (method === 'POST' && r2 === 'prices/import') {
    needDev()
    const f = body?.get?.('file')
    if (!f) throw new DemoError('Keine Datei übertragen', 400)
    let r
    try { r = priceImport(db, await f.text(), params.apply === '1', f.name) } catch (e) { throw e instanceof DemoError ? e : new DemoError(e.message, 400) }
    if (r.applied) log(me.id, 'minifig_price_import', 'minifig', { preise: r.imported_combinations })
    return r
  }
  if (method === 'GET' && r2 === 'transfer/export') {
    needDev()
    const mf = mfOf(db)
    return file('minifig-transfer.zip', 'application/zip', zip({ 'minifig-transfer.json': JSON.stringify({ format: 'tn-dashboard-minifig-transfer', version: 1,
      exported_at: now(), demo: { superset: mf.superset, catalog: mf.catalog, subsetState: mf.subsetState, decisions: mf.decisions }, price_guide: mf.prices }) }))
  }
  if (method === 'POST' && r2 === 'catalog/import') {
    // Wie Minifig::catalogImport: Katalogdatei (.json.gz), Ollies Transfer-ZIP oder unser Transferpaket. Datenbank-Dateien (.db, Installer-ZIP) liest nur der Server.
    needDev()
    const f = body?.get?.('file')
    if (!f) throw new DemoError('Keine Datei übertragen', 400)
    const bytes = new Uint8Array(await f.arrayBuffer())
    let data
    let quelle
    if (bytes[0] === 0x1f && bytes[1] === 0x8b) {
      data = JSON.parse(await gunzipText(bytes))
      if (data?.format !== 'tn-minifig-katalog') throw new DemoError('Keine gültige Katalogdatei.', 400)
      quelle = 'Katalogdatei'
    } else if (bytes[0] === 0x50 && bytes[1] === 0x4b) {
      const raw = await unzip(bytes, 'minifig-transfer.json')
      const p = raw ? JSON.parse(raw) : null
      if (p?.format === 'tn-dashboard-minifig-transfer') return { quelle: 'Transferpaket', ...importTransfer(db, me, log, p) }
      if (p?.format !== 'bi-legoshop-minifig-transfer') throw new DemoError('Datenbank-Dateien und Installer-ZIP liest nur der Server. In der Demo: Katalogdatei (.json.gz) oder Ollies Transfer-ZIP.', 400)
      const cache = (p.price_guide_cache ?? []).flatMap((r) => {
        let pl = null
        try { pl = JSON.parse(r.payload_json ?? '') } catch { /* ungültig */ }
        const avg = Number(pl?.avg_price || pl?.qty_avg_price || 0)
        return r.currency_code === 'EUR' && avg > 0 && ['M', 'MINIFIG', 'MINIFIGURE', 'P', 'PART'].includes(String(r.item_type).toUpperCase())
          ? [{ item_type: r.item_type, item_no: r.item_no, color_id: r.color_id ?? -1, new_or_used: r.condition || 'U', currency: 'EUR', avg_price: avg, source: pl.source ?? 'brickstore_import', fetched_at: r.fetched_at }] : []
      })
      data = { catalog: p.tables?.minifig_catalog ?? [], superset: p.tables?.minifig_superset_cache ?? [], price_guide: cache }
      quelle = 'Ollies Transfer-ZIP'
    } else {
      throw new DemoError('Datenbank-Dateien (.db) liest nur der Server. In der Demo: Katalogdatei (.json.gz) oder Ollies Transfer-ZIP.', 400)
    }
    const mf = mfOf(db)
    const t = now()
    const imported = { figuren: 0, supersets: 0, preise: 0 }
    for (const c of data.catalog ?? []) {
      if (!c.minifig_no || !c.subset_json) continue
      const row = { minifig_no: c.minifig_no, name: c.name ?? '', category_id: c.category_id ?? null, first_seen_at: c.first_seen_at ?? t, last_seen_at: c.last_seen_at ?? t,
        subset_fetched_at: c.subset_fetched_at ?? t, subset: JSON.parse(c.subset_json) }
      const i = mf.catalog.findIndex((x) => x.minifig_no === c.minifig_no)
      if (i >= 0) mf.catalog[i] = { ...mf.catalog[i], ...row, first_seen_at: mf.catalog[i].first_seen_at }
      else mf.catalog.push(row)
      imported.figuren++
    }
    for (const r of data.superset ?? []) {
      if (!r.item_no || typeof r.response_json !== 'string') continue
      mf.superset[key(r.item_no, r.color_id)] = { item_no: r.item_no, color_id: r.color_id, fetched_at: r.fetched_at ?? t, response: JSON.parse(r.response_json) }
      imported.supersets++
    }
    for (const r of data.price_guide ?? []) {
      if ((r.currency ?? 'EUR') !== 'EUR' || !(r.avg_price > 0) || !r.item_no) continue
      const same = (x) => x.item_type === r.item_type && x.item_no === r.item_no && x.color_id === (r.color_id ?? -1) && x.new_or_used === (r.new_or_used ?? 'U')
      mf.prices = mf.prices.filter((x) => !same(x))
      mf.prices.push({ item_type: r.item_type, item_no: r.item_no, color_id: r.color_id ?? -1, new_or_used: r.new_or_used ?? 'U', currency: 'EUR', avg_price: r.avg_price, source: r.source ?? 'katalog', fetched_at: r.fetched_at ?? t })
      imported.preise++
    }
    log(me.id, 'minifig_catalog_import', 'minifig', { quelle, ...imported })
    return { quelle, imported }
  }
  if (method === 'POST' && r2 === 'transfer/import') {
    needDev()
    const f = body?.get?.('file')
    if (!f) throw new DemoError('Keine Datei übertragen', 400)
    const raw = await unzip(new Uint8Array(await f.arrayBuffer()), 'minifig-transfer.json')
    return importTransfer(db, me, log, raw ? JSON.parse(raw) : null)
  }
  throw new DemoError('Nicht gefunden', 404)
}
