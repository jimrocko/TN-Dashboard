<script setup>
import { onMounted, ref } from 'vue'
import { Lock } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import SegControl from '../components/SegControl.vue'
import DevGate from './dev/DevGate.vue'
import DevSystem from './dev/DevSystem.vue'
import DevTests from './dev/DevTests.vue'
import DevData from './dev/DevData.vue'
import DevApi from './dev/DevApi.vue'
import DevSettings from './dev/DevSettings.vue'
import { api, IS_DEMO } from '../api'
import { useSettings } from '../stores/settings'
import { applyCheat } from '../utils/cheats'

// Dev-Bereich: nur für Lehrer und Teilnehmer mit Dev-Rolle, zusätzlich durch den Dev-Code gesichert.
const status = ref(null)
const error = ref('')
const tab = ref(readTab())
const tabs = [
  { value: 'system', label: 'System' },
  { value: 'tests', label: 'Tests' },
  { value: 'daten', label: 'Daten' },
  { value: 'api', label: 'API' },
  { value: 'einstellungen', label: 'Einstellungen' },
]
function readTab() {
  try { return sessionStorage.getItem('tn-dev-tab') || 'system' } catch { return 'system' }
}
function setTab(t) {
  tab.value = t
  try { sessionStorage.setItem('tn-dev-tab', t) } catch { /* egal */ }
}

onMounted(async () => {
  try { status.value = await api.get('dev/status') } catch (e) { error.value = e.message }
})
// Versteckt: Versionszeile 5× schnell antippen öffnet die Code-Eingabe (nirgends beschriftet).
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
  cheatMsg.value = applyCheat(code.value, settings) ? 'OK.' : 'Unbekannter Code.'
  code.value = ''
}

async function lock() {
  status.value = await api.post('dev/lock')
}
</script>

<template>
  <div class="page stack">
    <PageHead title="Dev-Bereich" :sub="`Systemdaten, Selbsttests, Datenbank und Einstellungen für Entwickler.${IS_DEMO ? ' Demo-Version: alle Daten sind erfunden.' : ''}`">
      <button v-if="status?.unlocked" class="btn btn-sm" @click="lock"><Lock :size="14" /> Sperren</button>
    </PageHead>
    <p v-if="error" class="error">{{ error }}</p>

    <DevGate v-if="status && !status.unlocked" :status="status" @unlocked="status = $event" />

    <template v-else-if="status?.unlocked">
      <SegControl :model-value="tab" :options="tabs" label="Bereich" @update:model-value="setTab" />
      <DevSystem v-if="tab === 'system'" />
      <DevTests v-else-if="tab === 'tests'" />
      <DevData v-else-if="tab === 'daten'" />
      <DevApi v-else-if="tab === 'api'" />
      <DevSettings v-else :status="status" @status="status = $event" @locked="status = $event" />

      <form v-if="cheatOpen" class="panel cheat" @submit.prevent="submitCheat">
        <div class="panel-head"><h2>Diagnose</h2><button type="button" class="btn btn-ghost btn-sm" @click="cheatOpen = false">Schließen</button></div>
        <div class="panel-body toolbar">
          <input id="cheat-code" class="input" v-model="code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Diagnose-Code" aria-label="Diagnose-Code" />
          <button class="btn btn-primary">OK</button>
          <span v-if="cheatMsg" class="small" :class="cheatMsg.startsWith('Unbekannt') ? 'error' : 'success'">{{ cheatMsg }}</span>
        </div>
      </form>
      <button type="button" class="version" @click="tap">TN Dashboard · Version 0.4{{ IS_DEMO ? ' · Demo' : '' }}</button>
    </template>
  </div>
</template>

<style scoped>
.version { align-self: center; background: none; border: 0; padding: 12px; color: var(--text-3); font-size: 0.8rem; cursor: default; user-select: none; -webkit-user-select: none; }
.cheat { max-width: 520px; }
</style>
