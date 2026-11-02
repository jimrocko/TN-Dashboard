// BrickLink-XML nach dem Umlagern und nach Inventuren (Antwort von GET bricklink/changes): nur die Änderungen.
// Der ganze Bestand war für BrickLink zu groß (Grenze ca. 200 KB je Datei, Rückmeldung des Inhabers, Sitzung 8).
// - Update-Datei („Update Inventory“): je Lot Mengenänderung mit Vorzeichen, neuer Lagerort in den Remarks oder löschen,
//   wenn das Lot leer ist (BrickLink nimmt kein Minus auf Menge 0 an).
// - Upload-Datei („Upload“): neue Lots aus Teilmengen mit Teil, Farbe, Menge, Preis, Zustand und Kästchen.
// Sehr viele Änderungen werden auf mehrere Dateien unter 200 KB verteilt. BrickLink: keine XML-Deklaration am Anfang.

// Upload-Seite im BrickLink-Shop
export const BRICKLINK_XML_URL = 'https://www.bricklink.com/invXML.asp'

const ITEM_TYPES = { PART: 'P', MINIFIG: 'M', SET: 'S', BOOK: 'B', GEAR: 'G', CATALOG: 'C', INSTRUCTION: 'I', ORIGINAL_BOX: 'O' }
const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const tag = (name, v) => `<${name}>${esc(v)}</${name}>`

const MAX_BYTES = 190 * 1024 // etwas Luft unter BrickLinks Grenze von 200 KB
const bytes = (t) => new TextEncoder().encode(t).length
const signed = (n) => (n > 0 ? `+${n}` : String(n))

function updateItem(u) {
  if (u.delete) return `<ITEM>${tag('LOTID', u.lot_id)}${tag('DELETE', 'Y')}</ITEM>`
  return `<ITEM>${tag('LOTID', u.lot_id)}${u.qty ? tag('QTY', signed(u.qty)) : ''}${u.remarks !== undefined ? tag('REMARKS', u.remarks) : ''}</ITEM>`
}
function uploadItem(l) {
  return `<ITEM>${tag('ITEMTYPE', ITEM_TYPES[l.item_type] ?? 'P')}${tag('ITEMID', l.item_no)}${tag('COLOR', l.color_id)}${tag('QTY', l.qty)}`
    + `${tag('PRICE', Number(l.price).toFixed(3))}${tag('CONDITION', l.condition === 'N' ? 'N' : 'U')}${l.remarks ? tag('REMARKS', l.remarks) : ''}</ITEM>`
}
/** Zeilen auf Dateien unter MAX_BYTES verteilen: [{ xml, lots }] */
function split(items) {
  const files = []
  let cur = []
  let size = 0
  const head = bytes('<INVENTORY>\n\n</INVENTORY>')
  for (const it of items) {
    const n = bytes(it) + 1
    if (cur.length && head + size + n > MAX_BYTES) { files.push(cur); cur = []; size = 0 }
    cur.push(it)
    size += n
  }
  if (cur.length) files.push(cur)
  return files.map((f) => ({ xml: `<INVENTORY>\n${f.join('\n')}\n</INVENTORY>`, lots: f.length }))
}

/**
 * Dateien für BrickLink aus GET bricklink/changes, in der Reihenfolge zum Einfügen:
 * [{ kind: 'update' | 'upload', xml, lots, part, parts }]
 */
export function buildChangeFiles(changes) {
  const out = []
  for (const [kind, items] of [['update', changes.update.map(updateItem)], ['upload', changes.upload.map(uploadItem)]]) {
    const files = split(items)
    files.forEach((f, i) => out.push({ kind, ...f, part: i + 1, parts: files.length }))
  }
  return out
}

/**
 * Neue Lots für fertig gebaute Figuren (Minifig-Finder → „Figuren einlagern“), bei BrickLink unter „Upload“ einzufügen.
 * rows: [{ minifig_no, qty, price, condition: 'U'|'N', location }]
 */
export function buildNewFiguresXml(rows) {
  const items = rows.map((r) => `<ITEM>${tag('ITEMTYPE', 'M')}${tag('ITEMID', r.minifig_no)}${tag('COLOR', 0)}${tag('QTY', r.qty)}`
    + `${tag('PRICE', (Number(r.price) || 0).toFixed(3))}${tag('CONDITION', r.condition === 'N' ? 'N' : 'U')}${r.location ? tag('REMARKS', r.location) : ''}</ITEM>`)
  return `<INVENTORY>\n${items.join('\n')}\n</INVENTORY>\n`
}

/** In die Zwischenablage, ohne vorher zu warten: muss direkt im Tipp passieren (iPad-Safari). */
export function copyText(text) {
  try {
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).catch(() => fallbackCopy(text))
      return true
    }
  } catch { /* weiter mit dem alten Weg */ }
  return fallbackCopy(text)
}
function fallbackCopy(text) {
  const ta = Object.assign(document.createElement('textarea'), { value: text, readOnly: true })
  ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0'
  document.body.appendChild(ta)
  ta.select()
  ta.setSelectionRange(0, text.length)
  let ok = false
  try { ok = document.execCommand('copy') } catch { ok = false }
  ta.remove()
  return ok
}
