<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api'

// Reihenfolge der Lagerbereiche für die Pickliste (z. B. FT, G, Plate, H, N) und Pick-Testdaten zurücksetzen.
const text = ref('')
const available = ref([])
const msg = ref('')
const err = ref('')
const confirm = ref('')
async function load() {
  const [g, a] = await Promise.all([api.get('picking/location-order'), api.get('picking/location-order/available')])
  text.value = g.groups.map((x) => x.location_group).join('\n')
  available.value = a.locations
}
async function save() {
  msg.value = err.value = ''
  try {
    const r = await api.post('picking/location-order', { groups: text.value.split(/[\n,;]+/).map((s) => s.trim()).filter(Boolean) })
    text.value = r.groups.map((x) => x.location_group).join('\n')
    msg.value = 'Reihenfolge gespeichert.'
  } catch (e) { err.value = e.message }
}
async function reset() {
  msg.value = err.value = ''
  try {
    const r = await api.post('picking/reset', { confirmation: confirm.value })
    msg.value = `Zurückgesetzt: ${r.deleted_progress} gepickte Positionen, ${r.deleted_events} Ereignisse, ${r.deleted_reports} Meldungen.`
    confirm.value = ''
  } catch (e) { err.value = e.message }
}
// Bereichs-Kürzel aus den bekannten Lagerorten (Buchstaben am Anfang), als Hilfe
const prefixes = () => [...new Set(available.value.map((c) => (/^[A-Za-z]+(-[A-Za-z]+)?/.exec(c) ?? [''])[0]).filter(Boolean))].slice(0, 30)
onMounted(() => load().catch((e) => { err.value = e.message }))
</script>

<template>
  <section class="panel">
    <div class="panel-head"><h2>Pickliste: Laufweg</h2></div>
    <div class="panel-body">
      <label class="field"><span>Reihenfolge der Lagerbereiche (eine pro Zeile). Längster passender Anfang gewinnt, „Akte“ vor „A“.</span>
        <textarea v-model="text" class="input area" rows="5" placeholder="z. B. FT, dann G, Plate, H, N (je eine Zeile)" /></label>
      <p class="small faint">Bekannte Bereiche: {{ prefixes().join(', ') || '–' }}</p>
      <button class="btn btn-primary" @click="save">Speichern</button>
      <hr />
      <p class="small muted">Vor dem echten Start: Pick-Verlauf (gepickt, vorgemerkt, Meldungen) löschen. Aufträge und Bestand bleiben.</p>
      <div class="toolbar">
        <input v-model="confirm" class="input" placeholder="RESET eintippen" aria-label="Bestätigung" />
        <button class="btn" :disabled="confirm !== 'RESET'" @click="reset">Testdaten zurücksetzen</button>
      </div>
      <p v-if="msg" class="success small">{{ msg }}</p>
      <p v-if="err" class="error small">{{ err }}</p>
    </div>
  </section>
</template>

<style scoped>
.area { height: auto; padding: 8px 10px; font-family: var(--mono); }
hr { border: 0; border-top: 1px solid var(--border); margin: 16px 0; }
</style>
