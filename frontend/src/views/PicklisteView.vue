<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Check, CircleAlert, Mail, BarChart3, X, LoaderCircle } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import PartImage from '../components/PartImage.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import TnDialog from '../components/TnDialog.vue'
import { api } from '../api'
import { useAuth } from '../stores/auth'
import { num, eur, dateTime } from '../utils/format'
import { WINDOW_VISIBLE } from '../utils/windowing'

// Pickliste: gleiche Abläufe wie das bisherige Programm (Übersicht → Auftrag → Positionen picken, vormerken, melden, abschließen).
// Picker ist der angemeldete Benutzer. Die Seite fragt jede Sekunde nur die eigene Datenbank, ob sich etwas geändert hat.
const route = useRoute()
const router = useRouter()
const auth = useAuth()
const visible = inject(WINDOW_VISIBLE, ref(true))

const orders = ref([])
const detail = ref(null)
const pickers = ref([])
const notice = ref('')
const loading = ref(true)
const claimMode = ref(false)
const filter = ref('all')
const busy = ref(false)
let overviewRev = -1
let orderRev = -1

const currentId = computed(() => (route.query.id ? Number(route.query.id) : null))
const open = (id) => router.replace({ query: { ...route.query, id } })
const back = () => { const { id, ...q } = route.query; router.replace({ query: q }) }

// Farben der Picker wie im Original (Reihenfolge der Picker-Liste)
const COLORS = ['#187a52', '#2769b3', '#b33c67', '#bd5b1b', '#117d8a', '#7050a3', '#737400', '#a53a96']
const colorOf = (id) => { const i = pickers.value.findIndex((p) => p.id === Number(id)); return COLORS[i >= 0 ? i : Math.abs(Number(id) || 0) % COLORS.length] }

async function loadOrders() {
  const r = await api.get('picking/orders')
  orders.value = r.orders
  overviewRev = r.revision
}
async function loadDetail() {
  if (!currentId.value) { detail.value = null; return }
  try {
    const d = await api.get(`picking/orders/${currentId.value}`)
    detail.value = d
    orderRev = d.order.revision
  } catch (e) { notice.value = e.message; back() }
}
async function reload() {
  try { currentId.value ? await loadDetail() : await loadOrders() } catch (e) { notice.value = e.message } finally { loading.value = false }
}

watch(currentId, () => { filter.value = 'all'; zone.value = ''; claimMode.value = false; detail.value = null; orderRev = -1; reload() })

// Alle 1 s nachsehen, ob jemand anderes gepickt hat (nur wenn die Seite sichtbar ist)
let timer = null
async function poll() {
  if (busy.value || !visible.value || document.visibilityState !== 'visible' || !document.hasFocus()) return
  try {
    if (currentId.value) {
      const r = await api.get(`picking/orders/${currentId.value}/revision`)
      if (r.revision !== orderRev) await loadDetail()
    } else {
      const r = await api.get('picking/revision')
      if (r.revision !== overviewRev) await loadOrders()
    }
  } catch { /* nächster Versuch */ }
}
onMounted(async () => {
  try { pickers.value = (await api.get('picking/pickers')).pickers } catch { /* nur Farben/Filter */ }
  await reload()
  timer = setInterval(poll, 1000)
})
onBeforeUnmount(() => clearInterval(timer))

