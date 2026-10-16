<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHead from '../components/PageHead.vue'
import LocationTag from '../components/LocationTag.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import { api } from '../api'
import { num, dateTime } from '../utils/format'

const route = useRoute()
const router = useRouter()
const list = ref([])
const locations = ref([])
const current = ref(null)
const newLoc = ref('')
const msg = ref('')
const error = ref('')
const busy = ref(false)

async function loadList() {
  try { list.value = (await api.get('stocktakes')).stocktakes } catch (e) { error.value = e.message }
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
    await Promise.all([loadList(), open(current.value.id)])
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
const diffText = (i) => (i.counted === null ? '' : i.counted - i.expected > 0 ? `+${i.counted - i.expected}` : String(i.counted - i.expected))
</script>

<template>
  <div class="page stack">
    <PageHead title="Inventur & Bestandsprüfung" sub="Lagerort zählen, Abweichungen erfassen, beim Abschluss wird der Bestand korrigiert.">
      <form class="toolbar" @submit.prevent="create">
        <select class="select" v-model="newLoc" required aria-label="Lagerort für neue Inventur">
          <option value="" disabled>Lagerort wählen</option>
          <option v-for="l in locations" :key="l.id" :value="l.id" :disabled="openLocations.has(l.code)">{{ l.code }} · {{ l.lots }} Lose</option>
        </select>
        <button class="btn btn-primary" :disabled="busy || !newLoc">Inventur starten</button>
      </form>
    </PageHead>
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>

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
                <td class="num" :class="{ dev: s.deviations }">{{ s.deviations }}</td>
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
              {{ progress }} von {{ current.items.length }} gezählt · {{ deviations }} Abweichung(en)
              <template v-if="current.status !== 'offen'"> · abgeschlossen {{ dateTime(current.closed_at) }}<template v-if="current.closed_by_name"> von {{ current.closed_by_name }}</template></template>
            </div>
          </div>
          <button v-if="current.status === 'offen'" class="btn btn-primary btn-sm" :disabled="busy || !progress" @click="close">Abschließen</button>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Teil</th><th>Farbe</th><th class="num">Soll</th><th class="num">Gezählt</th><th class="num">Diff.</th></tr></thead>
            <tbody>
              <tr v-for="i in current.items" :key="i.id">
                <td><div class="partcell"><PartImage :no="i.item_no" :color="i.color_id" :type="i.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${i.item_no}`">{{ i.item_no }}</RouterLink> <span class="muted small">{{ i.name }}</span></div></div></td>
                <td class="nowrap"><ColorSwatch :name="i.color_name" /></td>
                <td class="num">{{ num(i.expected) }}</td>
                <td class="num">
                  <input v-if="current.status === 'offen'" class="input count" type="number" min="0" inputmode="numeric"
                    :value="i.counted ?? ''" :placeholder="String(i.expected)" @change="save(i, $event.target.value)" :aria-label="`Gezählt ${i.item_no}`" />
                  <span v-else>{{ num(i.counted) }}</span>
                </td>
                <td class="num" :class="{ dev: i.counted !== null && i.counted !== i.expected }">{{ diffText(i) }}</td>
              </tr>
              <tr v-if="!current.items.length"><td colspan="5" class="empty">Dieser Lagerort ist leer.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.split { display: grid; grid-template-columns: 1fr; gap: var(--gap); align-items: start; }
.split.detail { grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); }
@media (max-width: 1100px) { .split.detail { grid-template-columns: minmax(0, 1fr); } }
.nowrap { white-space: nowrap; }
.count { width: 90px; height: 30px; text-align: right; }
.dev { color: var(--warn); font-weight: 500; }
</style>
