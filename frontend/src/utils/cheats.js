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

export function applyCheat(code, settings) {
  const style = CHEATS[String(code).trim().toUpperCase()]
  if (!style) return null
  settings.set(look(style))
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
    }
  })
}
