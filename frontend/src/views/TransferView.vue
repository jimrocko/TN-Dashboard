<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRight } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import BrickLinkExport from '../components/BrickLinkExport.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import RoomPlan from '../components/RoomPlan.vue'
import SegControl from '../components/SegControl.vue'
import { api } from '../api'
import { num, dateTime } from '../utils/format'
import { appSettings } from '../utils/appSettings'

const route = useRoute()
const suggestions = ref([])
const history = ref([])
const locations = ref([])
const msg = ref('')
const error = ref('')
const busy = ref(false)
const blExport = ref(null)

// Manuelle Umbuchung
const lotQuery = ref('')
const lotResults = ref([])
const lot = ref(null)
const to = ref('')
const qty = ref(1)

async function load() {
  try {
    const [s, h, m] = await Promise.all([api.get('transfers/suggestions'), api.get('transfers', { limit: appSettings.umlagern.history }), api.get('inventory/meta')])
    suggestions.value = s.suggestions
    history.value = h.transfers
    locations.value = m.locations
  } catch (e) { error.value = e.message }
}

async function book(l, toId, amount) {
  busy.value = true
  msg.value = error.value = ''
  try {
    // Erwarteter Stand mit: hat inzwischen jemand anders dieses Lot umgelagert, lehnt der Server ab (409)
    const r = (await api.post('transfers', { lot_id: l.id, to_location_id: Number(toId), qty: Number(amount),
      expect_location_id: l.location_id, expect_qty: l.qty })).transfer
    msg.value = `${num(r.qty)} × ${r.item_no} ${r.color} von ${r.from} nach ${r.to} umgelagert.`
    lot.value = null
    await Promise.all([load(), blExport.value?.refresh({ popup: appSettings.umlagern.popup })])
  } catch (e) {
    error.value = e.message
    if (e.status === 409) { lot.value = null; await load() }
  } finally { busy.value = false }
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

// Vorschläge: Filter nach Grund und seitenweise anzeigen (bei vielen Lots kann die Liste lang werden).
const REASONS = { falsch: 'Falsch einsortiert', farben: 'Farben zusammenlegen', art: 'Gleiche Teile-Art' }
const reason = ref(appSettings.umlagern.reason)
const PAGE = appSettings.umlagern.page
const shown = ref(PAGE)
const count = (r) => suggestions.value.filter((s) => s.reason === r).length
const filtered = computed(() => (reason.value === 'alle' ? suggestions.value : suggestions.value.filter((s) => s.reason === reason.value)))

// Ein Panel mit Reitern wie im Minifig-Finder (Wunsch des Inhabers: „übersichtlicher, es ist so viel“)
const tab = ref(route.query.lot ? 'manuell' : 'vorschlaege')
const tabs = computed(() => [
  { value: 'vorschlaege', label: 'Vorschläge', count: suggestions.value.length },
  ...(appSettings.umlagern.plan ? [{ value: 'plan', label: 'Lagerplan' }] : []),
  { value: 'manuell', label: 'Manuell umbuchen' },
  { value: 'verlauf', label: 'Verlauf' },
])
// Zwei Ansichten: je Teil (Liste) oder je Ziel-Kästchen (alles, was in dasselbe Kästchen gehört, zusammen). Gemerkt pro Gerät.
const VIEW_KEY = 'tn-umlagern-ansicht'
const view = ref((() => { try { return localStorage.getItem(VIEW_KEY) || 'ziel' } catch { return 'ziel' } })())
watch(view, (v) => { shown.value = PAGE; try { localStorage.setItem(VIEW_KEY, v) } catch { /* egal */ } })
watch(reason, () => { shown.value = PAGE })
const viewOptions = [{ value: 'ziel', label: 'Nach Ziel-Kästchen' }, { value: 'teil', label: 'Nach Teil' }]
const nat = (a, b) => String(a).localeCompare(String(b), 'de', { numeric: true })
const groups = computed(() => {
  const m = new Map()
  for (const s of filtered.value) {
    const g = m.get(s.to_location_code) ?? { code: s.to_location_code, id: s.to_location_id, rows: [], already: new Set() }
    g.rows.push(s)
    for (const x of s.reason === 'falsch' ? [] : s.reason === 'farben' ? s.target_colors ?? [] : s.target_parts ?? []) g.already.add(x)
    m.set(s.to_location_code, g)
  }
  return [...m.values()].sort((a, b) => b.rows.length - a.rows.length || nat(a.code, b.code))
})
const total = computed(() => (view.value === 'ziel' ? groups.value.length : filtered.value.length))
// Kurze Zusatzinfo je Vorschlag (früher eigene Spalte „Am Ziel“)
const hint = (s) => (s.reason === 'falsch' ? `am Ziel schon ${num(s.target_qty)} Stück` : '')

// „Alle übernehmen“: Vorschläge eines Kästchens nacheinander buchen, danach einmal das BrickLink-Pop-up
async function bookMany(rows) {
  busy.value = true
  msg.value = error.value = ''
  let ok = 0
  let skipped = 0
  try {
    for (const s of rows) {
      try {
        await api.post('transfers', { lot_id: s.lot.id, to_location_id: s.to_location_id, qty: s.lot.qty, expect_location_id: s.lot.location_id, expect_qty: s.lot.qty })
        ok++
      } catch { skipped++ }
    }
    msg.value = `${ok} Lot(s) nach ${rows[0].to_location_code} umgelagert.${skipped ? ` ${skipped} übersprungen (inzwischen geändert).` : ''}`
    await Promise.all([load(), blExport.value?.refresh({ popup: appSettings.umlagern.popup })])
  } finally { busy.value = false }
}

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
    if (found) { pick(found); tab.value = 'manuell' }
  }
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Umlagern" sub="Teile ins richtige Kästchen bringen und zusammenlegen, immer im selben Bereich." />
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>

    <section class="panel umlist">
      <div class="tabs" role="tablist" aria-label="Umlagern">
        <button v-for="t in tabs" :key="t.value" type="button" role="tab" class="tabbtn" :class="[{ on: tab === t.value }, `t-${t.value}`]" :aria-selected="tab === t.value" @click="tab = t.value">
          {{ t.label }} <span v-if="t.count !== undefined" class="cnt">{{ num(t.count) }}</span></button>
      </div>

      <!-- Vorschläge -->
      <template v-if="tab === 'vorschlaege'">
        <div class="bar">
          <SegControl v-model="view" :options="viewOptions" label="Ansicht" />
          <select v-model="reason" class="select" aria-label="Art der Vorschläge">
            <option value="alle">Alle Arten ({{ num(suggestions.length) }})</option>
            <option v-for="(l, k) in REASONS" :key="k" :value="k">{{ l }} ({{ num(count(k)) }})</option>
          </select>
        </div>

        <!-- Nach Ziel-Kästchen -->
        <div v-if="view === 'ziel'" class="groups">
          <div v-for="g in groups.slice(0, shown)" :key="g.code" class="group">
            <div class="ghead">
              <div class="gtitle"><span class="faint">Nach</span> <LocationTag :code="g.code" class="big" />
                <span class="faint small">{{ g.rows.length === 1 ? '1 Lot' : `${g.rows.length} Lots` }}<template v-if="g.already.size"> · schon da: {{ [...g.already].join(', ') }}</template></span></div>
              <button v-if="g.rows.length > 1" class="btn btn-sm btn-primary" :disabled="busy" @click="bookMany(g.rows)">Alle übernehmen</button>
            </div>
            <div v-for="s in g.rows" :key="s.lot.id" class="sugrow">
              <PartImage :no="s.lot.item_no" :color="s.lot.color_id" :type="s.lot.item_type" :size="44" />
              <div class="what">
                <div><RouterLink class="mono strong" :to="`/teil/${s.lot.item_no}`">{{ s.lot.item_no }}</RouterLink> <ColorSwatch v-if="s.lot.color_name" :name="s.lot.color_name" /></div>
                <div class="small muted ell">{{ s.lot.name }}</div>
                <div class="small why" :class="`r-${s.reason}`"><i /><span>{{ REASONS[s.reason] }}<template v-if="hint(s)"> · {{ hint(s) }}</template></span></div>
              </div>
              <div class="from"><span class="faint small">aus</span> <LocationTag :code="s.lot.location_code" /></div>
              <div class="qty">{{ num(s.lot.qty) }}×</div>
              <button class="btn btn-sm btn-take" :class="{ 'btn-primary': g.rows.length === 1 }" :disabled="busy" @click="book(s.lot, s.to_location_id, s.lot.qty)">Übernehmen</button>
            </div>
          </div>
        </div>

        <!-- Nach Teil -->
        <div v-else class="groups">
          <div v-for="s in filtered.slice(0, shown)" :key="s.lot.id" class="sugrow single">
            <PartImage :no="s.lot.item_no" :color="s.lot.color_id" :type="s.lot.item_type" :size="44" />
            <div class="what">
              <div><RouterLink class="mono strong" :to="`/teil/${s.lot.item_no}`">{{ s.lot.item_no }}</RouterLink> <ColorSwatch v-if="s.lot.color_name" :name="s.lot.color_name" /></div>
              <div class="small muted ell">{{ s.lot.name }}</div>
              <div class="small why" :class="`r-${s.reason}`"><i /><span>{{ REASONS[s.reason] }}<template v-if="hint(s)"> · {{ hint(s) }}</template></span></div>
            </div>
            <div class="route"><LocationTag :code="s.lot.location_code" /> <ArrowRight :size="16" class="faint" /> <LocationTag :code="s.to_location_code" /></div>
            <div class="qty">{{ num(s.lot.qty) }}×</div>
            <button class="btn btn-sm btn-take btn-primary" :disabled="busy" @click="book(s.lot, s.to_location_id, s.lot.qty)">Übernehmen</button>
          </div>
        </div>

        <p v-if="!filtered.length" class="empty">Keine Vorschläge. Alles liegt dort, wo es hingehört.</p>
        <div v-if="total > shown" class="more">
          <span class="faint small">{{ shown }} von {{ num(total) }} {{ view === 'ziel' ? 'Kästchen' : 'Vorschlägen' }}</span>
          <button class="btn btn-sm" @click="shown += PAGE">Weitere {{ Math.min(PAGE, total - shown) }} anzeigen</button>
        </div>
      </template>

      <!-- Lagerplan -->
      <div v-else-if="tab === 'plan'" class="panel-body">
        <p class="faint small top0">Bereich und Kästchen antippen, um den Inhalt zu sehen. Rot = Mischkiste, die noch aufgeteilt werden muss.</p>
        <RoomPlan :locations="locations" :open="openByCode" />
      </div>

      <!-- Manuell umbuchen -->
      <div v-else-if="tab === 'manuell'" class="panel-body manual">
        <label class="field">
          <span>Lot suchen</span>
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
        <form v-if="lot" class="form" @submit.prevent="book(lot, to, qty)">
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
        <p v-else-if="!lotResults.length" class="faint small">Wähle ein Lot aus, um es an einen anderen Lagerort zu buchen. Gleiches Teil am Ziel wird zusammengeführt.</p>
      </div>

      <!-- Verlauf -->
      <div v-else class="table-wrap">
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
    <BrickLinkExport ref="blExport" />
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; padding: 8px var(--pad) 0; overflow-x: auto; border-bottom: 1px solid var(--border); scrollbar-width: thin; }
.tabbtn { flex: none; display: inline-flex; gap: 6px; align-items: center; padding: 9px 14px; border: 1px solid transparent; border-bottom: 0; border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  background: none; color: var(--text-2); font: inherit; font-size: 0.92rem; cursor: pointer; margin-bottom: -1px; }
.tabbtn.on { background: var(--surface); border-color: var(--border); color: var(--text); font-weight: 600; }
.tabbtn .cnt { padding: 0 7px; border-radius: 10px; background: var(--surface-2); font-size: 0.78rem; color: var(--warn); font-variant-numeric: tabular-nums; }
.bar { display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: 12px var(--pad); }
.groups { display: flex; flex-direction: column; gap: 12px; padding: 0 var(--pad) 14px; }
.group { border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
.ghead { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px; background: var(--surface-2); border-bottom: 1px solid var(--border); }
.gtitle { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 1.05rem; }
.gtitle :deep(.big) { font-size: 1rem; font-weight: 700; }
.sugrow { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto auto; gap: 14px; align-items: center; padding: 10px 14px; border-top: 1px solid var(--border); }
.ghead + .sugrow { border-top: 0; }
.sugrow.single { border: 1px solid var(--border); border-radius: var(--radius-sm); }
.what { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.strong { font-weight: 700; }
.ell { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.why { display: flex; align-items: baseline; gap: 6px; color: var(--text-3); }
.why i { position: relative; top: -1px; }
.why i { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex: none; }
.why.r-falsch i { background: var(--warn); }
.why.r-farben i { background: var(--accent); }
.why.r-art i { background: var(--ok); }
.from, .route { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
.from :deep(.tag), .route :deep(.tag), .gtitle :deep(.tag) { font-size: 1rem; font-weight: 700; padding: 3px 8px; }
.qty { font-size: 1.25rem; font-weight: 800; min-width: 3.5ch; text-align: right; font-variant-numeric: tabular-nums; }
.empty { padding: 24px var(--pad); text-align: center; color: var(--text-3); margin: 0; }
.more { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px var(--pad); border-top: 1px solid var(--border); }
.top0 { margin-top: 0; }
.manual { max-width: 720px; }
.nowrap { white-space: nowrap; }
.results { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 14px; overflow: hidden; }
.res { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: none; border: 0; border-bottom: 1px solid var(--border); text-align: left; }
.res:last-child { border-bottom: 0; }
.res:hover { background: var(--hover); }
.grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chosen { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 10px 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 14px; }
.row { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
/* Handy: Bild, Teil und Menge oben, Weg und Knopf darunter */
@media (max-width: 640px) {
  .sugrow { grid-template-columns: auto minmax(0, 1fr) auto; grid-template-areas: 'img what qty' 'way way btn'; gap: 8px 10px; }
  .sugrow > :first-child { grid-area: img; }
  .what { grid-area: what; }
  .qty { grid-area: qty; align-self: start; }
  .from, .route { grid-area: way; }
  .btn-take { grid-area: btn; justify-self: end; }
}
</style>
