<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Lock } from 'lucide-vue-next'
import SegControl from '../../components/SegControl.vue'
import { api, IS_DEMO } from '../../api'
import { useAuth } from '../../stores/auth'
import { devFlags, resetDevFlags } from '../../utils/devtools'
import { resetDemo } from '../../demo/backend'

// Einstellungen für Entwickler: Debug-Hilfen, lokale Daten, Dev-Code. Der Stil ist hier bewusst nicht umschaltbar.
const props = defineProps({ status: Object })
const emit = defineEmits(['status', 'locked'])
const router = useRouter()
const auth = useAuth()
const msg = ref('')
const err = ref('')

const say = (m) => { msg.value = m; err.value = ''; setTimeout(() => { if (msg.value === m) msg.value = '' }, 3000) }

const delays = [{ value: 0, label: 'Aus' }, { value: 300, label: '0,3 s' }, { value: 1000, label: '1 s' }, { value: 3000, label: '3 s' }]
const onOff = [{ value: true, label: 'An' }, { value: false, label: 'Aus' }]

function clearLocal(what) {
  try {
    if (what === 'scan') { sessionStorage.removeItem('tn-last-recognition'); say('Letzte Teileerkennung gelöscht.') }
    if (what === 'settings') { resetDevFlags(); say('Dev-Optionen zurückgesetzt.') }
  } catch { err.value = 'Browser-Speicher nicht verfügbar.' }
}
async function clearCaches() {
  try {
    const regs = (await navigator.serviceWorker?.getRegistrations?.()) ?? []
    await Promise.all(regs.map((r) => r.unregister()))
    const keys = (await caches?.keys?.()) ?? []
    await Promise.all(keys.map((k) => caches.delete(k)))
    say(`${regs.length} Service Worker und ${keys.length} Caches entfernt. Beim nächsten Laden wird alles neu geholt.`)
  } catch (e) { err.value = e.message }
}
const confirmDemo = ref(false)
async function resetDemoData() {
  resetDemo()
  auth.user = null
  auth.checked = false
  router.push('/login')
}

const pin1 = ref('')
const pin2 = ref('')
async function changePin() {
  err.value = ''
  if (pin1.value !== pin2.value) { err.value = 'Die beiden Codes sind verschieden.'; return }
  try {
    emit('status', await api.post('dev/pin', { pin: pin1.value }))
    pin1.value = pin2.value = ''
    say('Dev-Code geändert. Alle anderen müssen den neuen Code eingeben.')
  } catch (e) { err.value = e.message }
}
async function lock() {
  emit('locked', await api.post('dev/lock'))
}
</script>

<template>
  <div class="stack narrow">
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="err" class="error">{{ err }}</p>

    <section class="panel">
      <div class="panel-head"><h2>Debug-Hilfen</h2><span class="faint small">nur dieses Gerät</span></div>
      <div class="rows">
        <div class="row">
          <div><div class="k">Debug-Anzeige</div><div class="h">Kleine Leiste unten links mit Adresse, Fenstergröße, Farbschema und letztem API-Aufruf.</div></div>
          <SegControl v-model="devFlags.overlay" :options="onOff" label="Debug-Anzeige" />
        </div>
        <div class="row">
          <div><div class="k">Langsames Netz simulieren</div><div class="h">Jeder API-Aufruf wartet zusätzlich. Gut, um Ladeanzeigen zu prüfen.</div></div>
          <SegControl v-model="devFlags.delay" :options="delays" label="Verzögerung" />
        </div>
        <div class="row">
          <div><div class="k">API-Aufrufe mitschreiben</div><div class="h">Liste unter „API“. Bleibt nur bis zum Neuladen.</div></div>
          <SegControl v-model="devFlags.logApi" :options="onOff" label="API-Protokoll" />
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Lokale Daten</h2></div>
      <div class="rows">
        <div class="row">
          <div><div class="k">Letzte Teileerkennung</div><div class="h">Bild, Treffer und Alternativen des Teile-Scanners.</div></div>
          <button class="btn btn-sm" @click="clearLocal('scan')">Löschen</button>
        </div>
        <div class="row">
          <div><div class="k">Dev-Optionen</div><div class="h">Debug-Anzeige, Verzögerung und API-Protokoll auf Standard.</div></div>
          <button class="btn btn-sm" @click="clearLocal('settings')">Zurücksetzen</button>
        </div>
        <div class="row">
          <div><div class="k">App-Cache</div><div class="h">Service Worker und gespeicherte Dateien entfernen, falls eine alte Version hängt.</div></div>
          <button class="btn btn-sm" @click="clearCaches">Leeren</button>
        </div>
        <div v-if="IS_DEMO" class="row">
          <div><div class="k">Demo-Daten</div><div class="h">Lager, Aufträge, Protokoll und Konten auf den Anfang zurücksetzen. Danach neu anmelden.</div></div>
          <div class="inline">
            <button v-if="!confirmDemo" class="btn btn-sm" @click="confirmDemo = true">Zurücksetzen</button>
            <template v-else>
              <button class="btn btn-sm danger" @click="resetDemoData">Ja, zurücksetzen</button>
              <button class="btn btn-sm btn-ghost" @click="confirmDemo = false">Abbrechen</button>
            </template>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Zugang</h2></div>
      <div class="rows">
        <form v-if="status.can_set_pin" class="row" @submit.prevent="changePin">
          <div><div class="k">Dev-Code ändern</div><div class="h">4 bis 8 Ziffern. Danach müssen alle anderen den neuen Code eingeben. Wer Zugang hat, legst du in der Lehrer-Ansicht fest.</div></div>
          <div class="inline">
            <input id="dev-pin1" class="input mono short" v-model="pin1" type="password" inputmode="numeric" pattern="\d{4,8}" placeholder="Neu" autocomplete="new-password" required />
            <input id="dev-pin2" class="input mono short" v-model="pin2" type="password" inputmode="numeric" pattern="\d{4,8}" placeholder="Wiederholen" autocomplete="new-password" required />
            <button class="btn">Ändern</button>
          </div>
        </form>
        <div class="row">
          <div><div class="k">Dev-Bereich sperren</div><div class="h">Beim nächsten Öffnen wird der Code wieder abgefragt.</div></div>
          <button class="btn btn-sm" @click="lock"><Lock :size="14" /> Sperren</button>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.narrow { max-width: 900px; }
.rows { display: flex; flex-direction: column; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px var(--pad); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.row:last-child { border-bottom: 0; }
.row > div:first-child { flex: 1; min-width: 220px; }
.k { font-weight: 600; }
.h { color: var(--text-3); font-size: 0.85rem; margin-top: 2px; }
.inline { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.short { width: 120px; }
.danger { color: var(--danger); border-color: var(--danger); }
</style>
