<script setup>
import { watch } from 'vue'
import { useRoute } from 'vue-router'
import GlobalSearch from '../components/GlobalSearch.vue'
import { logActivity } from '../api'

const route = useRoute()
watch(() => route.query.q, (q) => { if (q) logActivity('search', 'suche', { q }) }, { immediate: true })
</script>

<template>
  <div class="page">
    <GlobalSearch :initial="route.query.q || ''" :key="route.query.q" />
    <div class="card result">
      <p>Suche nach <strong>„{{ route.query.q }}“</strong></p>
      <p class="muted">Die globale Suche über Teile, Farben, Lagerorte, Sets und Minifiguren wird mit den Modulen angebunden.</p>
    </div>
  </div>
</template>

<style scoped>
.result { margin-top: 24px; }
</style>
