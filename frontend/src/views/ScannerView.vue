<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Camera, CameraOff, ImageUp, ScanSearch, ExternalLink } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import { api, IS_DEMO } from '../api'
import { rememberRecognition } from '../utils/recognition'
import { num } from '../utils/format'
import { cameraAvailable, startCamera, captureFrame, shrinkFile } from '../utils/camera'
import { WINDOW_VISIBLE } from '../utils/windowing'

// Teile-Scanner: Foto eines Teils machen, der kostenlose Dienst Brickognize erkennt es,
// danach zeigt die App, ob und wo das Teil bei uns im Lager liegt.
// Im XP-Stil liefert das Fenster, ob es sichtbar ist; minimiert wird die Kamera gestoppt.
const visible = inject(WINDOW_VISIBLE, ref(true))
const router = useRouter()

const video = ref(null)
const fileInput = ref(null)
const camOn = ref(false)
const camError = ref('')
const busy = ref(false)
const error = ref('')
const photo = ref(null) // Vorschau des zuletzt erkannten Fotos (Object-URL)
const items = ref(null)
let stopCam = null

async function camStart() {
  camError.value = ''
  try {
    camOn.value = true
    await nextTick()
    stopCam = await startCamera(video.value)
  } catch (e) {
    camOn.value = false
    camError.value = e?.name === 'NotAllowedError'
      ? 'Kamera-Zugriff wurde verweigert. In den Browser-Einstellungen erlauben oder „Foto wählen“ nutzen.'
      : 'Kamera konnte nicht gestartet werden (auf dem Schulserver ist HTTPS nötig). „Foto wählen“ geht immer.'
  }
}
function camStop() {
  stopCam?.()
  stopCam = null
  camOn.value = false
}
watch(visible, (v) => { if (!v) camStop() })

async function recognize(blob) {
  busy.value = true
  error.value = ''
  if (photo.value) URL.revokeObjectURL(photo.value)
  photo.value = URL.createObjectURL(blob)
  try {
    const fd = new FormData()
    fd.append('image', blob, 'foto.jpg')
    show((await api.upload('recognize', fd)).items)
  } catch (e) {
    items.value = null
    error.value = e.message
  } finally { busy.value = false }
}
// Erkanntes Teil direkt öffnen (Teile-Seite mit Bestand). Minifigs/Sets und „nichts erkannt“ bleiben hier.
function show(list) {
  items.value = list
  rememberRecognition(list)
  if (list[0]?.type === 'part') {
    camStop()
    router.push({ path: `/teil/${list[0].id}`, query: { scan: '1', t: String(Date.now()) } })
  }
}

// Nur Demo-Version: Erkennung ohne Internet nachspielen, mit einem zufälligen Teil aus dem Demo-Lager.
async function demoRecognize() {
  const lots = (await api.get('inventory', { limit: 2000 })).items
  const nos = [...new Set(lots.map((l) => l.item_no))].sort(() => Math.random() - 0.5).slice(0, 4)
  const all = await Promise.all(nos.map((no) => api.get(`part/${encodeURIComponent(no)}`)))
  show(all.map(({ part: p }, i) => ({
    id: p.item_no, name: p.name, type: 'part', category: p.category, score: [0.93, 0.41, 0.22, 0.11][i],
    img_url: null, bricklink_url: null, lots: p.lots,
  })))
}

async function snap() {
  if (!video.value?.videoWidth) return
  recognize(await captureFrame(video.value))
}
async function onFile(e) {
  const f = e.target.files?.[0]
  e.target.value = ''
  if (!f) return
  try { recognize(await shrinkFile(f)) } catch { error.value = 'Das Foto konnte nicht gelesen werden.' }
}

const best = computed(() => items.value?.[0] ?? null)
const others = computed(() => items.value?.slice(1) ?? [])
const pct = (s) => `${Math.round(s * 100)} %`
const typeLabel = { part: 'Teil', fig: 'Minifig', set: 'Set' }
const totalQty = (lots) => lots.reduce((s, l) => s + l.qty, 0)

