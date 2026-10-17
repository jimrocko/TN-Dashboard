// Abzeichen (Wunsch des Inhabers, Sitzung 6): werden aus den eigenen Zahlen berechnet (GET achievements),
// nichts wird extra gespeichert. Neue Abzeichen einfach hier ergänzen: key = Zahl aus ActivityLog::stats, goal = Ziel.
import { Award, Boxes, ClipboardCheck, Crown, PackageCheck, RefreshCw, ScanSearch, Search, Sparkles, Sunrise, Upload, CalendarCheck, Medal, Trophy } from 'lucide-vue-next'

export const ACHIEVEMENTS = [
  { id: 'start', icon: Sparkles, title: 'Los geht’s', text: 'Die erste Aktion in der App', key: 'total', goal: 1 },
  { id: 'umlagern1', icon: RefreshCw, title: 'Umräumer', text: 'Das erste Lot umgelagert', key: 'transfers', goal: 1 },
  { id: 'umlagern25', icon: Boxes, title: 'Ordnungsliebe', text: '25 Lots umgelagert', key: 'transfers', goal: 25 },
  { id: 'umlagern100', icon: Trophy, title: 'Lagermeister', text: '100 Lots umgelagert', key: 'transfers', goal: 100 },
  { id: 'pick1', icon: PackageCheck, title: 'Erster Pick', text: 'Einen Auftrag fertig gepackt', key: 'picks', goal: 1 },
  { id: 'pick10', icon: Medal, title: 'Packprofi', text: '10 Aufträge gepackt', key: 'picks', goal: 10 },
  { id: 'pick50', icon: Crown, title: 'Pick-König', text: '50 Aufträge gepackt', key: 'picks', goal: 50 },
  { id: 'inventur1', icon: ClipboardCheck, title: 'Nachgezählt', text: 'Die erste Inventur abgeschlossen', key: 'stocktakes', goal: 1 },
  { id: 'inventur10', icon: Award, title: 'Zählkünstler', text: '10 Inventuren abgeschlossen', key: 'stocktakes', goal: 10 },
  { id: 'scan1', icon: ScanSearch, title: 'Teile-Detektiv', text: 'Ein Teil per Foto erkannt', key: 'scans', goal: 1 },
  { id: 'scan25', icon: ScanSearch, title: 'Sherlock Brick', text: '25 Teile per Foto erkannt', key: 'scans', goal: 25 },
  { id: 'export1', icon: Upload, title: 'BrickLink-Bote', text: 'Den Bestand zu BrickLink übertragen', key: 'exports', goal: 1 },
  { id: 'export10', icon: Upload, title: 'Shop-Pfleger', text: '10-mal zu BrickLink übertragen', key: 'exports', goal: 10 },
  { id: 'suche25', icon: Search, title: 'Suchmaschine', text: '25-mal gesucht', key: 'searches', goal: 25 },
  { id: 'tage5', icon: CalendarCheck, title: 'Stammgast', text: 'An 5 verschiedenen Tagen gearbeitet', key: 'days', goal: 5 },
  { id: 'tage20', icon: CalendarCheck, title: 'Dauerbrenner', text: 'An 20 verschiedenen Tagen gearbeitet', key: 'days', goal: 20 },
  { id: 'frueh', icon: Sunrise, title: 'Frühaufsteher', text: 'Vor 8 Uhr schon etwas erledigt', key: 'early', goal: 1 },
]

/** Alle Abzeichen mit Fortschritt für diese Zahlen (stats = null → alles noch offen). */
export const progress = (stats) => ACHIEVEMENTS.map((a) => {
  const have = stats?.[a.key] ?? 0
  return { ...a, have: Math.min(have, a.goal), done: have >= a.goal }
})

export const earnedCount = (stats) => ACHIEVEMENTS.filter((a) => (stats?.[a.key] ?? 0) >= a.goal).length