// ---- Bereiche (Wunsch des Inhabers): Lager unter mehreren Pickern aufteilen ----
// Bereich = Buchstaben am Anfang des Lagerorts (G245 → G, FT3 → FT, Plate2 → PLATE), ohne Lagerort → „–“
const zone = ref('')
const zoneOf = (x) => (String(x.location || '').trim().match(/^[A-Za-zÄÖÜäöü]+/)?.[0] ?? '–').toUpperCase()
const zones = computed(() => {
  const m = new Map()
  for (const x of detail.value?.items ?? []) {
    const z = zoneOf(x)
    const e = m.get(z) ?? { zone: z, total: 0, picked: 0, mine: 0, free: 0 }
    e.total++
    if (x.picked) e.picked++
    else if (!x.claim_picker_id) e.free++
    if (x.claim_picker_id === auth.user?.id) e.mine++
    m.set(z, e)
  }
  return [...m.values()].sort((a, b) => a.zone.localeCompare(b.zone, 'de-DE', { numeric: true }))
})
const zoneInfo = computed(() => zones.value.find((z) => z.zone === zone.value) ?? null)
// Alle freien (nicht gepickten, nicht vorgemerkten) Positionen des Bereichs für mich vormerken, bzw. meine Vormerkungen dort aufheben.
// Nutzt die gleiche Vormerkung wie „Markieren“; hat jemand anderes inzwischen vorgemerkt, wird die Position übersprungen.
async function takeZone(release = false) {
  const rows = (detail.value?.items ?? []).filter((x) => zoneOf(x) === zone.value
    && (release ? x.claim_picker_id === auth.user?.id : !x.picked && !x.claim_picker_id))
  if (!rows.length) return
  busy.value = true
  let skipped = 0
  try {
    for (const x of rows) {
      try { await api.post(`picking/items/${x.id}/claim`) } catch { skipped++ }
    }
    if (skipped) notice.value = `${skipped} Position(en) wurden gerade von jemand anderem vorgemerkt oder gepickt und sind übersprungen.`
  } finally { busy.value = false; await loadDetail() }
}

// ---- Detail ----
const items = computed(() => {
  let rows = [...(detail.value?.items ?? [])]
  if (zone.value) rows = rows.filter((x) => zoneOf(x) === zone.value)
  const f = filter.value
  if (f === 'open') rows = rows.filter((x) => !x.picked)
  else if (f.startsWith('picker:')) { const id = Number(f.split(':')[1]); rows = rows.filter((x) => x.claim_picker_id === id || x.picker_id === id) }
  const loc = (a, b) => String(a.location || '').localeCompare(String(b.location || ''), 'de-DE', { numeric: true, sensitivity: 'base' })
  if (f === 'locationAsc') rows.sort(loc)
  else if (f === 'locationDesc') rows.sort((a, b) => loc(b, a))
  else if (f === 'qtyAsc') rows.sort((a, b) => a.quantity - b.quantity)
  else if (f === 'qtyDesc') rows.sort((a, b) => b.quantity - a.quantity)
  return rows
})
// Gleicher Lagerort bei benachbarten Positionen: orange Rahmen; vorgemerkte Positionen eines Pickers: farbige Leiste
const locKey = (x) => String(x.location || '').trim().toLocaleLowerCase('de-DE')
const locCounts = computed(() => {
  const m = new Map()
  for (const x of detail.value?.items ?? []) { const k = locKey(x); if (k) m.set(k, (m.get(k) ?? 0) + 1) }
  return m
})
const rowClass = (x, i) => {
  const k = locKey(x)
  const shared = k && locCounts.value.get(k) > 1
  const rows = items.value
  return {
    picked: x.picked, locked: x.picked && x.picker_id !== auth.user?.id, other: x.claim_picker_id && x.claim_picker_id !== auth.user?.id,
    done: detail.value?.order.completed, same: shared, 'same-start': shared && locKey(rows[i - 1] ?? {}) !== k, 'same-end': shared && locKey(rows[i + 1] ?? {}) !== k,
  }
}
const ownerOf = (x) => x.claim_picker_id || (x.picked ? x.picker_id : 0) || 0
const ownerName = (x) => x.claim_picker_name || x.picker_name || ''
const groups = computed(() => {
  const out = []
  items.value.forEach((x, i) => {
    const id = x.claim_picker_id || 0
    const last = out.at(-1)
    if (last && last.owner === id && id) last.rows.push([x, i])
    else out.push({ owner: id, name: x.claim_picker_name, rows: [[x, i]] })
  })
  return out
})
const pickedCount = computed(() => (detail.value?.items ?? []).filter((x) => x.picked).length)
const pieces = computed(() => (detail.value?.items ?? []).reduce((s, x) => s + x.quantity, 0))
const shipping = computed(() => detail.value?.shipping ?? {})
const isLetter = computed(() => /großbrief|grossbrief|large letter/i.test(shipping.value.shipping_method || '') && (shipping.value.recipient_address ?? []).length > 0)
const total = computed(() => shipping.value.grand_total_minor != null ? shipping.value.grand_total_minor / 100 : detail.value?.order.grand_total)

