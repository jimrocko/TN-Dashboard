// BrickLink-XML mit dem ganzen Bestand (Antwort von GET bricklink/stock), Wunsch des Inhabers:
// alle Lots mit der Menge nach dem Abziehen, Lagerort in den Remarks. Wird bei BrickLink unten beim Upload eingefügt.
// Ganz weggeräumte Lots stehen mit Menge 0 drin, damit sie bei BrickLink nicht stehen bleiben.
// Lots ohne BrickLink-Lot-ID (neu aus einer Teilmenge) kommen mit Teil, Farbe, Zustand und Preis.
// BrickLink: keine XML-Deklaration am Anfang. Immer eine einzige XML, auch bei großem Bestand
// (Inhaber: geht in einem Stück wie beim BrickStore-Export).

// Upload-Seite im BrickLink-Shop
export const BRICKLINK_XML_URL = 'https://www.bricklink.com/invXML.asp'

const ITEM_TYPES = { PART: 'P', MINIFIG: 'M', SET: 'S', BOOK: 'B', GEAR: 'G', CATALOG: 'C', INSTRUCTION: 'I', ORIGINAL_BOX: 'O' }
const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const tag = (name, v) => `<${name}>${esc(v)}</${name}>`

function item(l) {
  const remarks = l.code ? tag('REMARKS', l.code) : ''
  if (l.bl_lot_id) return `<ITEM>${tag('LOTID', l.bl_lot_id)}${tag('QTY', l.qty)}${remarks}</ITEM>`
  return `<ITEM>${tag('ITEMTYPE', ITEM_TYPES[l.item_type] ?? 'P')}${tag('ITEMID', l.item_no)}${tag('COLOR', l.color_id)}`
    + `${tag('QTY', l.qty)}${tag('PRICE', Number(l.price).toFixed(3))}${tag('CONDITION', l.bl_condition === 'N' ? 'N' : 'U')}${remarks}</ITEM>`
}

/** Ganzer Bestand als eine XML. Ergebnis als Liste mit einem Eintrag: [{ xml, lots }] */
export function buildStockXml(stock) {
  const items = [...stock.lots.map(item), ...stock.deleted.map((id) => `<ITEM>${tag('LOTID', id)}${tag('QTY', 0)}</ITEM>`)]
  return [{ xml: `<INVENTORY>\n${items.join('\n')}\n</INVENTORY>`, lots: items.length }]
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
