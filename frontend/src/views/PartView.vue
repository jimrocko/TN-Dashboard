<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ExternalLink, ScanSearch, ArrowRight } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import PartImage from '../components/PartImage.vue'
import { api } from '../api'
import { num, eur, dateTime, orderStatusClass } from '../utils/format'
import { lastRecognition } from '../utils/recognition'

// Teile-Seite: alles Wichtige zu einer Teil-Nr. Öffnet sich nach dem Scannen und aus Suche/Inventar.
const route = useRoute()
const part = ref(null)
const error = ref('')
const loading = ref(false)

watch(() => route.params.no, async (no) => {
  if (!no) return
  loading.value = true
  error.value = ''
  try { part.value = (await api.get(`part/${encodeURIComponent(no)}`)).part } catch (e) { error.value = e.message } finally { loading.value = false }
}, { immediate: true })

// Kommt man vom Scanner, gibt es Bild, Name und Sicherheit der Erkennung sowie die anderen Möglichkeiten.
const scan = computed(() => {
  if (route.query.scan !== '1' || !route.query.t) return null // route.query.t: neue Erkennung = neu berechnen
  const r = lastRecognition()
  if (!r) return null
  const hit = r.items.find((i) => i.id === route.params.no)
  return hit ? { hit, others: r.items.filter((i) => i !== hit) } : null
})

const title = computed(() => part.value?.name ?? scan.value?.hit.name ?? `Teil ${route.params.no}`)
const lots = computed(() => part.value?.lots ?? [])
const stats = computed(() => {
  const active = (part.value?.orders ?? []).filter((o) => o.active)
  return {
    qty: lots.value.reduce((s, l) => s + l.qty, 0),
    value: lots.value.reduce((s, l) => s + l.qty * l.price, 0),
    colors: new Set(lots.value.map((l) => l.color_id)).size,
    reserved: active.reduce((s, o) => s + o.qty, 0),
  }
})
// Pro Farbe zusammengefasst, damit man sofort sieht, welche Farben da sind.
const byColor = computed(() => {
  const m = new Map()
  for (const l of lots.value) {
    const g = m.get(l.color_id) ?? { color_id: l.color_id, color_name: l.color_name, qty: 0, price: l.price, lots: [] }
    g.qty += l.qty
    g.lots.push(l)
    m.set(l.color_id, g)
  }
  return [...m.values()]
})
const blUrl = computed(() => scan.value?.hit.bricklink_url
  ?? `https://www.bricklink.com/v2/catalog/catalogitem.page?P=${encodeURIComponent(route.params.no)}`)
const pct = (s) => `${Math.round(s * 100)} %`
</script>

