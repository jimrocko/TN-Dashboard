<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRight } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import RoomPlan from '../components/RoomPlan.vue'
import SegControl from '../components/SegControl.vue'
import { api } from '../api'
import { num, dateTime } from '../utils/format'

const route = useRoute()
const suggestions = ref([])
const history = ref([])
const locations = ref([])
const msg = ref('')
const error = ref('')
const busy = ref(false)

// Manuelle Umbuchung
const lotQuery = ref('')
const lotResults = ref([])
const lot = ref(null)
const to = ref('')
const qty = ref(1)

async function load() {
  try {
    const [s, h, m] = await Promise.all([api.get('transfers/suggestions'), api.get('transfers', { limit: 15 }), api.get('inventory/meta')])
    suggestions.value = s.suggestions
    history.value = h.transfers
    locations.value = m.locations
  } catch (e) { error.value = e.message }
}

async function book(lotId, toId, amount, label) {
  busy.value = true
  msg.value = error.value = ''
  try {
    const r = (await api.post('transfers', { lot_id: lotId, to_location_id: Number(toId), qty: Number(amount) })).transfer
    msg.value = `${num(r.qty)} × ${r.item_no} ${r.color} von ${r.from} nach ${r.to} umgelagert.`
    lot.value = null
    await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

let timer
watch(lotQuery, (q) => {
  clearTimeout(timer)
  if (!q.trim()) { lotResults.value = []; return }
  timer = setTimeout(async () => { lotResults.value = (await api.get('inventory', { q, limit: 8 })).items }, 200)
})
function pick(l) {
  lot.value = l
  qty.value = l.qty
  to.value = ''
  lotQuery.value = ''
  lotResults.value = []
}
const targets = computed(() => locations.value.filter((l) => l.id !== lot.value?.location_id))

// Vorschläge: Filter nach Grund und seitenweise anzeigen (bei vielen Losen kann die Liste lang werden).
const REASONS = { falsch: 'Falsch einsortiert', farben: 'Farben zusammenlegen', art: 'Gleiche Teile-Art' }
const reasonClass = { falsch: 'warn', farben: 'accent', art: 'ok' }
const reason = ref('alle')
const PAGE = 30
const shown = ref(PAGE)
const count = (r) => suggestions.value.filter((s) => s.reason === r).length
const reasonOptions = computed(() => [
  { value: 'alle', label: `Alle (${suggestions.value.length})` },
  { value: 'falsch', label: `${REASONS.falsch} (${count('falsch')})` },
  { value: 'farben', label: `${REASONS.farben} (${count('farben')})` },
  { value: 'art', label: `${REASONS.art} (${count('art')})` },
])
const filtered = computed(() => (reason.value === 'alle' ? suggestions.value : suggestions.value.filter((s) => s.reason === reason.value)))
watch(reason, () => { shown.value = PAGE })

// Raumplan: Zähler offener Vorschläge je Kästchen.
const openByCode = computed(() => {
  const m = {}
  for (const s of suggestions.value) m[s.lot.location_code] = (m[s.lot.location_code] ?? 0) + 1
  return m
})

onMounted(async () => {
  await load()
  if (route.query.lot) {
    const found = (await api.get('inventory', { id: route.query.lot, limit: 1 })).items[0]
    if (found) pick(found)
  }
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Umlagern" sub="Falsch einsortierte Teile zurückbringen, gleiche Teile in anderen Farben und Teile derselben Art (z. B. Bricks, Plates, Tiles) zusammenlegen, damit die Slots sauberer werden. Immer nur innerhalb eines Bereichs (gebraucht bleibt gebraucht)." />
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>

    <section class="panel">
      <div class="panel-head">
        <h2>Vorschläge</h2>
        <SegControl v-model="reason" :options="reasonOptions" label="Art der Vorschläge" />
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Grund</th><th>Teil</th><th>Farbe</th><th>Von</th><th></th><th>Nach</th><th class="num">Menge</th><th>Am Ziel</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in filtered.slice(0, shown)" :key="s.lot.id">
              <td><span class="pill" :class="reasonClass[s.reason]">{{ REASONS[s.reason] }}</span></td>
              <td><div class="partcell"><PartImage :no="s.lot.item_no" :color="s.lot.color_id" :type="s.lot.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${s.lot.item_no}`">{{ s.lot.item_no }}</RouterLink> <span class="muted small">{{ s.lot.name }}</span></div></div></td>
              <td class="nowrap"><ColorSwatch :name="s.lot.color_name" /></td>
              <td><LocationTag :code="s.lot.location_code" /></td>
              <td class="faint"><ArrowRight :size="14" /></td>
              <td><LocationTag :code="s.to_location_code" /></td>
              <td class="num">{{ num(s.lot.qty) }}</td>
              <td class="small muted">
                <template v-if="s.reason === 'falsch'">{{ num(s.target_qty) }} Stück gleiche Farbe</template>
                <template v-else-if="s.reason === 'farben'">schon da: {{ s.target_colors?.join(', ') }}</template>
                <template v-else>schon da: {{ s.target_parts?.join(', ') }}</template>
              </td>
              <td class="num"><button class="btn btn-sm" :disabled="busy" @click="book(s.lot.id, s.to_location_id, s.lot.qty)">Übernehmen</button></td>
            </tr>
            <tr v-if="!filtered.length"><td colspan="9" class="empty">Keine Vorschläge. Alles liegt dort, wo es hingehört.</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="filtered.length > shown" class="more">
        <span class="faint small">{{ shown }} von {{ filtered.length }} angezeigt</span>
        <button class="btn btn-sm" @click="shown += PAGE">Weitere {{ Math.min(PAGE, filtered.length - shown) }} anzeigen</button>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Lagerplan</h2>
        <span class="faint small">Bereich und Kästchen antippen, um den Inhalt zu sehen</span>
      </div>
      <div class="panel-body">
        <RoomPlan :locations="locations" :open="openByCode" />
      </div>
    </section>

    <div class="grid-2">
      <section class="panel">
        <div class="panel-head"><h2>Manuell umbuchen</h2></div>
        <div class="panel-body">
          <label class="field">
            <span>Los suchen</span>
            <input class="input" v-model="lotQuery" type="search" placeholder="Teil-Nr., Name, Farbe oder Lagerort" />
          </label>
          <div v-if="lotResults.length" class="results">
            <button v-for="l in lotResults" :key="l.id" class="res" type="button" @click="pick(l)">
              <PartImage :no="l.item_no" :color="l.color_id" :type="l.item_type" :size="32" />
              <span class="mono">{{ l.item_no }}</span>
              <span class="muted small grow">{{ l.color_name }} · {{ l.name }}</span>
              <LocationTag :code="l.location_code" :kind="l.location_kind" :link="false" />
              <span class="small num">{{ num(l.qty) }}</span>
            </button>
          </div>
          <form v-if="lot" class="form" @submit.prevent="book(lot.id, to, qty)">
            <div class="chosen">
              <PartImage :no="lot.item_no" :color="lot.color_id" :type="lot.item_type" :size="40" />
              <RouterLink class="mono" :to="`/teil/${lot.item_no}`">{{ lot.item_no }}</RouterLink> <ColorSwatch :name="lot.color_name" />
              <span class="faint small">· {{ num(lot.qty) }} Stück in</span> <LocationTag :code="lot.location_code" :kind="lot.location_kind" />
            </div>
            <div class="row">
              <label class="field"><span>Nach</span>
                <select class="select" v-model="to" required>
                  <option value="" disabled>Lagerort wählen</option>
                  <option v-for="l in targets" :key="l.id" :value="l.id">{{ l.code }} · {{ l.name }}</option>
                </select>
              </label>
              <label class="field"><span>Menge</span>
                <input class="input" v-model.number="qty" type="number" min="1" :max="lot.qty" required inputmode="numeric" />
              </label>
            </div>
            <button class="btn btn-primary" :disabled="busy || !to">Umbuchen</button>
          </form>
          <p v-else-if="!lotResults.length" class="faint small">Wähle ein Los aus, um es an einen anderen Lagerort zu buchen. Gleiches Teil am Ziel wird zusammengeführt.</p>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Zuletzt umgelagert</h2></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Zeit</th><th>Teil</th><th>Von → Nach</th><th class="num">Menge</th><th>Von wem</th></tr></thead>
            <tbody>
              <tr v-for="t in history" :key="t.id">
                <td class="faint nowrap">{{ dateTime(t.created_at) }}</td>
                <td><div class="partcell"><PartImage :no="t.item_no" :size="32" /><div><RouterLink class="mono" :to="`/teil/${t.item_no}`">{{ t.item_no }}</RouterLink> <span class="muted small">{{ t.color_name }}</span></div></div></td>
                <td class="nowrap"><LocationTag :code="t.from_code" /> <ArrowRight :size="12" class="faint" /> <LocationTag :code="t.to_code" /></td>
                <td class="num">{{ num(t.qty) }}</td>
                <td class="muted">{{ t.display_name }}</td>
              </tr>
              <tr v-if="!history.length"><td colspan="5" class="empty">Noch nichts umgelagert.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.more { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px var(--pad); border-top: 1px solid var(--border); }
.nowrap { white-space: nowrap; }
.results { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 14px; overflow: hidden; }
.res { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: none; border: 0; border-bottom: 1px solid var(--border); text-align: left; }
.res:last-child { border-bottom: 0; }
.res:hover { background: var(--hover); }
.grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chosen { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 10px 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 14px; }
.row { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
</style>
