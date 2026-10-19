const nf = new Intl.NumberFormat('de-DE')
const ef = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })

export const num = (n) => (n === null || n === undefined ? '–' : nf.format(n))
export const eur = (n) => (n === null || n === undefined ? '–' : ef.format(n))
export const dateTime = (s) =>
  s ? new Date(s.replace(' ', 'T')).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '–'
export const date = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleDateString('de-DE') : '–')

export const orderStatusClass = (s) =>
  ({ Neu: 'accent', Bezahlt: 'warn', Verpackt: 'warn', Versandt: 'ok', Abgeschlossen: '' })[s] ?? ''

// Namen aus dem BrickLink-Katalog enthalten oft HTML-Zeichen wie „&#40;“ oder „&amp;“: als normalen Text anzeigen
const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' }
export const htmlText = (s) => String(s ?? '').replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => (e[0] === '#'
  ? String.fromCodePoint(e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10))
  : ENTITIES[e.toLowerCase()] ?? m))
