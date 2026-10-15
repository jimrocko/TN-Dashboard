// Bereiche im Lagerzimmer (Skizze des Inhabers): Kästchen-Codes wie G245, H012, N005, FT3, Plate2.
// G, H und N haben immer drei Stellen (N005), FG/FN/FT/Plate ohne führende Nullen.
// G = gebraucht (linke Wand), H (rechte Wand oben), N = neu (rechte Wand unten),
// FG/FN/FT/Plate = Figuren gebraucht/neu, Figurenteile, Plates (unten links). Mischkisten (MK-…) liegen außerhalb des Plans.
export const AREAS = [
  { id: 'G', label: 'Gebraucht', color: '#3f8f3a', from: 100, to: 999, digits: 3 },
  { id: 'H', label: 'Regal H', color: '#2f63d8', from: 0, to: 999, digits: 3 },
  { id: 'N', label: 'Neu', color: '#e3b81c', from: 0, to: 999, digits: 3 },
  { id: 'FG', label: 'Figuren gebraucht', color: '#c8412f', group: 'F' },
  { id: 'FN', label: 'Figuren neu', color: '#c8412f', group: 'F' },
  { id: 'FT', label: 'Figurenteile', color: '#c8412f', group: 'F' },
  { id: 'Plate', label: 'Plates', color: '#c8412f', group: 'F' },
]

const CODE = /^(FG|FN|FT|Plate|G|H|N)(\d+)$/i

/** Baut den Code eines Kästchens: ('N', 5) → 'N005', ('FT', 3) → 'FT3'. */
export function formatCode(areaId, n) {
  const digits = AREAS.find((a) => a.id === areaId)?.digits ?? 0
  return `${areaId}${String(n).padStart(digits, '0')}`
}

/** Zerlegt einen Lagerort-Code: G245 → { area: 'G', num: 245 }, N005 → { area: 'N', num: 5 }; Mischkisten → { area: 'MK' }; sonst null. */
export function parseCode(code) {
  const m = CODE.exec(String(code ?? ''))
  if (m) {
    const area = AREAS.find((a) => a.id.toLowerCase() === m[1].toLowerCase())
    return { area: area.id, num: Number(m[2]) }
  }
  if (/^MK/i.test(String(code ?? ''))) return { area: 'MK', num: null }
  return null
}

export const areaById = (id) => AREAS.find((a) => a.id === id)
