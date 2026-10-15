import { Smile, ClipboardList, Boxes, RefreshCw, ScanBarcode, Truck, ClipboardCheck, BarChart3 } from 'lucide-vue-next'

// Zentrale Liste der Bereiche: Kacheln, XP-Desktop und Protokoll-Bezeichnungen kommen von hier.
// status: 'aktiv' = in dieser App, 'extern' = bestehendes Programm wird integriert, 'geplant' = kommt noch.
// tile(d) liefert die Statuszeile der Kachel aus den Dashboard-Daten.
const n = (v) => (v === undefined || v === null ? '–' : Number(v).toLocaleString('de-DE'))

export const modules = [
  { id: 'minifig', title: 'Minifig-Finder', icon: Smile, path: '/modul/minifig', status: 'extern', xpColor: '#e6b81e',
    text: 'Minifiguren und ihre Varianten schnell finden und anzeigen.', tile: () => 'Wird integriert (eigener Server)' },
  { id: 'pickliste', title: 'Pickliste', icon: ClipboardList, path: '/modul/pickliste', status: 'extern', xpColor: '#d04a3a',
    text: 'Bestellungen kommissionieren, Fehlmengen melden und Fortschritt verfolgen.', tile: () => 'Wird integriert' },
  { id: 'inventar', title: 'Inventar', icon: Boxes, path: '/inventar', status: 'aktiv', xpColor: '#e2932c',
    text: 'Bestände, Lagerorte und Bestandsverlauf verwalten.', tile: (d) => `Gesamtbestand: ${n(d?.inventar?.total_parts)} Teile` },
  { id: 'umlagern', title: 'Umlagern', icon: RefreshCw, path: '/umlagern', status: 'aktiv', xpColor: '#1d9fa8',
    text: 'Mischkisten analysieren, Sortierungsvorschläge erhalten und Bestände optimieren.', tile: (d) => `Offene Vorschläge: ${n(d?.umlagern?.suggestions)}` },
  { id: 'scanner', title: 'Teile-Scanner', icon: ScanBarcode, path: '/modul/scanner', status: 'geplant', xpColor: '#5d6b7a',
    text: 'Teile, Barcodes und Lagerplatzcodes scannen und direkt verarbeiten.', tile: () => 'Geplant' },
  { id: 'auftraege', title: 'Auftragszentrale', icon: Truck, path: '/auftraege', status: 'aktiv', xpColor: '#2b6fd6',
    text: 'Bestellungen importieren, verfolgen und Auftragsstatus prüfen.', tile: (d) => `Aktive Aufträge: ${n(d?.auftraege?.active)}` },
  { id: 'inventur', title: 'Inventur & Bestandsprüfung', icon: ClipboardCheck, path: '/inventur', status: 'aktiv', xpColor: '#3c9b3c',
    text: 'Tatsächliche Mengen überprüfen, Abweichungen aufklären und Bestände korrigieren.', tile: (d) => `Offene Prüfungen: ${n(d?.inventur?.open)}` },
  { id: 'analyse', title: 'Lager- & Auftragsanalyse', icon: BarChart3, path: '/analyse', status: 'aktiv', xpColor: '#8a4fc7',
    text: 'Engpässe, häufige Fehlmengen und wichtige Kennzahlen erkennen.', tile: (d) => `Letzte Analyse: ${d?.analyse?.last_run ?? '–'}` },
]

export const moduleById = (id) => modules.find((m) => m.id === id)

export const statusLabel = { aktiv: 'Aktiv', extern: 'Wird integriert', geplant: 'Geplant' }
