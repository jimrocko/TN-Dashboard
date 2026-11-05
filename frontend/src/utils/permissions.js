// Berechtigungen je Teilnehmer (gleiche Liste wie Perm::KEYS in api/lib/Perm.php). Lehrer haben immer alles.
export const PERM_KEYS = [
  ['see_inventar', 'Bereiche sehen', 'Inventar'],
  ['see_umlagern', 'Bereiche sehen', 'Umlagern'],
  ['see_inventur', 'Bereiche sehen', 'Inventur'],
  ['see_analyse', 'Bereiche sehen', 'Analyse'],
  ['see_pickliste', 'Bereiche sehen', 'Pickliste'],
  ['see_minifig', 'Bereiche sehen', 'Minifig-Finder'],
  ['umlagern_buchen', 'Umlagern', 'Umlagern (Vorschläge übernehmen, umbuchen)'],
  ['inventur_zaehlen', 'Inventur', 'Inventur starten und zählen'],
  ['inventur_abschliessen', 'Inventur', 'Inventur abschließen'],
  ['pick_picken', 'Pickliste', 'Picken und Markieren'],
  ['pick_melden', 'Pickliste', 'Probleme melden und schließen'],
  ['pick_abschliessen', 'Pickliste', 'Bestellung abschließen / Status ändern'],
  ['minifig_bauen', 'Minifig-Finder', '„Fertig gebaut“, Teile ändern, „Nicht bauen“'],
  ['minifig_fehlt', 'Minifig-Finder', '„Fehlt“ (Bestand abziehen)'],
  ['minifig_abschluss', 'Minifig-Finder', 'Sammlung abschließen'],
  ['minifig_dateien', 'Minifig-Finder', 'BSX-Dateien hochladen und löschen'],
  ['minifig_einstellungen', 'Minifig-Finder', 'Quelle und Reihenfolge ändern'],
  ['bricklink_uebertragen', 'BrickLink', 'Übertragung zu BrickLink starten und bestätigen'],
]
export const PERM_LABEL = Object.fromEntries(PERM_KEYS.map(([k, , l]) => [k, l]))
export const cleanPerms = (p = {}) => Object.fromEntries(PERM_KEYS.map(([k]) => [k, k in p ? !!p[k] : true]))

// Welche Schreib-Route welches Recht braucht (wie require_perm in api/index.php)
export function permForRoute(method, parts) {
  if (method !== 'POST') return null
  const [a, b, c, d] = parts
  const id = (x) => /^\d+$/.test(x ?? '')
  if (a === 'orders' && id(b) && c === 'status') return 'pick_abschliessen'
  if (a === 'transfers' && parts.length === 1) return 'umlagern_buchen'
  if (a === 'bricklink' && (b === 'start' || b === 'finish' || b === 'dismiss')) return 'bricklink_uebertragen'
  if (a === 'stocktakes' && parts.length === 1) return 'inventur_zaehlen'
  if (a === 'stocktakes' && id(b) && c === 'count') return 'inventur_zaehlen'
  if (a === 'stocktakes' && id(b) && c === 'close') return 'inventur_abschliessen'
  if (a === 'picking' && b === 'orders' && id(c) && (d === 'complete' || d === 'reopen')) return 'pick_abschliessen'
  if (a === 'picking' && b === 'items' && id(c)) return d === 'reports' ? 'pick_melden' : (d === 'toggle' || d === 'claim') ? 'pick_picken' : null
  if (a === 'picking' && b === 'reports' && id(c) && d === 'resolve') return 'pick_melden'
  if (a === 'minifig') {
    if (b === 'source' || b === 'priority') return 'minifig_einstellungen'
    if (b === 'missing') return 'minifig_fehlt'
    if (b === 'decision') return 'minifig_bauen'
    if (b === 'bsx' && (parts.length === 2 || (id(c) && d === 'delete'))) return 'minifig_dateien'
    if (b === 'builds' && (c === 'start' || c === 'finish')) return 'minifig_abschluss'
    if (b === 'builds' && (parts.length === 2 || (id(c) && (d === 'lots' || d === 'delete')))) return 'minifig_bauen'
  }
  return null
}
