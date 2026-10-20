<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHead from '../components/PageHead.vue'
import LocationTag from '../components/LocationTag.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import BrickLinkExport from '../components/BrickLinkExport.vue'
import { api } from '../api'
import { num, dateTime } from '../utils/format'
import { appSettings } from '../utils/appSettings'

const route = useRoute()
const router = useRouter()
const list = ref([])
const locations = ref([])
const current = ref(null)
const newLoc = ref('')
const msg = ref('')
const error = ref('')
const busy = ref(false)
const blExport = ref(null)

async function loadList() {
  try {
    const [l, t] = await Promise.all([api.get('stocktakes'), api.get('stocktakes/today')])
    list.value = l.stocktakes
    today.value = t
  } catch (e) { error.value = e.message }
}
// ---- Zählliste des Tages (gleich für alle) und Vormerkungen aus Meldungen der Pickliste ----
const today = ref(null)
const todayDone = computed(() => (today.value?.items ?? []).filter((x) => x.done).length)
const WHY = { vorgemerkt: 'warn', 'nie gezählt': '', 'lange nicht gezählt': '' }
async function startToday(x) {
  if (x.open_stocktake) return select(x.open_stocktake)
  busy.value = true
  msg.value = error.value = ''
  try {
    const s = (await api.post('stocktakes', { location_id: x.location_id })).stocktake
    await loadList()
    select(s.id)
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function open(id) {
  if (!id) { current.value = null; return }
  try { current.value = (await api.get(`stocktakes/${id}`)).stocktake } catch (e) { error.value = e.message }
}
const select = (id) => router.replace({ query: { id } })
watch(() => route.query.id, open, { immediate: true })
onMounted(async () => {
  await loadList()
  locations.value = (await api.get('inventory/meta')).locations
})

const openLocations = computed(() => new Set(list.value.filter((s) => s.status === 'offen').map((s) => s.location_code)))

async function create() {
  busy.value = true
  msg.value = error.value = ''
  try {
    const s = (await api.post('stocktakes', { location_id: Number(newLoc.value) })).stocktake
    newLoc.value = ''
    await loadList()
    select(s.id)
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

async function save(item, value) {
  const counted = value === '' ? null : Number(value)
  try {
    await api.post(`stocktakes/${current.value.id}/count`, { item_id: item.id, counted })
    item.counted = counted
  } catch (e) { error.value = e.message }
}
const progress = computed(() => current.value ? current.value.items.filter((i) => i.counted !== null).length : 0)
const deviations = computed(() => current.value ? current.value.items.filter((i) => i.counted !== null && i.counted !== i.expected).length : 0)

async function close() {
  busy.value = true
  msg.value = error.value = ''
  try {
    const r = await api.post(`stocktakes/${current.value.id}/close`)
    msg.value = `Inventur ${r.location} abgeschlossen. ${r.corrections.length} Bestandskorrektur(en) gebucht.`
    // Korrekturen gehen wie beim Umlagern per Kopieren zu BrickLink: gleiches Pop-up (nur wenn sich etwas geändert hat)
    await Promise.all([loadList(), open(current.value.id), r.corrections.length ? blExport.value?.refresh({ popup: true }) : null])
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
// Dev-Einstellung „Blind zählen“: Soll und Differenz bleiben verborgen, bis die Inventur abgeschlossen ist.
const hideDev = (s) => appSettings.inventur.blind && s.status === 'offen'
const blind = computed(() => appSettings.inventur.blind && current.value?.status === 'offen')
const diffText = (i) => (i.counted === null ? '' : i.counted - i.expected > 0 ? `+${i.counted - i.expected}` : String(i.counted - i.expected))
</script>

<template>
  <div class="page stack">
    <PageHead title="Inventur & Bestandsprüfung" sub="Lagerort zählen, Abweichungen erfassen, beim Abschluss wird der Bestand korrigiert.">
      <form class="toolbar" @submit.prevent="create">
        <select class="select" v-model="newLoc" required aria-label="Lagerort für neue Inventur">
          <option value="" disabled>Lagerort wählen</option>
          <option v-for="l in locations" :key="l.id" :value="l.id" :disabled="openLocations.has(l.code)">{{ l.code }} · {{ l.lots }} Lots</option>
        </select>
        <button class="btn btn-primary" :disabled="busy || !newLoc">Inventur starten</button>
      </form>
    </PageHead>
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>

    <section v-if="today" class="panel today">
      <div class="panel-head">
        <h2>Heute zählen</h2>
        <span class="faint small">{{ todayDone }} von {{ today.items.length }} erledigt · gleich für alle, jeden Tag neu</span>
      </div>
      <div class="cards">
        <div v-for="x in [...today.items, ...today.extra]" :key="x.location_id" class="card" :class="{ done: x.done }">
          <div class="card-top">
            <LocationTag :code="x.code" />
            <span v-if="x.done" class="pill ok">erledigt</span>
            <span v-else class="pill" :class="WHY[x.why]">{{ x.why }}</span>
          </div>
          <div class="faint small">{{ num(x.lots) }} Lots · {{ num(x.parts) }} Teile<template v-if="x.last_counted"> · zuletzt {{ dateTime(x.last_counted) }}</template></div>
          <ul v-if="x.requests.length" class="reqs small">
            <li v-for="(r, k) in x.requests" :key="k">{{ r.label }}<template v-if="r.by"> · {{ r.by }}</template> · {{ dateTime(r.at) }}</li>
          </ul>
          <button v-if="!x.done" class="btn btn-sm" :class="{ 'btn-primary': !x.open_stocktake }" :disabled="busy" @click="startToday(x)">
            {{ x.open_stocktake ? 'Weiterzählen' : 'Starten' }}</button>
        </div>
      </div>
      <p v-if="today.extra.length" class="faint small pad">Zusätzlich vorgemerkt, nachdem die Liste heute festgelegt wurde: {{ today.extra.map((x) => x.code).join(', ') }}.</p>
    </section>

    <div class="split" :class="{ detail: current }">
      <section class="panel">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Lagerort</th><th>Status</th><th class="num">Gezählt</th><th class="num">Abweichungen</th><th>Erstellt</th></tr></thead>
            <tbody>
              <tr v-for="s in list" :key="s.id" class="clickable" :class="{ selected: current?.id === s.id }" @click="select(s.id)">
                <td><LocationTag :code="s.location_code" /> <span class="faint small">{{ s.location_name }}</span></td>
                <td><span class="pill" :class="s.status === 'offen' ? 'warn' : 'ok'">{{ s.status === 'offen' ? 'Offen' : 'Abgeschlossen' }}</span></td>
                <td class="num">{{ s.counted }} / {{ s.positions }}</td>
                <td class="num" :class="{ dev: s.deviations && !hideDev(s) }">{{ hideDev(s) ? '–' : s.deviations }}</td>
                <td class="faint nowrap">{{ dateTime(s.created_at) }}</td>
              </tr>
              <tr v-if="!list.length"><td colspan="5" class="empty">Noch keine Inventuren.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="current" class="panel">
        <div class="panel-head">
          <div>
            <h2>Inventur <LocationTag :code="current.location_code" /></h2>
            <div class="faint small">
              {{ progress }} von {{ current.items.length }} gezählt<template v-if="!blind"> · {{ deviations }} Abweichung(en)</template>
              <template v-if="current.status !== 'offen'"> · abgeschlossen {{ dateTime(current.closed_at) }}<template v-if="current.closed_by_name"> von {{ current.closed_by_name }}</template></template>
            </div>
          </div>
          <button v-if="current.status === 'offen'" class="btn btn-primary btn-sm" :disabled="busy || !progress" @click="close">Abschließen</button>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Teil</th><th>Farbe</th><th v-if="!blind" class="num">Soll</th><th class="num">Gezählt</th><th v-if="!blind" class="num">Diff.</th></tr></thead>
            <tbody>
              <tr v-for="i in current.items" :key="i.id">
                <td><div class="partcell"><PartImage :no="i.item_no" :color="i.color_id" :type="i.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${i.item_no}`">{{ i.item_no }}</RouterLink> <span class="muted small">{{ i.name }}</span></div></div></td>
                <td class="nowrap"><ColorSwatch :name="i.color_name" /></td>
                <td v-if="!blind" class="num">{{ num(i.expected) }}</td>
                <td class="num">
                  <input v-if="current.status === 'offen'" class="input count" type="number" min="0" inputmode="numeric"
                    :value="i.counted ?? ''" :placeholder="blind ? '' : String(i.expected)" @change="save(i, $event.target.value)" :aria-label="`Gezählt ${i.item_no}`" />
                  <span v-else>{{ num(i.counted) }}</span>
                </td>
                <td v-if="!blind" class="num" :class="{ dev: i.counted !== null && i.counted !== i.expected }">{{ diffText(i) }}</td>
              </tr>
              <tr v-if="!current.items.length"><td colspan="5" class="empty">Dieser Lagerort ist leer.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
    <BrickLinkExport ref="blExport" />
  </div>
</template>

<style scoped>
.split { display: grid; grid-template-columns: 1fr; gap: var(--gap); align-items: start; }
.split.detail { grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); }
@media (max-width: 1100px) { .split.detail { grid-template-columns: minmax(0, 1fr); } }
.nowrap { white-space: nowrap; }
.count { width: 90px; height: 30px; text-align: right; }
.dev { color: var(--warn); font-weight: 500; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; padding: 12px var(--pad) 14px; }
.card { display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); }
.card.done { opacity: 0.6; }
.card .btn { align-self: flex-start; margin-top: auto; }
.card-top { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
.reqs { margin: 0; padding-left: 16px; color: var(--warn); }
.pad { margin: 0; padding: 0 var(--pad) 12px; }
</style>
