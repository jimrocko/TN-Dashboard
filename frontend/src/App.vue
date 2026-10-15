<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from './components/AppSidebar.vue'
import AppTopbar from './components/AppTopbar.vue'

const route = useRoute()
const chrome = computed(() => !route.meta.public)
const navOpen = ref(false)
watch(() => route.fullPath, () => { navOpen.value = false })
</script>

<template>
  <div v-if="chrome" class="shell">
    <AppSidebar :open="navOpen" @close="navOpen = false" />
    <div class="main">
      <AppTopbar @menu="navOpen = true" />
      <main><RouterView /></main>
    </div>
  </div>
  <RouterView v-else />
</template>

<style scoped>
.shell { display: flex; min-height: 100dvh; }
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
main { flex: 1; }
</style>