async function act(fn) {
  busy.value = true
  try { await fn() } catch (e) { notice.value = e.message } finally { busy.value = false; await loadDetail() }
}
const tap = (x) => {
  if (claimMode.value) return act(() => api.post(`picking/items/${x.id}/claim`))
  if (detail.value.order.completed) return
  if (x.picked && x.picker_id !== auth.user?.id) {
    notice.value = `Diese Position wurde von ${x.picker_name ?? 'einem anderen Picker'} gepickt und kann nur von diesem Picker zurückgesetzt werden.`
    return
  }
  return act(() => api.post(`picking/items/${x.id}/toggle`))
}
const complete = () => act(() => api.post(`picking/orders/${currentId.value}/complete`))
const reopen = () => act(() => api.post(`picking/orders/${currentId.value}/reopen`))
const resolve = (r) => act(() => api.post(`picking/reports/${r.id}/resolve`))

// ---- Melden ----
const ISSUES = { missing: 'Teil/Teile fehlen', wrong_location: 'Lagerort stimmt nicht', wrong_quantity: 'Restmenge stimmt nicht', wrong_item: 'Falsches Teil' }
const reportFor = ref(null)
const form = ref({})
const reportErr = ref('')
function openReport(x) {
  reportFor.value = x
  reportErr.value = ''
  form.value = { issue_type: 'missing', missing_quantity: 1, found_location: '', actual_quantity: 0, details: '' }
}
async function sendReport() {
  const f = form.value
  reportErr.value = ''
  busy.value = true
  try {
    await api.post(`picking/items/${reportFor.value.id}/reports`, {
      issue_type: f.issue_type, details: f.details.trim() || null,
      missing_quantity: f.issue_type === 'missing' ? Number(f.missing_quantity) : null,
      found_location: f.issue_type === 'wrong_location' ? f.found_location.trim() || null : null,
      actual_quantity: f.issue_type === 'wrong_quantity' ? Number(f.actual_quantity) : null,
    })
    reportFor.value = null
  } catch (e) { reportErr.value = e.message } finally { busy.value = false; await loadDetail() }
}
const reportLabel = (r) => {
  const extra = r.issue_type === 'missing' && r.missing_quantity ? `: ${r.missing_quantity}`
    : r.issue_type === 'wrong_location' && r.found_location ? `: ${r.found_location}`
      : r.issue_type === 'wrong_quantity' && r.actual_quantity !== null ? `: ${r.actual_quantity}` : r.details ? `: ${r.details}` : ''
  return (ISSUES[r.issue_type] ?? 'Problem') + extra
}

const imageFor = ref(null)
const letter = ref(false)
</script>

