<script setup>
import { computed, ref } from 'vue'
import { AREAS, areaById, parseCode, formatCode, isMixedBox } from '../utils/areas'
import { num } from '../utils/format'

// Raumplan des Lagerzimmers. Antippen: Bereich → Hunderter-Block → Kästchen → Lagerort-Seite.
// locations: aus inventory/meta (id, code, name, kind, lots, qty); open: { code: Anzahl offener Umlagerungsvorschläge }
// markMixed: Mischkisten rot markieren (Umlagern ja, Inventar nein)
const props = defineProps({
  locations: { type: Array, default: () => [] },
  open: { type: Object, default: () => ({}) },
  markMixed: { type: Boolean, default: true },
})

const selected = ref(null) // 'G' | 'H' | 'N' | 'F'
const block = ref(null) // Startnummer des Hunderter-Blocks, z. B. 200

const byArea = computed(() => {
  const m = {}
  for (const l of props.locations) {
    const p = parseCode(l.code)
    if (!p) continue // Codes außerhalb des Raumplans
    ;(m[p.area] ??= []).push({ ...l, num: p.num })
  }
  for (const list of Object.values(m)) list.sort((a, b) => a.code.localeCompare(b.code, 'de', { numeric: true }))
  return m
})
const stats = (ids) => {
  const list = ids.flatMap((id) => byArea.value[id] ?? [])
  return {
    boxes: list.filter((l) => l.lots).length,
    lots: list.reduce((s, l) => s + l.lots, 0),
    qty: list.reduce((s, l) => s + l.qty, 0),
    open: list.reduce((s, l) => s + (props.open[l.code] ?? 0), 0),
    mixed: props.markMixed ? list.filter((l) => isMixedBox(l.code, l.lots)).length : 0,
  }
}
const fIds = AREAS.filter((a) => a.group === 'F').map((a) => a.id)
const zones = computed(() => [
  { key: 'G', title: `${formatCode('G', 100)}–${formatCode('G', 999)}`, sub: 'Gebraucht', color: areaById('G').color, s: stats(['G']) },
  { key: 'H', title: `${formatCode('H', 0)}–${formatCode('H', 999)}`, sub: 'Regal H', color: areaById('H').color, s: stats(['H']) },
  { key: 'N', title: `${formatCode('N', 0)}–${formatCode('N', 999)}`, sub: 'Neu', color: areaById('N').color, s: stats(['N']) },
  { key: 'F', title: 'FG · FN · FT · Plate', sub: 'Figuren & Plates', color: areaById('FG').color, s: stats(fIds) },
])
const zone = computed(() => zones.value.find((z) => z.key === selected.value))

function pick(key) {
  selected.value = selected.value === key ? null : key
  block.value = null
}

// Hunderter-Blöcke für G, H, N
const area = computed(() => areaById(selected.value))
const blocks = computed(() => {
  if (!area.value?.to) return []
  const used = byArea.value[area.value.id] ?? []
  const out = []
  for (let start = Math.floor(area.value.from / 100) * 100; start <= area.value.to; start += 100) {
    const inBlock = used.filter((l) => l.num >= start && l.num < start + 100)
    out.push({
      start, end: start + 99,
      boxes: inBlock.filter((l) => l.lots).length,
      mixed: props.markMixed ? inBlock.filter((l) => isMixedBox(l.code, l.lots)).length : 0,
      open: inBlock.reduce((s, l) => s + (props.open[l.code] ?? 0), 0),
    })
  }
  return out
})
const cells = computed(() => {
  if (block.value === null || !area.value) return []
  const byNum = Object.fromEntries((byArea.value[area.value.id] ?? []).map((l) => [l.num, l]))
  return Array.from({ length: 100 }, (_, i) => {
    const n = block.value + i
    const loc = byNum[n] ?? null
    return { n, code: formatCode(area.value.id, n), loc, mixed: props.markMixed && !!loc && isMixedBox(loc.code, loc.lots) }
  })
})
const fGroups = computed(() => AREAS.filter((a) => a.group === 'F').map((a) => ({ ...a, items: byArea.value[a.id] ?? [] })))
</script>

