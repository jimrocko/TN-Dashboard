<script setup>
import { computed, defineAsyncComponent, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from './components/AppHeader.vue'
import BottomNav from './components/BottomNav.vue'
import XpShell from './components/XpShell.vue'
import DebugOverlay from './components/DebugOverlay.vue'
import { useSettings } from './stores/settings'
import { IS_TUTORIAL } from './api'
import { help, helpSteps, closeHelp } from './tutorial/help'

// Klemmi: im Tutorial-Build die große Tour. In App und Demo nur die Kurzhilfe je Bereich (Knopf „?“),
// erst beim ersten Öffnen nachgeladen.
const Coach = defineAsyncComponent(() => import('./tutorial/TutorialCoach.vue'))

const route = useRoute()
const settings = useSettings()
const chrome = computed(() => !route.meta.public)
// XP verlassen, während eine Spaß-App offen ist: zurück zur Startseite (die Apps gibt es nur im XP-Stil)
const router = useRouter()
watch(() => settings.style, (s) => { if (s !== 'xp' && route.meta.xpOnly) router.replace('/') })
// Hilfe schließen, wenn man den Bereich verlässt
watch(() => route.path, () => closeHelp())
</script>

<template>
  <RouterView v-if="!chrome" />
  <XpShell v-else-if="settings.style === 'xp'" />
  <div v-else class="shell">
    <AppHeader />
    <main class="main"><RouterView /></main>
    <BottomNav />
  </div>
  <DebugOverlay v-if="chrome" />
  <Coach v-if="IS_TUTORIAL" />
  <Coach v-else-if="help.topic" :key="help.run" :help="helpSteps(help.topic)" @close="closeHelp" />
</template>

<style scoped>
.shell { min-height: 100dvh; display: flex; flex-direction: column; }
.main { flex: 1; padding-bottom: 110px; }
</style>
