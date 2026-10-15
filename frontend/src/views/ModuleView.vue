<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, Construction } from 'lucide-vue-next'
import { moduleById } from '../modules'

const route = useRoute()
const mod = computed(() => moduleById(route.params.id))
</script>

<template>
  <div class="page" v-if="mod">
    <RouterLink to="/" class="back"><ArrowLeft :size="18" /> Zurück</RouterLink>
    <div class="head">
      <component :is="mod.icon" :size="44" :stroke-width="1.5" />
      <div>
        <h1 class="page-title">{{ mod.title }}</h1>
        <p class="page-sub">{{ mod.text }}</p>
      </div>
    </div>
    <div class="card">
      <h2 class="soon"><Construction :size="22" /> Dieses Modul ist in Arbeit</h2>
      <p class="muted">Geplante Funktionen:</p>
      <ul><li v-for="f in mod.planned" :key="f">{{ f }}</li></ul>
    </div>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); margin-bottom: 16px; }
.head { display: flex; gap: 18px; align-items: flex-start; }
.head > :first-child { margin-top: 8px; flex: none; }
.soon { display: flex; align-items: center; gap: 10px; font-size: 18px; margin: 0 0 8px; }
ul { margin: 0; padding-left: 20px; line-height: 1.8; }
</style>