<template>
  <div class="page stack pick">
    <PageHead title="Pickliste" :sub="detail ? `${detail.order.platform} · Bestellung ${detail.order.order_no}` : 'Offene Bestellungen. Tippe einen Auftrag an und picke Position für Position.'">
      <button v-if="detail" class="btn" @click="back"><ArrowLeft :size="16" /> Bestellungen</button>
      <RouterLink v-else-if="auth.canDev || auth.isStaff" to="/pickliste/statistik" class="btn"><BarChart3 :size="16" /> Statistik</RouterLink>
    </PageHead>

    <!-- Übersicht -->
    <template v-if="!currentId">
      <div v-if="loading && !orders.length" class="panel loading" role="status"><LoaderCircle :size="22" class="spin" /> Bestellungen werden geladen …</div>
      <div v-else-if="!orders.length" class="panel empty">Keine offenen Bestellungen.</div>
      <div class="orders">
        <button v-for="o in orders" :key="o.id" class="panel order" :class="{ completed: o.completed }" @click="open(o.id)">
          <div class="order-top">
            <strong>{{ o.platform }} · <span class="mono">{{ o.order_no }}</span></strong>
            <span v-if="o.completed" class="pill ok">Verpackt/Erledigt</span>
          </div>
          <div>{{ o.buyer_name || '–' }} · {{ o.positions }} Positionen · {{ num(o.parts) }} Teile</div>
          <div class="faint small">{{ o.status }} · {{ dateTime(o.date_ordered) }} · {{ o.percent }} % gepickt</div>
          <div class="small">Picker: {{ o.picker_names.length ? o.picker_names.join(', ') : '–' }}</div>
          <div class="progress" :class="{ ok: o.percent === 100 }"><i :style="{ width: `${o.percent}%` }" /></div>
        </button>
      </div>
    </template>

    <!-- Auftrag -->
    <template v-else-if="detail">
      <section class="panel">
        <div class="head">
          <div>
            <div><strong>Bestellung <span class="mono">{{ detail.order.order_no }}</span></strong> · {{ detail.order.buyer_name || '–' }}
              <span v-if="detail.order.completed" class="pill ok">Verpackt/Erledigt</span></div>
            <div class="muted small">Bisher gepickt von: {{ detail.picker_names.length ? detail.picker_names.join(', ') : 'noch niemand' }}</div>
          </div>
          <div class="toolbar">
            <button class="btn" :class="{ 'btn-primary': claimMode }" :aria-pressed="claimMode" :disabled="detail.order.completed" @click="claimMode = !claimMode">
              <Check :size="16" /> {{ claimMode ? 'Fertig' : 'Markieren' }}
            </button>
            <button v-if="detail.order.completed" class="btn" :disabled="busy" @click="reopen">Bestellung ändern</button>
            <button v-else-if="detail.items.length && pickedCount === detail.items.length" class="btn btn-primary" :disabled="busy" @click="complete">Bestellung abschließen</button>
          </div>
        </div>
        <div class="summary">
          <div><span class="faint small">Versandart</span>
            <button v-if="isLetter" class="linkish" @click="letter = true"><Mail :size="15" /> {{ shipping.shipping_method }}</button>
            <span v-else>{{ shipping.shipping_method || '–' }}</span></div>
          <div><span class="faint small">Positionen</span><span>{{ detail.items.length }} <span class="faint small">({{ pickedCount }} gepickt)</span></span></div>
          <div><span class="faint small">Gesamtstückzahl</span><span>{{ num(pieces) }}</span></div>
          <div><span class="faint small">Gesamtbetrag</span><span>{{ eur(total) }}</span></div>
        </div>
        <div class="filters">
          <label class="small muted" for="pick-filter">Filter</label>
          <select id="pick-filter" v-model="filter" class="select">
            <option value="all">Alle</option><option value="locationAsc">Lagerort ↑</option><option value="locationDesc">Lagerort ↓</option>
            <option value="qtyAsc">Anzahl ↑</option><option value="qtyDesc">Anzahl ↓</option><option value="open">Offen / ungepickt</option>
            <option v-for="p in pickers" :key="p.id" :value="`picker:${p.id}`">{{ p.name }}</option>
          </select>
          <span v-if="claimMode" class="small accent-note">Markieren: Tippe Positionen an, um sie für dich vorzumerken.</span>
        </div>
        <div v-if="zones.length > 1" class="zones">
          <span class="small muted">Bereich</span>
          <div class="zchips" role="radiogroup" aria-label="Bereich">
            <button type="button" class="zchip" :class="{ on: !zone }" role="radio" :aria-checked="!zone" @click="zone = ''">Alle</button>
            <button v-for="z in zones" :key="z.zone" type="button" class="zchip" :class="{ on: zone === z.zone, fertig: z.picked === z.total }" role="radio" :aria-checked="zone === z.zone" @click="zone = z.zone">
              {{ z.zone }} <span class="zcount">{{ z.picked === z.total ? 'fertig' : `${z.picked}/${z.total}` }}</span>
            </button>
          </div>
          <template v-if="zoneInfo && !detail.order.completed">
            <button v-if="zoneInfo.free" class="btn btn-sm btn-primary" :disabled="busy" @click="takeZone()">Bereich {{ zone }} übernehmen ({{ zoneInfo.free }})</button>
            <button v-else-if="zoneInfo.mine" class="btn btn-sm" :disabled="busy" @click="takeZone(true)">Meine Vormerkungen in {{ zone }} aufheben</button>
          </template>
        </div>
      </section>

      <div v-if="!items.length" class="panel empty">Keine Positionen für diesen Filter.</div>
      <div class="rows">
        <div v-for="(g, gi) in groups" :key="gi" :class="{ claimgroup: g.owner }" :style="g.owner ? { '--claim': colorOf(g.owner) } : null">
          <span v-if="g.owner" class="claim-name">{{ g.name }}</span>
          <div v-for="[x, i] in g.rows" :key="x.id" class="row" :class="rowClass(x, i)" role="button" tabindex="0"
            :aria-pressed="x.picked" @click="tap(x)" @keydown.enter.prevent="tap(x)">
            <button class="imgbtn" title="Bild vergrößern" @click.stop="imageFor = x"><PartImage :no="x.item_no" :color="x.color_id" :type="x.item_type" :src="x.image_url" :size="60" /></button>
            <div class="desc">
              <div class="name">{{ x.item_name || x.item_no }}</div>
              <div class="meta small">
                <span class="cond">{{ x.condition || 'Zustand –' }}</span> ·
                <ColorSwatch :name="x.color_name || 'Farbe –'" /> ·
                <RouterLink class="mono" :to="`/teil/${encodeURIComponent(x.item_no)}`" @click.stop>{{ x.item_no }}</RouterLink>
                <span v-if="x.picker_name" class="pickedby"> · gepickt von {{ x.picker_name }}</span>
                <span v-else-if="x.claim_picker_name" class="faint"> · vorgemerkt: {{ x.claim_picker_name }}</span>
              </div>
              <div v-if="x.reports.length" class="reports">
                <button v-for="r in x.reports" :key="r.id" class="chip" title="Meldung schließen" @click.stop="resolve(r)">{{ reportLabel(r) }}<span v-if="r.user_name" class="by"> · {{ r.user_name }}</span> <X :size="12" /></button>
              </div>
            </div>
            <div class="loc">{{ x.location || '–' }}</div>
            <div class="qty">{{ x.quantity }}<small>Rest: {{ x.remaining_stock ?? 0 }}</small></div>
            <button class="report" :title="`Problem melden: ${x.item_name || x.item_no}`" @click.stop="openReport(x)">
              <CircleAlert :size="18" /><span v-if="x.report_count" class="badge">{{ x.report_count }}</span>
            </button>
          </div>
        </div>
      </div>
    </template>
    <div v-else class="panel loading" role="status"><LoaderCircle :size="22" class="spin" /> Bestellung wird geöffnet …</div>

    <TnDialog v-if="notice" title="Hinweis" @close="notice = ''">
      <p>{{ notice }}</p>
      <template #actions><button class="btn btn-primary" @click="notice = ''">Schließen</button></template>
    </TnDialog>

    <TnDialog v-if="reportFor" title="Problem melden" @close="reportFor = null">
      <p class="muted">{{ reportFor.item_name || reportFor.item_no }} · {{ reportFor.color_name }} · {{ reportFor.location }}</p>
      <label class="field"><span>Meldegrund</span>
        <select v-model="form.issue_type" class="select"><option v-for="(l, k) in ISSUES" :key="k" :value="k">{{ l }}</option></select></label>
      <label v-if="form.issue_type === 'missing'" class="field"><span>Wie viele fehlen?</span><input v-model="form.missing_quantity" class="input" type="number" min="1" /></label>
      <label v-if="form.issue_type === 'wrong_location'" class="field"><span>Wo wurde das Teil gefunden?</span><input v-model="form.found_location" class="input" placeholder="z. B. G320" /></label>
      <label v-if="form.issue_type === 'wrong_quantity'" class="field"><span>Wie viele sind tatsächlich noch da?</span><input v-model="form.actual_quantity" class="input" type="number" min="0" /></label>
      <label class="field"><span>Notiz (optional)</span><textarea v-model="form.details" class="input area" maxlength="500" /></label>
      <p v-if="reportErr" class="error">{{ reportErr }}</p>
      <template #actions>
        <button class="btn" @click="reportFor = null">Abbrechen</button>
        <button class="btn btn-primary" :disabled="busy" @click="sendReport">Melden</button>
      </template>
    </TnDialog>

    <TnDialog v-if="imageFor" :title="imageFor.item_name || imageFor.item_no" @close="imageFor = null">
      <div class="bigimg"><PartImage :no="imageFor.item_no" :color="imageFor.color_id" :type="imageFor.item_type" :src="imageFor.image_url" :size="240" /></div>
      <div class="info">
        <div><span class="faint small">Teil-Nr.</span><span class="mono">{{ imageFor.item_no }}</span></div>
        <div><span class="faint small">Zustand</span>{{ imageFor.condition || '–' }}</div>
        <div><span class="faint small">Farbe</span>{{ imageFor.color_name || '–' }}</div>
        <div><span class="faint small">Fach</span>{{ imageFor.location || '–' }}</div>
        <div><span class="faint small">Menge</span>{{ imageFor.quantity }}</div>
      </div>
      <template #actions>
        <RouterLink v-if="imageFor.location" class="btn" :to="`/lagerort/${encodeURIComponent(imageFor.location)}`">Lagerort öffnen</RouterLink>
        <RouterLink class="btn" :to="`/teil/${encodeURIComponent(imageFor.item_no)}`">Teil öffnen</RouterLink>
      </template>
    </TnDialog>

    <TnDialog v-if="letter" title="Briefvorschau" wide @close="letter = false">
      <div class="paper">
        <div class="sender">{{ (detail.sender ?? []).join('\n') }}</div>
        <div class="recipient">{{ (shipping.recipient_address ?? []).join('\n') }}</div>
        <div class="tape tape-left" aria-hidden="true"></div>
        <div class="tape tape-right" aria-hidden="true"></div>
        <div class="tape tape-bottom" aria-hidden="true"></div>
      </div>
    </TnDialog>
  </div>
