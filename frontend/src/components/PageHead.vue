<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import HelpButton from './HelpButton.vue'
import { useSettings } from '../stores/settings'
import { helpTopic } from '../tutorial/help'
defineProps({ title: String, sub: String })
// Im XP-Stil schließt man das Fenster; im normalen Design führt „Zurück“ zur Startseite.
const settings = useSettings()
// Oben rechts „Hilfe“: Klemmi erklärt diesen Bereich oder startet die Rundführung.
const route = useRoute()
const topic = computed(() => helpTopic(route.path))
</script>

<template>
  <div v-if="settings.style !== 'xp'" class="topline">
    <RouterLink to="/" class="back"><ArrowLeft :size="18" /> Zurück</RouterLink>
    <HelpButton :topic="topic" />
  </div>
  <div class="page-head">
    <div>
      <h2 class="page-title">{{ title }}</h2>
      <p v-if="sub" class="page-sub">{{ sub }}</p>
    </div>
    <div class="toolbar"><slot /></div>
  </div>
</template>

<style scoped>
.topline { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: -4px; }
.back { display: inline-flex; align-items: center; gap: 6px; color: var(--text-2); }
.back:hover { color: var(--text); }
</style>
