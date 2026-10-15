<script setup>
import { ref } from 'vue'
import { Send, Trash2 } from 'lucide-vue-next'
import { api } from '../../api'
import { apiLog } from '../../utils/devtools'

// API-Konsole (nur GET, damit nichts versehentlich geändert wird), Protokoll der letzten Aufrufe, BrickLink-Abruf.
const route = ref('dashboard')
const params = ref('')
const out = ref('')
const meta = ref('')
const presets = ['auth/me', 'dashboard', 'inventory/meta', 'orders', 'part/3001', 'analysis', 'dev/status', 'dev/tables']

async function send() {
  out.value = ''
  meta.value = 'Lade …'
  let p = {}
  try {
    if (params.value.trim()) p = Object.fromEntries(new URLSearchParams(params.value.trim().replace(/^\?/, '')))
  } catch { meta.value = 'Parameter bitte als a=1&b=2'; return }
  const t0 = performance.now()
  try {
    const d = await api.get(route.value.trim(), p)
    out.value = JSON.stringify(d, null, 2)
    meta.value = `OK · ${Math.round(performance.now() - t0)} ms · ${out.value.length.toLocaleString('de-DE')} Zeichen`
  } catch (e) {
    meta.value = `Fehler ${e.status ?? ''} · ${e.message}`
  }
}

const blType = ref('PART')
const blNo = ref('3001')
const blOut = ref('')
async function lookup() {
  blOut.value = 'Lade …'
  try { blOut.value = JSON.stringify(await api.get(`bl/item/${blType.value}/${encodeURIComponent(blNo.value.trim())}`), null, 2) } catch (e) { blOut.value = `Fehler: ${e.message}` }
}
const time = (d) => d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
</script>

<template>
  <div class="stack">
    <section class="panel">
      <div class="panel-head"><h2>API-Konsole</h2><span class="faint small">nur lesende GET-Aufrufe</span></div>
      <div class="panel-body stack-sm">
        <form class="row" @submit.prevent="send">
          <span class="mono faint">api/?r=</span>
          <input id="dev-api-route" class="input mono grow" v-model="route" placeholder="z. B. part/3001" aria-label="Route" autocapitalize="none" spellcheck="false" />
          <input id="dev-api-params" class="input mono grow" v-model="params" placeholder="Parameter, z. B. limit=5&q=3001" aria-label="Parameter" autocapitalize="none" spellcheck="false" />
          <button class="btn btn-primary"><Send :size="15" /> Senden</button>
        </form>
        <div class="chips">
          <button v-for="p in presets" :key="p" class="btn btn-sm mono" type="button" @click="route = p; params = ''; send()">{{ p }}</button>
        </div>
        <div v-if="meta" class="small" :class="meta.startsWith('Fehler') ? 'error' : 'faint'">{{ meta }}</div>
        <pre v-if="out">{{ out }}</pre>
      </div>
    </section>

    <div class="grid-2">
      <section class="panel">
        <div class="panel-head">
          <h2>Letzte API-Aufrufe</h2>
          <button class="btn btn-ghost btn-sm" @click="apiLog.splice(0)"><Trash2 :size="14" /> Leeren</button>
        </div>
        <div class="table-wrap log">
          <table class="table">
            <thead><tr><th>Zeit</th><th>Aufruf</th><th class="num">Status</th><th class="num">Dauer</th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in apiLog" :key="i" :title="l.error">
                <td class="faint small nowrap">{{ time(l.at) }}</td>
                <td class="mono small"><span class="faint">{{ l.method }}</span> {{ l.route }}</td>
                <td class="num small" :class="l.status >= 400 || l.status === 0 ? 'bad' : 'okc'">{{ l.status || 'Fehler' }}</td>
                <td class="num small" :class="{ slow: l.ms > 800 }">{{ l.ms }} ms</td>
              </tr>
              <tr v-if="!apiLog.length"><td colspan="4" class="empty">Noch keine Aufrufe aufgezeichnet.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>BrickLink-Artikel abrufen</h2></div>
        <div class="panel-body stack-sm">
          <p class="muted small">Antworten werden in der Datenbank zwischengespeichert. <code>"cached": true</code> heißt: kein API-Aufruf nötig.</p>
          <form class="row" @submit.prevent="lookup">
            <select id="dev-bl-type" class="select" v-model="blType" aria-label="Typ"><option>PART</option><option>MINIFIG</option><option>SET</option></select>
            <input id="dev-bl-no" class="input grow" v-model="blNo" placeholder="z. B. 3001" aria-label="Nummer" />
            <button class="btn btn-primary">Abrufen</button>
          </form>
          <pre v-if="blOut">{{ blOut }}</pre>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.stack-sm { display: flex; flex-direction: column; gap: 10px; }
.row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.grow { flex: 1; min-width: 160px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
pre { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; overflow: auto; max-height: 460px; font-family: var(--mono); font-size: 0.8rem; margin: 0; }
.log { max-height: 420px; overflow: auto; }
.nowrap { white-space: nowrap; }
.okc { color: var(--ok); }
.bad { color: var(--danger); }
.slow { color: var(--warn); }
</style>
