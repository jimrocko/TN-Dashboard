import { Globe, Mail, CalendarDays, FileText, Trash2 } from 'lucide-vue-next'

// Spaß-Apps im XP-Stil (nur dort sichtbar, wie der XP-Stil selbst geheim). Alles erfunden, nichts wird gekauft oder verschickt.
export const xpApps = [
  { id: 'xp-internet', title: 'Internet', icon: Globe, path: '/xp/internet', xpColor: '#2b6fd6' },
  { id: 'xp-mail', title: 'E-Mail', icon: Mail, path: '/xp/mail', xpColor: '#d8a21b' },
  { id: 'xp-kalender', title: 'Kalender', icon: CalendarDays, path: '/xp/kalender', xpColor: '#c0392b' },
  { id: 'xp-editor', title: 'Editor', icon: FileText, path: '/xp/editor', xpColor: '#5d7a99' },
  { id: 'xp-papierkorb', title: 'Papierkorb', icon: Trash2, path: '/xp/papierkorb', xpColor: '#6f7d84' },
]
