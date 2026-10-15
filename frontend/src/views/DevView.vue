<script setup>
import { onMounted, ref } from 'vue'
import PageHead from '../components/PageHead.vue'
import { api } from '../api'

const status = ref(null)
const type = ref('PART')
const no = ref('3001')
const result = ref('')

onMounted(async () => {
  try {
    const d = await api.get('dashboard')
    delete d.recent
    status.value = d
  } catch (e) { status.value = { error: e.message } }
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
  <div class="page stack">
    <PageHead title="Dev-Bereich" sub="Werkzeuge zum Testen von API und BrickLink-Cache." />
    <div class="grid-2">
      <section class="panel">
        <div class="panel-head"><h2>Dashboard-API</h2></div>
        <div class="panel-body"><pre>{{ JSON.stringify(status, null, 2) }}</pre></div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>BrickLink-Artikel abrufen</h2></div>
        <div class="panel-body">
          <p class="muted small">Antworten werden in der Datenbank zwischengespeichert. <code>"cached": true</code> heißt: kein API-Aufruf nötig.</p>
          <form class="toolbar" @submit.prevent="lookup">
            <select class="select" v-model="type" aria-label="Typ"><option>PART</option><option>MINIFIG</option><option>SET</option></select>
            <input class="input" v-model="no" placeholder="z. B. 3001" aria-label="Nummer" />
            <button class="btn btn-primary">Abrufen</button>
          </form>
          <pre v-if="result">{{ result }}</pre>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
pre { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; overflow: auto; max-height: 420px; font-family: var(--mono); font-size: 0.8rem; margin: 12px 0 0; }
</style>
