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

async function request(method, route, { body, params } = {}) {
  const res = await fetch(apiUrl(route, params), {
    method,
    credentials: 'same-origin',
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
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
  get: (route, params) => request('GET', route, { params }),
  post: (route, body) => request('POST', route, { body }),
}

/** Aktion ins Protokoll schreiben, das der Lehrer einsehen kann. Fehler blockieren die Bedienung nicht. */
export function logActivity(action, module = null, details = undefined) {
  return api.post('activity', { action, module, details }).catch(() => {})
}
