<script setup>
import { computed, onMounted, ref } from 'vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import ModuleTile from '../components/ModuleTile.vue'
import HelpButton from '../components/HelpButton.vue'
import { homeModules } from '../modules'
import { api } from '../api'
import { useAuth } from '../stores/auth'

const auth = useAuth()
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

// Gruß unter dem Titel, nach Tageszeit, mit Vornamen
const greeting = computed(() => { const h = new Date().getHours(); return h < 11 ? 'Guten Morgen' : h < 17 ? 'Hallo' : 'Guten Abend' })
const firstName = computed(() => String(auth.user?.display_name ?? '').trim().split(/\s+/)[0])
</script>

<template>
  <div class="page">
    <div class="hero">
      <h1 class="hero-title"><span class="tn">TN</span> Dashboard</h1>
      <p v-if="auth.user" class="hero-hi">{{ greeting }}, {{ firstName }}!</p>
    </div>
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
/* Großer Titel mit Gruß (Wunsch des Inhabers): schlicht, einmal kurz eingeblendet */
.hero { text-align: center; margin: 4px 0 26px; animation: hero-in 0.5s ease-out both; }
.hero-title { margin: 0; font-size: clamp(2.1rem, 5.5vw, 3.3rem); font-weight: 800; letter-spacing: -0.01em; line-height: 1.05; }
.hero-title .tn { color: var(--brand); font-style: italic; }
.hero-hi { margin: 8px 0 0; color: var(--text-2); font-size: 1.05rem; }
/* iPad und kleiner: kompakter, damit alle 6 Kacheln ohne Scrollen sichtbar bleiben (Titel rückt in den Abstand unter der Kopfzeile) */
@media (max-height: 920px), (max-width: 1199px) {
  .hero { margin: -16px 0 12px; }
  .hero-title { font-size: clamp(2rem, 4.2vw, 2.5rem); }
  .hero-hi { margin-top: 2px; font-size: 0.98rem; }
  .grid { margin-top: 12px; }
}
/* Sehr flache Bildschirme (iPad mit Homebutton quer): nur der Titel, ohne Gruß */
@media (max-height: 790px) { .hero-hi { display: none; } .hero-title { font-size: 2.1rem; } }
@media (max-width: 699px) { .hero { margin: -8px 0 12px; } }
@keyframes hero-in { from { opacity: 0; transform: translateY(6px); } }
</style>