<template>
  <div class="page stack">
    <PageHead :title="title" :sub="[route.params.no, part?.category ?? scan?.hit.category].filter(Boolean).join(' · ')">
      <RouterLink to="/scanner" class="btn"><ScanSearch :size="16" /> Neues Teil scannen</RouterLink>
    </PageHead>
    <p v-if="error" class="error">{{ error }}</p>

    <div class="top">
      <section class="panel imgpanel">
        <PartImage :no="route.params.no" :type="part?.item_type" :src="scan?.hit.img_url" :size="200" />
        <div v-if="scan" class="small center">
          Erkannt mit <strong>{{ pct(scan.hit.score) }}</strong> Sicherheit
        </div>
        <a class="btn btn-sm" :href="blUrl" target="_blank" rel="noopener">Auf BrickLink <ExternalLink :size="14" /></a>
      </section>

      <div class="stack grow">
        <div class="stats">
          <div class="stat"><div class="label">Im Bestand</div><div class="value">{{ num(stats.qty) }}</div><div class="faint small">Stück</div></div>
          <div class="stat"><div class="label">Farben</div><div class="value">{{ num(stats.colors) }}</div><div class="faint small">{{ num(lots.length) }} Lose</div></div>
          <div class="stat"><div class="label">Wert</div><div class="value">{{ eur(stats.value) }}</div><div class="faint small">zum Lagerpreis</div></div>
          <div class="stat"><div class="label">In offenen Aufträgen</div><div class="value">{{ num(stats.reserved) }}</div><div class="faint small">Stück reserviert</div></div>
        </div>
        <div v-if="!loading && part && !lots.length" class="panel panel-body notstock">
          <strong>Dieses Teil haben wir nicht im Lager.</strong>
          <span class="muted small">Es ist in keinem Regal und keiner Mischkiste erfasst.</span>
        </div>
      </div>
    </div>

    <section v-if="byColor.length" class="panel">
      <div class="panel-head"><h2>Bestand nach Farbe und Lagerort</h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th></th><th>Farbe</th><th>Lagerort</th><th class="num">Menge</th><th class="num">Preis</th><th class="num">Wert</th><th>Geändert</th><th></th></tr></thead>
          <tbody>
            <template v-for="g in byColor" :key="g.color_id">
              <tr v-for="(l, i) in g.lots" :key="l.id">
                <td v-if="i === 0" :rowspan="g.lots.length" class="tcell"><PartImage :no="route.params.no" :color="g.color_id" :type="part?.item_type" :size="40" /></td>
                <td v-if="i === 0" :rowspan="g.lots.length" class="nowrap"><ColorSwatch :name="g.color_name" /></td>
                <td><LocationTag :code="l.location_code" :kind="l.location_kind" /></td>
                <td class="num strong">{{ num(l.qty) }}</td>
                <td class="num faint">{{ eur(l.price) }}</td>
                <td class="num">{{ eur(l.qty * l.price) }}</td>
                <td class="faint nowrap">{{ dateTime(l.updated_at) }}</td>
                <td class="num"><RouterLink class="btn btn-sm" :to="{ path: '/umlagern', query: { lot: l.id } }">Umlagern</RouterLink></td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <div class="grid-2">
      <section class="panel">
        <div class="panel-head"><h2>Aufträge mit diesem Teil</h2><span class="faint small">{{ part?.orders.length ?? 0 }}</span></div>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <tr v-for="o in part?.orders ?? []" :key="o.id + o.color_name">
                <td class="mono"><RouterLink :to="{ path: '/auftraege', query: { status: '', id: o.id } }">{{ o.order_no }}</RouterLink></td>
                <td class="nowrap"><ColorSwatch :name="o.color_name" /></td>
                <td class="num">{{ num(o.qty) }} Stk.</td>
                <td><span class="pill" :class="orderStatusClass(o.status)">{{ o.status }}</span></td>
              </tr>
              <tr v-if="!part?.orders.length"><td class="empty">In keinem Auftrag.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>Zuletzt umgelagert</h2></div>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <tr v-for="t in part?.transfers ?? []" :key="t.id">
                <td class="faint nowrap">{{ dateTime(t.created_at) }}</td>
                <td class="nowrap"><ColorSwatch :name="t.color_name" /></td>
                <td class="mono small nowrap">{{ t.from_code }} <ArrowRight :size="12" /> {{ t.to_code }}</td>
                <td class="num">{{ num(t.qty) }}</td>
                <td class="muted">{{ t.display_name }}</td>
              </tr>
              <tr v-if="!part?.transfers.length"><td class="empty">Noch nicht umgelagert.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <section v-if="scan?.others.length" class="panel">
      <div class="panel-head"><h2>Doch ein anderes Teil?</h2><span class="faint small">weitere Möglichkeiten der Erkennung</span></div>
      <div class="alts">
        <RouterLink v-for="o in scan.others" :key="o.type + o.id" class="alt" :to="{ path: `/teil/${o.id}`, query: { scan: '1', t: route.query.t } }">
          <PartImage :no="o.id" :src="o.img_url" :size="56" />
          <span class="mono">{{ o.id }}</span>
          <span class="small muted name">{{ o.name }}</span>
          <span class="small faint">{{ pct(o.score) }} · {{ o.lots.length ? `${num(o.lots.reduce((s, l) => s + l.qty, 0))} bei uns` : 'nicht im Lager' }}</span>
        </RouterLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.top { display: flex; gap: 16px; align-items: stretch; flex-wrap: wrap; }
.imgpanel { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 14px; width: 232px; flex: none; }
.grow { flex: 1; min-width: 260px; }
.stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
.stat .label { color: var(--text-3); font-size: 0.82rem; }
.stat .value { font-size: 1.6rem; font-weight: 700; line-height: 1.25; }
.notstock { display: flex; flex-direction: column; gap: 4px; border-color: var(--warn); }
.center { text-align: center; }
.nowrap { white-space: nowrap; }
.strong { font-weight: 700; }
.tcell { width: 52px; }
.alts { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; padding: 12px; }
.alt { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px; text-align: center; border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text); }
.alt:hover { background: var(--hover); }
.alt .name { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
@media (max-width: 560px) { .imgpanel { width: 100%; } }
</style>
