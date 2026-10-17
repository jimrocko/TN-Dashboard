<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHead from '../components/PageHead.vue'
import SegControl from '../components/SegControl.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import { api } from '../api'
import { num, eur, dateTime, orderStatusClass } from '../utils/format'

const STATUSES = ['Neu', 'Bezahlt', 'Verpackt', 'Versandt', 'Abgeschlossen']
const route = useRoute()
const router = useRouter()
const status = ref(route.query.status ?? 'aktiv')
const orders = ref([])
const current = ref(null)
const error = ref('')
const busy = ref(false)

const filters = [{ value: 'aktiv', label: 'Aktiv' }, { value: '', label: 'Alle' }, ...STATUSES.map((s) => ({ value: s, label: s }))]

async function loadList() {
  try { orders.value = (await api.get('orders', status.value ? { status: status.value } : {})).orders } catch (e) { error.value = e.message }
}
async function open(id) {
  if (!id) { current.value = null; return }
  try { current.value = (await api.get(`orders/${id}`)).order } catch (e) { error.value = e.message }
}
const select = (id) => router.replace({ query: { ...route.query, id } })

watch(status, (s) => { router.replace({ query: { ...route.query, status: s } }); loadList() })
watch(() => route.query.id, (id) => open(id), { immediate: true })
// Links von anderen Seiten (z. B. Analyse → „Bezahlt“) setzen den Filter, auch wenn die Ansicht schon offen ist.
watch(() => route.query.status, (s) => { if (s !== undefined && s !== status.value) status.value = s })
onMounted(loadList)

const next = computed(() => {
  const i = STATUSES.indexOf(current.value?.status)
  return i >= 0 && i < STATUSES.length - 1 ? STATUSES[i + 1] : null
})
async function setStatus(s) {
  busy.value = true
  try {
    current.value = (await api.post(`orders/${current.value.id}/status`, { status: s })).order
    await loadList()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
</script>

<template>
  <div class="page stack">
    <PageHead title="Auftragszentrale" sub="Bestellungen aus dem Shop. Positionen sind nach Lagerort sortiert.">
      <SegControl v-model="status" :options="filters" label="Status" />
    </PageHead>
    <p v-if="error" class="error">{{ error }}</p>

    <div class="split" :class="{ detail: current }">
      <section class="panel">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Auftrag</th><th>Käufer</th><th>Datum</th><th class="num">Pos.</th><th class="num">Teile</th><th class="num">Summe</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="o in orders" :key="o.id" class="clickable" :class="{ selected: current?.id === o.id }" @click="select(o.id)">
                <td class="mono">{{ o.order_no }}<div v-if="o.platform && o.platform !== 'TN'" class="faint small">{{ o.platform }}</div></td>
                <td>{{ o.buyer }}</td>
                <td class="faint nowrap">{{ dateTime(o.ordered_at) }}</td>
                <td class="num">{{ o.positions }}</td>
                <td class="num">{{ num(o.parts) }}</td>
                <td class="num">{{ eur(o.total) }}</td>
                <td><span class="pill" :class="orderStatusClass(o.status)">{{ o.status }}</span></td>
              </tr>
              <tr v-if="!orders.length"><td colspan="7" class="empty">Keine Aufträge mit diesem Status.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="current" class="panel">
        <div class="panel-head">
          <div>
            <h2>Auftrag <span class="mono">{{ current.order_no }}</span></h2>
            <div class="faint small">{{ current.buyer }} · {{ dateTime(current.ordered_at) }} · {{ eur(current.total) }}</div>
          </div>
          <button class="btn btn-ghost btn-sm" @click="select(undefined)">Schließen</button>
        </div>
        <div class="panel-body status-row">
          <span class="pill" :class="orderStatusClass(current.status)">{{ current.status }}</span>
          <button v-if="next" class="btn btn-primary btn-sm" :disabled="busy" @click="setStatus(next)">Weiter zu „{{ next }}“</button>
          <select class="select" :value="current.status" @change="setStatus($event.target.value)" :disabled="busy" aria-label="Status setzen">
            <option v-for="s in STATUSES" :key="s">{{ s }}</option>
          </select>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Lagerort</th><th>Teil</th><th>Farbe</th><th class="num">Menge</th><th class="num">Bestand</th></tr></thead>
            <tbody>
              <tr v-for="it in current.items" :key="it.id">
                <td><LocationTag :code="it.location_code" /></td>
                <td><div class="partcell"><PartImage :no="it.item_no" :color="it.color_id" :type="it.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${it.item_no}`" @click.stop>{{ it.item_no }}</RouterLink> <span class="muted small">{{ it.name }}</span></div></div></td>
                <td class="nowrap"><ColorSwatch :name="it.color_name" /></td>
                <td class="num">{{ num(it.qty) }}</td>
                <td class="num" :class="{ short: it.stock !== null && it.stock < it.qty }">{{ num(it.stock) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.split { display: grid; grid-template-columns: 1fr; gap: var(--gap); align-items: start; }
.split.detail { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
@media (max-width: 1100px) { .split.detail { grid-template-columns: minmax(0, 1fr); } }
.status-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-bottom: 1px solid var(--border); }
.status-row .select { margin-left: auto; }
.nowrap { white-space: nowrap; }
.short { color: var(--danger); }
</style>
