<script setup>
// Lagerort-Kürzel. Standardmäßig ein Link auf die Lagerort-Seite (/lagerort/<code>);
// :link="false" nur dort, wo der Tag schon in einem Knopf oder Link steckt.
defineProps({ code: String, kind: String, link: { type: Boolean, default: true } })
</script>

<template>
  <RouterLink v-if="link && code" :to="`/lagerort/${encodeURIComponent(code)}`" class="tag mono loc" :class="{ mixed: kind === 'mischkiste' }"
    :title="`${kind === 'mischkiste' ? 'Mischkiste' : 'Lagerort'} ${code} öffnen`" @click.stop>{{ code }}</RouterLink>
  <span v-else class="tag mono" :class="{ mixed: kind === 'mischkiste' }" :title="kind === 'mischkiste' ? 'Mischkiste' : 'Regalplatz'">{{ code ?? '–' }}</span>
</template>

<style scoped>
.mixed { border-style: dashed; color: var(--warn); }
.loc { cursor: pointer; text-decoration: none; }
.loc:hover { border-color: var(--accent); color: var(--accent-text); background: var(--accent-soft); }
</style>
