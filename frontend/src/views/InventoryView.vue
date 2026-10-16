<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ChevronRight, Map as MapIcon } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import LotTable from '../components/LotTable.vue'
import RoomPlan from '../components/RoomPlan.vue'
import SegControl from '../components/SegControl.vue'
import { api } from '../api'
import { num, eur } from '../utils/format'
import { appSettings } from '../utils/appSettings'

const route = useRoute()
const items = ref([])
const meta = ref({ categories: [], category_stats: [], locations: [] })
const loading = ref(false)
const error = ref('')
const f = ref({ q: route.query.q ?? '', category: route.query.category ?? '', location: route.query.location ?? '', sort: appSettings.inventar.sort })
// Links wie /inventar?category=Brick oder ?location=3 setzen die Filter, auch wenn die Ansicht schon offen ist.
watch(() => [route.query.category, route.query.location, route.query.q], ([c, l, q]) => {
  if (c !== undefined) f.value.category = c
  if (l !== undefined) f.value.location = l
  if (q !== undefined) f.value.q = q
})

// Ansicht (Wunsch des Inhabers, alles in einer Liste war unübersichtlich): Standard = nach Kategorien gegliedert,
// jede Kategorie klappt auf. Mit Suche, Kategorie- oder Lagerort-Filter immer die Liste. Die Wahl merkt sich das Gerät.
const VIEW_KEY = 'tn-inventar-ansicht'
const view = ref((() => { try { return localStorage.getItem(VIEW_KEY) || 'kategorien' } catch { return 'kategorien' } })())
watch(view, (v) => { try { localStorage.setItem(VIEW_KEY, v) } catch { /* egal */ } })
const viewOptions = [{ value: 'kategorien', label: 'Kategorien' }, { value: 'liste', label: 'Liste' }]
const filtered = computed(() => !!(f.value.q.trim() || f.value.category || f.value.location))
const showList = computed(() => view.value === 'liste' || filtered.value)

let timer
async function load() {
  if (!showList.value) return
  loading.value = true
  try {
    items.value = (await api.get('inventory', { ...f.value, limit: LIMIT })).items
    error.value = ''
  } catch (e) { error.value = e.message } finally { loading.value = false }
}
watch(() => [f.value.category, f.value.location], load)
watch(() => f.value.q, () => { clearTimeout(timer); timer = setTimeout(load, 250) })
watch(showList, (v) => { if (v) load() })
watch(() => f.value.sort, () => { load(); reloadGroups() })
onMounted(async () => {
  meta.value = await api.get('inventory/meta').catch(() => meta.value)
  load()
})

// Bei über 3.000 Lots nur die ersten LIMIT laden (schnell auf dem iPad); Filter grenzen ein. Wert im Dev-Bereich einstellbar.
const LIMIT = appSettings.inventar.limit
const capped = computed(() => items.value.length >= LIMIT)
const totals = computed(() => ({
  qty: items.value.reduce((s, i) => s + i.qty, 0),
  value: items.value.reduce((s, i) => s + i.qty * i.price, 0),
}))

// Kategorien: Zahlen aus inventory/meta, Lots werden erst beim Aufklappen geladen.
const cats = computed(() => meta.value.category_stats ?? [])
const catTotals = computed(() => cats.value.reduce((s, c) => ({ lots: s.lots + c.lots, qty: s.qty + c.qty, value: s.value + c.value }), { lots: 0, qty: 0, value: 0 }))
const open = ref({}) // Kategorie → Lots (null = lädt)
async function loadGroup(c) {
  open.value[c] = null
  try {
    const list = (await api.get('inventory', { category: c, sort: f.value.sort, limit: LIMIT })).items
    if (c in open.value) open.value[c] = list
  } catch (e) { error.value = e.message; delete open.value[c] }
}
function toggle(c) {
  if (c in open.value) delete open.value[c]
  else loadGroup(c)
}
function reloadGroups() { for (const c of Object.keys(open.value)) loadGroup(c) }

// Lagerplan zum Aufklappen; die Wahl merkt sich das Gerät (Dev-Einstellung: merken, immer offen oder immer zu).
const PLAN_KEY = 'tn-inventar-lagerplan'
const planMode = appSettings.inventar.plan
const showPlan = ref(planMode === 'offen' || (planMode === 'merken' && (() => { try { return localStorage.getItem(PLAN_KEY) === '1' } catch { return false } })()))
watch(showPlan, (v) => { try { localStorage.setItem(PLAN_KEY, v ? '1' : '0') } catch { /* egal */ } })