</template>

<style scoped>
.orders { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.order { display: flex; flex-direction: column; gap: 6px; text-align: left; padding: 14px 16px; cursor: pointer; color: var(--text); font: inherit; }
@media (hover: hover) { .order:hover, .row:hover { background: var(--hover); } }
.order.completed { border-color: color-mix(in srgb, var(--ok) 50%, transparent); }
.order.completed strong { text-decoration: line-through; }
.order-top { display: flex; justify-content: space-between; gap: 8px; align-items: center; }

.head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px var(--pad); border-bottom: 1px solid var(--border); }
.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; padding: 12px var(--pad); border-bottom: 1px solid var(--border); }
.summary > div { display: flex; flex-direction: column; gap: 2px; }
.linkish { display: inline-flex; gap: 6px; align-items: center; padding: 0; border: 0; background: none; color: var(--accent-text); text-decoration: underline; cursor: pointer; text-align: left; }
.filters { display: flex; gap: 10px; align-items: center; padding: 10px var(--pad); flex-wrap: wrap; }
.accent-note { color: var(--accent-text); }
.zones { display: flex; gap: 8px 10px; align-items: center; flex-wrap: wrap; padding: 0 var(--pad) 12px; }
.zchips { display: flex; gap: 6px; flex-wrap: wrap; }
.zchip { display: inline-flex; gap: 6px; align-items: center; height: 34px; padding: 0 12px; border-radius: 17px; border: 1px solid var(--border-strong); background: var(--surface-2); color: var(--text); font: inherit; font-weight: 600; cursor: pointer; }
.zchip.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); background: color-mix(in srgb, var(--accent) 14%, var(--surface-2)); }
.zchip.fertig .zcount { color: var(--ok); }
.zcount { font-weight: 400; font-size: 0.8rem; color: var(--text-3); font-variant-numeric: tabular-nums; }

