import { reactive } from 'vue'

// Großansicht eines Bildes (Wunsch des Inhabers, Sitzung 8: „alles klickbar, Bild groß“).
// Jedes PartImage öffnet sie beim Antippen; angezeigt wird sie von ImageZoom.vue (in App.vue).
export const zoom = reactive({ item: null })

export function openZoom(item) { zoom.item = item }
export function closeZoom() { zoom.item = null }

// BrickLink-Katalogseite passend zur Art (Teil mit Farbe, Minifigur, Set …)
const CAT = { MINIFIG: 'M', M: 'M', SET: 'S', S: 'S', GEAR: 'G', BOOK: 'B', INSTRUCTION: 'I', I: 'I' }
export function catalogUrl({ no, type, color }) {
  const k = CAT[String(type ?? '').toUpperCase()] ?? 'P'
  const c = k === 'P' && color !== undefined && color !== null ? `#T=C&C=${color}` : ''
  return `https://www.bricklink.com/v2/catalog/catalogitem.page?${k}=${encodeURIComponent(no)}${c}`
}
// Teile-Seite gibt es für Teile; Minifiguren und Sets haben dort meist nichts
export const hasPartPage = (type) => !CAT[String(type ?? '').toUpperCase()]
