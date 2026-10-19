// Dateien im Browser: BrickStore (.bsx) lesen/schreiben, ZIP packen/entpacken, Downloads.
// Gleiche Formate wie api/lib/Bsx.php, damit Demo und Server dieselben Dateien liefern.

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c])

/** BrickStore-Datei schreiben: Liste von { Tag: Wert }. */
export function buildBsx(items) {
  const body = items.map((it) => `<Item>${Object.entries(it).map(([k, v]) => `<${k}>${esc(v)}</${k}>`).join('')}</Item>`).join('')
  return `<?xml version="1.0" encoding="utf-8"?>\n<BrickStoreXML><Inventory Currency="EUR">${body}</Inventory></BrickStoreXML>\n`
}

/** BrickStore-Datei lesen (wie Bsx::parse): [Positionen, Währung]. */
export function parseBsx(text) {
  const doc = new DOMParser().parseFromString(text, 'application/xml')
  if (doc.querySelector('parsererror')) throw new Error('Datei ist keine gültige BrickStore-BSX/XML-Datei')
  const root = doc.documentElement
  const inv = ['BrickStoreXML', 'BrickStockXML'].includes(root.nodeName) ? root.querySelector(':scope > Inventory') : null
  if (!inv) throw new Error('BrickStore-Inventar wurde in der Datei nicht gefunden')
  const currency = (inv.getAttribute('Currency') || 'USD').toUpperCase()
  const rows = []
  ;[...inv.children].filter((n) => n.nodeName === 'Item').forEach((item, idx) => {
    const t = (tag, def = '') => { const n = [...item.children].find((c) => c.nodeName === tag); return n ? n.textContent.trim() : def }
    const no = t('ItemID')
    const type = t('ItemTypeID')
    const cond = t('Condition').toUpperCase() || 'N'
    if (!no || !type || !['N', 'U'].includes(cond)) return
    const qty = Math.trunc(Number(t('Qty', '0')) || 0)
    const status = t('Status', 'I').toUpperCase() || 'I'
    if (status === 'X' || qty <= 0) return
    const color = Number(t('ColorID', '-1'))
    rows.push({ item_type: type, item_no: no, color_id: Number.isFinite(color) ? Math.trunc(color) : -1, condition: cond, quantity: qty,
      price: Number(t('Price', '0')) || 0, lot_id: Number(t('LotID', '0')) || null, item_name: t('ItemName'), color_name: t('ColorName'), remarks: t('Remarks'), comments: t('Comments'), index: idx })
  })
  if (!rows.length) throw new Error('Keine verwendbaren Bestandspositionen in der BrickStore-Datei gefunden')
  return [rows, currency]
}

let crcTable
function crc32(bytes) {
  crcTable ??= Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
  let c = 0xffffffff
  for (const b of bytes) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

/** ZIP ohne Kompression (wie Bsx::zip). files = { name: Text oder Uint8Array } */
export function zip(files) {
  const enc = new TextEncoder()
  const parts = []
  const dir = []
  let offset = 0
  for (const [name, content] of Object.entries(files)) {
    const data = typeof content === 'string' ? enc.encode(content) : content
    const n = enc.encode(name)
    const crc = crc32(data)
    const head = new DataView(new ArrayBuffer(30))
    ;[[0, 0x04034b50, 4], [4, 20, 2], [6, 0x0800, 2], [8, 0, 2], [10, 0, 2], [12, 0x21, 2], [14, crc, 4], [18, data.length, 4], [22, data.length, 4], [26, n.length, 2], [28, 0, 2]]
      .forEach(([o, v, s]) => (s === 4 ? head.setUint32(o, v, true) : head.setUint16(o, v, true)))
    const cd = new DataView(new ArrayBuffer(46))
    ;[[0, 0x02014b50, 4], [4, 20, 2], [6, 20, 2], [8, 0x0800, 2], [10, 0, 2], [12, 0, 2], [14, 0x21, 2], [16, crc, 4], [20, data.length, 4], [24, data.length, 4],
      [28, n.length, 2], [30, 0, 2], [32, 0, 2], [34, 0, 2], [36, 0, 2], [38, 0, 4], [42, offset, 4]]
      .forEach(([o, v, s]) => (s === 4 ? cd.setUint32(o, v, true) : cd.setUint16(o, v, true)))
    parts.push(new Uint8Array(head.buffer), n, data)
    dir.push(new Uint8Array(cd.buffer), n)
    offset += 30 + n.length + data.length
  }
  const dirLen = dir.reduce((s, p) => s + p.length, 0)
  const end = new DataView(new ArrayBuffer(22))
  const count = Object.keys(files).length
  ;[[0, 0x06054b50, 4], [4, 0, 2], [6, 0, 2], [8, count, 2], [10, count, 2], [12, dirLen, 4], [16, offset, 4], [20, 0, 2]]
    .forEach(([o, v, s]) => (s === 4 ? end.setUint32(o, v, true) : end.setUint16(o, v, true)))
  return new Blob([...parts, ...dir, new Uint8Array(end.buffer)], { type: 'application/zip' })
}

/** Eine Datei aus einem ZIP lesen (unkomprimiert oder Deflate). */
export async function unzip(bytes, want) {
  const v = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
  let end = -1
  for (let i = bytes.length - 22; i >= 0; i--) if (v.getUint32(i, true) === 0x06054b50) { end = i; break }
  if (end < 0) throw new Error('Transferdatei ist kein gültiges ZIP.')
  let p = v.getUint32(end + 16, true)
  const dec = new TextDecoder()
  for (let i = 0; i < v.getUint16(end + 10, true); i++) {
    const method = v.getUint16(p + 10, true)
    const csize = v.getUint32(p + 20, true)
    const nlen = v.getUint16(p + 28, true)
    const xlen = v.getUint16(p + 30, true)
    const clen = v.getUint16(p + 32, true)
    const off = v.getUint32(p + 42, true)
    const name = dec.decode(bytes.subarray(p + 46, p + 46 + nlen))
    p += 46 + nlen + xlen + clen
    if (name !== want) continue
    const start = off + 30 + v.getUint16(off + 26, true) + v.getUint16(off + 28, true)
    const raw = bytes.subarray(start, start + csize)
    if (method === 0) return dec.decode(raw)
    if (method === 8) return new Response(new Blob([raw]).stream().pipeThrough(new DecompressionStream('deflate-raw'))).text()
    return null
  }
  return null
}

/** Datei im Browser speichern. */
export function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob)
  const a = Object.assign(document.createElement('a'), { href: url, download: name })
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
