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
    </template>
  </div>
</template>
