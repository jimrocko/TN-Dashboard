<script setup>
import { computed, ref } from 'vue'
import { Play, CheckCircle2, XCircle, Loader2 } from 'lucide-vue-next'
import { api, IS_DEMO } from '../../api'
import { cameraAvailable, startCamera } from '../../utils/camera'

// Selbsttests: Server (über die API), API-Endpunkte (nur lesend) und Funktionen dieses Geräts.
const server = [
  { id: 'db_read', label: 'Datenbank lesen' },
  { id: 'db_write', label: 'Datenbank schreiben (Testwert, wird sofort gelöscht)' },
  { id: 'session', label: 'Sitzung' },
  { id: 'tmp_write', label: 'Temp-Ordner für Foto-Uploads' },
  { id: 'bricklink', label: 'BrickLink-API (Teil 3001, über den Cache)' },
  { id: 'brickognize', label: 'Teileerkennung erreichbar' },
]
const endpoints = [
  ['auth/me', 'Anmeldung'], ['dashboard', 'Startseite'], ['inventory/meta', 'Lagerorte und Kategorien'],
  ['inventory', 'Inventar', { limit: 50 }], ['orders', 'Aufträge'], ['transfers/suggestions', 'Umlager-Vorschläge'],
  ['transfers', 'Umlager-Verlauf'], ['stocktakes', 'Inventuren'], ['analysis', 'Analyse'], ['part/3001', 'Teile-Seite (3001)'],
  ['search', 'Suche (3001)', { q: '3001' }], ['activity', 'Protokoll', { limit: 20 }],
]

async function deviceTest(id) {
  switch (id) {
    case 'local': localStorage.setItem('tn-t', '1'); if (localStorage.getItem('tn-t') !== '1') throw new Error('Wert kam nicht zurück'); localStorage.removeItem('tn-t'); return 'Lesen und Schreiben ok'
    case 'session': sessionStorage.setItem('tn-t', '1'); sessionStorage.removeItem('tn-t'); return 'ok'
    case 'idb': if (!window.indexedDB) throw new Error('nicht vorhanden'); return 'vorhanden'
    case 'camera': {
      if (!cameraAvailable()) throw new Error('Browser bietet keine Kamera an (HTTPS nötig)')
      const v = document.createElement('video')
      const stop = await startCamera(v)
      const size = `${v.videoWidth} × ${v.videoHeight}`
      stop()
      return `Kamera liefert Bild (${size})`
    }
    case 'canvas': { const c = document.createElement('canvas'); c.width = c.height = 8; const b = await new Promise((r) => c.toBlob(r, 'image/jpeg')); if (!b) throw new Error('kein JPEG'); return `JPEG-Export ok (${b.size} B)` }
    case 'sw': { const r = await navigator.serviceWorker?.getRegistration?.(); if (!r) throw new Error(IS_DEMO ? 'Demo hat keinen Service Worker' : 'nicht registriert'); return 'registriert' }
    case 'online': if (!navigator.onLine) throw new Error('offline'); return 'online'
    case 'clock': { const d = await api.get('auth/me'); return d.user ? 'Sitzung gültig' : 'abgemeldet' }
  }
}
const device = [
  { id: 'local', label: 'Browser-Speicher (localStorage)' }, { id: 'session', label: 'Sitzungsspeicher' },
  { id: 'idb', label: 'IndexedDB' }, { id: 'canvas', label: 'Fotos verkleinern (Canvas → JPEG)' },
  { id: 'camera', label: 'Kamera (fragt nach Erlaubnis)' }, { id: 'sw', label: 'Service Worker (Offline/App)' },
  { id: 'online', label: 'Internetverbindung' },
]

const results = ref({}) // key -> { state: 'run' | 'ok' | 'bad', ms, detail }
async function run(key, fn) {
  results.value[key] = { state: 'run' }
  const t0 = performance.now()
  try {
    const r = await fn()
    results.value[key] = r?.ok === false
      ? { state: 'bad', ms: r.ms, detail: r.detail }
      : { state: 'ok', ms: r?.ms ?? Math.round(performance.now() - t0), detail: r?.detail ?? r }
  } catch (e) {
    results.value[key] = { state: 'bad', ms: Math.round(performance.now() - t0), detail: e.message }
  }
}
const runServer = (t) => run(`s:${t.id}`, () => api.post('dev/test', { name: t.id }))
const runEndpoint = ([route, , params]) => run(`e:${route}`, async () => {
  const d = await api.get(route, params)
  const n = Object.values(d).find(Array.isArray)?.length
  return n !== undefined ? `${n} Einträge` : `${JSON.stringify(d).length} Zeichen`
})
const runDevice = (t) => run(`d:${t.id}`, () => deviceTest(t.id))

async function runAll() {
  for (const t of server) await runServer(t)
  for (const e of endpoints) await runEndpoint(e)
  for (const t of device.filter((x) => x.id !== 'camera')) await runDevice(t)
}
const summary = computed(() => {
  const v = Object.values(results.value)
  return { ok: v.filter((r) => r.state === 'ok').length, bad: v.filter((r) => r.state === 'bad').length }
})
const r = (k) => results.value[k]
</script>

<template>
  <div class="stack">
    <div class="toolbar">
      <button class="btn btn-primary" @click="runAll"><Play :size="15" /> Alle Tests starten</button>
      <span v-if="summary.ok || summary.bad" class="small"><span class="okc">{{ summary.ok }} ok</span> · <span :class="{ badc: summary.bad }">{{ summary.bad }} fehlgeschlagen</span></span>
      <span class="faint small">Die Kamera wird nur einzeln getestet.</span>
    </div>

    <section v-for="grp in [
      { title: 'Server', items: server.map((t) => ({ key: `s:${t.id}`, label: t.label, go: () => runServer(t) })) },
      { title: 'API-Endpunkte (nur lesend)', items: endpoints.map((e) => ({ key: `e:${e[0]}`, label: e[1], sub: e[0], go: () => runEndpoint(e) })) },
      { title: 'Dieses Gerät', items: device.map((t) => ({ key: `d:${t.id}`, label: t.label, go: () => runDevice(t) })) },
    ]" :key="grp.title" class="panel">
      <div class="panel-head"><h2>{{ grp.title }}</h2></div>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <tr v-for="t in grp.items" :key="t.key">
              <td class="st">
                <Loader2 v-if="r(t.key)?.state === 'run'" :size="18" class="spin faint" />
                <CheckCircle2 v-else-if="r(t.key)?.state === 'ok'" :size="18" class="okc" />
                <XCircle v-else-if="r(t.key)?.state === 'bad'" :size="18" class="badc" />
                <span v-else class="idle"></span>
              </td>
              <td>{{ t.label }}<div v-if="t.sub" class="mono faint small">{{ t.sub }}</div></td>
              <td class="muted small detail">{{ r(t.key)?.detail ?? '' }}</td>
              <td class="num faint small nowrap">{{ r(t.key)?.ms !== undefined ? `${r(t.key).ms} ms` : '' }}</td>
              <td class="num"><button class="btn btn-sm" :disabled="r(t.key)?.state === 'run'" @click="t.go">Testen</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.st { width: 28px; }
.idle { display: inline-block; width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--border-strong); }
.okc { color: var(--ok); }
.badc { color: var(--danger); }
.detail { overflow-wrap: anywhere; }
.nowrap { white-space: nowrap; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation: none; } }
</style>
