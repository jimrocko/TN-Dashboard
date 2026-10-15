<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftRight } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import { api } from '../api'
import { num, eur, date } from '../utils/format'

const route = useRoute()
const router = useRouter()
const items = ref([])
const meta = ref({ categories: [], locations: [] })
const loading = ref(false)
const error = ref('')
const f = ref({ q: '', category: '', location: route.query.location ?? '', sort: 'item' })

let timer
async function load() {
  loading.value = true
  try {
    items.value = (await api.get('inventory', f.value)).items
    error.value = ''
  } catch (e) { error.value = e.message } finally { loading.value = false }
}
watch(() => [f.value.category, f.value.location, f.value.sort], load)
watch(() => f.value.q, () => { clearTimeout(timer); timer = setTimeout(load, 250) })
onMounted(async () => {
  meta.value = await api.get('inventory/meta').catch(() => meta.value)
  load()
})

const totals = computed(() => ({
  qty: items.value.reduce((s, i) => s + i.qty, 0),
  value: items.value.reduce((s, i) => s + i.qty * i.price, 0),
}))
const reset = () => { f.value = { q: '', category: '', location: '', sort: 'item' } }
</script>

<template>
  <div class="page stack">
    <PageHead title="Inventar" :sub="`${num(items.length)} Lose · ${num(totals.qty)} Teile · ${eur(totals.value)}`" />

    <div class="panel">
      <div class="panel-head toolbar">
        <input class="input grow" v-model="f.q" type="search" placeholder="Filtern nach Nummer, Name, Farbe, Lagerort …" aria-label="Filter" />
        <select class="select" v-model="f.category" aria-label="Kategorie">
          <option value="">Alle Kategorien</option>
          <option v-for="c in meta.categories" :key="c">{{ c }}</option>
        </select>
        <select class="select" v-model="f.location" aria-label="Lagerort">
          <option value="">Alle Lagerorte</option>
          <option v-for="l in meta.locations" :key="l.id" :value="String(l.id)">{{ l.code }} · {{ l.name }}</option>
        </select>
        <select class="select" v-model="f.sort" aria-label="Sortierung">
          <option value="item">Nach Teil-Nr.</option>
          <option value="location">Nach Lagerort</option>
          <option value="qty">Nach Menge</option>
          <option value="updated">Zuletzt geändert</option>
        </select>
        <button class="btn btn-ghost" @click="reset">Zurücksetzen</button>
      </div>
      <p v-if="error" class="error panel-body">{{ error }}</p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Teil-Nr.</th><th>Bezeichnung</th><th>Farbe</th><th>Kategorie</th><th>Lagerort</th>
              <th class="num">Menge</th><th class="num">Stückpreis</th><th class="num">Wert</th><th>Geändert</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="i in items" :key="i.id">
              <td class="mono"><RouterLink :to="`/teil/${i.item_no}`">{{ i.item_no }}</RouterLink></td>
              <td>{{ i.name }}</td>
              <td class="nowrap"><ColorSwatch :name="i.color_name" /></td>
              <td class="muted">{{ i.category }}</td>
              <td><LocationTag :code="i.location_code" :kind="i.location_kind" /></td>
              <td class="num" :class="{ low: i.qty < 20 && i.location_kind === 'regal' }">{{ num(i.qty) }}</td>
              <td class="num muted">{{ eur(i.price) }}</td>
              <td class="num">{{ eur(i.qty * i.price) }}</td>
              <td class="faint nowrap">{{ date(i.updated_at) }}</td>
              <td><button class="btn btn-ghost btn-sm" title="Umlagern" @click="router.push({ path: '/umlagern', query: { lot: i.id } })"><ArrowLeftRight :size="15" /></button></td>
            </tr>
            <tr v-if="!items.length && !loading"><td colspan="10" class="empty">Keine Lose gefunden.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.grow { flex: 1; min-width: 200px; }
.nowrap { white-space: nowrap; }
.low { color: var(--warn); }
</style>
