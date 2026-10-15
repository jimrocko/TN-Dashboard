<script setup>
import { computed, onMounted, ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import DevKV from './DevKV.vue'
import { api, IS_DEMO } from '../../api'
import { useAuth } from '../../stores/auth'
import { useSettings } from '../../stores/settings'
import { cameraAvailable } from '../../utils/camera'

const auth = useAuth()
const settings = useSettings()
const sys = ref(null)
const error = ref('')
const storage = ref(null)
const sw = ref('–')

const bytes = (n) => (n === null || n === undefined ? '–' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`)
const yes = (b) => (b ? 'ja' : 'nein')

async function load() {
  error.value = ''
  try { sys.value = await api.get('dev/system') } catch (e) { error.value = e.message }
  try { storage.value = await navigator.storage?.estimate?.() } catch { storage.value = null }
  try {
    const reg = await navigator.serviceWorker?.getRegistration?.()
    sw.value = reg ? (reg.active ? 'aktiv' : 'installiert') : 'keiner'
  } catch { sw.value = 'nicht verfügbar' }
}
onMounted(load)

const app = computed(() => sys.value && [
  { k: 'Version', v: sys.value.app.version, mono: true },
  { k: 'Modus', v: IS_DEMO ? 'Demo im Browser (Fake-Daten)' : sys.value.app.test_mode ? 'Testbetrieb (SQLite)' : 'Schulserver', state: sys.value.app.test_mode ? 'warn' : 'ok' },
  { k: 'Serverzeit', v: `${sys.value.app.server_time} (${sys.value.app.timezone})` },
  { k: 'Angemeldet', v: `${auth.user?.display_name} · ${auth.roleLabel}` },
])
const db = computed(() => sys.value && [
  { k: 'Treiber', v: sys.value.db.driver },
  { k: 'Version', v: sys.value.db.version, mono: true },
  { k: 'Datenbank', v: sys.value.db.name, mono: true },
  { k: 'Host', v: sys.value.db.host ?? '–', mono: true },
  { k: 'Tabellen-Präfix', v: sys.value.db.prefix, mono: true },
  { k: 'Größe', v: bytes(sys.value.db.size_bytes) },
])
const services = computed(() => sys.value && [
  { k: 'BrickLink-API', v: sys.value.services.bricklink_configured ? 'eingerichtet' : 'keine Zugangsdaten', state: sys.value.services.bricklink_configured ? 'ok' : 'warn' },
  { k: 'BrickLink-Cache', v: sys.value.services.bricklink_cache_ttl ? `${Math.round(sys.value.services.bricklink_cache_ttl / 3600)} h` : '–' },
  { k: 'Teileerkennung', v: sys.value.services.brickognize_url ?? 'abgeschaltet', state: sys.value.services.brickognize_url ? 'ok' : 'warn', mono: true },
  { k: 'HTTPS', v: yes(sys.value.session.https), state: sys.value.session.https ? 'ok' : 'warn' },
  { k: 'Sitzungsdauer', v: sys.value.session.lifetime ? `${Math.round(sys.value.session.lifetime / 3600)} h` : '–' },
])
const php = computed(() => sys.value?.php && [
  { k: 'PHP', v: `${sys.value.php.version} (${sys.value.php.sapi}, ${sys.value.php.os})`, mono: true },
  { k: 'memory_limit', v: sys.value.php.memory_limit, mono: true },
  { k: 'upload_max_filesize', v: sys.value.php.upload_max_filesize, mono: true },
  { k: 'post_max_size', v: sys.value.php.post_max_size, mono: true },
  { k: 'max_execution_time', v: `${sys.value.php.max_execution_time} s`, mono: true },
  ...sys.value.php.extensions.map((e) => ({ k: e.name, v: e.loaded ? 'geladen' : 'fehlt', state: e.loaded ? 'ok' : 'bad', mono: true })),
])
const env = computed(() => sys.value && [
  { k: '.env-Datei', v: sys.value.env.file ?? (IS_DEMO ? 'keine (Demo)' : 'nicht gefunden'), state: sys.value.env.file ? 'ok' : IS_DEMO ? undefined : 'warn', mono: true },
  ...sys.value.env.fields.map((f) => ({ k: f.field, v: f.from ?? 'Standardwert', state: f.from ? 'ok' : undefined, mono: true })),
])

const device = computed(() => {
  const n = navigator
  const c = n.connection
  return [
    { k: 'Browser', v: n.userAgent, mono: true },
    { k: 'Bildschirm', v: `${screen.width} × ${screen.height} · Fenster ${innerWidth} × ${innerHeight} · Pixeldichte ${devicePixelRatio}` },
    { k: 'Touch', v: n.maxTouchPoints ? `ja (${n.maxTouchPoints} Punkte)` : 'nein' },
    { k: 'Als App installiert', v: yes(matchMedia('(display-mode: standalone)').matches || n.standalone) },
    { k: 'Sprache / Zeitzone', v: `${n.language} · ${Intl.DateTimeFormat().resolvedOptions().timeZone}` },
    { k: 'Online', v: yes(n.onLine), state: n.onLine ? 'ok' : 'bad' },
    { k: 'Verbindung', v: c ? `${c.effectiveType ?? '?'}${c.downlink ? `, ${c.downlink} Mbit/s` : ''}${c.saveData ? ', Datensparmodus' : ''}` : 'unbekannt' },
    { k: 'CPU-Kerne / RAM', v: `${n.hardwareConcurrency ?? '?'} · ${n.deviceMemory ? `${n.deviceMemory} GB` : '?'}` },
    { k: 'Kamera-Zugriff', v: cameraAvailable() ? 'verfügbar' : 'nicht verfügbar', state: cameraAvailable() ? 'ok' : 'warn' },
    { k: 'Service Worker', v: sw.value },
    { k: 'Speicher', v: storage.value ? `${bytes(storage.value.usage)} von ${bytes(storage.value.quota)}` : 'unbekannt' },
    { k: 'System-Farbschema', v: matchMedia('(prefers-color-scheme: dark)').matches ? 'dunkel' : 'hell' },
    { k: 'App-Darstellung', v: `${settings.style === 'xp' ? 'XP' : 'Kacheln'} · ${settings.resolvedTheme} · ${settings.density} · Schrift ${settings.fontSize}` },
  ]
})
</script>

<template>
  <div class="stack">
    <p v-if="error" class="error">{{ error }}</p>
    <div class="toolbar"><button class="btn btn-sm" @click="load"><RefreshCw :size="14" /> Aktualisieren</button></div>
    <div v-if="sys" class="grid-2">
      <section class="panel"><div class="panel-head"><h2>App</h2></div><div class="panel-body"><DevKV :rows="app" /></div></section>
      <section class="panel"><div class="panel-head"><h2>Dienste</h2></div><div class="panel-body"><DevKV :rows="services" /></div></section>
      <section class="panel"><div class="panel-head"><h2>Datenbank</h2></div><div class="panel-body"><DevKV :rows="db" /></div></section>
      <section v-if="php" class="panel"><div class="panel-head"><h2>PHP</h2></div><div class="panel-body"><DevKV :rows="php" /></div></section>
      <section class="panel">
        <div class="panel-head"><h2>.env-Zuordnung</h2><span class="faint small">nur Namen, nie Werte</span></div>
        <div class="panel-body"><DevKV :rows="env" /></div>
      </section>
      <section class="panel"><div class="panel-head"><h2>Dieses Gerät</h2></div><div class="panel-body"><DevKV :rows="device" /></div></section>
    </div>
  </div>
</template>
