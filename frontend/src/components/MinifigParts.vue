<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import LocationTag from './LocationTag.vue'
import PartImage from './PartImage.vue'
import { htmlText } from '../utils/format'
import { api } from '../api'

// Aufklappbare Teileliste einer Minifigur: Bild, BrickLink-Katalog, jedes Teil mit Lots, Remarks und Bemerkung.
// copies = wie viele Figuren die Teileliste abdeckt (gemeinsamer Bauplan „Plan 3×“: die Liste enthält alle 3 Kopien).
// Gleiche Teile (Teil + Farbe) werden zusammengefasst: „je Figur 2× · für 3 Figuren 6×“.
// upTo = einzeln gerechnet so oft baubar (Reiter „Einzeln baubar“ und „Alle“): zeigt zusätzlich die Menge für alle diese Figuren.
// choose: Ort je Quelle per Aufklappmenü umstellen (nur Bauplan, vor „Fertig gebaut“); swaps = gewählte Lots {"Teil|Farbe|Lot": neues Lot}
const props = defineProps({ row: Object, open: Boolean, copies: { type: Number, default: 1 }, upTo: { type: Number, default: 0 }, choose: Boolean, swaps: { type: Object, default: () => ({}) } })
// open: von außen steuerbar (Tipp auf Figurennummer/-name in der Liste klappt auf und zu)
const emit = defineEmits(['update:open', 'swap', 'missing'])
const shown = ref(props.open)
watch(() => props.open, (v) => { shown.value = v })
const toggled = (v) => { shown.value = v; if (v !== props.open) emit('update:open', v) }

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
// Teil aus mehreren Quellen (z. B. 1× aus FG03, 2× aus einer BSX-Datei): eine Zeile, Quellen deutlich markiert
const srcName = (x) => x.file || x.remarks || '–'
const multi = (p) => new Set(p.sources.map(srcName)).size > 1
// Freie Orte je Teil+Farbe, erst beim Aufklappen geladen
const opts = ref({})
const pkey = (p) => `${p.item_no}|${p.color_id}`
watch(() => props.row.pick_list, () => { opts.value = {} })
watch(() => [shown.value, props.choose, props.row.pick_list], async ([on, ch]) => {
  if (!on || !ch || Object.keys(opts.value).length) return
  try { opts.value = (await api.get('minifig/part-lots', { keys: parts.value.map(pkey).join(',') })).lots ?? {} } catch { opts.value = {} }
}, { immediate: true })
const srcLabel = (l) => `${l.file || l.remarks || 'ohne Lagerort'} · ${l.available} da${l.condition ? (l.condition === 'N' ? ' · neu' : ' · gebr.') : ''}`
const choices = (p, s) => {
  const list = opts.value[pkey(p)] ?? []
  return list.some((l) => l.inventory_id === s.inventory_id) ? list : [{ inventory_id: s.inventory_id, remarks: s.remarks, file: s.file, available: s.quantity, condition: s.new_or_used }, ...list]
}
// Eigenes Menü (schöner und deutlicher als das Standard-Menü des Browsers)
const menu = ref(null)
// Menü so ausrichten, dass es nie über den Bildschirmrand ragt
const menuPos = ref({})
const toggleMenu = (e, k) => {
  if (menu.value === k) { menu.value = null; return }
  const r = e.currentTarget.getBoundingClientRect()
  const box = (e.currentTarget.closest('.table-wrap, .panel') ?? document.body).getBoundingClientRect()
  const lo = Math.max(8, box.left + 8), hi = Math.min(window.innerWidth - 8, box.right - 8)
  const w = Math.min(340, hi - lo)
  const left = Math.max(lo, Math.min(r.left, hi - w)) - r.left
  menuPos.value = { left: `${left}px`, width: `${w}px` }
  menu.value = k
}
const pick = (p, s, l) => { emit('swap', { key: `${pkey(p)}|${s.inventory_id}`, to: l.inventory_id }); menu.value = null }
const closeMenu = () => { menu.value = null }
const onKey = (e) => { if (e.key === 'Escape') closeMenu() }
onMounted(() => { document.addEventListener('click', closeMenu); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('click', closeMenu); document.removeEventListener('keydown', onKey) })
const shownName = (p, s) => { const l = choices(p, s).find((x) => x.inventory_id === chosen(p, s)); return l?.remarks || s.remarks || l?.file || '–' }
const chosen = (p, s) => props.swaps[`${pkey(p)}|${s.inventory_id}`] ?? s.inventory_id
const per = (p) => Math.round(p.quantity / Math.max(1, props.copies))
const cond = (v) => (v === 'N' ? 'neu' : v === 'U' ? 'gebraucht' : v)
</script>

