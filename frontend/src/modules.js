import {
  Smile, ClipboardList, Box, RefreshCw, ScanBarcode, Truck, ClipboardCheck, BarChart3,
} from 'lucide-vue-next'

// Zentrale Beschreibung aller Module. Dashboard-Kacheln und Platzhalterseiten werden daraus erzeugt.
export const modules = [
  {
    id: 'minifig', title: 'Minifig-Finder', icon: Smile,
    text: 'Minifiguren und ihre Varianten schnell finden und anzeigen.',
    status: () => 'z. B. twn146, Figurenname …',
    planned: ['Suche nach Minifig-Nummer und Name', 'Varianten und Bestandteile anzeigen', 'Lagerort und Bestand der Figur'],
  },
  {
    id: 'pickliste', title: 'Pickliste', icon: ClipboardList,
    text: 'Bestellungen kommissionieren, Fehlmengen melden und Fortschritt verfolgen.',
    status: (d) => `Offene Picklisten: ${d?.pickliste?.open ?? '–'}`,
    planned: ['Picklisten aus BrickLink-Bestellungen erzeugen', 'Positionen nach Lagerort sortiert abhaken', 'Fehlmengen melden'],
  },
  {
    id: 'inventar', title: 'Inventar', icon: Box,
    text: 'Bestände, Lagerorte und Bestandsverlauf verwalten.',
    status: (d) => `Gesamtbestand: ${d?.inventar?.total_parts?.toLocaleString('de-DE') ?? '–'} Teile`,
    planned: ['BrickLink-Inventar abgleichen (gecacht)', 'BrickStore-Dateien (.bsx) importieren', 'Bestandsverlauf je Teil'],
  },
  {
    id: 'umlagern', title: 'Umlagern', icon: RefreshCw,
    text: 'Mischkisten analysieren, Sortierungsvorschläge erhalten und Bestände optimieren.',
    status: (d) => `Offene Vorschläge: ${d?.umlagern?.suggestions ?? '–'}`,
    planned: ['Umlagerung von Lagerort A nach B buchen', 'Mischkisten erkennen', 'Automatische Sortierungsvorschläge'],
  },
  {
    id: 'scanner', title: 'Teile-Scanner', icon: ScanBarcode,
    text: 'Teile, Barcodes und Lagerplatzcodes scannen und direkt verarbeiten.',
    status: () => 'Scanner bereit', statusDot: true,
    planned: ['Barcode- und QR-Scan über die iPad-Kamera', 'Lagerplatzcodes erkennen', 'Import von Brickscanner-Listen'],
  },
  {
    id: 'auftraege', title: 'Auftragszentrale', icon: Truck,
    text: 'Bestellungen importieren, verfolgen und Auftragsstatus prüfen.',
    status: (d) => `Aktive Aufträge: ${d?.auftraege?.active ?? '–'}`,
    planned: ['BrickLink-Bestellungen abrufen', 'Status verfolgen', 'Aus Auftrag eine Pickliste erzeugen'],
  },
  {
    id: 'inventur', title: 'Inventur & Bestandsprüfung', icon: ClipboardCheck,
    text: 'Tatsächliche Mengen überprüfen, Abweichungen aufklären und Bestände korrigieren.',
    status: (d) => `Offene Prüfungen: ${d?.inventur?.open ?? '–'}`,
    planned: ['Zählaufträge je Lagerort', 'Abweichungen dokumentieren', 'Korrekturen buchen'],
  },
  {
    id: 'analyse', title: 'Lager- & Auftragsanalyse', icon: BarChart3,
    text: 'Engpässe, häufige Fehlmengen und wichtige Kennzahlen erkennen.',
    status: (d) => `Letzte Analyse: ${d?.analyse?.last_run ?? '–'}`,
    planned: ['Häufige Fehlmengen', 'Lagerauslastung', 'Kennzahlen pro Teilnehmer'],
  },
]

export const moduleById = (id) => modules.find((m) => m.id === id)
