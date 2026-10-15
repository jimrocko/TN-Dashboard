import {
  LayoutDashboard, Search, Boxes, ArrowLeftRight, ClipboardCheck, Truck, BarChart3, ScanBarcode, Smile, ClipboardList,
} from 'lucide-vue-next'

// Zentrale Liste der Bereiche: Navigation, Übersicht und Protokoll-Bezeichnungen kommen von hier.
// status: 'aktiv' = in dieser App, 'extern' = bestehendes Programm wird integriert, 'geplant' = kommt noch.
export const modules = [
  { id: 'inventar', title: 'Inventar', icon: Boxes, path: '/inventar', status: 'aktiv', group: 'Lager',
    text: 'Bestände, Lagerorte und Lose durchsuchen und filtern.' },
  { id: 'umlagern', title: 'Umlagern', icon: ArrowLeftRight, path: '/umlagern', status: 'aktiv', group: 'Lager',
    text: 'Mischkisten auflösen, Vorschläge übernehmen, Teile umbuchen.' },
  { id: 'inventur', title: 'Inventur', icon: ClipboardCheck, path: '/inventur', status: 'aktiv', group: 'Lager',
    text: 'Lagerorte zählen, Abweichungen erfassen und korrigieren.' },
  { id: 'auftraege', title: 'Auftragszentrale', icon: Truck, path: '/auftraege', status: 'aktiv', group: 'Verkauf',
    text: 'Bestellungen verfolgen und den Status weiterschalten.' },
  { id: 'analyse', title: 'Analyse', icon: BarChart3, path: '/analyse', status: 'aktiv', group: 'Auswertung',
    text: 'Bestand nach Kategorie, Lagerauslastung, Engpässe.' },
  { id: 'minifig', title: 'Minifig-Finder', icon: Smile, path: '/modul/minifig', status: 'extern', group: 'In Integration',
    text: 'Bestehender Minifig-Finder (eigener Server) wird eingebunden.' },
  { id: 'pickliste', title: 'Pickliste', icon: ClipboardList, path: '/modul/pickliste', status: 'extern', group: 'In Integration',
    text: 'Bestehende Pickliste wird eingebunden.' },
  { id: 'scanner', title: 'Teile-Scanner', icon: ScanBarcode, path: '/modul/scanner', status: 'geplant', group: 'In Integration',
    text: 'Teile, Barcodes und Lagerplatzcodes per Kamera scannen.' },
]

export const navTop = [
  { title: 'Übersicht', icon: LayoutDashboard, path: '/' },
  { title: 'Suche', icon: Search, path: '/suche' },
]

export const moduleById = (id) => modules.find((m) => m.id === id)

export const statusLabel = { aktiv: 'Aktiv', extern: 'Wird integriert', geplant: 'Geplant' }
