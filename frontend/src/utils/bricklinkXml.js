// BrickLink-XML mit dem ganzen Bestand (Antwort von GET bricklink/stock), Wunsch des Inhabers:
// alle Lots mit der Menge nach dem Abziehen, Lagerort in den Remarks. Wird bei BrickLink unten beim Upload eingefügt.
// Ganz weggeräumte Lots stehen mit Menge 0 drin, damit sie bei BrickLink nicht stehen bleiben.
// Lots ohne BrickLink-Lot-ID (neu aus einer Teilmenge) kommen mit Teil, Farbe, Zustand und Preis.
// BrickLink: keine XML-Deklaration am Anfang, höchstens 200 KB pro Eintrag, sonst in Teile aufteilen.

// Upload-Seite im BrickLink-Shop
export const BRICKLINK_XML_URL = 'https://www.bricklink.com/invXML.asp'
export const MAX_BYTES = 204800

const ITEM_TYPES = { PART: 'P', MINIFIG: 'M', SET: 'S', BOOK: 'B', GEAR: 'G', CATALOG: 'C', INSTRUCTION: 'I', ORIGINAL_BOX: 'O' }
const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const tag = (name, v) => `<${name}>${esc(v)}</${name}>`
const bytes = (s) => new TextEncoder().encode(s).length

function item(l) {
  const remarks = l.code ? tag('REMARKS', l.code) : ''
  if (l.bl_lot_id) return `<ITEM>${tag('LOTID', l.bl_lot_id)}${tag('QTY', l.qty)}${remarks}</ITEM>`
  return `<ITEM>${tag('ITEMTYPE', ITEM_TYPES[l.item_type] ?? 'P')}${tag('ITEMID', l.item_no)}${tag('COLOR', l.color_id)}`
    + `${tag('QTY', l.qty)}${tag('PRICE', Number(l.price).toFixed(3))}${tag('CONDITION', l.bl_condition === 'N' ? 'N' : 'U')}${remarks}</ITEM>`
}

/** Ganzer Bestand als XML, aufgeteilt in Teile mit höchstens 200 KB. Ergebnis: [{ xml, lots }] */
export function buildStockXml(stock) {
  const items = [...stock.lots.map(item), ...stock.deleted.map((id) => `<ITEM>${tag('LOTID', id)}${tag('QTY', 0)}</ITEM>`)]
  const head = '<INVENTORY>\n'
  const foot = '</INVENTORY>'
  const parts = []
  let cur = []
  let size = bytes(head + foot)
  for (const it of items) {
    const n = bytes(it) + 1
    if (cur.length && size + n > MAX_BYTES) {
      parts.push(cur)
      cur = []
      size = bytes(head + foot)
    }
    cur.push(it)
    size += n
  }
  if (cur.length) parts.push(cur)
  return parts.map((p) => ({ xml: head + p.join('\n') + '\n' + foot, lots: p.length }))
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
