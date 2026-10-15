<script setup>
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from './components/AppHeader.vue'
import BottomNav from './components/BottomNav.vue'
import XpShell from './components/XpShell.vue'
import DebugOverlay from './components/DebugOverlay.vue'
import { useSettings } from './stores/settings'

const route = useRoute()
const settings = useSettings()
const chrome = computed(() => !route.meta.public)
// XP verlassen, während eine Spaß-App offen ist: zurück zur Startseite (die Apps gibt es nur im XP-Stil)
const router = useRouter()
watch(() => settings.style, (s) => { if (s !== 'xp' && route.meta.xpOnly) router.replace('/') })
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
</template>

<style scoped>
.shell { min-height: 100dvh; display: flex; flex-direction: column; }
.main { flex: 1; padding-bottom: 110px; }
</style>
