<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'

const status = ref(null)
const type = ref('PART')
const no = ref('3001')
const result = ref('')

onMounted(async () => {
  try { status.value = await api.get('dashboard') } catch (e) { status.value = { error: e.message } }
})

async function lookup() {
  result.value = 'Lade …'
  try {
    const r = await api.get(`bl/item/${type.value}/${encodeURIComponent(no.value.trim())}`)
    result.value = JSON.stringify(r, null, 2)
  } catch (e) {
    result.value = `Fehler: ${e.message}`
  }
}
</script>

<template>
  <div class="page">
    <h1 class="page-title">Dev-Bereich</h1>
    <p class="page-sub">Werkzeuge zum Testen von API und BrickLink-Cache</p>
    <div class="cols">
      <div class="card">
        <h2>Dashboard-API</h2>
        <pre>{{ JSON.stringify(status, null, 2) }}</pre>
      </div>
      <div class="card">
        <h2>BrickLink-Artikel abrufen</h2>
        <p class="muted">Antworten werden in der Datenbank zwischengespeichert. <code>"cached": true</code> heißt, dass kein API-Aufruf nötig war.</p>
        <form class="row" @submit.prevent="lookup">
          <select class="input" v-model="type">
            <option>PART</option><option>MINIFIG</option><option>SET</option>
          </select>
          <input class="input" v-model="no" placeholder="z. B. 3001" />
          <button class="btn btn-primary">Abrufen</button>
        </form>
        <pre v-if="result">{{ result }}</pre>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }
h2 { font-size: 18px; margin: 0 0 12px; }
.row { display: flex; gap: 10px; margin-top: 12px; }
.row select { width: 130px; }
pre { background: #0d131c; border: 1px solid var(--border); border-radius: 10px; padding: 12px; overflow: auto; max-height: 420px; font-size: 13px; }
</style>
