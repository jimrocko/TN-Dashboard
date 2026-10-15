<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppHeader from './components/AppHeader.vue'
import BottomNav from './components/BottomNav.vue'
import XpShell from './components/XpShell.vue'
import DebugOverlay from './components/DebugOverlay.vue'
import { useSettings } from './stores/settings'

const route = useRoute()
const settings = useSettings()
const chrome = computed(() => !route.meta.public)
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
