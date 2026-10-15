<script setup>
import { computed } from 'vue'
// Einfache horizontale Balken (eine Datenreihe): Länge = Wert, Zahl steht daneben.
const props = defineProps({
  rows: { type: Array, default: () => [] },
  valueKey: { type: String, default: 'value' },
  format: { type: Function, default: (v) => v },
  unit: { type: String, default: '' },
})
const max = computed(() => Math.max(1, ...props.rows.map((r) => r[props.valueKey] ?? 0)))
</script>

<template>
  <div class="bars" role="table">
    <div v-for="r in rows" :key="r.label" class="row" role="row" :title="`${r.label}: ${format(r[valueKey])}${unit}`">
      <div class="label" role="cell">{{ r.label }}</div>
      <div class="track" role="cell" aria-hidden="true">
        <div class="bar" :style="{ width: `${Math.max(0.5, (r[valueKey] / max) * 100)}%` }"></div>
      </div>
      <div class="val" role="cell">{{ format(r[valueKey]) }}{{ unit }}</div>
    </div>
    <div v-if="!rows.length" class="faint small">Keine Daten.</div>
  </div>
</template>

<style scoped>
.bars { display: flex; flex-direction: column; gap: 4px; }
.row { display: grid; grid-template-columns: minmax(90px, 32%) 1fr auto; align-items: center; gap: 12px; padding: 3px 4px; border-radius: 4px; }
.row:hover { background: var(--hover); }
.label { font-size: 0.86rem; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.track { height: 10px; }
.bar { height: 100%; background: var(--accent); border-radius: 0 4px 4px 0; min-width: 2px; }
.val { font-size: 0.86rem; font-variant-numeric: tabular-nums; min-width: 64px; text-align: right; }
</style>
