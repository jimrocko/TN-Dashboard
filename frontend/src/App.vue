<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppHeader from './components/AppHeader.vue'
import BottomNav from './components/BottomNav.vue'

const route = useRoute()
const chrome = computed(() => !route.meta.public)
</script>

<template>
  <div class="shell" :class="{ bare: !chrome }">
    <AppHeader v-if="chrome" />
    <main class="main"><RouterView /></main>
    <BottomNav v-if="chrome" />
  </div>
</template>

<style scoped>
.shell { min-height: 100dvh; display: flex; flex-direction: column; padding-top: env(safe-area-inset-top); }
.main { flex: 1; padding-bottom: 110px; }
.bare .main { padding-bottom: 0; }
</style>
