import { reactive, watch } from 'vue'

// Einstellungen je Bereich (Dev-Bereich → Einstellungen). Gelten nur auf diesem Gerät.
// Im Tutorial immer die Standardwerte, damit Klemmis Schritte zur Ansicht passen.
const TUTORIAL = import.meta.env.MODE === 'tutorial'
const KEY = 'tn-app-settings'

export const appDefaults = {
  inventar: { limit: 500, sort: 'item', plan: 'merken' },
  umlagern: { page: 30, reason: 'alle', history: 15, popup: true, plan: true },
  inventur: { blind: false },
  analyse: { metric: 'qty' },
}

const clone = (o) => JSON.parse(JSON.stringify(o))

function read() {
  const s = clone(appDefaults)
  if (TUTORIAL) return s
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) || '{}')
    for (const id of Object.keys(s)) Object.assign(s[id], saved[id] ?? {})
  } catch { /* Standardwerte */ }
  return s
}

/** appSettings.<bereich>.<schlüssel>, z. B. appSettings.umlagern.page */
export const appSettings = reactive(read())
if (!TUTORIAL) {
  watch(appSettings, (v) => { try { localStorage.setItem(KEY, JSON.stringify(v)) } catch { /* nur für diese Sitzung */ } }, { deep: true })
}

/** Einen Bereich (oder alle ohne id) auf Standard setzen. */
export function resetAppSettings(id) {
  for (const k of id ? [id] : Object.keys(appDefaults)) Object.assign(appSettings[k], clone(appDefaults[k]))
}

/** Weicht ein Bereich vom Standard ab? */
export const isChanged = (id) => JSON.stringify(appSettings[id]) !== JSON.stringify(appDefaults[id])
