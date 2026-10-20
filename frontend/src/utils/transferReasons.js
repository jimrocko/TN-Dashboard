import { num } from './format'

// Arten der Umlagerungsvorschläge (Shop::suggestions / demo/shop.js): Name, Farbe (Pill/Punkt) und kurzer Hinweis.
export const REASONS = {
  falsch: 'Falsch einsortiert',
  farben: 'Farben zusammenlegen',
  art: 'Gleiche Teile-Art',
  bereich: 'Falscher Bereich',
  mischkiste: 'Mischkiste aufteilen',
  rest: 'Kästchen leeren',
  trennen: 'Verwechselbares trennen',
}
export const REASON_CLASS = { falsch: 'warn', farben: 'accent', art: 'ok', bereich: 'danger', mischkiste: 'danger', rest: '', trennen: 'warn' }

/** Kurze Zusatzinfo je Vorschlag, z. B. „am Ziel schon 6 Stück“ oder „verwechselbar mit 3001 Light Gray“. */
export function reasonHint(s) {
  if (s.reason === 'falsch') return `am Ziel schon ${num(s.target_qty)} Stück`
  if (s.reason === 'bereich') return s.condition === 'N' ? 'neues Teil im Gebraucht-Bereich' : 'gebrauchtes Teil im Neu-Bereich'
  if (s.reason === 'mischkiste') return `${s.from_mixed ?? s.lot.location_code} hat mehr als 3 Lots`
  if (s.reason === 'rest') return `nur ${num(s.lot.qty)} Stück im Kästchen, danach ist es frei`
  if (s.reason === 'trennen') return `verwechselbar mit ${s.confused_with}`
  return ''
}