<template>
  <div class="roomplan">
    <div class="room" role="group" aria-label="Lagerzimmer">
      <!-- Wände mit Regalen, Lage wie auf der Skizze -->
      <button type="button" class="wall w-g" :class="{ on: selected === 'G' }" :style="{ '--c': zones[0].color }" aria-label="Regal G" @click="pick('G')"></button>
      <button type="button" class="wall w-h" :class="{ on: selected === 'H' }" :style="{ '--c': zones[1].color }" aria-label="Regal H" @click="pick('H')"></button>
      <button type="button" class="wall w-n" :class="{ on: selected === 'N' }" :style="{ '--c': zones[2].color }" aria-label="Regal N" @click="pick('N')"></button>
      <button type="button" class="wall w-f1" :class="{ on: selected === 'F' }" :style="{ '--c': zones[3].color }" aria-label="Figuren und Plates" @click="pick('F')"></button>
      <button type="button" class="wall w-f2" :class="{ on: selected === 'F' }" :style="{ '--c': zones[3].color }" aria-label="Figuren und Plates" @click="pick('F')"></button>

      <button v-for="z in zones" :key="z.key" type="button" class="zone" :class="[`z-${z.key}`, { on: selected === z.key }]" :style="{ '--c': z.color }" @click="pick(z.key)">
        <span class="zt">{{ z.title }}</span>
        <span class="zs">{{ z.sub }} · {{ z.s.boxes }} Kästchen belegt</span>
        <span v-if="z.s.mixed" class="zmixed">{{ z.s.mixed }} Mischkiste{{ z.s.mixed === 1 ? '' : 'n' }}</span>
        <span v-if="z.s.open" class="badge" :title="`${z.s.open} Umlagerungsvorschläge`">{{ z.s.open }}</span>
      </button>
    </div>

    <p v-if="!zone" class="faint small hint">Bereich antippen, um die Kästchen zu sehen.</p>

    <div v-else class="detail" :style="{ '--c': zone.color }">
      <div class="dhead">
        <div>
          <strong>{{ zone.title }}</strong> <span class="muted">· {{ zone.sub }}</span>
          <div class="faint small">{{ zone.s.boxes }} Kästchen belegt · {{ num(zone.s.lots) }} Lose · {{ num(zone.s.qty) }} Teile</div>
          <div v-if="markMixed && area?.maxLots" class="legend small"><i class="lg mixed"></i> Mischkiste: mehr als {{ area.maxLots }} verschiedene Lose, noch aufteilen ({{ zone.s.mixed }})</div>
        </div>
        <button type="button" class="btn btn-sm" @click="pick(zone.key)">Schließen</button>
      </div>

      <!-- G / H / N: erst Hunderter-Block, dann 100 Kästchen -->
      <template v-if="blocks.length">
        <div class="blocks">
          <button v-for="b in blocks" :key="b.start" type="button" class="blk" :class="{ on: block === b.start, empty: !b.boxes }" @click="block = block === b.start ? null : b.start">
            <span class="mono">{{ formatCode(area.id, b.start) }}–{{ formatCode(area.id, b.end) }}</span>
            <span class="faint small">{{ b.boxes }} belegt</span>
            <span v-if="b.mixed" class="bmixed small">{{ b.mixed }} Mischkiste{{ b.mixed === 1 ? '' : 'n' }}</span>
            <span v-if="b.open" class="badge">{{ b.open }}</span>
          </button>
        </div>
        <div v-if="block !== null" class="cells" role="grid" :aria-label="`Kästchen ${formatCode(area.id, block)} bis ${formatCode(area.id, block + 99)}`">
          <template v-for="c in cells" :key="c.n">
            <RouterLink v-if="c.loc" :to="`/lagerort/${encodeURIComponent(c.code)}`" class="cell used" :class="{ empty: !c.loc.lots, mixed: c.mixed }"
              :title="`${c.code}: ${c.loc.lots} Lose, ${num(c.loc.qty)} Teile${c.mixed ? ' · Mischkiste, aufteilen' : ''}`">
              {{ c.code.slice(area.id.length) }}<i v-if="open[c.code]" class="dot"></i>
            </RouterLink>
            <span v-else class="cell" :title="`${c.code}: nicht angelegt`">{{ c.code.slice(area.id.length) }}</span>
          </template>
        </div>
      </template>

      <!-- Figuren & Plates: Kästchen je Unterbereich -->
      <template v-else>
        <div v-for="g in fGroups" :key="g.id" class="sub">
          <div class="subname">{{ g.id }} · {{ g.label }}</div>
          <div class="tiles">
            <RouterLink v-for="l in g.items" :key="l.id" :to="`/lagerort/${encodeURIComponent(l.code)}`" class="tile2" :class="{ empty: !l.lots }">
              <span class="mono code">{{ l.code }}</span><span class="faint small">{{ l.lots }} Lose · {{ num(l.qty) }}</span>
            </RouterLink>
            <span v-if="!g.items.length" class="faint small">Noch keine Kästchen angelegt.</span>
          </div>
        </div>
      </template>

    </div>
  </div>
</template>

<style scoped>
.roomplan { display: flex; flex-direction: column; gap: 14px; }

