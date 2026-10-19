import * as demoBackend from './demo/backend.js'
import { devFlags, recordApi } from './utils/devtools.js'
import { saveBlob } from './utils/files.js'
import { trackLoad } from './utils/pageLoading.js'

// Demo-Version (npm run build:demo): läuft ohne Server, Daten nur im Browser.
// Tutorial (npm run build:tutorial): dieselbe Demo, dazu führt Klemmi Schritt für Schritt durch die App.
export const IS_TUTORIAL = import.meta.env.MODE === 'tutorial'
export const IS_DEMO = import.meta.env.MODE === 'demo' || IS_TUTORIAL

// Relativer Pfad, damit die App in jedem Unterordner des Webservers läuft.
const BASE = 'api/'

export class ApiError extends Error {
  constructor(message, status) {
    super(message)
    this.status = status
  }
}

export function apiUrl(route, params = {}) {
  const qs = new URLSearchParams({ r: route, ...params })
  return `${BASE}?${qs}`
}

async function request(method, route, opts = {}) {
  const t0 = performance.now()
  if (devFlags.delay > 0) await new Promise((r) => setTimeout(r, devFlags.delay))
  try {
    const data = await send(method, route, opts)
    recordApi({ method, route, status: 200, ms: Math.round(performance.now() - t0) })
    // Das Tutorial wartet bei manchen Schritten auf eine Aktion (z. B. Umlagerung gebucht).
    if (IS_TUTORIAL) window.dispatchEvent(new CustomEvent('tn-api', { detail: { method, route } }))
    return data
  } catch (e) {
    recordApi({ method, route, status: e.status ?? 0, ms: Math.round(performance.now() - t0), error: e.message })
    throw e
  }
}

async function send(method, route, { body, params, form } = {}) {
  if (IS_DEMO) {
    // Kurz Luft lassen, damit eine Ladeanzeige gezeichnet wird, bevor das Demo-Backend länger rechnet
    await new Promise((r) => setTimeout(r, 0))
    try {
      return await demoBackend.handle(method, route, params, form ?? body)
    } catch (e) {
      throw new ApiError(e.message, e.status)
    }
  }
  const res = await fetch(apiUrl(route, params), {
    method,
    credentials: 'same-origin',
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: form ?? (body ? JSON.stringify(body) : undefined),
  })
  let data = null
  try {
    data = await res.json()
  } catch {
    throw new ApiError(`Server antwortet nicht korrekt (HTTP ${res.status})`, res.status)
  }
  if (!res.ok) throw new ApiError(data?.error || `Fehler ${res.status}`, res.status)
  return data
}

export const api = {
  get: (route, params) => trackLoad(request('GET', route, { params })),
  post: (route, body) => request('POST', route, { body }),
  /** Datei-Upload als multipart/form-data, z. B. ein Foto für die Teileerkennung. */
  upload: (route, form, params) => request('POST', route, { form, params }),
  /** Datei einer Route herunterladen (CSV, BrickStore, ZIP): Server liefert sie direkt, die Demo erzeugt sie im Browser. */
  async download(route, params = {}) {
    if (!IS_DEMO) {
      const a = Object.assign(document.createElement('a'), { href: apiUrl(route, params), download: '' })
      document.body.appendChild(a)
      a.click()
      a.remove()
      return
    }
    const f = (await request('GET', route, { params }))?.__file
    if (!f) throw new ApiError('Keine Datei erhalten', 500)
    saveBlob(f.data instanceof Blob ? f.data : new Blob([f.data], { type: f.type }), f.name)
  },
}

/** Aktion ins Protokoll schreiben, das der Lehrer einsehen kann. Fehler blockieren die Bedienung nicht. */
export function logActivity(action, module = null, details = undefined) {
  return api.post('activity', { action, module, details }).catch(() => {})
}
