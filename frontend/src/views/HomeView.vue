<script setup>
import { onMounted, ref } from 'vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import ModuleTile from '../components/ModuleTile.vue'
import { modules } from '../modules'
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
      <ModuleTile v-for="m in modules" :key="m.id" :module="m" :data="data" />
    </div>
  </div>
</template>

<style scoped>
/* 4 Kacheln pro Reihe auf PC und jedem iPad (auch mit Homebutton, quer und hochkant), 2 auf dem Handy */
.grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; margin-top: 36px; }
@media (max-width: 1199px) { .grid { gap: 14px; margin-top: 22px; } }
@media (max-width: 699px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 16px; } }
.seed { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px var(--pad); flex-wrap: wrap; margin-top: 24px; border-color: var(--accent); }
</style>