/* Zimmer von oben */
.room {
  position: relative; width: 100%; max-width: 760px; aspect-ratio: 4 / 3.3; margin: 0 auto;
  border: 3px solid var(--text-3); border-radius: 4px; background: var(--surface-2);
}
.wall { position: absolute; padding: 0; border: 0; border-radius: 4px; background: var(--c); opacity: 0.85; cursor: pointer; }
.wall:hover, .wall.on { opacity: 1; box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 45%, transparent); }
.w-g { left: 2%; top: 3%; width: 4%; height: 58%; }
.w-h { right: 2%; top: 3%; width: 4%; height: 25%; }
.w-n { right: 2%; top: 31%; width: 4%; height: 59%; }
.w-f1 { left: 2%; top: 64%; width: 4%; height: 10%; }
.w-f2 { left: 2%; top: 70%; width: 40%; height: 4%; }

.zone {
  position: absolute; display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
  padding: 10px 14px; border-radius: 999px; border: 2px solid var(--c); background: var(--surface);
  color: var(--text); text-align: left; min-width: 0; max-width: 46%;
}
.zone:hover, .zone.on { background: color-mix(in srgb, var(--c) 18%, var(--surface)); }
.zone.on { box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 40%, transparent); }
.zt { font-weight: 700; font-size: clamp(0.85rem, 2.2vw, 1.15rem); white-space: nowrap; }
.zs { font-size: 0.75rem; color: var(--text-2); }
.z-G { left: 10%; top: 14%; }
.z-H { right: 9%; top: 9%; }
.z-N { right: 9%; top: 40%; }
.z-F { left: 10%; top: 55%; }

.badge {
  position: absolute; top: -8px; right: -6px; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 11px;
  display: grid; place-items: center; font-size: 0.75rem; font-weight: 700; background: var(--warn); color: #111;
}
.hint { text-align: center; margin: 0; }

/* Auswahl */
.detail { border: 2px solid var(--c); border-radius: var(--radius-sm); padding: 14px; display: flex; flex-direction: column; gap: 12px; }
.dhead { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.blocks { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 8px; }
.blk {
  position: relative; display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; min-height: 52px; text-align: left;
  border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); color: var(--text);
}
.blk:hover, .blk.on { border-color: var(--c); background: color-mix(in srgb, var(--c) 15%, var(--surface)); }
.blk.empty { opacity: 0.55; }
.cells { display: grid; grid-template-columns: repeat(10, minmax(0, 1fr)); gap: 4px; width: 100%; max-width: 620px; }
.cell {
  position: relative; display: grid; place-items: center; aspect-ratio: 1; min-height: 34px; border-radius: 5px;
  font-size: 0.75rem; font-variant-numeric: tabular-nums; color: var(--text-3); border: 1px dashed var(--border);
}
.cell.used { border: 1px solid var(--c); background: color-mix(in srgb, var(--c) 30%, var(--surface)); color: var(--text); font-weight: 600; }
.cell.used:hover { background: color-mix(in srgb, var(--c) 55%, var(--surface)); }
.cell.used.empty { background: var(--surface); font-weight: 400; }
.cell.used.mixed { background: color-mix(in srgb, var(--danger) 70%, var(--surface)); border-color: var(--danger); color: #fff; }
.cell.used.mixed:hover { background: var(--danger); }
.zmixed, .bmixed { color: var(--danger); font-weight: 600; font-size: 0.75rem; }
.legend { display: flex; align-items: center; gap: 6px; color: var(--text-2); margin-top: 4px; }
.lg { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
.lg.mixed { background: var(--danger); }
.dot { position: absolute; top: 3px; right: 3px; width: 7px; height: 7px; border-radius: 50%; background: var(--warn); }
.sub { display: flex; flex-direction: column; gap: 6px; }
.subname { font-size: 0.85rem; color: var(--text-2); }
.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 8px; }
.tile2 {
  position: relative; display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; min-height: 52px;
  border: 1px solid var(--c); border-radius: var(--radius-sm); background: color-mix(in srgb, var(--c) 14%, var(--surface)); color: var(--text);
}
.tile2:hover { background: color-mix(in srgb, var(--c) 30%, var(--surface)); }
.tile2.empty { opacity: 0.6; }
.tile2 .code { font-weight: 600; }

@media (max-width: 640px) {
  .room { aspect-ratio: 3 / 4; }
  .zone { padding: 6px 10px; max-width: 60%; }
  .zs { display: none; }
  .z-G { left: 9%; top: 8%; }
  .z-H { right: 8%; top: 5%; }
  .z-N { right: 8%; top: 42%; }
  .z-F { left: 9%; top: 58%; }
  .cells { gap: 3px; }
  .cell { min-height: 28px; font-size: 0.68rem; }
}
</style>
