<script setup>
import { computed, ref } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import LocationTag from './LocationTag.vue'
import PartImage from './PartImage.vue'
import { htmlText } from '../utils/format'

// Aufklappbare Teileliste einer Minifigur: Bild, BrickLink-Katalog, jedes Teil mit Lots, Remarks und Bemerkung.
// copies = wie viele Figuren die Teileliste abdeckt (gemeinsamer Bauplan „Plan 3×“: die Liste enthält alle 3 Kopien).
// Gleiche Teile (Teil + Farbe) werden zusammengefasst: „je Figur 2× · für 3 Figuren 6×“.
// upTo = einzeln gerechnet so oft baubar (Rangliste, „Einzeln baubar“): zeigt zusätzlich die Menge für alle diese Figuren.
const props = defineProps({ row: Object, open: Boolean, copies: { type: Number, default: 1 }, upTo: { type: Number, default: 0 } })
const shown = ref(props.open)

const parts = computed(() => {
  const m = new Map()
  for (const p of props.row.pick_list ?? []) {
    const k = `${p.item_no}|${p.color_id}`
    const g = m.get(k) ?? { ...p, quantity: 0, shortfall: 0, available: 0, sources: [] }
    g.quantity += p.quantity ?? 0
    g.shortfall += p.shortfall ?? 0
    g.available += p.available ?? 0
    for (const s of p.sources ?? []) {
      const same = g.sources.find((x) => x.inventory_id === s.inventory_id)
      if (same) same.quantity += s.quantity
      else g.sources.push({ ...s })
    }
    m.set(k, g)
  }
  return [...m.values()]
})
const per = (p) => Math.round(p.quantity / Math.max(1, props.copies))
const cond = (v) => (v === 'N' ? 'neu' : v === 'U' ? 'gebraucht' : v)
</script>

<template>
  <details class="figdetails" :open="shown" @toggle="shown = $event.target.open">
    <summary>{{ (row.missing ?? []).length ? 'Bild, Fehlteil & Lagerorte' : 'Teile & Lagerorte' }} <span class="faint">({{ parts.length }})</span></summary>
    <div v-if="shown" class="wrap">
      <div class="fig">
        <PartImage :no="row.minifig_no" type="MINIFIG" :src="row.image_url" :size="110" eager />
        <a v-if="row.catalog_url" :href="row.catalog_url" target="_blank" rel="noopener" class="small">BrickLink-Katalog <ExternalLink :size="12" /></a>
      </div>
      <div class="parts">
        <div v-for="(p, i) in parts" :key="i" class="part" :class="{ missing: p.shortfall > 0 }">
          <PartImage :no="p.item_no" :color="p.color_id" :size="40" eager />
          <div class="pinfo">
            <div>
              <RouterLink class="mono" :to="`/teil/${encodeURIComponent(p.item_no)}`">{{ p.item_no }}</RouterLink>
              · {{ p.color_name ? `${p.color_name} (Farbe ${p.color_id})` : `Farbe ${p.color_id}` }}
              <span v-if="p.shortfall > 0" class="pill danger">fehlt {{ p.shortfall }}×</span>
            </div>
            <div class="small muted">{{ htmlText(p.item_name) }}</div>
            <div class="small need">
              <strong>je Figur {{ per(p) }}×</strong>
              <template v-if="copies > 1"> · für {{ copies }} Figuren <strong>{{ p.quantity }}×</strong></template>
              <template v-else-if="upTo > 1"> · für alle {{ upTo }} Figuren <strong>{{ per(p) * upTo }}×</strong></template>
              <span class="faint"><template v-if="p.stock_before_figure !== undefined"> · verfügbar vor Reservierung {{ p.stock_before_figure }}</template><template v-else> · zugeordnet {{ p.available ?? 0 }}</template></span>
            </div>
            <div v-for="(s, k) in p.sources" :key="k" class="src small">
              <span class="faint">Lot {{ s.inventory_id }} · {{ s.quantity }}×<template v-if="s.lot_quantity"> von {{ s.lot_quantity }}</template></span>
              <span v-if="cond(s.new_or_used)" class="faint">{{ cond(s.new_or_used) }}</span>
              <span v-if="s.file" class="tag">Datei: {{ s.file }}</span>
              <span class="lbl">Remarks:</span>
              <template v-if="s.remarks"><span v-if="s.file" class="mono">{{ s.remarks }}</span><LocationTag v-else :code="s.remarks" /></template>
              <span v-else class="warnc">{{ s.file ? '–' : 'Lagerort fehlt' }}</span>
              <template v-if="s.description"><span class="lbl">Bemerkung:</span><span>{{ htmlText(s.description) }}</span></template>
            </div>
            <div v-if="!p.sources.length && !(p.shortfall > 0)" class="small warnc">Kein aktuelles Lot vorhanden</div>
          </div>
        </div>
        <div v-if="!parts.length" class="small faint">Keine Teileliste verfügbar.</div>
      </div>
    </div>
  </details>
</template>

<style scoped>
.figdetails summary { cursor: pointer; color: var(--accent-text); font-size: 0.88rem; }
.wrap { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 12px; margin-top: 8px; }
.fig { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.parts { display: flex; flex-direction: column; gap: 8px; }
.part { display: flex; gap: 10px; align-items: flex-start; padding: 6px 8px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.part.missing { border-color: var(--danger); }
.pinfo { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.need strong { color: var(--text); }
.src { display: flex; gap: 6px 8px; align-items: center; flex-wrap: wrap; }
.lbl { color: var(--text-3); }
.tag { padding: 0 6px; border-radius: 4px; background: var(--surface-2); border: 1px solid var(--border); }
.warnc { color: var(--warn); }
@media (max-width: 600px) { .wrap { grid-template-columns: minmax(0, 1fr); } }
</style>
