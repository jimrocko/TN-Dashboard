<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Download, Upload, RefreshCw, Search } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import MinifigParts from '../components/MinifigParts.vue'
import TnDialog from '../components/TnDialog.vue'
import { api, IS_DEMO } from '../api'
import { useAuth } from '../stores/auth'
import { num, eur, dateTime } from '../utils/format'

// Minifig-Finder: gleiche Auswertung wie das bisherige Programm. Abrufe bei BrickLink (Teile prüfen, Stücklisten laden,
// neue Figuren) nur mit Dev-Rolle und auf Knopfdruck; alles andere rechnet mit der eigenen Datenbank.
const auth = useAuth()
const st = ref(null)
const res = ref(null)
const prices = ref(null)
const msg = ref('')
const err = ref('')
const busy = ref(false)
const batch = ref({ scan: 25, subsets: 20, catalog: 10 })
const newFigs = ref([])

async function loadStatus() { st.value = await api.get('minifig/status') }
async function loadResults() { res.value = await api.get('minifig/results', { limit: 10000 }) }
async function loadPrices() { try { prices.value = await api.get('minifig/prices/status') } catch { /* optional */ } }
async function loadAll() {
  err.value = ''
  try { await Promise.all([loadStatus(), loadResults(), loadPrices()]) } catch (e) { err.value = e.message }
}

async function run(label, fn) {
  busy.value = true
  err.value = ''
  msg.value = `${label} …`
  try { msg.value = await fn() } catch (e) { err.value = e.message; msg.value = '' } finally { busy.value = false }
}
const clamp = (n) => Math.max(1, Math.min(1000, Math.trunc(Number(n)) || 1))
const scan = () => run('Teile werden geprüft', async () => {
  const r = await api.post('minifig/scan', { batch_size: clamp(batch.value.scan) })
  await loadAll()
  return `${r.api_calls} Abrufe, ${r.candidate_hits} Figuren-Treffer${r.errors ? `, ${r.errors} Fehler` : ''}.`
})
const subsets = () => run('Stücklisten werden geladen', async () => {
  const r = await api.post('minifig/subsets', { batch_size: clamp(batch.value.subsets) })
  await loadAll()
  return r.already_running ? 'Es läuft bereits ein Abruf.' : `${r.loaded_subsets} Stücklisten geladen (${r.api_calls} Abrufe${r.deferred_this_run ? `, ${r.deferred_this_run} später erneut` : ''}).`
})
const catalog = () => run('Neue Figuren werden gesucht', async () => {
  const r = await api.post('minifig/catalog-refresh', { batch_size: clamp(batch.value.catalog) })
  newFigs.value = r.new_minifigs
  await loadAll()
  return `${r.api_calls} Varianten geprüft, ${r.new_minifig_count} neue Figuren.`
})
const refreshInv = () => run('Bestand wird eingelesen', async () => {
  const r = await api.post('sync/inventory')
  await loadAll()
  return `Bestand aktualisiert: ${num(r.lots)} Lose, ${num(r.parts)} Teile.`
})
const recalc = () => run('Wird neu berechnet', async () => { await loadAll(); return 'Ergebnis neu berechnet.' })

// Alle 5 Minuten lokal neu rechnen (wie im Original), ohne API
let timer = null
onMounted(() => { loadAll(); timer = setInterval(() => { if (!busy.value) loadAll() }, 300000) })
onBeforeUnmount(() => clearInterval(timer))

const pct = (a, b) => (b ? Math.min(100, Math.round((100 * a) / b)) : 0)
const variantPct = computed(() => pct(st.value?.scanned_variants ?? 0, st.value?.eligible_core_variants ?? 0))
const subsetPct = computed(() => pct(st.value?.cached_subsets ?? 0, st.value?.candidate_minifigs ?? 0))
const budgetPct = computed(() => pct(st.value?.api_usage_24h ?? 0, (st.value?.api_daily_budget ?? 5000) - (st.value?.api_reserved_calls ?? 500)))
const freshness = computed(() => {
  const at = st.value?.inventory_import_at
  if (!at) return { text: IS_DEMO ? 'Demo-Daten' : 'unbekannt', cls: 'warn' }
  const h = (Date.now() - new Date(at.replace(' ', 'T')).getTime()) / 3600000
  return h <= 6 ? { text: 'aktuell', cls: 'ok' } : h <= 24 ? { text: `${Math.max(1, Math.round(h))} Std. alt`, cls: 'warn' } : { text: `${Math.max(1, Math.round(h / 24))} Tg. alt`, cls: 'danger' }
})

