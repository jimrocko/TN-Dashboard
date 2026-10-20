<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight, ArrowRight, ClipboardCheck } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import BrickLinkExport from '../components/BrickLinkExport.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import PartImage from '../components/PartImage.vue'
import { api } from '../api'
import { num, eur, dateTime, orderStatusClass } from '../utils/format'
import { isMixedBox, maxLotsFor } from '../utils/areas'

// Lagerort-Seite: was liegt drin, was gehört hierher oder woanders hin, welche Aufträge holen hier Teile.
// Erreichbar über jeden Lagerort-Tag in der App.
const route = useRoute()
const router = useRouter()
const blExport = ref(null)
const loc = ref(null)
const error = ref('')
const busy = ref(false)
const msg = ref('')
const filter = ref('')

async function load() {
  error.value = ''
  try { loc.value = (await api.get(`location/${encodeURIComponent(route.params.code)}`)).location } catch (e) { error.value = e.message; loc.value = null }
}
watch(() => route.params.code, (c) => { if (c) { filter.value = ''; msg.value = ''; load() } }, { immediate: true })

const lots = computed(() => {
  const f = filter.value.trim().toLowerCase()
  const all = loc.value?.lots ?? []
  return f ? all.filter((l) => [l.item_no, l.name, l.color_name, l.category].some((v) => String(v).toLowerCase().includes(f))) : all
})
const stats = computed(() => {
  const all = loc.value?.lots ?? []
  return {
    qty: all.reduce((s, l) => s + l.qty, 0),
    value: all.reduce((s, l) => s + l.qty * l.price, 0),
    parts: new Set(all.map((l) => l.item_no)).size,
    categories: [...new Set(all.map((l) => l.category))].sort(),
  }
})
const outgoing = computed(() => (loc.value?.suggestions ?? []).filter((s) => s.lot.location_id === loc.value.id))
const incoming = computed(() => (loc.value?.suggestions ?? []).filter((s) => s.to_location_id === loc.value.id))

