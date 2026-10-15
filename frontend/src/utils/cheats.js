// Versteckte Stil-Umschaltung zwischen Windows-XP-Optik und der modernen Variante.
// Codes werden im Dev-Bereich eingegeben (Versionszeile 5× antippen) oder am PC per Konami-Code.
export const CHEATS = {
  WINXP: 'xp',
  BLISS: 'xp',
  MODERN: 'modern',
  NEUSTART: 'modern',
}

// XP startet in der klassischen blauen Optik, das normale Design wieder dunkel.
const look = (style) => ({ style, theme: style === 'xp' ? 'light' : 'dark' })

/** Zurück zum normalen Design (Ausschalten-Knopf, Zeitablauf, Ab-/Anmelden). */
export function leaveXp(settings) {
  if (settings.style === 'xp') settings.set(look('modern'))
  try { localStorage.removeItem(XP_SEEN) } catch { /* egal */ }
}

// XP schaltet sich nach 15 Minuten ohne Bedienung von selbst ab. Zeitpunkt der letzten Bedienung (auch über Neuladen hinweg).
export const XP_IDLE_MS = 15 * 60 * 1000
const XP_SEEN = 'tn-xp-seen'
export function xpTouch() {
  try { localStorage.setItem(XP_SEEN, String(Date.now())) } catch { /* egal */ }
}
export function xpIdleFor() {
  try { return Date.now() - Number(localStorage.getItem(XP_SEEN) || Date.now()) } catch { return 0 }
}

export function applyCheat(code, settings) {
  const style = CHEATS[String(code).trim().toUpperCase()]
  if (!style) return null
  settings.set(look(style))
  if (style === 'xp') xpTouch()
  return style
}

const KONAMI = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a']

/** ↑ ↑ ↓ ↓ ← → ← → B A wechselt den Stil hin und her. */
export function listenKonami(settings) {
  let pos = 0
  window.addEventListener('keydown', (e) => {
    const key = e.key.length === 1 ? e.key.toLowerCase() : e.key
    pos = key === KONAMI[pos] ? pos + 1 : key === KONAMI[0] ? 1 : 0
    if (pos === KONAMI.length) {
      pos = 0
      settings.set(look(settings.style === 'xp' ? 'modern' : 'xp'))
      if (settings.style === 'xp') xpTouch()
    }
  })
}