<template>
  <details class="figdetails" :open="shown" @toggle="toggled($event.target.open)">
    <summary>{{ (row.missing ?? []).length ? 'Bild, Fehlteil & Lagerorte' : 'Teile & Lagerorte' }} <span class="faint">({{ parts.length }})</span></summary>
    <div v-if="shown" class="wrap">
      <div class="fig">
        <PartImage :no="row.minifig_no" type="MINIFIG" :src="row.image_url" :size="110" eager :name="row.name" />
        <a v-if="row.catalog_url" :href="row.catalog_url" target="_blank" rel="noopener" class="small">BrickLink-Katalog <ExternalLink :size="12" /></a>
      </div>
      <div class="parts">
        <div v-for="(p, i) in parts" :key="i" class="part" :class="{ missing: p.shortfall > 0 }">
          <PartImage :name="p.name ?? p.item_name" :color-name="p.color_name" :no="p.item_no" :color="p.color_id" :size="40" eager />
          <div class="pinfo">
            <div>
              <RouterLink class="mono" :to="`/teil/${encodeURIComponent(p.item_no)}`">{{ p.item_no }}</RouterLink>
              · {{ p.color_name ? `${p.color_name} (Farbe ${p.color_id})` : `Farbe ${p.color_id}` }}
              <span v-if="p.shortfall > 0" class="pill danger">fehlt {{ p.shortfall }}×</span>
              <span v-if="multi(p)" class="pill warn">aus {{ new Set(p.sources.map(srcName)).size }} Quellen</span>
            </div>
            <div class="small muted">{{ htmlText(p.item_name) }}</div>
            <div class="small need">
              <strong>je Figur {{ per(p) }}×</strong>
              <template v-if="copies > 1"> · für {{ copies }} Figuren <strong>{{ p.quantity }}×</strong></template>
              <template v-else-if="upTo > 1"> · für alle {{ upTo }} Figuren <strong>{{ per(p) * upTo }}×</strong></template>
              <span class="faint"><template v-if="p.stock_before_figure !== undefined"> · verfügbar vor Reservierung {{ p.stock_before_figure }}</template><template v-else> · zugeordnet {{ p.available ?? 0 }}</template></span>
            </div>
            <div v-for="(s, k) in p.sources" :key="k" class="src small" :class="{ many: multi(p) }">
              <strong v-if="multi(p)" class="srcqty">{{ s.quantity }}× {{ srcName(s) }}</strong>
              <span class="faint">Lot {{ s.inventory_id }} · {{ s.quantity }}×<template v-if="s.lot_quantity"> von {{ s.lot_quantity }}</template></span>
              <span v-if="cond(s.new_or_used)" class="faint">{{ cond(s.new_or_used) }}</span>
              <span v-if="s.file" class="tag">Datei: {{ s.file }}</span>
              <span class="lbl">Remarks:</span>
              <!-- Aussehen wie vorher (Schild mit Remarks); ein Tipp öffnet ein eigenes Menü mit den freien Orten -->
              <span v-if="choose && opts[pkey(p)]" class="ortpick" :class="{ changed: chosen(p, s) !== s.inventory_id, open: menu === `${pkey(p)}|${s.inventory_id}` }">
                <button type="button" class="ortbtn" :aria-expanded="menu === `${pkey(p)}|${s.inventory_id}`" :aria-label="`Ort für ${p.item_no} wählen`"
                  @click.stop="toggleMenu($event, `${pkey(p)}|${s.inventory_id}`)">
                  <span class="tag mono">{{ shownName(p, s) }}</span><span class="caret" aria-hidden="true">▾</span>
                </button>
                <span v-if="menu === `${pkey(p)}|${s.inventory_id}`" class="ortmenu" :style="menuPos" role="listbox" @click.stop>
                  <span class="ortmenu-head">Anderen Ort wählen · {{ p.item_no }}</span>
                  <button v-for="l in choices(p, s)" :key="l.inventory_id" type="button" role="option" class="ortopt" :class="{ sel: l.inventory_id === chosen(p, s), orig: l.inventory_id === s.inventory_id }"
                    :aria-selected="l.inventory_id === chosen(p, s)" @click="pick(p, s, l)">
                    <span class="mark">{{ l.inventory_id === chosen(p, s) ? '✓' : '' }}</span>
                    <span class="where"><strong class="mono">{{ l.remarks || l.file || 'ohne Lagerort' }}</strong><span v-if="l.file && l.remarks" class="small faint">{{ l.file }}</span></span>
                    <span class="avail"><strong>{{ l.available }}</strong> da</span>
                    <span v-if="l.condition" class="cond" :class="l.condition === 'N' ? 'n' : 'u'">{{ l.condition === 'N' ? 'neu' : 'gebr.' }}</span>
                    <span v-if="l.inventory_id === s.inventory_id" class="small faint vorschlag">Vorschlag</span>
                  </button>
                </span>
              </span>
              <template v-else-if="s.remarks"><span v-if="s.file" class="mono">{{ s.remarks }}</span><LocationTag v-else :code="s.remarks" /></template>
              <span v-else class="warnc">{{ s.file ? '–' : 'Lagerort fehlt' }}</span>
              <template v-if="s.description"><span class="lbl">Bemerkung:</span><span>{{ htmlText(s.description) }}</span></template>
              <button v-perm="'minifig_fehlt'" type="button" class="btn btn-ghost btn-sm fehlt" :title="`${p.item_no} fehlt hier – Menge abziehen`" @click="emit('missing', { part: p, src: s, inventory_id: chosen(p, s) })">Fehlt</button>
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
.src.many { border-left: 3px solid var(--warn); padding-left: 6px; }
.srcqty { color: var(--warn); }
.ortpick { position: relative; display: inline-flex; align-items: center; gap: 3px; cursor: pointer; }
.ortbtn { all: unset; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; border-radius: 6px; padding: 1px 2px; }
.ortbtn .tag { border-color: var(--accent); }
.ortbtn .caret { font-size: 0.8rem; color: var(--accent-text); }
.ortbtn:focus-visible { outline: 2px solid var(--accent); }
.ortpick.open .tag { background: color-mix(in srgb, var(--accent) 18%, transparent); }
.ortmenu { position: absolute; z-index: 60; top: calc(100% + 6px); left: 0; max-height: 300px; overflow: auto;
  background: var(--surface); border: 1px solid var(--border-strong, var(--border)); border-radius: var(--radius-sm); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
  display: flex; flex-direction: column; padding: 4px; }
