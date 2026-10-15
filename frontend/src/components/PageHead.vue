<script setup>
import { ArrowLeft } from 'lucide-vue-next'
import { useSettings } from '../stores/settings'
defineProps({ title: String, sub: String })
// Im XP-Stil schließt man das Fenster; im normalen Design führt „Zurück“ zur Startseite.
const settings = useSettings()
</script>

<template>
  <RouterLink v-if="settings.style !== 'xp'" to="/" class="back"><ArrowLeft :size="18" /> Zurück</RouterLink>
  <div class="page-head">
    <div>
      <h2 class="page-title">{{ title }}</h2>
      <p v-if="sub" class="page-sub">{{ sub }}</p>
    </div>
    <div class="toolbar"><slot /></div>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: 6px; color: var(--text-2); margin-bottom: -4px; align-self: flex-start; }
.back:hover { color: var(--text); }
</style>
