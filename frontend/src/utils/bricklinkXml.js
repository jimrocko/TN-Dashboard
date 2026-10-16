// BrickLink-XML aus offenen Umlagerungen (Antwort von GET bricklink/pending).
// Mass Update ändert bestehende Lose (per Lot-ID), Mass Upload legt neue an. Der Lagerort steht in den Remarks.
// Regeln von BrickLink: Menge nur relativ (+5 / -3), nie auf 0 oder darunter (dann <DELETE>Y</DELETE>),
// keine XML-Deklaration am Anfang, höchstens 200 KB.

// Seite „Upload/Update“ im BrickLink-Shop. Dort „Mass Update“ bzw. „Mass Upload“ wählen und einfügen.
export const BRICKLINK_XML_URL = 'https://www.bricklink.com/invXML.asp'

const ITEM_TYPES = { PART: 'P', MINIFIG: 'M', SET: 'S', BOOK: 'B', GEAR: 'G', CATALOG: 'C', INSTRUCTION: 'I', ORIGINAL_BOX: 'O' }
const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const tag = (name, v) => `<${name}>${esc(v)}</${name}>`
const wrap = (items) => (items.length ? `<INVENTORY>\n${items.map((i) => `<ITEM>${i}</ITEM>`).join('\n')}\n</INVENTORY>` : null)

/**
 * Alle Änderungen zusammenfassen: pro Lot-ID eine Zeile (Mengen addiert, letzter Lagerort, Löschen gewinnt).
 * Ergebnis: { update, upload } als XML-Text (oder null), dazu missing = Lose ohne BrickLink-Lot-ID.
 */
export function buildBrickLinkXml(transfers) {
  const lots = new Map()
  const uploads = []
  const missing = []
  for (const t of transfers) {
    for (const c of t.changes ?? []) {
      if (c.type === 'update') {
        const l = lots.get(c.lot_id) ?? { qty: 0, remarks: null, del: false }
        if (c.qty) l.qty += c.qty
        if (c.remarks) l.remarks = c.remarks
        if (c.delete) l.del = true
        lots.set(c.lot_id, l)
      } else if (c.type === 'upload') {
        uploads.push(c)
      } else if (c.type === 'fehlt') {
        missing.push(c)
      }
    }
  }
  const updateItems = []
  for (const [id, l] of lots) {
    if (l.del) { updateItems.push(tag('LOTID', id) + tag('DELETE', 'Y')); continue }
    let x = ''
    if (l.qty) x += tag('QTY', l.qty > 0 ? `+${l.qty}` : String(l.qty))
    if (l.remarks) x += tag('REMARKS', l.remarks)
    if (x) updateItems.push(tag('LOTID', id) + x)
  }
  const uploadItems = uploads.map((u) => tag('ITEMTYPE', ITEM_TYPES[u.item_type] ?? 'P') + tag('ITEMID', u.item_no) + tag('COLOR', u.color_id)
    + tag('PRICE', Number(u.price).toFixed(3)) + tag('QTY', u.qty) + tag('CONDITION', u.condition === 'N' ? 'N' : 'U') + tag('REMARKS', u.remarks))
  return { update: wrap(updateItems), upload: wrap(uploadItems), missing, updateCount: updateItems.length, uploadCount: uploadItems.length }
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
