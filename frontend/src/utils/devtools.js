import { reactive, watch } from 'vue'

// Dev-Optionen (pro Gerät) und Protokoll der letzten API-Aufrufe für den Dev-Bereich.
const KEY = 'tn-dev-flags'
const defaults = { overlay: false, delay: 0, logApi: true }

function read() {
  try { return { ...defaults, ...JSON.parse(localStorage.getItem(KEY) || '{}') } } catch { return { ...defaults } }
}

/** overlay: Debug-Anzeige unten links · delay: künstliche Verzögerung je API-Aufruf in ms · logApi: Aufrufe mitschreiben */
export const devFlags = reactive(read())
watch(devFlags, (v) => { try { localStorage.setItem(KEY, JSON.stringify(v)) } catch { /* nur für diese Sitzung */ } })
export const resetDevFlags = () => Object.assign(devFlags, defaults)

/** Letzte 100 API-Aufrufe, neueste zuerst: { at, method, route, status, ms, error } */
export const apiLog = reactive([])
export function recordApi(entry) {
  if (!devFlags.logApi) return
  apiLog.unshift({ at: new Date(), ...entry })
  if (apiLog.length > 100) apiLog.length = 100
}
