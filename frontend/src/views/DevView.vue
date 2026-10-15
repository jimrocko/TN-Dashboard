<script setup>
import { onMounted, ref } from 'vue'
import PageHead from '../components/PageHead.vue'
import { api, IS_DEMO } from '../api'
import { useSettings } from '../stores/settings'
import { applyCheat } from '../utils/cheats'

// Versteckter Knopf: Versionszeile 5× schnell antippen öffnet die Code-Eingabe.
const settings = useSettings()
const taps = ref([])
const cheatOpen = ref(false)
const code = ref('')
const cheatMsg = ref('')
function tap() {
  const now = Date.now()
  taps.value = [...taps.value.filter((t) => now - t < 2500), now]
  if (taps.value.length >= 5) { cheatOpen.value = true; taps.value = [] }
}
function submitCheat() {
  const style = applyCheat(code.value, settings)
  cheatMsg.value = style ? 'OK.' : 'Unbekannter Code.'
  code.value = ''
}

const status = ref(null)
const type = ref('PART')
const no = ref('3001')
const result = ref('')

onMounted(async () => {
  try {
    const d = await api.get('dashboard')
    delete d.recent
    status.value = d
  } catch (e) { status.value = { error: e.message } }
})

async function lookup() {
  result.value = 'Lade …'
  try {
    const r = await api.get(`bl/item/${type.value}/${encodeURIComponent(no.value.trim())}`)
    result.value = JSON.stringify(r, null, 2)
  } catch (e) {
    result.value = `Fehler: ${e.message}`
  }
}
</script>

<template>
  <div class="page stack">
    <PageHead title="Dev-Bereich" sub="Werkzeuge zum Testen von API und BrickLink-Cache." />
    <div class="grid-2">
      <section class="panel">
        <div class="panel-head"><h2>Dashboard-API</h2></div>
        <div class="panel-body"><pre>{{ JSON.stringify(status, null, 2) }}</pre></div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>BrickLink-Artikel abrufen</h2></div>
        <div class="panel-body">
          <p class="muted small">Antworten werden in der Datenbank zwischengespeichert. <code>"cached": true</code> heißt: kein API-Aufruf nötig.</p>
          <form class="toolbar" @submit.prevent="lookup">
            <select class="select" v-model="type" aria-label="Typ"><option>PART</option><option>MINIFIG</option><option>SET</option></select>
            <input class="input" v-model="no" placeholder="z. B. 3001" aria-label="Nummer" />
            <button class="btn btn-primary">Abrufen</button>
          </form>
          <pre v-if="result">{{ result }}</pre>
        </div>
      </section>
    </div>

    <form v-if="cheatOpen" class="panel cheat" @submit.prevent="submitCheat">
      <div class="panel-head"><h2>Diagnose</h2><button type="button" class="btn btn-ghost btn-sm" @click="cheatOpen = false">Schließen</button></div>
      <div class="panel-body toolbar">
        <input id="cheat-code" class="input" v-model="code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Diagnose-Code" aria-label="Diagnose-Code" />
        <button class="btn btn-primary">OK</button>
        <span v-if="cheatMsg" class="small" :class="cheatMsg.startsWith('Unbekannt') ? 'error' : 'success'">{{ cheatMsg }}</span>
      </div>
    </form>

    <button type="button" class="version" @click="tap">TN Dashboard · Version 0.3{{ IS_DEMO ? ' · Demo' : '' }}</button>
  </div>
</template>

<style scoped>
.version { align-self: center; background: none; border: 0; padding: 12px; color: var(--text-3); font-size: 0.8rem; cursor: default; user-select: none; -webkit-user-select: none; }
.cheat { max-width: 520px; }
pre { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; overflow: auto; max-height: 420px; font-family: var(--mono); font-size: 0.8rem; margin: 12px 0 0; }
</style>
