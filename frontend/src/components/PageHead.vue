<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, CircleHelp } from 'lucide-vue-next'
import { useSettings } from '../stores/settings'
import { IS_TUTORIAL } from '../api'
import { helpTopic, openHelp } from '../tutorial/help'
defineProps({ title: String, sub: String })
// Im XP-Stil schließt man das Fenster; im normalen Design führt „Zurück“ zur Startseite.
const settings = useSettings()
// Oben rechts „?“: Klemmi erklärt nur diesen Bereich (nicht im Tutorial, dort läuft schon die große Tour).
const route = useRoute()
const topic = computed(() => (IS_TUTORIAL || settings.style === 'xp' ? null : helpTopic(route.path)))
</script>

<template>
  <div v-if="settings.style !== 'xp'" class="topline">
    <RouterLink to="/" class="back"><ArrowLeft :size="18" /> Zurück</RouterLink>
    <button v-if="topic" type="button" class="help" title="Hilfe zu diesem Bereich" aria-label="Hilfe zu diesem Bereich" @click="openHelp(topic)">
      <CircleHelp :size="20" />
    </button>
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
.help { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--border-strong); background: var(--surface); color: var(--text-2); }
.help:hover { color: var(--text); border-color: var(--text-3); }
</style>
