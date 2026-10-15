<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Copy, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { api } from '../../api'
import { num } from '../../utils/format'

// Datenbank-Ansicht (nur lesen). Passwörter und gespeicherte Geheimnisse werden nie angezeigt.
const tables = ref([])
const current = ref('')
const page = ref(1)
const per = ref(25)
const data = ref(null)
const error = ref('')
const filter = ref('')
const copied = ref('')

onMounted(async () => {
  try {
    tables.value = (await api.get('dev/tables')).tables
    if (tables.value.length) current.value = tables.value[0].key ?? tables.value[0].name
  } catch (e) { error.value = e.message }
})

async function load() {
  if (!current.value) return
  error.value = ''
  try { data.value = await api.get('dev/table', { name: current.value, page: page.value, per: per.value }) } catch (e) { error.value = e.message }
}
watch(current, () => { page.value = 1; filter.value = ''; load() })
watch([page, per], load)

const pages = computed(() => (data.value ? Math.max(1, Math.ceil(data.value.total / data.value.per)) : 1))
const rows = computed(() => {
  const f = filter.value.trim().toLowerCase()
  if (!data.value || !f) return data.value?.rows ?? []
  return data.value.rows.filter((r) => Object.values(r).some((v) => String(v ?? '').toLowerCase().includes(f)))
})
const total = computed(() => tables.value.reduce((s, t) => s + (t.rows ?? 0), 0))

async function copy() {
  const text = JSON.stringify(rows.value, null, 2)
  try { await navigator.clipboard.writeText(text); copied.value = 'Kopiert.' } catch { copied.value = 'Kopieren nicht möglich.' }
  setTimeout(() => { copied.value = '' }, 2000)
}
</script>

<template>
  <div class="stack">
    <p v-if="error" class="error">{{ error }}</p>
    <div class="layout">
      <section class="panel side">
        <div class="panel-head"><h2>Tabellen</h2><span class="faint small">{{ num(total) }} Zeilen</span></div>
        <div class="list">
          <button v-for="t in tables" :key="t.name" class="tb" :class="{ on: current === (t.key ?? t.name) }" @click="current = t.key ?? t.name">
            <span class="mono">{{ t.table }}</span>
            <span class="faint small">{{ t.rows === null ? t.error : num(t.rows) }}</span>
          </button>
        </div>
      </section>

      <section class="panel main">
        <div class="panel-head toolbar">
          <input id="dev-data-filter" class="input grow" v-model="filter" type="search" placeholder="Auf dieser Seite filtern" aria-label="Filter" />
          <select id="dev-data-per" class="select" v-model.number="per" aria-label="Zeilen pro Seite">
            <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
          </select>
          <button class="btn btn-sm" :disabled="page <= 1" aria-label="Vorherige Seite" @click="page--"><ChevronLeft :size="16" /></button>
          <span class="small nowrap">Seite {{ page }} / {{ pages }}</span>
          <button class="btn btn-sm" :disabled="page >= pages" aria-label="Nächste Seite" @click="page++"><ChevronRight :size="16" /></button>
          <button class="btn btn-sm" :disabled="!rows.length" @click="copy"><Copy :size="14" /> JSON</button>
          <span v-if="copied" class="small faint">{{ copied }}</span>
        </div>
        <div class="table-wrap">
          <table v-if="data" class="table dense">
            <thead><tr><th v-for="c in data.columns" :key="c" class="mono">{{ c }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="i">
                <td v-for="c in data.columns" :key="c" class="mono small" :class="{ nul: r[c] === null }">{{ r[c] === null ? 'NULL' : r[c] }}</td>
              </tr>
              <tr v-if="!rows.length"><td :colspan="data.columns.length || 1" class="empty">Keine Zeilen.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: var(--gap); align-items: start; }
@media (max-width: 900px) { .layout { grid-template-columns: minmax(0, 1fr); } }
.list { display: flex; flex-direction: column; padding: 6px; }
.tb { display: flex; justify-content: space-between; gap: 8px; padding: 9px 10px; border: 0; background: none; border-radius: var(--radius-sm); color: var(--text); text-align: left; }
.tb:hover { background: var(--hover); }
.tb.on { background: var(--accent-soft); color: var(--accent-text); }
.grow { flex: 1; min-width: 140px; }
.nowrap { white-space: nowrap; }
.dense td, .dense th { white-space: nowrap; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
.nul { color: var(--text-3); font-style: italic; }
</style>
