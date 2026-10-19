<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Download, Upload, RefreshCw, Search, Trash2, Warehouse, FileStack, Files, ArrowUp, ArrowDown, PackageCheck, Copy, X } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import MinifigParts from '../components/MinifigParts.vue'
import TnDialog from '../components/TnDialog.vue'
import { api, IS_DEMO } from '../api'
import { useAuth } from '../stores/auth'
import { num, eur, dateTime, htmlText } from '../utils/format'
import { LIST_FILES, listCsv, listBsx, almostJointCsv, almostJointBsx } from '../utils/minifigExport'
import { saveBlob, zip } from '../utils/files'
import { copyText, BRICKLINK_XML_URL } from '../utils/bricklinkXml'

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
const bd = ref(null)
async function loadBuilds() { bd.value = await api.get('minifig/builds') }
async function loadAll() {
  err.value = ''
  try { await Promise.all([loadStatus(), loadResults(), loadPrices(), loadBuilds()]) } catch (e) { err.value = e.message }
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
  return `Bestand aktualisiert: ${num(r.lots)} Lots, ${num(r.parts)} Teile.`
})
// ---- Woher kommen die Teile? ----
const SOURCES = [
  { value: 'bestand', icon: Warehouse, title: 'Nur Lager', text: 'Sucht in unserem Bestand.' },
  { value: 'beides', icon: FileStack, title: 'Lager + BSX-Dateien', text: 'Bestand plus eigene BrickStore-Dateien.' },
  { value: 'bsx', icon: Files, title: 'Nur BSX-Dateien', text: 'Nur die hochgeladenen Dateien, ohne Lager.' },
]
const source = computed(() => st.value?.source ?? 'bestand')
const files = computed(() => st.value?.bsx_files ?? [])
const setSource = (v) => run('Quelle wird umgestellt', async () => {
  await api.post('minifig/source', { source: v })
  await loadAll()
  return `Quelle: ${SOURCES.find((x) => x.value === v).title}.`
})
const addFiles = (e) => {
  const list = [...e.target.files]
  e.target.value = ''
  if (!list.length) return
  run('Datei wird eingelesen', async () => {
    const done = []
    for (const f of list) {
      const form = new FormData()
      form.append('file', f, f.name)
      const r = await api.upload('minifig/bsx', form, {})
      done.push(`${r.name} (${num(r.lots)} Lots, ${num(r.parts)} Teile)`)
    }
    if (source.value === 'bestand') await api.post('minifig/source', { source: 'beides' })
    await loadAll()
    return `Hinzugefügt: ${done.join(', ')}.`
  })
}
const removeFile = (f) => run('Datei wird entfernt', async () => {
  await api.post(`minifig/bsx/${f.id}/delete`)
  await loadAll()
  return `${f.name} entfernt.`
})

// ---- Prio: welche Quelle zuerst, Reihenfolge der BSX-Dateien (gilt für alle) ----
const priority = computed(() => st.value?.priority ?? 'lager')
const savePriority = (prio, order) => run('Reihenfolge wird gespeichert', async () => {
  await api.post('minifig/priority', { priority: prio, bsx_order: order })
  await loadAll()
  return prio === 'bsx' ? 'Zuerst werden Teile aus den BSX-Dateien genommen, dann aus dem Lager.' : 'Zuerst werden Teile aus dem Lager genommen, dann aus den BSX-Dateien.'
})
const moveFile = (i, d) => {
  const ids = files.value.map((f) => f.id)
  ;[ids[i], ids[i + d]] = [ids[i + d], ids[i]]
  savePriority(priority.value, ids)
}

// ---- Fertige Figuren: gemeinsame Sammlung, erst am Ende einmal zu BrickLink (Wunsch des Inhabers) ----
const buildQty = ref({})
const figs = (n) => `${num(n)} ${n === 1 ? 'Figur' : 'Figuren'}`
const builds = computed(() => bd.value?.builds ?? [])
const buildCount = computed(() => builds.value.reduce((t, b) => t + b.qty, 0))
const markBuilt = (r) => run(`${r.minifig_no} wird in die Sammlung gelegt`, async () => {
  const k = Math.max(1, Math.min(r.buildable_qty || 1, Number(buildQty.value[r.minifig_no]) || 1))
  // Die Teileliste des Bauplans enthält alle geplanten Kopien hintereinander (je Kopie eine Zeile pro Teilegruppe)
  const lots = new Map()
  for (const p of (r.pick_list ?? []).slice(0, k * r.required_groups)) for (const s of p.sources ?? []) lots.set(s.inventory_id, (lots.get(s.inventory_id) ?? 0) + s.quantity)
  try {
    await api.post('minifig/builds', { minifig_no: r.minifig_no, qty: k, lots: [...lots].map(([inventory_id, quantity]) => ({ inventory_id, quantity })) })
  } finally { await loadAll() }
  return `${k}× ${r.minifig_no} liegt in „Fertige Figuren“. Die Teile sind in der Rechnung schon abgezogen.`
})
const removeBuild = (b) => run('Figur wird aus der Sammlung genommen', async () => {
  await api.post(`minifig/builds/${b.id}/delete`)
  await loadAll()
  return `${b.minifig_no} ist wieder aus der Sammlung raus.`
})
const expo = ref(null)
const applyBsx = ref([])
const copied = ref(false)
const startExport = () => run('Sammlung wird abgeschlossen', async () => {
  expo.value = await api.post('minifig/builds/start')
  applyBsx.value = expo.value.bsx_files.map((f) => f.id)
  copied.value = false
  await loadBuilds()
  return ''
})
function copyXml() {
  // Kopieren und Öffnen direkt im Tipp, sonst blockiert Safari beides
  copyText(expo.value.xml)
  window.open(BRICKLINK_XML_URL, '_blank', 'noopener')
  copied.value = true
}
const saveXml = () => saveBlob(new Blob([expo.value.xml], { type: 'application/xml' }), 'fertige-figuren-bricklink.xml')
const finishExport = (done) => run(done ? 'Bestand wird angepasst' : 'Abschluss wird verworfen', async () => {
  const r = await api.post('minifig/builds/finish', { done, apply_bsx: done ? applyBsx.value : [] })
  expo.value = null
  await loadAll()
  if (done && r.lots) api.post('ollie/after-export').catch(() => {})
  return done ? `Erledigt: ${figs(r.figures)}, ${num(r.parts)} Teile aus dem Bestand abgezogen${r.bsx_files.length ? `, ${r.bsx_files.length} BSX-Datei(en) aktualisiert` : ''}.`
    : 'Verworfen. Die Figuren bleiben in der Sammlung.'
})