// ---- Rangliste ----
const q = ref('')
const status = ref('all')
const sort = ref('figure_desc')
const limit = ref(100)
const page = ref(1)
watch([q, status, sort, limit], () => { page.value = 1 })
const rankRows = computed(() => {
  const r = res.value
  if (!r) return []
  const joint = new Set(r.joint_buildable.map((x) => x.minifig_no))
  const aj = new Map(r.almost_joint_buildable.map((x) => [x.minifig_no, x]))
  return [...r.buildable.map((x) => ({ ...x, rank: 'buildable', joint: joint.has(x.minifig_no), aj: false })),
    ...r.almost.map((x) => ({ ...x, ...(aj.get(x.minifig_no) ?? {}), rank: 'almost', joint: false, aj: aj.has(x.minifig_no) }))]
})
const filtered = computed(() => {
  let rows = [...rankRows.value]
  const t = q.value.trim().toLowerCase()
  if (t) rows = rows.filter((r) => `${r.minifig_no} ${r.name}`.toLowerCase().includes(t))
  if (status.value === 'joint') rows = rows.filter((r) => r.joint)
  else if (status.value === 'almost_joint') rows = rows.filter((r) => r.aj)
  else if (status.value !== 'all') rows = rows.filter((r) => r.rank === status.value)
  const v = (r, k, m) => (r[k] === null || r[k] === undefined ? m : Number(r[k]))
  const cmp = {
    figure_desc: (a, b) => v(b, 'figure_value', -1e15) - v(a, 'figure_value', -1e15),
    adv_desc: (a, b) => v(b, 'economic_advantage', -1e15) - v(a, 'economic_advantage', -1e15),
    completion_asc: (a, b) => v(a, 'completion_cost', 1e15) - v(b, 'completion_cost', 1e15),
    completion_desc: (a, b) => v(b, 'completion_cost', -1e15) - v(a, 'completion_cost', -1e15),
    parts_desc: (a, b) => v(b, 'parts_value', -1e15) - v(a, 'parts_value', -1e15),
    complete_desc: (a, b) => Number(b.completeness || 0) - Number(a.completeness || 0),
    name_asc: (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'de'),
  }[sort.value]
  return rows.sort(cmp)
})
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / limit.value)))
const pageRows = computed(() => filtered.value.slice((page.value - 1) * limit.value, page.value * limit.value))
const statusLabel = (r) => (r.aj ? 'gemeinsam ergänzbar · 1 Teil fehlt' : r.rank === 'almost' ? '1 Teil fehlt' : r.joint ? 'gemeinsam baubar' : 'einzeln baubar')

const coverage = computed(() => {
  const all = rankRows.value
  const almost = all.filter((r) => r.rank === 'almost')
  return { all: all.length, fig: all.filter((r) => r.figure_value !== null).length, almost: almost.length,
    miss: almost.filter((r) => r.completion_cost !== null).length, full: all.filter((r) => Number(r.price_coverage_pct) === 100).length }
})

// ---- Entscheidungen ----
const REASONS = { parts_value: 'Einzelteile wertvoller', missing_cost: 'Fehlteil zu teuer', not_build: 'Nicht bauen' }
const decide = (no, action, reason = '') => run(action === 'exclude' ? 'Wird ausgeschlossen' : 'Wird wieder zugelassen', async () => {
  await api.post('minifig/decision', { minifig_no: no, action, reason })
  await loadResults()
  return action === 'exclude' ? `${no} wird nicht gebaut, seine Teile sind wieder frei.` : `${no} ist wieder zugelassen.`
})

