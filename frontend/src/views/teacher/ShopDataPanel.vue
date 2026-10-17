<script setup>
import { onMounted, ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import { api } from '../../api'
import { num, dateTime } from '../../utils/format'

// Bestand und Aufträge von BrickLink/BrickOwl in die eigene Datenbank holen – nur auf Knopfdruck (spart Tageslimit).
const st = ref(null)
const msg = ref('')
const err = ref('')
const busy = ref(false)
const load = async () => { try { st.value = await api.get('sync/status') } catch (e) { err.value = e.message } }
async function sync(kind) {
  busy.value = true
  msg.value = err.value = ''
  try {
    const r = await api.post(`sync/${kind}`)
    msg.value = kind === 'inventory' ? `Bestand übernommen: ${num(r.lots)} Lots, ${num(r.parts)} Teile, ${num(r.removed)} entfernt.`
      : `Aufträge: BrickLink ${r.bricklink ? `${r.bricklink.orders} (${r.bricklink.new} neu)` : '–'}, BrickOwl ${r.brickowl ? `${r.brickowl.orders} (${r.brickowl.new} neu)` : '–'}.`
      + (r.errors.length ? ` Fehler: ${r.errors.join(' · ')}` : '')
  } catch (e) { err.value = e.message } finally { busy.value = false; load() }
}
onMounted(load)
</script>

<template>
  <section class="panel">
    <div class="panel-head"><h2>Shop-Daten holen</h2><span class="faint small">BrickLink / BrickOwl</span></div>
    <div v-if="st" class="panel-body stack-s">
      <p class="small muted">Die App rechnet immer mit der eigenen Datenbank. Abgerufen wird nur, wenn du hier tippst
        (frühestens alle {{ Math.round(st.min_interval / 60) }} Minuten). Aufträge laden ihre Positionen nur einmal.</p>
      <div class="facts">
        <div><span class="faint small">Bestand zuletzt</span>{{ st.inventory.last_at ? dateTime(st.inventory.last_at) : 'noch nie' }}</div>
        <div><span class="faint small">Aufträge zuletzt</span>{{ st.orders.last_at ? dateTime(st.orders.last_at) : 'noch nie' }}</div>
        <div><span class="faint small">Abrufe (24 h)</span>{{ num(st.api_usage.total) }}</div>
      </div>
      <div class="toolbar">
        <button class="btn" :disabled="busy || !st.bricklink_configured" @click="sync('inventory')"><RefreshCw :size="15" /> Bestand holen</button>
        <button class="btn" :disabled="busy || (!st.bricklink_configured && !st.brickowl_configured)" @click="sync('orders')"><RefreshCw :size="15" /> Aufträge holen</button>
      </div>
      <p v-if="st.demo" class="small faint">In der Demo gibt es keine Verbindung zu BrickLink/BrickOwl.</p>
      <p v-else-if="!st.bricklink_configured" class="small faint">BrickLink ist noch nicht eingerichtet (Zugangsdaten in der .env).</p>
      <p v-if="msg" class="success small">{{ msg }}</p>
      <p v-if="err" class="error small">{{ err }}</p>
    </div>
  </section>
</template>

<style scoped>
.stack-s { display: flex; flex-direction: column; gap: 10px; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
.facts > div { display: flex; flex-direction: column; gap: 2px; }
.stack-s p { margin: 0; }
</style>
