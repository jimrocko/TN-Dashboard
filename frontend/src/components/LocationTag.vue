<script setup>
defineProps({ code: String, kind: String })
</script>

<template>
  <span class="tag mono" :class="{ mixed: kind === 'mischkiste' }" :title="kind === 'mischkiste' ? 'Mischkiste' : 'Regalplatz'">{{ code ?? '–' }}</span>
</template>

<style scoped>
.mixed { border-style: dashed; color: var(--warn); }
</style>
