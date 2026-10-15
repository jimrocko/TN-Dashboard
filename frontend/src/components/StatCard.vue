<script setup>
defineProps({ label: String, value: [String, Number], hint: String, to: [String, Object] })
</script>

<template>
  <component :is="to ? 'RouterLink' : 'div'" :to="to" class="stat panel" :class="{ link: to }">
    <div class="label">{{ label }}</div>
    <div class="value">{{ value }}</div>
    <div v-if="hint" class="hint">{{ hint }}</div>
  </component>
</template>

<style scoped>
.stat { display: flex; flex-direction: column; gap: 4px; padding: 14px var(--pad); min-width: 0; }
.link:hover { border-color: var(--border-strong); background: var(--surface-2); }
.label { font-size: 0.8rem; color: var(--text-2); }
.value { font-size: 1.55rem; font-weight: 600; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.hint { font-size: 0.8rem; color: var(--text-3); }
</style>
