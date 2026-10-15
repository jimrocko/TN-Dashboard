<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import PageHead from '../components/PageHead.vue'
import { moduleById, statusLabel } from '../modules'

const route = useRoute()
const mod = computed(() => moduleById(route.params.id))
</script>

<template>
  <div class="page stack" v-if="mod">
    <PageHead :title="mod.title" :sub="mod.text">
      <span class="pill" :class="mod.status === 'extern' ? 'accent' : ''">{{ statusLabel[mod.status] }}</span>
    </PageHead>
    <div class="panel panel-body box">
      <component :is="mod.icon" :size="28" class="faint" />
      <div v-if="mod.status === 'extern'">
        <h3>Bestehendes Programm wird angebunden</h3>
        <p class="muted">Dieser Bereich existiert bereits als eigenes Programm. Er wird hier eingebunden, damit Login und Aktivitätsprotokoll gemeinsam laufen.</p>
      </div>
      <div v-else>
        <h3>Geplant</h3>
        <p class="muted">Dieser Bereich wird in einer späteren Version ergänzt.</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.box { display: flex; gap: 16px; align-items: flex-start; max-width: 720px; }
h3 { margin: 2px 0 6px; font-size: 1rem; }
p { margin: 0; }
</style>
