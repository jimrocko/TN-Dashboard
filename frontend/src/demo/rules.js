// Rechenregeln wie im Python-Original (bi-legoshop-dashboard), gleich in api/lib/Picking.php und Minifig.php.

/** Natürliche Reihenfolge wie _natural(): Zahlenblöcke als Zahl, Text klein geschrieben (G29 vor G100). */
export function naturalCmp(a, b) {
  const pa = String(a ?? '').split(/(\d+)/)
  const pb = String(b ?? '').split(/(\d+)/)
  const n = Math.min(pa.length, pb.length)
  for (let i = 0; i < n; i++) {
    let c
    if (i % 2) {
      const x = pa[i].replace(/^0+/, '')
      const y = pb[i].replace(/^0+/, '')
      c = x.length - y.length || (x < y ? -1 : x > y ? 1 : 0)
    } else {
      const x = pa[i].toLowerCase()
      const y = pb[i].toLowerCase()
      c = x < y ? -1 : x > y ? 1 : 0
    }
    if (c) return c < 0 ? -1 : 1
  }
  return pa.length - pb.length
}

/** Runden wie Python round(): auf Basis des exakten Dezimalwerts, exakte Hälften zur geraden Zahl. */
export function pyRound(x, nd = 0) {
  if (!Number.isFinite(x)) return x
  const neg = x < 0
  const [int, frac] = Math.abs(x).toFixed(60).split('.')
  let n = BigInt(int + frac.slice(0, nd))
  const rest = frac.slice(nd)
  const first = Number(rest[0] ?? 0)
  const tail = rest.slice(1).replace(/0+$/, '')
  if (first > 5 || (first === 5 && tail !== '') || (first === 5 && tail === '' && n % 2n === 1n)) n += 1n
  const r = Number(n) / 10 ** nd
  return neg ? -r : r
}

/** Tupel vergleichen wie in Python (für Sortierschlüssel). Booleans zählen als 0/1. */
export function cmpTuple(a, b) {
  for (let i = 0; i < a.length; i++) {
    const x = typeof a[i] === 'boolean' ? +a[i] : a[i]
    const y = typeof b[i] === 'boolean' ? +b[i] : b[i]
    if (x < y) return -1
    if (x > y) return 1
  }
  return 0
}

/** Byte-Vergleich von Texten (wie strcmp in PHP bzw. Python-Strings). */
export const strCmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0)

/** Summe nach Kahan-Babuška-Neumaier (wie SUM() in SQLite). */
export function kbnAdd(acc, x) {
  const t = acc.s + x
  acc.e += Math.abs(acc.s) > Math.abs(x) ? (acc.s - t) + x : (x - t) + acc.s
  acc.s = t
}
