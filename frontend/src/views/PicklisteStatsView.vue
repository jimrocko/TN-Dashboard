<script setup>
import { onMounted, ref } from 'vue'
import PageHead from '../components/PageHead.vue'
import { api } from '../api'
import { num, dateTime } from '../utils/format'

// Auswertung des Pick-Protokolls wie im bisherigen Programm: Zeitraum, Picker, Plattform.
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const today = new Date()
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)))
const to = ref(iso(today))
const picker = ref('')
const platform = ref('')
const pickers = ref([])
const data = ref(null)
const error = ref('')

function preset(kind) {
  const d = new Date()
  const start = new Date(d)
  let end = new Date(d)
  if (kind === 'yesterday') { start.setDate(d.getDate() - 1); end = new Date(start) }
  else if (kind === 'week') start.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  else if (kind === 'month') start.setDate(1)
  else if (kind === 'schoolyear') { start.setMonth(7, 1); if (d.getMonth() < 7) start.setFullYear(d.getFullYear() - 1) }
  from.value = iso(start)
  to.value = iso(end)
  load()
}
async function load() {
  error.value = ''
  try {
    data.value = await api.get('picking/stats', { date_from: from.value, date_to: to.value, picker_id: picker.value, platform: platform.value })
  } catch (e) { error.value = e.message }
}
const dur = (s) => (s === null || s === undefined ? '–' : s < 60 ? `${s} s` : s < 3600 ? `${Math.round(s / 60)} min` : `${(s / 3600).toFixed(1).replace('.', ',')} h`)
onMounted(async () => {
  try { pickers.value = (await api.get('picking/pickers')).pickers } catch { /* Filter optional */ }
  load()
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Pick-Statistik" sub="Wer hat wie viel gepickt? Ausgewertet wird jeder Pick und jede Rücknahme.">
      <RouterLink to="/pickliste" class="btn">Zur Pickliste</RouterLink>
    </PageHead>
    <section class="panel panel-body filters">
      <div class="toolbar">
        <button class="btn btn-sm" @click="preset('today')">Heute</button>
        <button class="btn btn-sm" @click="preset('yesterday')">Gestern</button>
        <button class="btn btn-sm" @click="preset('week')">Diese Woche</button>
        <button class="btn btn-sm" @click="preset('month')">Dieser Monat</button>
        <button class="btn btn-sm" @click="preset('schoolyear')">Schuljahr</button>
      </div>
      <div class="toolbar">
        <label class="small muted">Von <input v-model="from" type="date" class="input" @change="load" /></label>
        <label class="small muted">Bis <input v-model="to" type="date" class="input" @change="load" /></label>
        <select v-model="picker" class="select" aria-label="Picker" @change="load">
          <option value="">Alle Picker</option><option v-for="p in pickers" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="platform" class="select" aria-label="Plattform" @change="load">
          <option value="">Alle Plattformen</option><option>BrickLink</option><option>BrickOwl</option><option value="TN">Eigene</option>
        </select>
      </div>
    </section>
    <p v-if="error" class="error">{{ error }}</p>
    <template v-if="data">
      <div class="kpis">
        <div class="kpi"><div class="label">Bestellungen bearbeitet</div><div class="value">{{ num(data.summary.orders) }}</div></div>
        <div class="kpi good"><div class="label">Vollständig gepickt</div><div class="value">{{ num(data.summary.completed_orders) }}</div></div>
        <div class="kpi"><div class="label">Positionen gepickt</div><div class="value">{{ num(data.summary.positions) }}</div></div>
        <div class="kpi"><div class="label">Teile gepickt</div><div class="value">{{ num(data.summary.parts) }}</div></div>
        <div class="kpi warn"><div class="label">Korrekturen</div><div class="value">{{ num(data.summary.corrections) }}</div></div>
        <div class="kpi"><div class="label">Ø Dauer je Bestellung</div><div class="value">{{ dur(data.summary.avg_duration_seconds) }}</div></div>
      </div>
      <section class="panel">
        <div class="panel-head"><h2>Picker</h2><span class="faint small">{{ data.period.from }} bis {{ data.period.to }}</span></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Picker</th><th class="num">Positionen</th><th class="num">Teile</th><th class="num">Bestellungen</th><th class="num">Fertig</th><th class="num">Korrekturen</th></tr></thead>
            <tbody>
              <tr v-for="p in data.pickers" :key="p.picker_id">
                <td>{{ p.picker_name }}</td><td class="num">{{ num(p.positions) }}</td><td class="num">{{ num(p.parts) }}</td>
                <td class="num">{{ num(p.orders) }}</td><td class="num">{{ num(p.completed_orders) }}</td><td class="num">{{ num(p.corrections) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>Bestellungen</h2></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Bestellung</th><th>Kunde</th><th class="num">Pos.</th><th class="num">Teile</th><th>Picker</th><th>Start</th><th>Fertig</th><th class="num">Dauer</th></tr></thead>
            <tbody>
              <tr v-for="o in data.orders" :key="o.id" class="clickable" @click="$router.push({ path: '/pickliste', query: { id: o.id } })">
                <td class="nowrap">{{ o.platform }} · <span class="mono">{{ o.order_no }}</span></td>
                <td>{{ o.buyer_name || '–' }}</td>
                <td class="num">{{ o.picked_positions }}/{{ o.positions }}</td><td class="num">{{ num(o.parts) }}</td>
                <td>{{ o.picker_names.join(', ') || '–' }}</td>
                <td class="faint nowrap">{{ dateTime(o.first_pick_at) }}</td>
                <td class="nowrap"><span v-if="o.complete" class="pill ok">{{ dateTime(o.completed_at) }}</span><span v-else class="faint">offen</span></td>
                <td class="num">{{ dur(o.duration_seconds) }}</td>
              </tr>
              <tr v-if="!data.orders.length"><td colspan="8" class="empty">In diesem Zeitraum wurde nichts gepickt.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.filters { display: flex; flex-direction: column; gap: 10px; }
.filters label { display: inline-flex; align-items: center; gap: 6px; }
.nowrap { white-space: nowrap; }
</style>
