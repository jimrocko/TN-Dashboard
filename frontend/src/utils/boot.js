import { reactive } from 'vue'
import { appSettings } from './appSettings'
import { IS_TUTORIAL } from '../api'

// Startanimation (Wunsch des Inhabers): beim ersten Öffnen des Tages auf diesem Gerät, vor dem Login.
// An/Aus pro Gerät in Dev-Bereich → Einstellungen (appSettings.start.boot), dort auch „Jetzt abspielen“.
const KEY = 'tn-boot-tag'
const today = () => { const d = new Date(); return `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}` }

export const boot = reactive({ show: false, run: 0 })

/** Beim Laden der App: heute schon gezeigt? Sonst einmal abspielen und den Tag merken. */
export function bootOnStart() {
  if (IS_TUTORIAL || !appSettings.start.boot) return
  try {
    if (localStorage.getItem(KEY) === today()) return
    localStorage.setItem(KEY, today())
  } catch { return } // ohne Browser-Speicher lieber gar nicht, sonst käme sie bei jedem Laden
  playBoot()
}

/** Sofort abspielen (Test-Knopf im Dev-Bereich). */
export function playBoot() { boot.run++; boot.show = true }
export const closeBoot = () => { boot.show = false }