// ---- Download-Fenster: Liste, Bereich (Nr. von–bis) und Format wählen ----
const DL_LISTS = computed(() => [
  ...(isFigTab.value ? [{ value: 'view', label: `Diese Ansicht: ${TABS.find((t) => t.value === tab.value).label} (mit Suche und Sortierung)` }] : []),
  { value: 'joint', label: 'Gemeinsamer Bauplan' },
  { value: 'buildable', label: 'Einzeln baubar' },
  { value: 'almost_joint', label: 'Gemeinsam ergänzbar · 1 Teil fehlt' },
  { value: 'almost', label: 'Nur 1 Teil fehlt' },
  { value: 'fehlteile', label: 'Fehlteile zum Nachkaufen (gemeinsam ergänzbar)' },
  { value: 'zip', label: 'Alle Listen als ZIP' },
])
const TAB_KIND = { plan: 'joint', einzeln: 'buildable', fehlt: 'almost', ergaenzbar: 'almost_joint' }
const dlOpen = ref(false)
const dl2 = ref({ list: 'view', from: 1, to: 1, format: 'csv' })
const dlRows = (list) => (list === 'view' ? filtered.value : list === 'fehlteile' ? res.value.almost_joint_buildable
  : { joint: res.value.joint_buildable, buildable: res.value.buildable, almost_joint: res.value.almost_joint_buildable, almost: res.value.almost }[list] ?? [])
const dlCount = computed(() => (res.value && dl2.value.list !== 'zip' ? dlRows(dl2.value.list).length : 0))
function openDownload(list = isFigTab.value ? 'view' : 'fehlteile') {
  dl2.value = { ...dl2.value, list, from: 1, to: res.value ? dlRows(list).length : 1 }
  dlOpen.value = true
}
watch(() => dl2.value.list, (l) => { if (dlOpen.value && res.value) Object.assign(dl2.value, { from: 1, to: l === 'zip' ? 0 : dlRows(l).length }) })
function doDownload() {
  const { list, format } = dl2.value
  const r = res.value
  const ext = format === 'bsx' ? 'bsx' : 'csv'
  if (list === 'zip') {
    saveBlob(zip(Object.fromEntries(Object.entries(LIST_FILES).map(([kind, name]) => [`${name}.${ext}`, ext === 'bsx' ? listBsx(kind, r) : listCsv(kind, r)]))), `minifig-listen-${ext}.zip`)
  } else {
    const from = Math.max(1, Math.trunc(dl2.value.from) || 1)
    const to = Math.min(dlCount.value, Math.max(from, Math.trunc(dl2.value.to) || dlCount.value))
    const sel = dlRows(list).slice(from - 1, to)
    const range = from === 1 && to === dlCount.value ? '' : `-nr-${from}-${to}`
    let text
    let name
    if (list === 'fehlteile') { text = ext === 'bsx' ? almostJointBsx(r, sel) : almostJointCsv(r, sel); name = `minifig-fehlteile${range}` }
    else {
      const kind = list === 'view' ? TAB_KIND[tab.value] ?? 'all' : list
      text = ext === 'bsx' ? listBsx(kind, r, sel) : listCsv(kind, r, sel)
      name = `minifig-${list === 'view' ? `ansicht-${tab.value}` : LIST_FILES[list]}${range}`
    }
    saveBlob(new Blob([text], { type: ext === 'bsx' ? 'application/xml' : 'text/csv;charset=utf-8' }), `${name}.${ext}`)
  }
  dlOpen.value = false
}

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

