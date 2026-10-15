<script setup>
// Schlüssel/Wert-Liste für den Dev-Bereich. rows: [{ k, v, state: 'ok' | 'warn' | 'bad' | undefined, mono }]
defineProps({ rows: Array })
</script>

<template>
  <dl class="kv">
    <template v-for="r in rows" :key="r.k">
      <dt>{{ r.k }}</dt>
      <dd :class="[r.state, { mono: r.mono }]"><span v-if="r.state" class="dot" aria-hidden="true"></span>{{ r.v ?? '–' }}</dd>
    </template>
  </dl>
</template>

<style scoped>
.kv { display: grid; grid-template-columns: minmax(120px, max-content) minmax(0, 1fr); gap: 7px 18px; margin: 0; font-size: 0.9rem; }
dt { color: var(--text-3); }
dd { margin: 0; overflow-wrap: anywhere; display: flex; align-items: baseline; gap: 7px; }
.dot { width: 8px; height: 8px; border-radius: 50%; flex: none; align-self: center; }
.ok .dot { background: var(--ok); }
.warn .dot { background: var(--warn); }
.bad .dot { background: var(--danger); }
.bad { color: var(--danger); }
</style>
