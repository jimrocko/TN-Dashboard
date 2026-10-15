<script setup>
import { onMounted, ref } from 'vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import ModuleTile from '../components/ModuleTile.vue'
import { modules } from '../modules'
import { api } from '../api'

const data = ref(null)
onMounted(async () => {
  try { data.value = await api.get('dashboard') } catch { /* Kacheln zeigen dann „–“ */ }
})
</script>

<template>
  <div class="page">
    <GlobalSearch />
    <div class="grid">
      <ModuleTile v-for="m in modules" :key="m.id" :module="m" :data="data" />
    </div>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; margin-top: 36px; }
@media (max-width: 1100px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; } }
@media (max-width: 560px) { .grid { grid-template-columns: 1fr; margin-top: 20px; } }
</style>
