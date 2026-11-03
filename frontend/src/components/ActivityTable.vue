<script setup>
import { computed, ref } from 'vue'
import { RefreshCw, ClipboardCheck, ClipboardList, Truck, Smile, Boxes, Upload, ScanSearch, Search, UserRound, Wrench, BarChart3, Circle } from 'lucide-vue-next'
import { describe, dayLabel, KIND_LABEL } from '../utils/activityText'

// Verlauf als lesbare Sätze, nach Tagen gruppiert (Wunsch des Inhabers, Sitzung 8). Name und Rohdaten stehen weiter im CSV.
const props = defineProps({ rows: Array, showUser: Boolean, compact: Boolean })

const ICON = { umlagern: RefreshCw, inventur: ClipboardCheck, pickliste: ClipboardList, auftraege: Truck, minifig: Smile, inventar: Boxes,
  bricklink: Upload, scanner: ScanSearch, suche: Search, konto: UserRound, dev: Wrench, analyse: BarChart3 }
const COLOR = { umlagern: '#1d9fa8', inventur: '#3c9b3c', pickliste: '#d04a3a', auftraege: '#2b6fd6', minifig: '#e6b81e', inventar: '#e2932c',
  bricklink: '#2b6fd6', scanner: '#5d6b7a', suche: '#5d6b7a', konto: '#8a4fc7', dev: '#5d6b7a', analyse: '#8a4fc7' }

const onlyMain = ref(true)
const kind = ref('')
const items = computed(() => (props.rows ?? []).map((r) => ({ ...r, ...describe(r) })))
const kinds = computed(() => [...new Set(items.value.map((x) => x.kind))].filter((k) => KIND_LABEL[k]).sort((a, b) => KIND_LABEL[a].localeCompare(KIND_LABEL[b], 'de')))
const shown = computed(() => items.value.filter((x) => (!onlyMain.value || !x.minor) && (!kind.value || x.kind === kind.value)))
const hidden = computed(() => items.value.length - shown.value.length)
const days = computed(() => {
  const out = []
  for (const x of shown.value) {
    const label = dayLabel(x.created_at)
    if (!out.length || out.at(-1).label !== label) out.push({ label, rows: [] })
    out.at(-1).rows.push(x)
  }
  return out
})
const time = (iso) => String(iso ?? '').slice(11, 16)
</script>

<template>
  <div class="verlauf">
    <div class="filters">
      <div class="seg" role="radiogroup" aria-label="Einträge">
        <button type="button" :class="{ on: onlyMain }" role="radio" :aria-checked="onlyMain" @click="onlyMain = true">Wichtiges</button>
        <button type="button" :class="{ on: !onlyMain }" role="radio" :aria-checked="!onlyMain" @click="onlyMain = false">Alles</button>
      </div>
      <select v-model="kind" class="select select-sm" aria-label="Bereich">
        <option value="">Alle Bereiche</option>
        <option v-for="k in kinds" :key="k" :value="k">{{ KIND_LABEL[k] }}</option>
      </select>
      <span v-if="onlyMain && hidden" class="small faint">{{ hidden }} kleine Einträge (Anmelden, Bereich geöffnet, Suche) ausgeblendet</span>
    </div>
    <div v-for="d in days" :key="d.label" class="day">
      <h3 class="dayhead">{{ d.label }}</h3>
      <ul class="entries">
        <li v-for="x in d.rows" :key="x.id" class="entry" :class="{ minor: x.minor }">
          <span class="time">{{ time(x.created_at) }}</span>
          <span class="ico" :style="{ '--c': COLOR[x.kind] ?? 'var(--text-3)' }" :title="KIND_LABEL[x.kind] ?? ''"><component :is="ICON[x.kind] ?? Circle" :size="15" /></span>
          <span class="txt"><strong v-if="showUser">{{ x.display_name }}</strong> {{ x.text }}</span>
        </li>
      </ul>
    </div>
    <p v-if="!shown.length" class="empty">Noch keine Einträge.</p>
  </div>
</template>

<style scoped>
.verlauf { display: flex; flex-direction: column; gap: 6px; padding: 10px var(--pad, 14px) 14px; }
.filters { display: flex; flex-wrap: wrap; gap: 8px 10px; align-items: center; margin-bottom: 4px; }
.seg { display: inline-flex; padding: 3px; gap: 2px; border-radius: var(--radius-sm); background: var(--bg); border: 1px solid var(--border); }
.seg button { height: 30px; padding: 0 12px; border: 0; border-radius: 4px; background: none; color: var(--text-2); font: inherit; cursor: pointer; }
.seg button.on { background: var(--surface-2); color: var(--text); box-shadow: 0 0 0 1px var(--border-strong, var(--border)); font-weight: 500; }
.dayhead { margin: 12px 0 4px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-3); font-weight: 600; }
.entries { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.entry { display: grid; grid-template-columns: 44px 28px minmax(0, 1fr); gap: 8px; align-items: start; padding: 7px 0; border-bottom: 1px solid var(--border); line-height: 1.4; }
.entry:last-child { border-bottom: 0; }
.entry.minor { opacity: 0.7; }
.time { color: var(--text-3); font-variant-numeric: tabular-nums; padding-top: 3px; font-size: 0.88rem; }
.ico { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; color: var(--c); background: color-mix(in srgb, var(--c) 16%, transparent); }
.txt { padding-top: 2px; }
.txt strong { color: var(--text); }
</style>