.loading { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 40px 16px; color: var(--text-2); }
.spin { animation: spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.by { opacity: 0.8; }
.rows { display: flex; flex-direction: column; gap: 8px; }
.claimgroup { position: relative; display: flex; flex-direction: column; gap: 8px; padding-left: 14px; border-left: 5px solid var(--claim); border-radius: 4px; }
.claim-name { font-size: 0.78rem; font-weight: 600; color: var(--claim); margin-bottom: -4px; }
.row {
  display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto auto; gap: 14px; align-items: center; padding: 10px 12px;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; user-select: none;
}
.row.same { border-color: #f59e0b; border-width: 2px; }
.row.same:not(.same-end) { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.row.same:not(.same-start) { border-top-left-radius: 0; border-top-right-radius: 0; margin-top: -8px; border-top-style: dashed; }
.row.picked { opacity: 0.55; }
.row.picked .name, .row.picked .loc { text-decoration: line-through; }
.row.locked { cursor: not-allowed; }
.row.other { background: var(--surface-2); }
.row.done { cursor: default; }
.imgbtn { padding: 0; border: 0; background: none; cursor: zoom-in; }
.name { font-weight: 600; }
.meta { color: var(--text-2); display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.cond { font-weight: 600; }
.pickedby { color: var(--ok); }
.reports { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 11px; border: 1px solid var(--danger); background: color-mix(in srgb, var(--danger) 12%, transparent); color: var(--danger); font-size: 0.8rem; cursor: pointer; }
.loc { font-family: var(--mono); font-size: 1.35rem; font-weight: 700; min-width: 5ch; text-align: right; }
.qty { font-size: 1.5rem; font-weight: 800; text-align: right; min-width: 3ch; display: flex; flex-direction: column; line-height: 1.1; }
.qty small { font-size: 0.72rem; font-weight: 400; color: var(--text-3); white-space: nowrap; }
.report { position: relative; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--border-strong); background: var(--surface-2); color: var(--warn); cursor: pointer; }
.badge { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; border-radius: 9px; background: var(--danger); color: #fff; font-size: 0.7rem; display: grid; place-items: center; }
.area { height: 80px; padding: 8px 10px; resize: vertical; }
.bigimg { display: grid; place-items: center; margin-bottom: 12px; }
.info { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.info > div { display: flex; flex-direction: column; }
/* Briefvorschau wie im Original (picking.html): A4 quer, Absender oben links, Empfänger ganz unten rechts in der Ecke (Wunsch des Inhabers), Malerkrepp an drei Seiten */
.paper { position: relative; aspect-ratio: 297 / 210; width: 100%; background: #fff; color: #111; border: 1px solid #d1d5db; box-shadow: 0 8px 24px #0002; overflow: hidden; font-family: Arial, sans-serif; }
.sender { position: absolute; top: 9%; left: 7%; white-space: pre-line; font-size: clamp(10px, 1.5vw, 15px); line-height: 1.5; }
.recipient { position: absolute; right: 5%; bottom: 6%; max-width: 40%; white-space: pre-line; font-size: clamp(10px, 1.5vw, 15px); line-height: 1.5; overflow-wrap: anywhere; }
.tape { position: absolute; pointer-events: none; background: repeating-linear-gradient(2deg, #9a7040 0 7px, #ad8250 7px 14px); opacity: 0.88; }
.tape-left { left: 0; top: 40%; bottom: 40%; width: 4%; }
.tape-right { right: 0; top: 40%; bottom: 40%; width: 4%; }
.tape-bottom { left: 31%; right: 31%; bottom: 0; height: 10%; }
@media (max-width: 620px) { .recipient { max-width: 48%; } }
@media (max-width: 700px) {
  .row { grid-template-columns: auto minmax(0, 1fr) auto; gap: 8px 10px; }
  .desc { grid-column: 2 / 4; }
  .loc { grid-column: 1 / 2; text-align: left; font-size: 1.15rem; }
  .qty { grid-column: 2 / 3; flex-direction: row; align-items: baseline; gap: 8px; font-size: 1.2rem; }
  .report { grid-column: 3 / 4; grid-row: 2; }
  .imgbtn { grid-row: 1; }
}
</style>