.ortmenu-head { font-size: 0.75rem; color: var(--text-3); padding: 6px 8px 4px; text-transform: uppercase; letter-spacing: 0.04em; }
.ortopt { all: unset; display: grid; grid-template-columns: 16px 1fr auto auto; align-items: center; gap: 8px; padding: 9px 8px; border-radius: 6px; cursor: pointer; }
.ortopt:hover, .ortopt:focus-visible { background: var(--surface-2); }
.ortopt.sel { background: color-mix(in srgb, var(--accent) 16%, transparent); }
.ortopt .mark { color: var(--accent-text); font-weight: 700; }
.ortopt .where { display: flex; flex-direction: column; min-width: 0; }
.ortopt .avail { white-space: nowrap; }
.ortopt .cond { font-size: 0.75rem; padding: 1px 6px; border-radius: 999px; border: 1px solid var(--border); }
.ortopt .cond.n { color: var(--ok); border-color: color-mix(in srgb, var(--ok) 45%, transparent); }
.ortopt .vorschlag { grid-column: 2 / -1; margin-top: -4px; }
.ortpick.changed .tag { border-color: var(--warn); color: var(--warn); }
.fehlt { color: var(--danger); padding: 0 8px; height: 26px; }
.src { display: flex; gap: 6px 8px; align-items: center; flex-wrap: wrap; }
.lbl { color: var(--text-3); }
.tag { padding: 0 6px; border-radius: 4px; background: var(--surface-2); border: 1px solid var(--border); }
.warnc { color: var(--warn); }
@media (max-width: 600px) { .wrap { grid-template-columns: minmax(0, 1fr); } }
</style>