// ---- Eine Liste mit Reitern statt vieler Listen (Wunsch des Inhabers) ----
const TABS = [
  { value: 'plan', label: 'Bauplan', hint: 'Gemeinsamer Bauplan: Teile werden reserviert und nie doppelt vergeben. Zuerst zählt der wirtschaftliche Vorteil, dann Figurenwert und Bestandsreserve.' },
  { value: 'einzeln', label: 'Einzeln baubar', hint: 'Jede Figur für sich gerechnet, so oft wie die Teile reichen. „Fertig gebaut“ gibt es bei Figuren aus dem gemeinsamen Bauplan.' },
  { value: 'fehlt', label: '1 Teil fehlt', hint: 'Figuren, bei denen genau ein Teil fehlt.' },
  { value: 'ergaenzbar', label: 'Gemeinsam ergänzbar', hint: 'Zweite Runde: Die Teile des Bauplans bleiben reserviert. Nur der Rest wird auf Figuren mit genau einem Fehlteil verteilt.' },
  { value: 'blockiert', label: 'Blockiert', hint: 'Einzeln baubar, im gemeinsamen Bauplan aber nicht mehr vollständig, weil Teile schon anderen Figuren zugeordnet sind.' },
  { value: 'fehlteile', label: 'Fehlteile', hint: 'Nur Figuren mit „1 Teil fehlt“: Welches Teil würde die meisten Figuren freischalten?' },
  { value: 'alle', label: 'Alle', hint: 'Baubar und 1 Teil fehlt zusammen (frühere Rangliste).' },
]
const tab = ref('plan')
const q = ref('')
const sort = ref('order')
const limit = ref(50)
const page = ref(1)
watch([q, sort, limit], () => { page.value = 1 })
watch(tab, (t) => { page.value = 1; sort.value = t === 'alle' ? 'figure_desc' : 'order' })
function showTab(t) {
  tab.value = t
  document.querySelector('.mflist')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
// Zeile des gemeinsamen Bauplans je Figur: für „Fertig gebaut“ auch in den anderen Reitern (gleiche Lots wie im Bauplan)
const jointMap = computed(() => new Map((res.value?.joint_buildable ?? []).map((x) => [x.minifig_no, x])))
const rankRows = computed(() => {
  const r = res.value
  if (!r) return []
  const aj = new Map(r.almost_joint_buildable.map((x) => [x.minifig_no, x]))
  return [...r.buildable.map((x) => ({ ...x, rank: 'buildable', joint: jointMap.value.has(x.minifig_no), aj: false })),
    ...r.almost.map((x) => ({ ...x, ...(aj.get(x.minifig_no) ?? {}), rank: 'almost', joint: false, aj: aj.has(x.minifig_no) }))]
})
const tabRows = computed(() => {
  const r = res.value
  if (!r) return []
  const all = rankRows.value
  return {
    plan: r.joint_buildable.map((x) => ({ ...x, rank: 'buildable', joint: true, aj: false })),
    einzeln: all.filter((x) => x.rank === 'buildable'),
    fehlt: all.filter((x) => x.rank === 'almost'),
    ergaenzbar: r.almost_joint_buildable.map((x) => ({ ...x, rank: 'almost', joint: false, aj: true })),
    blockiert: r.joint_conflicts,
    fehlteile: r.missing_frequency,
    alle: all,
  }[tab.value] ?? []
})
const tabCount = (t) => {
  const r = res.value
  if (!r) return 0
  return { plan: r.joint_buildable.length, einzeln: r.buildable.length, fehlt: r.almost.length, ergaenzbar: r.almost_joint_buildable.length,
    blockiert: r.joint_conflicts.length, fehlteile: r.missing_frequency.length, alle: r.buildable.length + r.almost.length }[t]
}
const isFigTab = computed(() => tab.value !== 'fehlteile')
const filtered = computed(() => {
  let rows = [...tabRows.value]
  const t = q.value.trim().toLowerCase()
  if (t) rows = rows.filter((r) => (isFigTab.value ? `${r.minifig_no} ${r.name}` : `${r.item_no} ${r.item_name}`).toLowerCase().includes(t))
  if (!isFigTab.value || sort.value === 'order') return rows
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
  return cmp ? rows.sort(cmp) : rows
})
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / limit.value)))
const pageRows = computed(() => filtered.value.slice((page.value - 1) * limit.value, page.value * limit.value))
const statusLabel = (r) => (r.aj ? 'gemeinsam ergänzbar · 1 Teil fehlt' : r.rank === 'almost' ? '1 Teil fehlt' : r.joint ? 'gemeinsam baubar' : 'einzeln baubar')
const withLinks = (r) => ({ ...r, catalog_url: r.catalog_url ?? `https://www.bricklink.com/v2/catalog/catalogitem.page?M=${encodeURIComponent(r.minifig_no)}`,
  image_url: r.image_url ?? `https://img.bricklink.com/ItemImage/MN/0/${encodeURIComponent(r.minifig_no)}.png` })

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
// Figurenkatalog ohne API: Datenbank des MinifigFinder Standalone, dessen Installer-ZIP, Ollies Transfer-ZIP oder Katalogdatei
const catalogFile = ref(null)
const importCatalog = () => run('Figurenkatalog wird eingelesen', async () => {
  const form = new FormData()
  form.append('file', catalogFile.value, catalogFile.value.name)
  const r = await api.upload('minifig/catalog/import', form, {})
  catalogFile.value = null
  await loadAll()
  const i = r.imported
  return i.figuren !== undefined ? `${r.quelle}: ${num(i.figuren)} Figuren mit Stückliste, ${num(i.supersets)} Supersets, ${num(i.preise)} Preise übernommen.`
    : `${r.quelle}: ${Object.entries(i).map(([k, v]) => `${k} ${v}`).join(', ')}.`
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
    <PageHead title="Minifig-Finder" sub="Findet Minifiguren, die sich aus vorhandenen Teilen zusammenbauen lassen.">
      <button class="btn" :disabled="busy" @click="recalc"><RefreshCw :size="16" /> Neu berechnen</button>
    </PageHead>
    <p v-if="err" class="error">{{ err }}</p>
    <p v-if="msg" class="muted">{{ msg }}</p>

    <template v-if="st">
      <section class="panel how">
        <div class="panel-body">
          <ol class="howto">
            <li><strong>Teile wählen:</strong> Lager, eigene BSX-Dateien oder beides.</li>
            <li><strong>Der Finder vergleicht</strong> die Teile mit den Stücklisten bekannter Minifiguren.</li>
            <li><strong>Ergebnis:</strong> grün = sofort baubar, gelb = es fehlt nur 1 Teil. Ein Tipp auf „Teile“ zeigt, aus welchem Kästchen oder welcher Datei sie kommen.</li>
          </ol>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Woher kommen die Teile?</h2><span class="faint small">gilt für alle Listen und Exporte</span></div>
        <div class="panel-body stack-s">
          <div class="sources" role="radiogroup" aria-label="Quelle der Teile">
            <button v-for="o in SOURCES" :key="o.value" type="button" class="src" :class="{ on: source === o.value }" role="radio" :aria-checked="source === o.value"
              :disabled="busy" @click="source !== o.value && setSource(o.value)">
              <component :is="o.icon" :size="20" /><span><strong>{{ o.title }}</strong><span class="small muted">{{ o.text }}</span></span>
            </button>
          </div>
          <div class="toolbar">
            <label class="btn" :class="{ 'btn-primary': source !== 'bestand' }"><Upload :size="16" /> BSX-Datei hinzufügen
              <input type="file" accept=".bsx,.xml,application/xml,text/xml" multiple hidden @change="addFiles" /></label>
            <span class="small faint">BrickStore-Dateien (.bsx). Gezählt werden nur Teile; Remarks und Bemerkung stehen bei jedem Teil.</span>
          </div>
          <p v-if="source === 'bsx' && !files.length" class="small warnc">Noch keine BSX-Datei hochgeladen, deshalb gibt es keine Teile.</p>
          <div v-if="source === 'beides' && files.length" class="prio">
            <span class="small"><strong>Gleiches Teil im Lager und in einer Datei:</strong> zuerst nehmen aus</span>
            <div class="seg" role="radiogroup" aria-label="Reihenfolge der Quellen">
              <button type="button" :class="{ on: priority === 'lager' }" role="radio" :aria-checked="priority === 'lager'" :disabled="busy" @click="priority !== 'lager' && savePriority('lager', files.map((f) => f.id))">Lager</button>
              <button type="button" :class="{ on: priority === 'bsx' }" role="radio" :aria-checked="priority === 'bsx'" :disabled="busy" @click="priority !== 'bsx' && savePriority('bsx', files.map((f) => f.id))">BSX-Dateien</button>
            </div>
          </div>
          <ul v-if="files.length" class="files">
            <li v-for="(f, fi) in files" :key="f.id">
              <span v-if="files.length > 1" class="order">
                <button class="btn btn-ghost btn-sm" :disabled="busy || fi === 0" :title="`${f.name} nach oben`" @click="moveFile(fi, -1)"><ArrowUp :size="14" /></button>
                <button class="btn btn-ghost btn-sm" :disabled="busy || fi === files.length - 1" :title="`${f.name} nach unten`" @click="moveFile(fi, 1)"><ArrowDown :size="14" /></button>
              </span>
              <span class="grow"><strong>{{ fi + 1 }}. {{ f.name }}</strong> <span class="small muted">{{ num(f.lots) }} Lots · {{ num(f.parts) }} Teile · {{ dateTime(f.created_at) }}<template v-if="f.uploaded_by"> · {{ f.uploaded_by }}</template></span></span>
              <button class="btn btn-ghost btn-sm" :disabled="busy" :title="`${f.name} entfernen`" @click="removeFile(f)"><Trash2 :size="15" /></button>
            </li>
          </ul>
          <p v-if="files.length && source === 'bestand'" class="small muted">Die Dateien werden gerade nicht benutzt. Oben „Lager + BSX-Dateien“ oder „Nur BSX-Dateien“ wählen.</p>
        </div>
      </section>

      <section v-if="builds.length || bd?.export" class="panel good-border">
        <div class="panel-head"><h2><PackageCheck :size="18" /> Fertige Figuren</h2><span class="faint small">{{ figs(buildCount) }} gesammelt · für alle gemeinsam</span></div>
        <div class="panel-body stack-s">
          <p class="small muted">Hier landen alle Figuren, die jemand mit „Fertig gebaut“ markiert hat. Ihre Teile sind in der Rechnung schon abgezogen. Am Ende einmal „Sammlung abschließen“: dann kommt eine Datei für BrickLink und, falls Teile aus BSX-Dateien stammen, die geänderten Dateien.</p>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th class="nr">Nr.</th><th>Minifigur</th><th class="num">Anzahl</th><th class="num">Teile</th><th>Gesammelt von</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(b, i) in builds" :key="b.id">
                  <td class="nr">{{ i + 1 }}</td>
                  <td><div class="partcell"><PartImage :no="b.minifig_no" type="MINIFIG" :size="40" /><div><strong class="mono">{{ b.minifig_no }}</strong><div class="small">{{ htmlText(b.name) }}</div></div></div></td>
                  <td class="num"><strong>{{ b.qty }}×</strong></td><td class="num">{{ num(b.parts) }}</td>
                  <td class="small">{{ b.user_name || '–' }}<div class="faint">{{ dateTime(b.created_at) }}</div></td>
                  <td class="num"><button v-if="b.status === 'open'" class="btn btn-ghost btn-sm" :disabled="busy" :title="`${b.minifig_no} aus der Sammlung nehmen`" @click="removeBuild(b)"><Trash2 :size="15" /></button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="bd.export" class="small warnc">Abschluss läuft (gestartet von {{ bd.export.by || '–' }}, {{ dateTime(bd.export.at) }}). Erst bestätigen oder verwerfen, dann geht es weiter.</p>
          <p v-else-if="bd.transfer_open" class="small warnc">Beim Umlagern ist gerade eine BrickLink-Übertragung offen. Die bitte zuerst abschließen, dann geht „Sammlung abschließen“.</p>
          <div class="toolbar">
            <button class="btn btn-primary" :disabled="busy || (!bd.export && (bd.transfer_open || !builds.length))" @click="startExport">
              <PackageCheck :size="16" /> {{ bd.export ? 'Abschluss fortsetzen' : 'Sammlung abschließen' }}</button>
          </div>
        </div>
      </section>

      <div v-if="res" class="kpis">
        <button type="button" class="kpi good" @click="showTab('einzeln')"><div class="label">Sofort baubar</div><div class="value">{{ num(res.individual_buildable_count) }}</div><div class="hint">jede Figur für sich gezählt</div></button>
        <button type="button" class="kpi good" @click="showTab('plan')"><div class="label">Alle zusammen baubar</div><div class="value">{{ num(res.joint_buildable_count) }}</div><div class="hint">kein Teil doppelt verplant</div></button>
        <button type="button" class="kpi warn" @click="showTab('fehlt')"><div class="label">Es fehlt nur 1 Teil</div><div class="value">{{ num(res.almost_count) }}</div><div class="hint">lohnt sich zum Nachkaufen</div></button>
        <button type="button" class="kpi warn" @click="showTab('blockiert')"><div class="label">Blockiert</div><div class="value">{{ num(res.joint_conflict_count) }}</div><div class="hint">Teile schon für andere Figur verplant</div></button>
      </div>

      <details class="panel tech">
        <summary class="panel-head"><h2>Technik: Katalog, BrickLink, Preise</h2><span class="faint small">für Fortgeschrittene</span></summary>
        <div class="tech-body stack">
      <div class="kpis">
        <div class="kpi"><div class="label">Kernvarianten</div><div class="value">{{ num(st.eligible_core_variants) }}</div><div class="hint">Figurenteile im Bestand</div></div>
        <div class="kpi"><div class="label">Varianten geprüft</div><div class="value">{{ num(st.scanned_variants) }}</div><div class="hint">noch {{ num(st.remaining_core_variants) }} offen</div></div>
        <div class="kpi"><div class="label">Kandidaten</div><div class="value">{{ num(st.candidate_minifigs) }}</div><div class="hint">bekannte Figuren</div></div>
        <div class="kpi"><div class="label">Stücklisten geladen</div><div class="value">{{ num(st.cached_subsets) }}</div><div class="hint">noch {{ num(st.remaining_subsets) }} offen<template v-if="st.subset_retry_waiting"> · {{ st.subset_retry_waiting }} warten</template></div></div>
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
              <div><span class="faint small">Lots / Teile</span>{{ num(st.inventory_lots) }} / {{ num(st.inventory_parts) }}</div>
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

      <section v-if="res" class="panel">
          <div class="panel-head"><h2>Preisabdeckung</h2></div>
          <table class="table"><tbody>
            <tr><td>Figuren mit Referenzwert</td><td class="num"><strong>{{ coverage.fig }}/{{ coverage.all }}</strong></td></tr>
            <tr><td>„1 Teil fehlt“ mit Fehlteilpreis</td><td class="num"><strong>{{ coverage.miss }}/{{ coverage.almost }}</strong></td></tr>
            <tr><td>100 % Teile-Preisabdeckung</td><td class="num"><strong>{{ coverage.full }}/{{ coverage.all }}</strong></td></tr>
            <tr><td>„1 Teil fehlt“ ohne Fehlteilpreis (nicht gelistet)</td><td class="num">{{ res.almost_without_cost_count }}</td></tr>
            <tr><td>Stücklisten mit weniger als 4 Teilen (übersprungen)</td><td class="num">{{ res.under_min_parts_count }}</td></tr>
          </tbody></table>
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
        <section class="panel">
          <div class="panel-head"><h2>Figurenkatalog einlesen</h2></div>
          <div class="panel-body stack-s">
            <p class="small muted">Figuren mit Stücklisten ohne BrickLink-API übernehmen. Möglich sind die Datenbank des MinifigFinder Standalone (<span class="mono">minifigfinder.db</span>), dessen Installer-ZIP, die Minifig-Transfer-ZIP aus Ollies Dashboard oder eine Katalogdatei (<span class="mono">.json.gz</span>). Gleiche Figuren werden ersetzt, Bestand und Aufträge bleiben unberührt.</p>
            <div class="facts">
              <div><span class="faint small">Figuren mit Stückliste</span>{{ num(st.cached_subsets) }}</div>
              <div><span class="faint small">Mitgelieferter Katalog</span>{{ st.katalog ? `${num(st.katalog.figuren)} Figuren · Stand ${st.katalog.stand}` : '–' }}</div>
            </div>
            <div class="toolbar">
              <label class="btn">Datei wählen<input type="file" accept=".db,.sqlite,.zip,.gz,application/zip,application/gzip" hidden @change="catalogFile = $event.target.files[0]" /></label>
              <button class="btn btn-primary" :disabled="!catalogFile || busy" @click="importCatalog">Einlesen</button>
            </div>
            <span v-if="catalogFile" class="small faint">{{ catalogFile.name }}</span>
          </div>
        </section>
      </div>
        </div>
      </details>
    </template>

    <template v-if="res">
      <section class="panel mflist">
        <div class="panel-head"><h2>Figuren</h2><span class="faint small">lokal berechnet · ohne API</span></div>
        <div class="tabs" role="tablist" aria-label="Listen">
          <button v-for="t in TABS" :key="t.value" type="button" role="tab" class="tabbtn" :class="[{ on: tab === t.value }, `t-${t.value}`]" :aria-selected="tab === t.value" @click="tab = t.value">
            {{ t.label }} <span class="cnt">{{ num(tabCount(t.value)) }}</span></button>
        </div>
        <div class="panel-body stack-s">
          <p class="small muted">{{ TABS.find((t) => t.value === tab).hint }}<template v-if="tab === 'plan'">{{ ' ' }}{{ figs(res.joint_buildable_count) }} ({{ num(res.joint_buildable_distinct_count ?? res.joint_buildable_count) }} verschiedene) gleichzeitig baubar.</template>
            <template v-if="tab === 'ergaenzbar'">{{ ' ' }}{{ num(res.almost_joint_buildable_count) }} vorbereitbar · {{ num(res.almost_joint_blocked_count) }} blockiert · {{ num(res.almost_joint_excluded_count) }} ausgeschlossen.</template></p>
          <div class="toolbar">
            <label class="searchbox"><Search :size="16" /><input v-model="q" class="input" type="search" :placeholder="isFigTab ? 'Nummer oder Name' : 'Teil-Nr. oder Name'" aria-label="Suche" /></label>
            <select v-if="isFigTab" v-model="sort" class="select" aria-label="Sortierung">
              <option value="order">{{ tab === 'plan' ? 'Reihenfolge des Bauplans' : 'Vorgabe-Reihenfolge' }}</option>
              <option value="figure_desc">Figurenwert ↓</option><option value="adv_desc">Vorteil ↓</option><option value="completion_asc">Fehlteilkosten ↑</option>
              <option value="completion_desc">Fehlteilkosten ↓</option><option value="parts_desc">Wert der Lagerteile ↓</option><option value="complete_desc">Vollständigkeit ↓</option>
              <option value="name_asc">Name A–Z</option>
            </select>
            <select v-model.number="limit" class="select" aria-label="Anzeige"><option v-for="n in [25, 50, 100, 250, 500]" :key="n" :value="n">{{ n }} pro Seite</option></select>
            <button v-if="tab !== 'blockiert'" class="btn btn-sm" @click="openDownload()"><Download :size="14" /> Herunterladen …</button>
          </div>
          <p v-if="isFigTab && tab !== 'blockiert'" class="small faint">„Vorteil“ = Wert der Figur − Wert der Lagerteile − Fehlteilkosten, vor Gebühren und Versand. Ohne Figurenpreis bleibt er offen.</p>
        </div>

        <div v-if="tab === 'fehlteile'" class="table-wrap">
          <table class="table">
            <thead><tr><th class="nr">Nr.</th><th>Fehlteil</th><th class="num">Figuren</th><th class="num">Menge</th><th class="num">Preis</th></tr></thead>
            <tbody>
              <tr v-for="(m, i) in pageRows" :key="`${m.item_no}|${m.color_id}`">
                <td class="nr">{{ (page - 1) * limit + i + 1 }}</td>
                <td><div class="partcell"><PartImage :no="m.item_no" :color="m.color_id" :size="32" /><div><RouterLink class="mono" :to="`/teil/${encodeURIComponent(m.item_no)}`">{{ m.item_no }}</RouterLink> · Farbe {{ m.color_id }}<div class="small muted">{{ htmlText(m.item_name) }}</div></div></div></td>
                <td class="num"><strong>{{ m.figures_affected }}</strong></td><td class="num">{{ m.total_shortfall }}</td>
                <td class="num">{{ eur(m.estimated_unit_price) }}<div v-if="m.price_source" class="small faint">{{ m.price_source }}</div></td>
              </tr>
              <tr v-if="!pageRows.length"><td colspan="5" class="empty">Keine Fehlteile.</td></tr>
            </tbody>
          </table>
        </div>

        <div v-else-if="tab === 'blockiert'" class="table-wrap">
          <table class="table">
            <thead><tr><th class="nr">Nr.</th><th>Minifigur</th><th>Nach Reservierung fehlend</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in pageRows" :key="r.minifig_no"><td class="nr">{{ (page - 1) * limit + i + 1 }}</td><td><div class="partcell"><PartImage :no="r.minifig_no" type="MINIFIG" :size="40" /><div><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ htmlText(r.name) }}</div></div></div></td>
                <td><div v-for="m in r.missing_after_reservations" :key="`${m.item_no}|${m.color_id}`" class="small partcell"><PartImage :no="m.item_no" :color="m.color_id" :size="28" /><span><span class="mono">{{ m.item_no }}</span> · Farbe {{ m.color_id }} · Rest {{ m.have || 0 }}, benötigt {{ m.quantity }}</span></div>
                  <span v-if="!r.missing_after_reservations.length" class="faint small">Gemeinsame Teile bereits reserviert</span></td></tr>
              <tr v-if="!pageRows.length"><td colspan="3" class="empty">{{ q ? 'Keine Treffer.' : 'Keine Konflikte – alle einzeln baubaren Figuren passen gleichzeitig.' }}</td></tr>
            </tbody>
          </table>
        </div>

        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th class="nr">Nr.</th><th>Minifigur</th><th class="num">Figurenwert</th><th class="num">Lagerteile</th><th v-if="tab !== 'plan' && tab !== 'einzeln'">Fehlteil</th><th class="num">Vorteil</th><th>Teile / Lagerort</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in pageRows" :key="r.minifig_no">
                <td class="nr">{{ (page - 1) * limit + i + 1 }}</td>
                <td><div class="partcell top"><PartImage :no="r.minifig_no" type="MINIFIG" :size="40" /><div><strong class="mono">{{ r.minifig_no }}</strong><div class="small">{{ htmlText(r.name) }}</div>
                  <div class="badges">
                    <span v-if="tab === 'plan'" class="qtybadge">{{ r.buildable_qty || 1 }}× bauen</span>
                    <span v-else-if="r.rank === 'buildable'" class="qtybadge">{{ r.buildable_qty }}× baubar</span>
                    <span v-if="tab === 'plan' && r.individual_buildable_qty > (r.buildable_qty || 1)" class="small faint">einzeln max. {{ r.individual_buildable_qty }}×</span>
                    <span v-if="tab !== 'plan'" class="pill" :class="r.rank === 'almost' ? 'warn' : 'ok'">{{ statusLabel(r) }}</span>
                    <span v-if="r.completeness !== undefined" class="small faint">{{ r.completeness }} % · Preise Teile {{ r.price_coverage_pct || 0 }} %</span>
                  </div>
                  <div v-if="jointMap.get(r.minifig_no)" class="built">
                    <select v-if="(jointMap.get(r.minifig_no).buildable_qty || 1) > 1" v-model.number="buildQty[r.minifig_no]" class="select select-sm" :aria-label="`Anzahl fertig gebaut ${r.minifig_no}`">
                      <option v-for="n in jointMap.get(r.minifig_no).buildable_qty" :key="n" :value="n">{{ n }}×</option>
                    </select>
                    <button class="btn btn-sm btn-primary" :disabled="busy || !!bd?.export" @click="markBuilt(jointMap.get(r.minifig_no))"><PackageCheck :size="14" /> Fertig gebaut</button>
                  </div></div></div></td>
                <td class="num">{{ eur(r.figure_value) }}<div v-if="r.figure_price_source" class="small faint">{{ r.figure_price_source }}</div></td>
                <td class="num">{{ eur(r.parts_value) }}</td>
                <td v-if="tab !== 'plan' && tab !== 'einzeln'" class="misscol">
                  <template v-if="r.rank === 'almost'">
                    <div v-for="m in r.missing ?? []" :key="`${m.item_no}|${m.color_id}`" class="small partcell miss"><PartImage :no="m.item_no" :color="m.color_id" :size="28" /><span><RouterLink class="mono" :to="`/teil/${encodeURIComponent(m.item_no)}`">{{ m.item_no }}</RouterLink> · Farbe {{ m.color_id }}<br /><span class="faint">vorhanden {{ m.have || 0 }}, benötigt {{ m.quantity }}</span></span></div>
                    <div class="small">Kosten <strong>{{ eur(r.completion_cost) }}</strong></div>
                  </template><template v-else>–</template></td>
                <td class="num"><strong>{{ eur(r.economic_advantage) }}</strong></td>
                <td class="wide">
                  <MinifigParts v-if="tab === 'plan'" :copies="r.buildable_qty || 1" :row="withLinks(r)" />
                  <MinifigParts v-else :row="r" :up-to="r.rank === 'buildable' ? r.buildable_qty : 0" />
                  <div v-if="r.aj" class="toolbar rej">
                    <button v-for="(l, k) in REASONS" :key="k" class="btn btn-sm" :disabled="busy" @click="decide(r.minifig_no, 'exclude', k)">{{ k === 'not_build' ? 'Nicht bauen' : `Nicht bauen · ${l}` }}</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!pageRows.length"><td colspan="7" class="empty">{{ q ? 'Keine Treffer.' : 'Noch keine Figuren in dieser Liste.' }}</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="pages > 1" class="pager">
          <span class="small faint">{{ num((page - 1) * limit + 1) }}–{{ num(Math.min(page * limit, filtered.length)) }} von {{ num(filtered.length) }}</span>
          <button class="btn btn-sm" :disabled="page <= 1" @click="page--">‹</button>
          <span class="small">Seite {{ page }} / {{ pages }}</span>
          <button class="btn btn-sm" :disabled="page >= pages" @click="page++">›</button>
        </div>
        <details v-if="tab === 'ergaenzbar' && res.almost_joint_excluded.length" class="excl">
          <summary class="small"><strong>Ausgeschlossen ({{ res.almost_joint_excluded.length }})</strong> · ihre Teile sind für andere Figuren wieder frei</summary>
          <div class="table-wrap">
            <table class="table"><tbody>
              <tr v-for="r in res.almost_joint_excluded" :key="r.minifig_no">
                <td><div class="partcell"><PartImage :no="r.minifig_no" type="MINIFIG" :size="32" /><span><strong class="mono">{{ r.minifig_no }}</strong> {{ htmlText(r.name) }}</span></div></td><td>{{ REASONS[r.reason] ?? 'Nicht bauen' }}</td>
                <td class="num"><button class="btn btn-sm" :disabled="busy" @click="decide(r.minifig_no, 'allow')">Wieder zulassen</button></td>
              </tr>
            </tbody></table>
          </div>
        </details>
      </section>
    </template>

    <TnDialog v-if="dlOpen" title="Liste herunterladen" @close="dlOpen = false">
      <label class="field"><span>Liste</span>
        <select v-model="dl2.list" class="select"><option v-for="o in DL_LISTS" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>
      <div v-if="dl2.list !== 'zip'" class="range">
        <label class="field"><span>von Nr.</span><input v-model.number="dl2.from" class="input" type="number" min="1" :max="dlCount" /></label>
        <label class="field"><span>bis Nr.</span><input v-model.number="dl2.to" class="input" type="number" min="1" :max="dlCount" /></label>
        <span class="small faint">{{ num(dlCount) }} Figuren in dieser Liste</span>
      </div>
      <div class="seg" role="radiogroup" aria-label="Format">
        <button type="button" :class="{ on: dl2.format === 'csv' }" role="radio" :aria-checked="dl2.format === 'csv'" @click="dl2.format = 'csv'">CSV (Excel)</button>
        <button type="button" :class="{ on: dl2.format === 'bsx' }" role="radio" :aria-checked="dl2.format === 'bsx'" @click="dl2.format = 'bsx'">BrickStore (BSX)</button>
      </div>
      <p v-if="dl2.list === 'view'" class="small muted">Die Nummern sind die aus der Liste, so wie sie gerade gesucht und sortiert ist.</p>
      <template #actions>
        <button class="btn" @click="dlOpen = false">Abbrechen</button>
        <button class="btn btn-primary" :disabled="dl2.list !== 'zip' && !dlCount" @click="doDownload"><Download :size="16" /> Herunterladen</button>
      </template>
    </TnDialog>

    <TnDialog v-if="expo" title="Sammlung abschließen" wide @close="expo = null">
      <p>{{ figs(expo.builds.reduce((t, b) => t + b.qty, 0)) }} fertig gebaut ({{ expo.builds.length === 1 ? '1 Eintrag' : `${num(expo.builds.length)} Einträge` }}).</p>
      <section v-if="expo.xml" class="step">
        <h3>1. BrickLink: Teile abziehen</h3>
        <p class="small muted">{{ num(expo.bricklink_lots) }} Lots mit Minus-Mengen. Bei BrickLink unter „Upload/Update Inventory (XML)“ als <strong>Update</strong> einfügen.</p>
        <div class="toolbar">
          <button class="btn btn-primary" @click="copyXml"><Copy :size="16" /> Kopieren und BrickLink öffnen</button>
          <button class="btn" @click="saveXml"><Download :size="16" /> Als Datei speichern</button>
          <span v-if="copied" class="small ok">Kopiert.</span>
        </div>
      </section>
      <p v-if="expo.without_lot.length" class="small warnc">{{ expo.without_lot.length }} Lots haben keine BrickLink-Lot-Nr. (z. B. neu angelegt) und stehen nicht in der Datei: {{ expo.without_lot.map((l) => `${l.item_no} ${l.remarks}`).join(', ') }}. Diese bitte bei BrickLink von Hand abziehen.</p>
      <section v-if="expo.bsx_files.length" class="step">
        <h3>{{ expo.xml ? 2 : 1 }}. BSX-Dateien</h3>
        <p class="small muted">Teile aus diesen Dateien wurden benutzt. Die neue Fassung enthält die Teile ohne die gebauten Figuren.</p>
        <ul class="files">
          <li v-for="f in expo.bsx_files" :key="f.id">
            <span class="grow"><strong>{{ f.name }}</strong> <span class="small muted">{{ num(f.parts) }} Teile aus {{ num(f.lots) }} Zeilen</span></span>
            <label class="small chk"><input v-model="applyBsx" type="checkbox" :value="f.id" /> im Finder übernehmen</label>
            <button class="btn btn-sm" @click="dl('minifig/builds/bsx', { id: f.id })"><Download :size="14" /> Herunterladen</button>
          </li>
        </ul>
      </section>
      <p class="small">Erst wenn bei BrickLink alles hochgeladen ist: <strong>„Ja, ist hochgeladen“</strong>. Dann werden die Teile auch im Dashboard-Bestand abgezogen{{ expo.bsx_files.length ? ' und die angehakten BSX-Dateien ersetzt' : '' }}.</p>
      <template #actions>
        <button class="btn" :disabled="busy" @click="finishExport(false)"><X :size="16" /> Verwerfen</button>
        <button class="btn" @click="expo = null">Später</button>
        <button class="btn btn-primary" :disabled="busy" @click="finishExport(true)">Ja, ist hochgeladen</button>
      </template>
    </TnDialog>

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
.howto { margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 4px; font-size: 0.92rem; }
.sources { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.src { display: flex; gap: 10px; align-items: flex-start; text-align: left; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); color: var(--text); font: inherit; cursor: pointer; }
.src > span { display: flex; flex-direction: column; gap: 2px; }
.src.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
.src:disabled { cursor: default; }
.files { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.files li { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.warnc { color: var(--warn); margin: 0; }
.tech > summary { cursor: pointer; list-style: none; }
.tech > summary::-webkit-details-marker { display: none; }
.tech-body { padding: var(--pad); }

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
.nr { width: 1%; white-space: nowrap; color: var(--text-3); font-variant-numeric: tabular-nums; text-align: right; }
.qtybadge { display: inline-block; padding: 1px 8px; border-radius: 10px; background: color-mix(in srgb, var(--ok) 18%, transparent); color: var(--ok); font-weight: 700; font-size: 0.85rem; margin-right: 4px; }
.built { display: flex; gap: 6px; align-items: center; margin-top: 6px; flex-wrap: wrap; }
.select-sm { height: 30px; padding: 0 6px; width: auto; }
.prio { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.seg { display: inline-flex; padding: 3px; gap: 2px; border-radius: var(--radius-sm); background: var(--bg); border: 1px solid var(--border); }
.seg button { height: 30px; padding: 0 12px; border: 0; border-radius: 4px; background: none; color: var(--text-2); font: inherit; cursor: pointer; }
.seg button.on { background: var(--surface-2); color: var(--text); box-shadow: 0 0 0 1px var(--border-strong); font-weight: 500; }
.order { display: inline-flex; }
.grow { flex: 1; min-width: 0; }
.range { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 10px; }
.range .input { width: 110px; }
.step { margin: 12px 0; }
.step h3 { margin: 0 0 4px; font-size: 1rem; }
.chk { display: inline-flex; gap: 6px; align-items: center; white-space: nowrap; }
.ok { color: var(--ok); }
.pager { display: flex; gap: 8px; align-items: center; justify-content: flex-end; padding: 10px var(--pad); }
.good-border { border-color: color-mix(in srgb, var(--ok) 45%, var(--border)); }
.warn-border { border-color: color-mix(in srgb, var(--warn) 45%, var(--border)); }
label.btn { cursor: pointer; }
button.kpi { font: inherit; color: inherit; text-align: left; cursor: pointer; }
button.kpi:hover { border-color: var(--border-strong); }
.mflist { scroll-margin-top: 12px; }
.tabs { display: flex; gap: 4px; padding: 8px var(--pad) 0; overflow-x: auto; border-bottom: 1px solid var(--border); scrollbar-width: thin; }
.tabbtn { flex: none; display: inline-flex; gap: 6px; align-items: center; padding: 8px 12px; border: 1px solid transparent; border-bottom: 0; border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  background: none; color: var(--text-2); font: inherit; font-size: 0.9rem; cursor: pointer; margin-bottom: -1px; }
.tabbtn.on { background: var(--surface); border-color: var(--border); color: var(--text); font-weight: 600; }
.tabbtn .cnt { padding: 0 7px; border-radius: 10px; background: var(--surface-2); font-size: 0.78rem; font-variant-numeric: tabular-nums; }
.tabbtn.t-plan .cnt, .tabbtn.t-einzeln .cnt { color: var(--ok); }
.tabbtn.t-fehlt .cnt, .tabbtn.t-ergaenzbar .cnt, .tabbtn.t-blockiert .cnt { color: var(--warn); }
.badges { display: flex; gap: 4px 6px; align-items: center; flex-wrap: wrap; margin-top: 3px; }
.miss { margin-bottom: 4px; }
.misscol { min-width: 190px; }
.partcell.top { align-items: flex-start; }
.excl { padding: 10px var(--pad) 12px; }
.excl summary { cursor: pointer; margin-bottom: 8px; }
td { vertical-align: top !important; }
</style>
