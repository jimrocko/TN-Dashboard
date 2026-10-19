import { reactive } from 'vue'

// Ladeanzeige beim Öffnen eines Bereichs (Wunsch des Inhabers, Sitzung 7): zählt die Abrufe, die kurz nach einem
// Seitenwechsel starten. Spätere Abrufe (z. B. das sekündliche Nachfragen der Pickliste) zählen nicht mit.
export const pageLoading = reactive({ pending: 0, navAt: -1e9 })

export const markNavigation = () => { pageLoading.navAt = performance.now() }

/** Abruf mitzählen, wenn er bis 1,5 s nach dem Seitenwechsel startet. */
export function trackLoad(promise) {
  if (performance.now() - pageLoading.navAt > 1500) return promise
  pageLoading.pending++
  return promise.finally(() => { pageLoading.pending-- })
}