// ---- Dateien ----
const dl = (route, params) => api.download(route, params).catch((e) => { err.value = e.message })
const priceFile = ref(null)
const pricePreview = ref(null)
async function previewPrices(e) {
  const f = e.target.files[0]
  e.target.value = ''
  if (!f) return
  priceFile.value = f
  const form = new FormData()
  form.append('file', f, f.name)
  try { pricePreview.value = await api.upload('minifig/prices/import', form, {}) } catch (x) { err.value = x.message }
}
const applyPrices = () => run('Preise werden übernommen', async () => {
  const form = new FormData()
  form.append('file', priceFile.value, priceFile.value.name)
  const r = await api.upload('minifig/prices/import', form, { apply: '1' })
  pricePreview.value = null
  await loadAll()
  return `${r.imported_combinations} Preise übernommen${r.conflicts ? `, ${r.conflicts} widersprüchlich (übersprungen)` : ''}.`
})
const transferFile = ref(null)
const importTransfer = () => run('Daten werden übernommen', async () => {
  const form = new FormData()
  form.append('file', transferFile.value, transferFile.value.name)
  const r = await api.upload('minifig/transfer/import', form, {})
  await loadAll()
  return `Übernommen: ${Object.entries(r.imported).map(([k, v]) => `${k} ${v}`).join(', ')}.`
})
</script>