const reset = () => { f.value = { q: '', category: '', location: '', sort: appSettings.inventar.sort } }
const subline = computed(() => {
  if (!showList.value) return `${num(cats.value.length)} Kategorien · ${num(catTotals.value.lots)} Lots · ${num(catTotals.value.qty)} Teile · ${eur(catTotals.value.value)}`
  return capped.value ? `Erste ${num(items.value.length)} Lots (Filter nutzen, um gezielt zu suchen)` : `${num(items.value.length)} Lots · ${num(totals.value.qty)} Teile · ${eur(totals.value.value)}`
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Inventar" :sub="subline">
      <button type="button" class="btn" :class="{ 'btn-primary': showPlan }" :aria-expanded="showPlan" @click="showPlan = !showPlan">
        <MapIcon :size="16" /> {{ showPlan ? 'Lagerplan ausblenden' : 'Lagerplan' }}
      </button>
    </PageHead>

    <section v-if="showPlan" class="panel">
      <div class="panel-head">
        <h2>Lagerplan</h2>
        <span class="faint small">Bereich und Kästchen antippen, um den Inhalt zu sehen</span>
      </div>
      <div class="panel-body">
        <RoomPlan :locations="meta.locations" :mark-mixed="false" />
      </div>
    </section>

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
        <button v-if="filtered" class="btn btn-ghost" @click="reset">Zurücksetzen</button>
        <SegControl v-else v-model="view" :options="viewOptions" label="Ansicht" />
      </div>
      <p v-if="error" class="error panel-body">{{ error }}</p>

      <LotTable v-if="showList" :items="items">
        <tr v-if="!items.length && !loading"><td colspan="10" class="empty">Keine Lots gefunden.</td></tr>
        <tr v-if="capped"><td colspan="10" class="empty">Es werden die ersten {{ num(LIMIT) }} Lots gezeigt. Mit Suche, Kategorie oder Lagerort lässt sich gezielt filtern.</td></tr>
      </LotTable>

      <div v-else class="cats">
        <div v-for="c in cats" :key="c.category" class="cat" :class="{ on: c.category in open }">
          <button type="button" class="cat-head" :aria-expanded="c.category in open" @click="toggle(c.category)">
            <ChevronRight :size="18" class="chev" />
            <span class="cat-name">{{ c.category }}</span>
            <span class="cat-nums"><span>{{ num(c.lots) }} Lots</span><span>{{ num(c.qty) }} Teile</span><span class="val">{{ eur(c.value) }}</span></span>
          </button>
          <template v-if="c.category in open">
            <div v-if="open[c.category] === null" class="panel-body faint small">Lädt …</div>
            <LotTable v-else :items="open[c.category]" :show-category="false">
              <tr v-if="open[c.category].length >= LIMIT"><td colspan="9" class="empty">
                Erste {{ num(LIMIT) }} Lots. <button class="btn btn-sm" type="button" @click="f.category = c.category">Nur {{ c.category }} als Liste</button>
              </td></tr>
            </LotTable>
          </template>
        </div>
        <p v-if="!cats.length" class="empty panel-body">Keine Lots gefunden.</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.grow { flex: 1; min-width: 200px; }
.cats { display: flex; flex-direction: column; }
.cat { border-bottom: 1px solid var(--border); }
.cat:last-child { border-bottom: 0; }
.cat-head { display: flex; align-items: center; gap: 10px; width: 100%; padding: 14px var(--pad); background: none; border: 0; color: var(--text); text-align: left; }
.cat-head:hover { background: var(--hover); }
.cat.on .cat-head { background: var(--surface-2); }
.chev { flex: none; color: var(--text-3); transition: transform 0.15s; }
.cat.on .chev { transform: rotate(90deg); }
.cat-name { flex: 1; min-width: 0; font-weight: 600; }
.cat-nums { display: flex; gap: 18px; color: var(--text-2); font-size: 0.86rem; font-variant-numeric: tabular-nums; }
.cat-nums .val { min-width: 92px; text-align: right; color: var(--text); }
@media (max-width: 560px) {
  .cat-head { flex-wrap: wrap; row-gap: 2px; }
  .cat-nums { width: 100%; padding-left: 28px; gap: 12px; font-size: 0.8rem; }
  .cat-nums .val { min-width: 0; margin-left: auto; }
}
</style>
