// Listen des Minifig-Finders als CSV oder BrickStore-Datei (gleiches Format wie Minifig::listCsv/listBsx in PHP).
// Wird vom Download-Fenster im Minifig-Finder und vom Demo-Backend benutzt. $sel = ausgewählte Figuren (z. B. Nr. 1–20).
import { buildBsx } from './files.js'
import { strCmp } from '../demo/rules.js'

export const LIST_FILES = {
  all: '01-baubar-und-1-teil-fehlt', buildable: '02-einzeln-baubar', joint: '03-gemeinsam-baubar',
  almost_joint: '04-gemeinsam-ergaenzbar-1-teil-fehlt', almost: '05-nur-1-teil-fehlt',
}

export const listRows = (kind, r) => ({ joint: r.joint_buildable, almost_joint: r.almost_joint_buildable, buildable: r.buildable, almost: r.almost }[kind]
  ?? [...r.buildable, ...r.almost])

export function exportRows(kind, r, sel = null) {
  if (!LIST_FILES[kind]) kind = 'all'
  const jointIds = new Set(r.joint_buildable.map((x) => x.minifig_no))
  const ajIds = new Set(r.almost_joint_buildable.map((x) => x.minifig_no))
  return (sel ?? listRows(kind, r)).map((row) => {
    const no = String(row.minifig_no)
    const status = ajIds.has(no) ? 'Gemeinsam ergänzbar · 1 Teil fehlt' : (row.missing ?? []).length ? 'Nur 1 Teil fehlt' : jointIds.has(no) ? 'Gemeinsam baubar' : 'Einzeln baubar'
    const miss = (row.missing ?? []).length === 1 ? row.missing[0] : null
    const locs = new Set()
    for (const p of row.pick_list ?? []) {
      if ((p.shortfall ?? 0) > 0) continue
      for (const s of p.sources ?? []) {
        // Teile aus BSX-Dateien: Dateiname · Remarks
        const l = [s.file, s.remarks || s.stock_room_id].map((x) => String(x ?? '').trim()).filter(Boolean).join(' · ')
        if (l) locs.add(`${p.item_no}: ${l}`)
      }
    }
    return { minifig_no: no, name: String(row.name), status, completeness: row.completeness ?? null, bom_part_count: row.bom_part_count ?? null,
      figure_value: row.figure_value ?? null, parts_value: row.parts_value ?? null, completion_cost: row.completion_cost ?? null, economic_advantage: row.economic_advantage ?? null,
      missing_item_no: miss?.item_no ?? '', missing_item_name: miss?.item_name ?? '', missing_color_id: miss?.color_id ?? null,
      missing_quantity: miss ? Math.max(0, miss.quantity - (miss.have ?? 0)) : '', locations: [...locs].join(' | ') }
  })
}

const csvCell = (v) => { const s = String(v ?? ''); return /[;"\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s }
export const csv = (header, rows) => '﻿' + [header, ...rows].map((r) => r.map(csvCell).join(';')).join('\r\n') + '\r\n'
export const eurCsv = (v) => (v === null || v === undefined ? '' : Number(v).toFixed(2).replace('.', ','))

export function listCsv(kind, r, sel = null) {
  return csv(['Minifigur', 'Name', 'Status', 'Vollständigkeit %', 'Teileanzahl', 'Figurenwert', 'Lagerteilewert', 'Fehlteilkosten', 'Wirtschaftlicher Vorteil',
    'Fehlteil', 'Fehlteilname', 'Farbe', 'Fehlmenge', 'Lagerorte'], exportRows(kind, r, sel).map((x) => [x.minifig_no, x.name, x.status, x.completeness ?? '',
    x.bom_part_count ?? '', eurCsv(x.figure_value), eurCsv(x.parts_value), eurCsv(x.completion_cost), eurCsv(x.economic_advantage), x.missing_item_no, x.missing_item_name,
    x.missing_color_id ?? '', x.missing_quantity, x.locations]))
}

export function listBsx(kind, r, sel = null) {
  return buildBsx(exportRows(kind, r, sel).map((x) => ({ ItemTypeID: 'M', ItemID: x.minifig_no, ItemName: x.name, ColorID: '0', Qty: '1', Price: '0', Condition: 'U', Status: 'I',
    Remarks: x.status + (x.missing_item_no ? ` | Fehlteil: ${x.missing_item_no} Farbe ${x.missing_color_id}` : '') })))
}

// Fehlteile der „gemeinsam ergänzbaren“ Figuren (wie Minifig::almostJointMissingRows)
export function almostJointMissingRows(r, figs = null) {
  return (figs ?? r.almost_joint_buildable).flatMap((f) => f.missing.map((m) => ({ minifig_no: String(f.minifig_no), minifig_name: String(f.name), item_no: String(m.item_no),
    item_name: m.item_name || String(m.item_no), color_id: m.color_id, quantity: Math.max(1, Math.max(0, m.quantity) - Math.max(0, m.have ?? 0)),
    estimated_total: f.completion_cost, figure_value: f.figure_value, economic_advantage: f.economic_advantage })))
}

export function almostJointCsv(r, figs = null) {
  return csv(['Minifigur', 'Name', 'Fehlteil', 'Teilname', 'Farbe', 'Menge', 'geschätzte Fehlteilkosten', 'Figurenwert', 'Vorteil nach Zukauf'],
    almostJointMissingRows(r, figs).map((x) => [x.minifig_no, x.minifig_name, x.item_no, x.item_name, x.color_id, x.quantity, eurCsv(x.estimated_total), eurCsv(x.figure_value), eurCsv(x.economic_advantage)]))
}

export function almostJointBsx(r, figs = null) {
  const agg = new Map()
  for (const x of almostJointMissingRows(r, figs)) {
    const k = `${x.item_no}\u0000${String(x.color_id).padStart(10, '0')}`
    if (!agg.has(k)) agg.set(k, { ...x, quantity: 0, figures: [] })
    agg.get(k).quantity += x.quantity
    agg.get(k).figures.push(x.minifig_no)
  }
  return buildBsx([...agg.keys()].sort(strCmp).map((k) => agg.get(k)).map((x) => ({ ItemTypeID: 'P', ItemID: x.item_no,
    ItemName: x.item_name, ColorID: String(x.color_id), Qty: String(x.quantity), Price: '0', Condition: 'U', Status: 'I', Remarks: `Minifig Finder: ${x.figures.join(', ')}` })))
}
