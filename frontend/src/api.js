import * as demoBackend from './demo/backend.js'
import { devFlags, recordApi } from './utils/devtools.js'
import { saveBlob } from './utils/files.js'
import { trackLoad } from './utils/pageLoading.js'
import { offline, isCacheable, isQueueable, remember, recall, enqueue, flush, setSender } from './utils/offline.js'

// Demo-Version (npm run build:demo): läuft ohne Server, Daten nur im Browser.
// Tutorial (npm run build:tutorial): dieselbe Demo, dazu führt Klemmi Schritt für Schritt durch die App.
export const IS_TUTORIAL = import.meta.env.MODE === 'tutorial'
export const IS_DEMO = import.meta.env.MODE === 'demo' || IS_TUTORIAL

// Relativer Pfad, damit die App in jedem Unterordner des Webservers läuft.
const BASE = 'api/'

export class ApiError extends Error {
  constructor(message, status, isOffline = false) {
    super(message)
    this.status = status
    this.offline = isOffline // Server nicht erreicht (kein WLAN, Zeitüberschreitung)
  }
}
const NO_CONNECTION = 'Keine Verbindung zum Server. Bitte warten, bis das WLAN wieder da ist.'
const TIMEOUT_MS = 15000

export function apiUrl(route, params = {}) {
  const qs = new URLSearchParams({ r: route, ...params })
  return `${BASE}?${qs}`
}

async function request(method, route, opts = {}) {
  const t0 = performance.now()
  if (devFlags.delay > 0) await new Promise((r) => setTimeout(r, devFlags.delay))
  // Schlechtes WLAN (nur echte App): Pickliste und Inventur arbeiten mit gemerkten Daten und einer Warteschlange weiter
  if (!IS_DEMO && method === 'POST' && isQueueable(route)) {
    if (offline.queue.length) { // Reihenfolge bleibt: erst die wartenden Aktionen
      enqueue(route, opts.body, opts.label)
      flush()
      return { queued: true }
    }
    try {
      return await send(method, route, opts)
    } catch (e) {
      if (!e.offline) throw e
      enqueue(route, opts.body, opts.label)
      return { queued: true }
    }
  }
  if (!IS_DEMO && method === 'GET' && isCacheable(route)) {
    try {
      const data = await send(method, route, opts)
      remember(route, opts.params, data)
      return offline.queue.length ? recall(route, opts.params) : data
    } catch (e) {
      const cached = e.offline ? recall(route, opts.params) : undefined
      if (cached !== undefined) return cached
      throw e
    }
  }
  try {
    const data = await send(method, route, opts)
    // Nach der Anmeldung den Benutzer merken: so startet die App auch ohne Verbindung angemeldet
    if (!IS_DEMO && route === 'auth/login' && data?.user) remember('auth/me', undefined, { user: data.user })
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
  let res
  const ctrl = new AbortController()
  const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS)
  try {
    res = await fetch(apiUrl(route, params), {
      method,
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json' } : undefined,
      body: form ?? (body ? JSON.stringify(body) : undefined),
      signal: ctrl.signal,
    })
  } catch {
    // Netzwerkfehler oder Zeitüberschreitung: Server nicht erreicht
    offline.online = false
    throw new ApiError(NO_CONNECTION, 0, true)
  } finally { clearTimeout(timer) }
  if (!offline.online) { offline.online = true; flush() }
  let data = null
  try {
    data = await res.json()
  } catch {
    throw new ApiError(`Server antwortet nicht korrekt (HTTP ${res.status})`, res.status)
  }
  if (!res.ok) throw new ApiError(data?.error || `Fehler ${res.status}`, res.status)
  return data
}

setSender((route, body) => send('POST', route, { body }))

export const api = {
  get: (route, params) => trackLoad(request('GET', route, { params })),
  /** label: kurze Beschreibung, falls die Aktion offline warten muss und später abgelehnt wird (z. B. „3001 Rot, G245“). */
  post: (route, body, label) => request('POST', route, { body, label }),
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
