<script setup>
import { onBeforeUnmount, ref } from 'vue'
import PageHead from '../components/PageHead.vue'
import SegControl from '../components/SegControl.vue'
import DevSystem from './dev/DevSystem.vue'
import DevTests from './dev/DevTests.vue'
import DevData from './dev/DevData.vue'
import DevApi from './dev/DevApi.vue'
import DevSettings from './dev/DevSettings.vue'
import { IS_DEMO } from '../api'
import { useSettings } from '../stores/settings'
import { applyCheat } from '../utils/cheats'

// Dev-Bereich: nur für Lehrer und Teilnehmer mit Dev-Rolle (prüft der Router und jede Dev-Route am Server).
// Kein zusätzlicher Code: Man ist ohnehin mit dem eigenen Passwort angemeldet.
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

onBeforeUnmount(() => clearTimeout(holdTimer))

// Versteckt: Versionszeile 2 Sekunden gedrückt halten (iPad und PC) oder 5× schnell antippen öffnet die Code-Eingabe.
// touch-action: manipulation an der Zeile verhindert, dass Safari beim schnellen Tippen hineinzoomt.
const settings = useSettings()
const taps = ref([])
const cheatOpen = ref(false)
const code = ref('')
const cheatMsg = ref('')
const HOLD_MS = 2000
let holdTimer
function tap() {
  const now = Date.now()
  taps.value = [...taps.value.filter((t) => now - t < 2500), now]
  if (taps.value.length >= 5) { cheatOpen.value = true; taps.value = [] }
}
function holdStart() {
  clearTimeout(holdTimer)
  holdTimer = setTimeout(() => { cheatOpen.value = true; taps.value = [] }, HOLD_MS)
}
const holdEnd = () => clearTimeout(holdTimer)
function submitCheat() {
  cheatMsg.value = applyCheat(code.value, settings) ? 'OK.' : 'Unbekannter Code.'
  code.value = ''
}
</script>

<template>
  <div class="page stack">
    <PageHead title="Dev-Bereich" :sub="`Systemdaten, Selbsttests, Datenbank und Einstellungen für Entwickler.${IS_DEMO ? ' Demo-Version: alle Daten sind erfunden.' : ''}`" />

    <SegControl :model-value="tab" :options="tabs" label="Bereich" @update:model-value="setTab" />
    <DevSystem v-if="tab === 'system'" />
    <DevTests v-else-if="tab === 'tests'" />
    <DevData v-else-if="tab === 'daten'" />
    <DevApi v-else-if="tab === 'api'" />
    <DevSettings v-else />

    <form v-if="cheatOpen" class="panel cheat" @submit.prevent="submitCheat">
      <div class="panel-head"><h2>Diagnose</h2><button type="button" class="btn btn-ghost btn-sm" @click="cheatOpen = false">Schließen</button></div>
      <div class="panel-body toolbar">
        <input id="cheat-code" class="input" v-model="code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Diagnose-Code" aria-label="Diagnose-Code" />
        <button class="btn btn-primary">OK</button>
        <span v-if="cheatMsg" class="small" :class="cheatMsg.startsWith('Unbekannt') ? 'error' : 'success'">{{ cheatMsg }}</span>
      </div>
    </form>
    <button type="button" class="version" @click="tap" @pointerdown="holdStart" @pointerup="holdEnd" @pointerleave="holdEnd" @pointercancel="holdEnd" @contextmenu.prevent>TN Dashboard · Version 0.4{{ IS_DEMO ? ' · Demo' : '' }}</button>
  </div>
</template>

<style scoped>
.version { align-self: center; background: none; border: 0; padding: 12px; color: var(--text-3); font-size: 0.8rem; cursor: default; user-select: none; -webkit-user-select: none; touch-action: manipulation; -webkit-touch-callout: none; }
.cheat { max-width: 520px; }
.center { text-align: center; margin: 0; }
</style>