<template>
  <div class="page stack mf">
    <PageHead title="Minifig-Finder" sub="Minifiguren aus losen Teilen im Bestand erkennen. „Einzeln baubar“ prüft jede Figur für sich; der gemeinsame Bauplan vergibt kein Teil doppelt.">
      <button class="btn" :disabled="busy" @click="recalc"><RefreshCw :size="16" /> Neu berechnen</button>
    </PageHead>
    <p v-if="err" class="error">{{ err }}</p>
    <p v-if="msg" class="muted">{{ msg }}</p>

    <template v-if="st">
      <div class="kpis">
        <div class="kpi"><div class="label">Kernvarianten</div><div class="value">{{ num(st.eligible_core_variants) }}</div><div class="hint">Figurenteile im Bestand</div></div>
        <div class="kpi"><div class="label">Varianten geprüft</div><div class="value">{{ num(st.scanned_variants) }}</div><div class="hint">noch {{ num(st.remaining_core_variants) }} offen</div></div>
        <div class="kpi"><div class="label">Kandidaten</div><div class="value">{{ num(st.candidate_minifigs) }}</div><div class="hint">bekannte Figuren</div></div>
        <div class="kpi"><div class="label">Stücklisten geladen</div><div class="value">{{ num(st.cached_subsets) }}</div><div class="hint">noch {{ num(st.remaining_subsets) }} offen<template v-if="st.subset_retry_waiting"> · {{ st.subset_retry_waiting }} warten</template></div></div>
      </div>
      <div v-if="res" class="kpis">
        <div class="kpi good"><div class="label">Einzeln baubar</div><div class="value">{{ num(res.individual_buildable_count) }}</div></div>
        <div class="kpi good"><div class="label">Gemeinsam baubar</div><div class="value">{{ num(res.joint_buildable_count) }}</div><div class="hint">ohne doppelte Teile</div></div>
        <div class="kpi warn"><div class="label">Durch Reservierung blockiert</div><div class="value">{{ num(res.joint_conflict_count) }}</div></div>
        <div class="kpi warn"><div class="label">1 Teil fehlt</div><div class="value">{{ num(res.almost_count) }}</div><div class="hint">für Nachkauf</div></div>
      </div>
      <section class="panel panel-body progressbox">
        <div><div class="pl"><span>Variantenprüfung</span><strong>{{ variantPct }} %</strong></div><div class="progress"><i :style="{ width: `${variantPct}%` }" /></div></div>
        <div><div class="pl"><span>Stücklisten-Fortschritt</span><strong>{{ subsetPct }} %</strong></div><div class="progress"><i :style="{ width: `${subsetPct}%` }" /></div></div>
      </section>

      <section v-if="auth.canDev" class="panel">
        <div class="panel-head"><h2>Daten von BrickLink holen</h2><span class="faint small">kostet API-Abrufe · nur auf Knopfdruck</span></div>
        <div class="panel-body toolbar">
          <div class="batch"><label for="mf-scan">Teile</label><input id="mf-scan" v-model="batch.scan" class="input" type="number" min="1" max="1000" />
            <button class="btn btn-primary" :disabled="busy" @click="scan">Teile prüfen</button></div>
          <div class="batch"><label for="mf-sub">Stücklisten</label><input id="mf-sub" v-model="batch.subsets" class="input" type="number" min="1" max="1000" />
            <button class="btn" :disabled="busy" @click="subsets">Stücklisten laden</button></div>
          <span class="faint small">Noch frei heute: {{ num(st.safe_available_calls) }} Abrufe<template v-if="IS_DEMO"> (Demo: erfundener Katalog)</template></span>
        </div>
      </section>

      <div class="grid-2">
        <section class="panel">
          <div class="panel-head"><h2>Bestandsaktualität</h2><span class="pill" :class="freshness.cls">{{ freshness.text }}</span></div>
          <div class="panel-body stack-s">
            <div class="facts">
              <div><span class="faint small">Letzter BrickLink-Abruf</span>{{ st.inventory_import_at ? dateTime(st.inventory_import_at) : '–' }}</div>
              <div><span class="faint small">Lose / Teile</span>{{ num(st.inventory_lots) }} / {{ num(st.inventory_parts) }}</div>
              <div><span class="faint small">Inventarwert</span>{{ eur(st.inventory_value) }}</div>
            </div>
            <p class="small muted">Gerechnet wird immer mit dem gespeicherten Bestand. Einlesen aktualisiert nur den Bestand, keine Stücklisten.</p>
            <button v-if="auth.canDev" class="btn" :disabled="busy" @click="refreshInv">Bestand von BrickLink neu einlesen</button>
          </div>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>BrickLink-Abrufe · 24 Stunden</h2></div>
          <div class="panel-body stack-s">
            <div class="pl"><span>{{ num(st.api_usage_24h) }} von {{ num(st.api_daily_budget) }} (Reserve {{ num(st.api_reserved_calls) }})</span><strong>{{ budgetPct }} %</strong></div>
            <div class="progress"><i :style="{ width: `${budgetPct}%` }" /></div>
            <table class="table"><tbody>
              <tr v-for="(n, t) in st.api_usage_24h_by_type" :key="t"><td>{{ t }}</td><td class="num">{{ num(n) }}</td></tr>
              <tr v-if="!Object.keys(st.api_usage_24h_by_type ?? {}).length"><td class="faint">Keine Abrufe in den letzten 24 Stunden.</td></tr>
            </tbody></table>
          </div>
        </section>
      </div>

      <section class="panel">
        <div class="panel-head"><h2>Preise ohne API · BrickStore</h2></div>
        <div class="panel-body two">
          <div class="stack-s">
            <ol class="small steps">
              <li><strong>Preispaket herunterladen</strong> und in BrickStore öffnen.</li>
              <li>In BrickStore den <strong>6-Monats-Durchschnitt</strong> als Preis übernehmen und wieder als BSX speichern.</li>
              <li>Die Datei hier als <strong>Preise importieren</strong>. Danach wird lokal neu gerechnet.</li>
            </ol>
            <div class="toolbar">
              <button class="btn btn-primary" @click="dl('minifig/prices/export')"><Download :size="16" /> Preispaket herunterladen</button>
              <label v-if="auth.canDev" class="btn"><Upload :size="16" /> Preise importieren
                <input type="file" accept=".bsx,.xml,application/xml,text/xml" hidden @change="previewPrices" /></label>
            </div>
          </div>
          <div v-if="prices" class="facts">
            <div><span class="faint small">Preise Minifiguren</span>{{ num(prices.price_guide_minifig_count) }}</div>
            <div><span class="faint small">Preise Teile/Farben</span>{{ num(prices.price_guide_part_count) }}</div>
            <div><span class="faint small">Figuren mit Wert</span>{{ num(prices.rank_figures_priced) }} / {{ num(prices.rank_rows) }}</div>
            <div><span class="faint small">Fehlteilpreis vorhanden</span>{{ num(prices.almost_missing_priced) }} / {{ num(prices.almost_rows) }}</div>
            <div><span class="faint small">100 % Teile-Preise</span>{{ num(prices.full_part_coverage) }} / {{ num(prices.rank_rows) }}</div>
            <div><span class="faint small">Letzter Import</span>{{ prices.price_guide_import_at ? dateTime(prices.price_guide_import_at) : '–' }}</div>
          </div>
        </div>
      </section>

      <div v-if="auth.canDev" class="grid-2">
        <section class="panel">
          <div class="panel-head"><h2>Neue Minifiguren prüfen</h2></div>
          <div class="panel-body stack-s">
            <p class="small muted">Sparsame Rotation: bereits geprüfte Figurenteile werden erneut abgefragt, die älteste Prüfung zuerst. Neue Figuren landen im Katalog; ihre Stückliste ist danach „offen“.</p>
            <div class="facts">
              <div><span class="faint small">Rotierbare Varianten</span>{{ num(st.refreshable_variants) }}</div>
              <div><span class="faint small">Älteste Prüfung</span>{{ dateTime(st.oldest_checked_at) }}</div>
              <div><span class="faint small">Letzte Prüfung</span>{{ dateTime(st.latest_checked_at) }}</div>
            </div>
            <div class="batch"><label for="mf-cat">Varianten</label><input id="mf-cat" v-model="batch.catalog" class="input" type="number" min="1" max="1000" />
              <button class="btn" :disabled="busy" @click="catalog">Auf neue Figuren prüfen</button></div>
            <div v-if="newFigs.length" class="small">Neu: {{ newFigs.map((f) => `${f.minifig_no} ${f.name}`).join(', ') }}</div>
          </div>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>Minifig-Daten übertragen</h2></div>
          <div class="panel-body stack-s">
            <p class="small muted">Katalog, Stücklisten, Prüfstand, Entscheidungen und Preise in eine Datei packen oder von einem anderen Rechner übernehmen. Bestand und Aufträge bleiben unberührt.</p>
            <div class="toolbar">
              <button class="btn" @click="dl('minifig/transfer/export')"><Download :size="16" /> Exportieren</button>
              <label class="btn">Datei wählen<input type="file" accept=".zip,application/zip" hidden @change="transferFile = $event.target.files[0]" /></label>
              <button class="btn btn-primary" :disabled="!transferFile || busy" @click="importTransfer">Importieren</button>
            </div>
            <span v-if="transferFile" class="small faint">{{ transferFile.name }}</span>
          </div>
        </section>
      </div>
    </template>

    <template v-if="res">
      <section class="panel">
        <div class="panel-head"><h2>Rangliste</h2><span class="faint small">lokal berechnet · ohne API</span></div>
        <div class="panel-body stack-s">
          <div class="toolbar">
            <label class="searchbox"><Search :size="16" /><input v-model="q" class="input" type="search" placeholder="Nummer oder Name" aria-label="Suche" /></label>
            <select v-model="status" class="select" aria-label="Status">
              <option value="all">Baubar + 1 Teil fehlt</option><option value="buildable">Einzeln baubar</option><option value="joint">Gemeinsam baubar</option>
              <option value="almost_joint">Gemeinsam ergänzbar · 1 Teil fehlt</option><option value="almost">Nur 1 Teil fehlt</option>
            </select>
            <select v-model="sort" class="select" aria-label="Sortierung">
              <option value="figure_desc">Figurenwert ↓</option><option value="adv_desc">Vorteil ↓</option><option value="completion_asc">Fehlteilkosten ↑</option>
              <option value="completion_desc">Fehlteilkosten ↓</option><option value="parts_desc">Wert der Lagerteile ↓</option><option value="complete_desc">Vollständigkeit ↓</option>
              <option value="name_asc">Name A–Z</option>
            </select>
            <select v-model.number="limit" class="select" aria-label="Anzeige"><option v-for="n in [50, 100, 250, 500, 1000]" :key="n" :value="n">{{ n }}</option></select>
          </div>
          <p class="small muted">„Vorteil“ = Wert der Figur − Wert der Lagerteile − Fehlteilkosten, vor Gebühren und Versand. Ohne Figurenpreis bleibt er offen.</p>
          <div class="toolbar">
            <button class="btn btn-sm" @click="dl('minifig/export', { kind: status, format: 'csv' })"><Download :size="14" /> Liste als CSV</button>
            <button class="btn btn-sm" @click="dl('minifig/export', { kind: status, format: 'bsx' })"><Download :size="14" /> Liste für BrickStore</button>
            <button class="btn btn-sm" @click="dl('minifig/export-all', { format: 'csv' })">Alle Listen (CSV-ZIP)</button>
            <button class="btn btn-sm" @click="dl('minifig/export-all', { format: 'bsx' })">Alle Listen (BrickStore-ZIP)</button>
            <template v-if="status === 'almost_joint'">
              <button class="btn btn-sm" @click="dl('minifig/almost-joint-export', { format: 'csv' })">Fehlteile als CSV</button>
              <button class="btn btn-sm" @click="dl('minifig/almost-joint-export', { format: 'bsx' })">Fehlteile für BrickStore</button>
            </template>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Minifigur</th><th>Status</th><th class="num">Figurenwert</th><th class="num">Lagerteile</th><th class="num">Fehlteil</th><th class="num">Vorteil</th><th>Teile / Lagerort</th></tr></thead>
            <tbody>
              <tr v-for="r in pageRows" :key="r.minifig_no">
                <td><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ r.name }}</div></td>
                <td><span class="pill" :class="r.rank === 'almost' ? 'warn' : 'ok'">{{ statusLabel(r) }}</span>
                  <div class="small faint">{{ r.completeness }} % · Preise Teile {{ r.price_coverage_pct || 0 }} %</div></td>
                <td class="num">{{ eur(r.figure_value) }}<div v-if="r.figure_price_source" class="small faint">{{ r.figure_price_source }}</div></td>
                <td class="num">{{ eur(r.parts_value) }}</td>
                <td class="num">{{ r.rank === 'almost' ? eur(r.completion_cost) : '–' }}</td>
                <td class="num"><strong>{{ eur(r.economic_advantage) }}</strong></td>
                <td class="wide">
                  <MinifigParts :row="r" />
                  <div v-if="r.aj" class="toolbar rej">
                    <button v-for="(l, k) in REASONS" :key="k" class="btn btn-sm" :disabled="busy" @click="decide(r.minifig_no, 'exclude', k)">{{ k === 'not_build' ? 'Nicht bauen' : `Nicht bauen · ${l}` }}</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!pageRows.length"><td colspan="7" class="empty">Keine Treffer für diesen Filter.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="pages > 1" class="pager">
          <span class="small faint">{{ num((page - 1) * limit + 1) }}–{{ num(Math.min(page * limit, filtered.length)) }} von {{ num(filtered.length) }}</span>
          <button class="btn btn-sm" :disabled="page <= 1" @click="page--">‹</button>
          <span class="small">Seite {{ page }} / {{ pages }}</span>
          <button class="btn btn-sm" :disabled="page >= pages" @click="page++">›</button>
        </div>
      </section>

      <div class="grid-2">
        <section class="panel">
          <div class="panel-head"><h2>Häufigste Fehlteile</h2></div>
          <p class="small muted pad">Nur Figuren mit „1 Teil fehlt“: Welches Teil würde die meisten Figuren freischalten?</p>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Fehlteil</th><th class="num">Figuren</th><th class="num">Menge</th><th class="num">Preis</th></tr></thead>
              <tbody>
                <tr v-for="m in res.missing_frequency.slice(0, 100)" :key="`${m.item_no}|${m.color_id}`">
                  <td><RouterLink class="mono" :to="`/teil/${encodeURIComponent(m.item_no)}`">{{ m.item_no }}</RouterLink> · Farbe {{ m.color_id }}<div class="small muted">{{ m.item_name }}</div></td>
                  <td class="num"><strong>{{ m.figures_affected }}</strong></td><td class="num">{{ m.total_shortfall }}</td>
                  <td class="num">{{ eur(m.estimated_unit_price) }}<div v-if="m.price_source" class="small faint">{{ m.price_source }}</div></td>
                </tr>
                <tr v-if="!res.missing_frequency.length"><td colspan="4" class="empty">Keine Fehlteile.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>Preisabdeckung</h2></div>
          <table class="table"><tbody>
            <tr><td>Figuren mit Referenzwert</td><td class="num"><strong>{{ coverage.fig }}/{{ coverage.all }}</strong></td></tr>
            <tr><td>„1 Teil fehlt“ mit Fehlteilpreis</td><td class="num"><strong>{{ coverage.miss }}/{{ coverage.almost }}</strong></td></tr>
            <tr><td>100 % Teile-Preisabdeckung</td><td class="num"><strong>{{ coverage.full }}/{{ coverage.all }}</strong></td></tr>
            <tr><td>„1 Teil fehlt“ ohne Fehlteilpreis (nicht gelistet)</td><td class="num">{{ res.almost_without_cost_count }}</td></tr>
            <tr><td>Stücklisten mit weniger als 4 Teilen (übersprungen)</td><td class="num">{{ res.under_min_parts_count }}</td></tr>
          </tbody></table>
        </section>
      </div>

      <section class="panel good-border">
        <div class="panel-head"><h2>Gemeinsamer Bauplan</h2><span class="faint small">{{ res.joint_buildable_count }} von {{ res.individual_buildable_count }} gleichzeitig baubar</span></div>
        <p class="small muted pad">Teile werden im Rechenmodell reserviert und nie doppelt vergeben. Zuerst zählt der wirtschaftliche Vorteil, dann Figurenwert und Bestandsreserve.</p>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Minifigur</th><th class="num">Figurenwert</th><th class="num">Teilewert</th><th class="num">Vorteil</th><th>Teile zum Bauen</th></tr></thead>
            <tbody>
              <tr v-for="r in res.joint_buildable" :key="r.minifig_no">
                <td><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ r.name }}</div></td>
                <td class="num">{{ eur(r.figure_value) }}</td><td class="num">{{ eur(r.parts_value) }}</td><td class="num"><strong>{{ eur(r.economic_advantage) }}</strong></td>
                <td class="wide"><MinifigParts :row="{ ...r, catalog_url: `https://www.bricklink.com/v2/catalog/catalogitem.page?M=${encodeURIComponent(r.minifig_no)}`, image_url: `https://img.bricklink.com/ItemImage/MN/0/${encodeURIComponent(r.minifig_no)}.png` }" /></td>
              </tr>
              <tr v-if="!res.joint_buildable.length"><td colspan="5" class="empty">Noch keine gemeinsam baubaren Figuren.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel good-border">
        <div class="panel-head"><h2>Gemeinsam ergänzbar · 1 Teil fehlt</h2>
          <span class="faint small">{{ res.almost_joint_buildable_count }} vorbereitbar · {{ res.almost_joint_blocked_count }} blockiert · {{ res.almost_joint_excluded_count }} ausgeschlossen</span></div>
        <p class="small muted pad">Zweite Runde: Die Teile des gemeinsamen Bauplans bleiben reserviert. Nur der Rest wird auf Figuren mit genau einem Fehlteil verteilt.</p>
        <div class="toolbar pad">
          <button class="btn btn-sm" @click="dl('minifig/almost-joint-export', { format: 'csv' })"><Download :size="14" /> Fehlteile als CSV</button>
          <button class="btn btn-sm" @click="dl('minifig/almost-joint-export', { format: 'bsx' })"><Download :size="14" /> Fehlteile für BrickStore</button>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Minifigur</th><th>Fehlteil</th><th class="num">Fehlteilkosten</th><th class="num">Figurenwert</th><th class="num">Vorteil nach Zukauf</th><th>Teile / Entscheidung</th></tr></thead>
            <tbody>
              <tr v-for="r in res.almost_joint_buildable" :key="r.minifig_no">
                <td><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ r.name }}</div></td>
                <td><RouterLink class="mono" :to="`/teil/${encodeURIComponent(r.missing[0]?.item_no ?? '')}`">{{ r.missing[0]?.item_no }}</RouterLink> · Farbe {{ r.missing[0]?.color_id }}<div class="small muted">{{ r.missing[0]?.item_name }}</div></td>
                <td class="num">{{ eur(r.completion_cost) }}</td><td class="num">{{ eur(r.figure_value) }}</td><td class="num"><strong>{{ eur(r.economic_advantage) }}</strong></td>
                <td class="wide"><MinifigParts :row="r" />
                  <div class="toolbar rej"><button v-for="(l, k) in REASONS" :key="k" class="btn btn-sm" :disabled="busy" @click="decide(r.minifig_no, 'exclude', k)">{{ k === 'not_build' ? 'Nicht bauen' : `Nicht bauen · ${l}` }}</button></div></td>
              </tr>
              <tr v-if="!res.almost_joint_buildable.length"><td colspan="6" class="empty">Aktuell keine Figuren aus dem Restbestand vorbereitbar.</td></tr>
            </tbody>
          </table>
        </div>
        <template v-if="res.almost_joint_excluded.length">
          <p class="small pad"><strong>Ausgeschlossen</strong> · ihre Teile sind für andere Figuren wieder frei.</p>
          <div class="table-wrap">
            <table class="table"><tbody>
              <tr v-for="r in res.almost_joint_excluded" :key="r.minifig_no">
                <td><strong class="mono">{{ r.minifig_no }}</strong> {{ r.name }}</td><td>{{ REASONS[r.reason] ?? 'Nicht bauen' }}</td>
                <td class="num"><button class="btn btn-sm" :disabled="busy" @click="decide(r.minifig_no, 'allow')">Wieder zulassen</button></td>
              </tr>
            </tbody></table>
          </div>
        </template>
      </section>

      <div class="grid-2">
        <section class="panel">
          <div class="panel-head"><h2>Einzeln baubar</h2><span class="faint small">{{ res.individual_buildable_count }}</span></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Minifigur</th><th>Treffer</th><th class="num">Baubar</th></tr></thead>
              <tbody>
                <tr v-for="r in res.buildable" :key="r.minifig_no"><td><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ r.name }}</div></td>
                  <td><span class="pill ok">{{ r.present_groups }}/{{ r.required_groups }} · {{ r.completeness }} %</span></td><td class="num"><strong>{{ r.buildable_qty }}×</strong></td></tr>
                <tr v-if="!res.buildable.length"><td colspan="3" class="empty">Noch keine Treffer.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="panel">
          <div class="panel-head"><h2>Nur 1 Teil fehlt</h2><span class="faint small">{{ res.almost_count }}</span></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Minifigur</th><th>Treffer</th><th>Fehlend</th></tr></thead>
              <tbody>
                <tr v-for="r in res.almost" :key="r.minifig_no"><td><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ r.name }}</div></td>
                  <td><span class="pill warn">{{ r.present_groups }}/{{ r.required_groups }} · {{ r.completeness }} %</span></td>
                  <td><div v-for="m in r.missing" :key="m.item_no" class="small"><span class="mono">{{ m.item_no }}</span> · Farbe {{ m.color_id }} · vorhanden {{ m.have || 0 }}, benötigt {{ m.quantity }}</div></td></tr>
                <tr v-if="!res.almost.length"><td colspan="3" class="empty">Noch keine Treffer.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section class="panel warn-border">
        <div class="panel-head"><h2>Durch gemeinsame Reservierung blockiert</h2><span class="faint small">{{ res.joint_conflict_count }}</span></div>
        <p class="small muted pad">Einzeln baubar, im gemeinsamen Bauplan aber nicht mehr vollständig, weil Teile schon anderen Figuren zugeordnet sind.</p>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Minifigur</th><th>Nach Reservierung fehlend</th></tr></thead>
            <tbody>
              <tr v-for="r in res.joint_conflicts" :key="r.minifig_no"><td><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ r.name }}</div></td>
                <td><div v-for="m in r.missing_after_reservations" :key="`${m.item_no}|${m.color_id}`" class="small"><span class="mono">{{ m.item_no }}</span> · Farbe {{ m.color_id }} · Rest {{ m.have || 0 }}, benötigt {{ m.quantity }}</div>
                  <span v-if="!r.missing_after_reservations.length" class="faint small">Gemeinsame Teile bereits reserviert</span></td></tr>
              <tr v-if="!res.joint_conflicts.length"><td colspan="2" class="empty">Keine Konflikte – alle einzeln baubaren Figuren passen gleichzeitig.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <TnDialog v-if="pricePreview" title="Preise importieren?" @close="pricePreview = null">
      <p>{{ priceFile?.name }}: {{ num(pricePreview.rows) }} Zeilen, {{ num(pricePreview.usable_combinations) }} Preise{{ pricePreview.conflicts ? `, ${pricePreview.conflicts} widersprüchlich (werden übersprungen)` : '' }}.</p>
      <template #actions>
        <button class="btn" @click="pricePreview = null">Abbrechen</button>
        <button class="btn btn-primary" :disabled="busy" @click="applyPrices">Übernehmen</button>
      </template>
    </TnDialog>
  </div>
</template>

<style scoped>
.progressbox { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
.pl { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 4px; font-size: 0.88rem; }
.batch { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.batch label { font-size: 0.8rem; color: var(--text-2); }
.batch .input { width: 80px; }
.stack-s { display: flex; flex-direction: column; gap: 10px; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
.facts > div { display: flex; flex-direction: column; gap: 2px; }
.two { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 16px; }
@media (max-width: 900px) { .two { grid-template-columns: minmax(0, 1fr); } }
.steps { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 4px; }
.searchbox { display: inline-flex; align-items: center; gap: 6px; }
.searchbox .input { width: 220px; }
.pad { padding: 10px var(--pad) 0; margin: 0; }
.wide { min-width: 280px; }
.rej { margin-top: 8px; }
.pager { display: flex; gap: 8px; align-items: center; justify-content: flex-end; padding: 10px var(--pad); }
.good-border { border-color: color-mix(in srgb, var(--ok) 45%, var(--border)); }
.warn-border { border-color: color-mix(in srgb, var(--warn) 45%, var(--border)); }
label.btn { cursor: pointer; }
td { vertical-align: top !important; }
</style>
