// Letzte Teileerkennung des Scanners, damit die Teile-Seite Bild, Sicherheit und Alternativen zeigen kann.
// Pro Browser-Tab gespeichert (sessionStorage), sonst nur im Arbeitsspeicher.
const KEY = 'tn-last-recognition'
let memory = null

export function rememberRecognition(items) {
  memory = { items, at: Date.now() }
  try { sessionStorage.setItem(KEY, JSON.stringify(memory)) } catch { /* nur im Arbeitsspeicher */ }
}

export function lastRecognition() {
  if (memory) return memory
  try { return JSON.parse(sessionStorage.getItem(KEY) || 'null') } catch { return null }
}