async function book(s) {
  busy.value = true
  msg.value = ''
  try {
    // Erwarteter Stand mit: hat inzwischen jemand anders dieses Lot umgelagert, lehnt der Server ab (409)
    const r = (await api.post('transfers', { lot_id: s.lot.id, to_location_id: s.to_location_id, qty: s.lot.qty,
      expect_location_id: s.lot.location_id, expect_qty: s.lot.qty })).transfer
    msg.value = `${num(r.qty)} × ${r.item_no} ${r.color} von ${r.from} nach ${r.to} umgelagert.`
    await Promise.all([load(), blExport.value?.refresh({ popup: true })])
  } catch (e) {
    error.value = e.message
    if (e.status === 409) await load()
  } finally { busy.value = false }
}
async function startStocktake() {
  busy.value = true
  try {
    const s = (await api.post('stocktakes', { location_id: loc.value.id })).stocktake
    router.push({ path: '/inventur', query: { id: s.id } })
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
const kindLabel = { regal: 'Regalplatz', mischkiste: 'Mischkiste' }
// Kästchen mit zu vielen verschiedenen Lots (bei G mehr als 3) = Mischkiste, muss noch aufgeteilt werden
const mixed = computed(() => !!loc.value && isMixedBox(loc.value.code, loc.value.lots.length))
</script>

<template>
  <div class="page stack">
    <PageHead :title="loc ? `${loc.code} · ${loc.name}` : `Lagerort ${route.params.code}`" :sub="loc ? `${kindLabel[loc.kind]} · ${num(loc.lots.length)} Lots` : ''">
      <template v-if="loc">
        <RouterLink v-if="loc.prev" class="btn btn-sm" :to="`/lagerort/${loc.prev}`" :title="`Vorheriger: ${loc.prev}`"><ChevronLeft :size="16" /> {{ loc.prev }}</RouterLink>
        <RouterLink v-if="loc.next" class="btn btn-sm" :to="`/lagerort/${loc.next}`" :title="`Nächster: ${loc.next}`">{{ loc.next }} <ChevronRight :size="16" /></RouterLink>
      </template>
    </PageHead>
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <div v-if="mixed" class="mixed-note" role="status">
      <strong>Mischkiste:</strong> {{ loc.lots.length }} verschiedene Lots, erlaubt sind höchstens {{ maxLotsFor(loc.code) }}.
      Bitte aufteilen und überzählige Lots in andere Kästchen umlagern.
    </div>

    <template v-if="loc">
      <div v-if="loc.count_requests?.length" class="count-note" role="status">
        <strong>Zum Zählen vorgemerkt:</strong>
        {{ loc.count_requests.map((r) => `${r.label}${r.by ? ` (${r.by})` : ''}`).join(' · ') }}.
        Nach der nächsten abgeschlossenen Inventur hier ist die Vormerkung erledigt.
      </div>
      <div class="stats">
        <div class="stat"><div class="label">Teile</div><div class="value">{{ num(stats.qty) }}</div><div class="faint small">in {{ num(loc.lots.length) }} Lots</div></div>
        <div class="stat"><div class="label">Verschiedene Teile</div><div class="value">{{ num(stats.parts) }}</div><div class="faint small">Teil-Nummern</div></div>
        <div class="stat"><div class="label">Wert</div><div class="value">{{ eur(stats.value) }}</div><div class="faint small">zum Lagerpreis</div></div>
        <div class="stat">
          <div class="label">Inventur</div>
          <div class="value small-value">{{ loc.stocktake ? (loc.stocktake.status === 'offen' ? 'offen' : 'geprüft') : 'noch nie' }}</div>
          <div class="faint small">{{ loc.stocktake ? dateTime(loc.stocktake.closed_at ?? loc.stocktake.created_at) : '–' }}</div>
        </div>
      </div>

      <div class="actions">
        <RouterLink v-if="loc.stocktake?.status === 'offen'" class="btn btn-primary" :to="{ path: '/inventur', query: { id: loc.stocktake.id } }"><ClipboardCheck :size="16" /> Offene Inventur öffnen</RouterLink>
        <button v-else class="btn btn-primary" :disabled="busy || !loc.lots.length" @click="startStocktake"><ClipboardCheck :size="16" /> Inventur starten</button>
        <RouterLink class="btn" :to="{ path: '/inventar', query: { location: loc.id } }">Im Inventar zeigen</RouterLink>
        <RouterLink v-for="c in stats.categories" :key="c" class="chip" :to="{ path: '/inventar', query: { category: c } }">{{ c }}</RouterLink>
      </div>

      <section class="panel">
        <div class="panel-head toolbar">
          <h2>Inhalt</h2>
          <input id="loc-filter" class="input grow" v-model="filter" type="search" placeholder="Im Lagerort filtern" aria-label="Filter" />
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th></th><th>Teil-Nr.</th><th>Bezeichnung</th><th>Farbe</th><th class="num">Menge</th><th class="num">Wert</th><th>Geändert</th><th></th></tr></thead>
            <tbody>
              <tr v-for="l in lots" :key="l.id" class="clickable" @click="router.push(`/teil/${l.item_no}`)">
                <td class="tcell"><PartImage :no="l.item_no" :color="l.color_id" :type="l.item_type" :size="36" /></td>
                <td class="mono"><RouterLink :to="`/teil/${l.item_no}`" @click.stop>{{ l.item_no }}</RouterLink></td>
                <td>{{ l.name }}</td>
                <td class="nowrap"><ColorSwatch :name="l.color_name" /></td>
                <td class="num strong">{{ num(l.qty) }}</td>
                <td class="num">{{ eur(l.qty * l.price) }}</td>
                <td class="faint nowrap">{{ dateTime(l.updated_at) }}</td>
                <td class="num"><RouterLink class="btn btn-sm" :to="{ path: '/umlagern', query: { lot: l.id } }" @click.stop>Umlagern</RouterLink></td>
              </tr>
              <tr v-if="!lots.length"><td colspan="8" class="empty">{{ loc.lots.length ? 'Nichts passt zum Filter.' : 'Dieser Lagerort ist leer.' }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="outgoing.length || incoming.length" class="panel">
        <div class="panel-head">
          <h2>Umlagerungsvorschläge</h2>
          <span class="faint small">{{ outgoing.length }} von hier weg · {{ incoming.length }} hierher</span>
        </div>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <tr v-for="s in [...outgoing, ...incoming]" :key="s.lot.id">
                <td><span class="pill" :class="{ falsch: 'warn', farben: 'accent', art: 'ok' }[s.reason]">{{ { falsch: 'Falsch einsortiert', farben: 'Farben zusammenlegen', art: 'Gleiche Teile-Art' }[s.reason] }}</span></td>
                <td><div class="partcell"><PartImage :no="s.lot.item_no" :color="s.lot.color_id" :type="s.lot.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${s.lot.item_no}`">{{ s.lot.item_no }}</RouterLink> <span class="muted small">{{ s.lot.name }}</span></div></div></td>
                <td class="nowrap"><ColorSwatch :name="s.lot.color_name" /></td>
                <td class="nowrap"><LocationTag :code="s.lot.location_code" :kind="s.lot.location_kind" /> <ArrowRight :size="13" class="faint" /> <LocationTag :code="s.to_location_code" /></td>
                <td class="num">{{ num(s.lot.qty) }}</td>
                <td class="num"><button class="btn btn-sm" :disabled="busy" @click="book(s)">Übernehmen</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="grid-2">
        <section class="panel">
          <div class="panel-head"><h2>Offene Aufträge holen hier</h2><span class="faint small">{{ loc.picks.length }}</span></div>
          <div class="table-wrap">
            <table class="table">
              <tbody>
                <tr v-for="(p, i) in loc.picks" :key="i">
                  <td class="mono"><RouterLink :to="{ path: '/auftraege', query: { status: '', id: p.id } }">{{ p.order_no }}</RouterLink></td>
                  <td><div class="partcell"><PartImage :no="p.item_no" :color="p.color_id" :type="p.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${p.item_no}`">{{ p.item_no }}</RouterLink> <span class="faint small"><ColorSwatch :name="p.color_name" /></span></div></div></td>
                  <td class="num">{{ num(p.qty) }} Stk.</td>
                  <td><span class="pill" :class="orderStatusClass(p.status)">{{ p.status }}</span></td>
                </tr>
                <tr v-if="!loc.picks.length"><td class="empty">Kein offener Auftrag braucht Teile von hier.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>Zuletzt umgelagert</h2></div>
          <div class="table-wrap">
            <table class="table">
              <tbody>
                <tr v-for="t in loc.transfers" :key="t.id">
                  <td class="faint nowrap">{{ dateTime(t.created_at) }}</td>
                  <td><div class="partcell"><PartImage :no="t.item_no" :size="32" /><div><RouterLink class="mono" :to="`/teil/${t.item_no}`">{{ t.item_no }}</RouterLink></div></div></td>
                  <td class="nowrap"><LocationTag :code="t.from_code" /> <ArrowRight :size="12" class="faint" /> <LocationTag :code="t.to_code" /></td>
                  <td class="num">{{ num(t.qty) }}</td>
                  <td class="muted small">{{ t.display_name }}</td>
                </tr>
                <tr v-if="!loc.transfers.length"><td class="empty">Hier wurde noch nichts umgelagert.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </template>
    <BrickLinkExport ref="blExport" />
  </div>
</template>

<style scoped>
.mixed-note { padding: 12px 16px; border-radius: var(--radius-sm); border: 2px solid var(--danger); background: color-mix(in srgb, var(--danger) 14%, var(--surface)); color: var(--text); }
.mixed-note strong { color: var(--danger); }
.count-note { padding: 12px 16px; border-radius: var(--radius-sm); border: 2px solid var(--warn); background: color-mix(in srgb, var(--warn) 12%, var(--surface)); color: var(--text); }
.count-note strong { color: var(--warn); }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
@media (max-width: 900px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
.stat .label { color: var(--text-3); font-size: 0.82rem; }
.stat .value { font-size: 1.6rem; font-weight: 700; line-height: 1.25; }
.stat .small-value { font-size: 1.2rem; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.chip { display: inline-flex; align-items: center; height: 30px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--border); color: var(--text-2); font-size: 0.85rem; }
.chip:hover { background: var(--hover); color: var(--text); }
.grow { flex: 1; min-width: 160px; max-width: 320px; margin-left: auto; }
.nowrap { white-space: nowrap; }
.strong { font-weight: 700; }
.tcell { width: 48px; }
</style>