onBeforeUnmount(() => {
  camStop()
  if (photo.value) URL.revokeObjectURL(photo.value)
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Teile-Scanner" sub="Teil fotografieren: Es wird automatisch erkannt, und du siehst, wo es bei uns im Lager liegt." />

    <div class="grid-2">
      <section class="panel">
        <div class="panel-head"><h2>Foto</h2></div>
        <div class="panel-body stack">
          <div v-if="camOn" class="viewport">
            <video ref="video" muted playsinline></video>
            <div class="frame" aria-hidden="true"></div>
          </div>
          <img v-else-if="photo" :src="photo" alt="Zuletzt erkanntes Foto" class="shot" />
          <div v-else class="placeholder faint"><ScanSearch :size="40" /><span>Teil einzeln auf eine helle, ruhige Fläche legen.</span></div>

          <div class="actions">
            <button v-if="camOn" class="btn btn-primary" :disabled="busy" @click="snap"><ScanSearch :size="16" /> {{ busy ? 'Erkenne …' : 'Erkennen' }}</button>
            <button v-if="cameraAvailable()" class="btn" type="button" @click="camOn ? camStop() : camStart()">
              <component :is="camOn ? CameraOff : Camera" :size="16" /> {{ camOn ? 'Kamera aus' : 'Kamera starten' }}
            </button>
            <button class="btn" type="button" :disabled="busy" @click="fileInput.click()"><ImageUp :size="16" /> Foto wählen</button>
            <input ref="fileInput" type="file" accept="image/*" capture="environment" hidden @change="onFile" />
          </div>
          <p v-if="camError" class="error small">{{ camError }}</p>
          <button v-if="IS_DEMO" class="btn btn-sm demo" type="button" :disabled="busy" @click="demoRecognize">Demo: Erkennung ohne Foto testen</button>
          <p class="faint small">Die Erkennung macht der kostenlose Dienst Brickognize. Das Foto wird dafür an brickognize.com geschickt.</p>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Ergebnis</h2><span v-if="best" class="faint small">{{ pct(best.score) }} sicher</span></div>
        <div v-if="busy" class="panel-body faint">Erkenne …</div>
        <div v-else-if="error" class="panel-body"><p class="error">{{ error }}</p></div>
        <div v-else-if="items && !items.length" class="panel-body faint">Nichts erkannt. Näher ran, besseres Licht oder das Teil anders drehen.</div>
        <div v-else-if="best" class="panel-body stack">
          <div class="hit">
            <img v-if="best.img_url" :src="best.img_url" alt="" class="thumb" referrerpolicy="no-referrer" />
            <div class="grow">
              <div><span class="mono strong">{{ best.id }}</span> <span class="pill">{{ typeLabel[best.type] ?? best.type }}</span></div>
              <div>{{ best.name }}</div>
              <div class="faint small">{{ best.category }}</div>
            </div>
          </div>
          <div v-if="best.type === 'part'">
            <div v-if="best.lots.length" class="stack-sm">
              <div class="small"><strong>Bei uns im Lager:</strong> {{ num(totalQty(best.lots)) }} Stück in {{ best.lots.length }} {{ best.lots.length === 1 ? 'Los' : 'Losen' }}</div>
              <div class="table-wrap">
                <table class="table">
                  <thead><tr><th>Farbe</th><th>Lagerort</th><th class="num">Menge</th></tr></thead>
                  <tbody>
                    <tr v-for="l in best.lots" :key="l.id">
                      <td class="nowrap"><ColorSwatch :name="l.color_name" /></td>
                      <td><LocationTag :code="l.location_code" :kind="l.location_kind" /></td>
                      <td class="num">{{ num(l.qty) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <p v-else class="faint small">Dieses Teil haben wir nicht im Lager.</p>
          </div>
          <div class="actions">
            <RouterLink v-if="best.type === 'part'" class="btn btn-primary" :to="`/teil/${best.id}`">Teil öffnen</RouterLink>
            <a v-if="best.bricklink_url" class="btn" :href="best.bricklink_url" target="_blank" rel="noopener">BrickLink <ExternalLink :size="14" /></a>
          </div>
        </div>
        <div v-else class="panel-body faint">Noch kein Foto erkannt.</div>
      </section>
    </div>

    <section v-if="others.length && !busy" class="panel">
      <div class="panel-head"><h2>Andere Möglichkeiten</h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th></th><th>Nummer</th><th>Bezeichnung</th><th class="num">Sicherheit</th><th class="num">Bei uns</th></tr></thead>
          <tbody>
            <tr v-for="o in others" :key="o.type + o.id">
              <td class="tcell"><img v-if="o.img_url" :src="o.img_url" alt="" class="mini" referrerpolicy="no-referrer" /></td>
              <td class="mono"><RouterLink v-if="o.type === 'part'" :to="`/teil/${o.id}`">{{ o.id }}</RouterLink><template v-else>{{ o.id }}</template></td>
              <td>{{ o.name }} <span class="faint small">{{ typeLabel[o.type] ?? o.type }}</span></td>
              <td class="num">{{ pct(o.score) }}</td>
              <td class="num">
                <RouterLink v-if="o.lots.length" :to="{ path: '/suche', query: { q: o.id } }">{{ num(totalQty(o.lots)) }} Stk.</RouterLink>
                <span v-else class="faint">–</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.nowrap { white-space: nowrap; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; }
.viewport, .shot, .placeholder { width: 100%; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); }
.viewport { position: relative; background: #000; overflow: hidden; }
.viewport video { width: 100%; height: 100%; object-fit: cover; display: block; }
.frame { position: absolute; inset: 15% 20%; border: 3px solid rgba(255, 255, 255, 0.85); border-radius: 14px; box-shadow: 0 0 0 999px rgba(0, 0, 0, 0.3); }
.shot { object-fit: contain; background: var(--surface-2); }
.placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; text-align: center; padding: 16px; border: 1px dashed var(--border-strong); }
.hit { display: flex; gap: 14px; align-items: center; }
.thumb { width: 96px; height: 96px; object-fit: contain; background: #fff; border-radius: var(--radius-sm); border: 1px solid var(--border); flex: none; }
.mini { width: 40px; height: 40px; object-fit: contain; background: #fff; border-radius: 4px; display: block; }
.tcell { width: 48px; }
.grow { min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.strong { font-weight: 700; font-size: 1.1rem; }
.demo { align-self: flex-start; border-style: dashed; }
.stack-sm { display: flex; flex-direction: column; gap: 8px; }
</style>
