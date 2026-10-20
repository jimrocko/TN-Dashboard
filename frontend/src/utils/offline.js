import { reactive } from 'vue'

// Weiterarbeiten bei schlechtem WLAN (Wunsch des Inhabers), nur Pickliste und Inventur:
// - Lesen: Antworten der wichtigsten Abrufe werden auf dem Gerät gemerkt. Ohne Verbindung kommt die gemerkte Antwort,
//   ergänzt um die noch wartenden eigenen Änderungen (damit Gepicktes nicht wieder „ungepickt“ aussieht).
// - Schreiben: Picken, Markieren, Melden, Meldung schließen und gezählte Mengen gehen ohne Verbindung in eine
//   Warteschlange und werden automatisch nachgeschickt. Picken/Markieren schicken den gewünschten Zustand mit,
//   eine Wiederholung ändert also nichts doppelt. Was der Server ablehnt (z. B. inzwischen von jemand anderem
//   gepickt), steht danach als Hinweis da und wird nicht überschrieben.
// Nur für die echte App (in Demo und Tutorial gibt es kein Netz).

const QUEUE_KEY = 'tn-offline-queue'
const CACHE_KEY = 'tn-offline-cache'
const MAX_CACHE = 25

// Abrufe, deren letzte Antwort gemerkt wird
const CACHEABLE = [/^auth\/me$/, /^picking\/(orders|pickers|revision)$/, /^picking\/orders\/\d+(\/revision)?$/, /^stocktakes$/, /^stocktakes\/today$/, /^stocktakes\/\d+$/]
// Aktionen, die warten dürfen
const QUEUEABLE = [/^picking\/items\/\d+\/(toggle|claim|reports)$/, /^picking\/reports\/\d+\/resolve$/, /^stocktakes\/\d+\/count$/]

const read = (k, d) => { try { return JSON.parse(localStorage.getItem(k) || 'null') ?? d } catch { return d } }
const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch { /* Speicher voll oder gesperrt */ } }

export const offline = reactive({
  online: true,          // letzter Abruf hat den Server erreicht
  queue: read(QUEUE_KEY, []), // [{ id, route, body, user_id, at, label }]
  problems: [],          // [{ label, message }] vom Server abgelehnt
  syncing: false,
})
let me = null
let nextId = Date.now()

export const isCacheable = (route) => CACHEABLE.some((r) => r.test(route))
export const isQueueable = (route) => QUEUEABLE.some((r) => r.test(route))
const cacheKey = (route, params) => `${route}?${new URLSearchParams(params ?? {})}`

/** Angemeldeter Benutzer (für die eigene Anzeige wartender Picks und damit nur dessen Änderungen nachgeschickt werden). */
export function setOfflineUser(u) {
  me = u ? { id: u.id, display_name: u.display_name } : null
  if (!me) write(CACHE_KEY, {}) // nach dem Abmelden nichts Persönliches auf dem Gerät lassen
  else flush()
}

export function remember(route, params, data) {
  const c = read(CACHE_KEY, {})
  const k = cacheKey(route, params)
  delete c[k]
  c[k] = data
  const keys = Object.keys(c)
  for (const old of keys.slice(0, Math.max(0, keys.length - MAX_CACHE))) delete c[old]
  write(CACHE_KEY, c)
}

/** Gemerkte Antwort plus eigene wartende Änderungen, oder undefined. */
export function recall(route, params) {
  const data = read(CACHE_KEY, {})[cacheKey(route, params)]
  if (data === undefined) return undefined
  return applyQueue(route, structuredClone(data))
}

function applyQueue(route, data) {
  const mine = offline.queue.filter((q) => !me || q.user_id === me.id)
  if (/^picking\/orders\/\d+$/.test(route) && data?.items) {
    for (const q of mine) {
      const m = /^picking\/items\/(\d+)\/(toggle|claim|reports)$/.exec(q.route)
      const it = m && data.items.find((x) => x.id === Number(m[1]))
      if (!it) continue
      if (m[2] === 'toggle') {
        Object.assign(it, { picked: !!q.body.picked, picker_id: q.body.picked ? me?.id : null, picker_name: q.body.picked ? me?.display_name : null })
        if (q.body.picked && me && Array.isArray(data.picker_names) && !data.picker_names.includes(me.display_name)) data.picker_names.push(me.display_name)
      }
      if (m[2] === 'claim') Object.assign(it, { claim_picker_id: q.body.claimed ? me?.id : null, claim_picker_name: q.body.claimed ? me?.display_name : null })
      if (m[2] === 'reports') {
        it.reports = [...(it.reports ?? []), { id: -q.id, issue_type: q.body.issue_type, details: q.body.details, missing_quantity: q.body.missing_quantity,
          found_location: q.body.found_location, actual_quantity: q.body.actual_quantity, user_name: me?.display_name, pending: true }]
        it.report_count = it.reports.length
      }
    }
    for (const q of mine) {
      const m = /^picking\/reports\/(\d+)\/resolve$/.exec(q.route)
      if (!m) continue
      for (const it of data.items) {
        const before = it.reports?.length ?? 0
        it.reports = (it.reports ?? []).filter((r) => r.id !== Number(m[1]))
        if (it.reports.length !== before) it.report_count = it.reports.length
      }
    }
  }
  if (/^stocktakes\/\d+$/.test(route) && data?.stocktake?.items) {
    const id = Number(route.split('/')[1])
    for (const q of mine) {
      if (q.route !== `stocktakes/${id}/count`) continue
      const it = data.stocktake.items.find((x) => x.id === Number(q.body.item_id))
      if (it) it.counted = q.body.counted === '' || q.body.counted === undefined ? null : q.body.counted
    }
  }
  return data
}

export function enqueue(route, body, label) {
  offline.queue.push({ id: nextId++, route, body: body ?? {}, user_id: me?.id ?? null, at: new Date().toISOString(), label })
  write(QUEUE_KEY, offline.queue)
}

export const dismissProblems = () => { offline.problems = [] }

let sender = null
/** api.js gibt die Funktion, die eine Aktion wirklich an den Server schickt (ohne Warteschlange). */
export function setSender(fn) { sender = fn }

/** Wartende Aktionen der Reihe nach nachschicken. Ohne Verbindung wird abgebrochen und später neu versucht. */
export async function flush() {
  if (offline.syncing || !sender || !offline.queue.length || !me) return
  offline.syncing = true
  try {
    while (offline.queue.length) {
      const q = offline.queue[0]
      if (q.user_id !== null && q.user_id !== me.id) break // gehört zu einem anderen Konto: warten, bis es wieder angemeldet ist
      try {
        const r = await sender(q.route, q.body)
        // Inzwischen hat jemand anderes dieselbe Position gepickt bzw. vorgemerkt: Bescheid geben (Teil evtl. doppelt geholt)
        if (r?.unchanged && r.picker_id && r.picker_id !== me.id) {
          offline.problems.push({ label: q.label, message: /toggle$/.test(q.route) ? `wurde inzwischen von ${r.picker_name ?? 'jemand anderem'} gepickt` : `ist inzwischen für ${r.picker_name ?? 'jemand anderen'} vorgemerkt` })
        }
      } catch (e) {
        if (e.offline) break
        // Meldung schon geschlossen: kein Problem
        if (!(/resolve$/.test(q.route) && e.status === 400)) offline.problems.push({ label: q.label, message: e.message })
      }
      offline.queue.shift()
      write(QUEUE_KEY, offline.queue)
    }
  } finally { offline.syncing = false }
}

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => flush())
  setInterval(() => { if (offline.queue.length) flush() }, 15000)
}
