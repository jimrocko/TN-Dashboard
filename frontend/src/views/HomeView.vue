<script setup>
import { onMounted, ref } from 'vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import ModuleTile from '../components/ModuleTile.vue'
import HelpButton from '../components/HelpButton.vue'
import { homeModules } from '../modules'
import { api } from '../api'

const data = ref(null)
const error = ref('')
const seeding = ref(false)

async function load() {
  try { data.value = await api.get('dashboard') } catch (e) { error.value = e.message }
}
async function seed() {
  seeding.value = true
  try { await api.post('demo/seed'); await load() } catch (e) { error.value = e.message } finally { seeding.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <GlobalSearch />
    <p v-if="error" class="error">{{ error }}</p>
    <div v-if="data?.demo_seedable" class="panel seed">
      <div>
        <strong>Noch keine Lagerdaten vorhanden.</strong>
        <div class="muted small">Zum Ausprobieren können erfundene Beispieldaten geladen werden (Lagerorte, Teile, Aufträge, Inventuren).</div>
      </div>
      <button class="btn btn-primary" :disabled="seeding" @click="seed">Beispieldaten laden</button>
    </div>
    <div class="grid">
      <ModuleTile v-for="m in homeModules" :key="m.id" :module="m" :data="data" />
    </div>
    <div class="helprow"><HelpButton topic="start" align="center" /></div>
  </div>
</template>

<style scoped>
/* 6 Kacheln: 3 + 3, mittig, gleiche Kachelbreite wie früher bei 4 pro Reihe (Wunsch des Inhabers). Handy: 2 pro Reihe. */
.grid { --g: 24px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--g); margin: 36px auto 0;
  width: calc((100% - 3 * var(--g)) * 0.75 + 2 * var(--g)); }
@media (max-width: 1199px) { .grid { --g: 14px; margin-top: 22px; } }
@media (max-width: 699px) { .grid { --g: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; margin-top: 16px; } }
.seed { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px var(--pad); flex-wrap: wrap; margin-top: 24px; border-color: var(--accent); }
.helprow { display: flex; justify-content: center; margin-top: 22px; }
</style>
