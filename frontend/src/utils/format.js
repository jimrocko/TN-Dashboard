const nf = new Intl.NumberFormat('de-DE')
const ef = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })

export const num = (n) => (n === null || n === undefined ? '–' : nf.format(n))
export const eur = (n) => (n === null || n === undefined ? '–' : ef.format(n))
export const dateTime = (s) =>
  s ? new Date(s.replace(' ', 'T')).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '–'
export const date = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleDateString('de-DE') : '–')

export const orderStatusClass = (s) =>
  ({ Neu: 'accent', Bezahlt: 'warn', Verpackt: 'warn', Versandt: 'ok', Abgeschlossen: '' })[s] ?? ''
