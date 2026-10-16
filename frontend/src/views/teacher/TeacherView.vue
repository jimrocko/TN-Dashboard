<script setup>
import { onMounted, ref } from 'vue'
import { Download, UserPlus, Code2, Trash2, RefreshCw } from 'lucide-vue-next'
import PageHead from '../../components/PageHead.vue'
import ActivityTable from '../../components/ActivityTable.vue'
import { api, apiUrl, IS_DEMO } from '../../api'
import { dateTime } from '../../utils/format'

const users = ref([])
const selected = ref(null)
const activity = ref([])
const form = ref({ username: '', display_name: '', password: '' })
const formMsg = ref('')
const formErr = ref('')

const roleLabel = { teilnehmer: 'Teilnehmer', lehrer: 'Lehrer', admin: 'Admin' }

async function loadUsers() {
  users.value = (await api.get('users')).users
}
async function select(u) {
  selected.value = u
  activity.value = (await api.get('activity', u ? { user: u.id } : {})).activity
}
async function addUser() {
  formMsg.value = formErr.value = ''
  try {
    await api.post('users', { ...form.value, role: 'teilnehmer' })
    formMsg.value = `Konto „${form.value.username}“ angelegt.`
    form.value = { username: '', display_name: '', password: '' }
    await loadUsers()
  } catch (e) {
    formErr.value = e.message
  }
}
// Dev-Rolle: Teilnehmer bekommen Zugang zum Dev-Bereich (Lehrer haben ihn immer).
const devErr = ref('')
async function toggleDev(u) {
  devErr.value = ''
  try {
    await api.post(`users/${u.id}/dev`, { enabled: !u.dev_access })
    u.dev_access = !u.dev_access
  } catch (e) { devErr.value = e.message }
}
// Konto löschen: erst Rückfrage in der Zeile, dann deaktivieren (Protokoll bleibt erhalten).
const confirmDelete = ref(null)
async function removeUser(u) {
  devErr.value = ''
  try {
    await api.post(`users/${u.id}/delete`)
    confirmDelete.value = null
    await loadUsers()
    await select(selected.value?.id === u.id ? null : selected.value) // Protokoll neu laden („Konto gelöscht“)
  } catch (e) { devErr.value = e.message }
}
// Demo (ohne Server): CSV im Browser erzeugen, gleiche Spalten wie der Server-Export.
async function demoCsv() {
  const rows = (await api.get('activity', { limit: 5000, ...(selected.value ? { user: selected.value.id } : {}) })).activity
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const lines = [['Zeit', 'Benutzer', 'Name', 'Aktion', 'Modul', 'Details'].join(';'),
    ...rows.map((r) => [r.created_at, r.username, r.display_name, r.action, r.module, r.details ? JSON.stringify(r.details) : ''].map(esc).join(';'))]
  const url = URL.createObjectURL(new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }))
  const a = Object.assign(document.createElement('a'), { href: url, download: 'aktivitaeten.csv' })
  document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
// Lager komplett aus dem BrickLink-Shop übernehmen (Lot-IDs für den Export nach dem Umlagern, Lagerort aus den Remarks)
const importAsk = ref(false)
const importBusy = ref(false)
const importMsg = ref('')
const importErr = ref('')
async function importBrickLink() {
  importBusy.value = true
  importMsg.value = importErr.value = ''
  try {
    const r = await api.post('bricklink/import')
    importMsg.value = `${r.lots} Lose übernommen (${r.new} neu, ${r.updated} aktualisiert, ${r.removed} entfernt, ${r.new_locations} neue Lagerorte`
      + (r.without_location ? `, ${r.without_location} ohne Lagerort in den Remarks` : '') + ').'
    importAsk.value = false
  } catch (e) { importErr.value = e.message } finally { importBusy.value = false }
}
const csvUrl = () => apiUrl('activity', { format: 'csv', limit: 5000, ...(selected.value ? { user: selected.value.id } : {}) })

onMounted(async () => { await loadUsers(); await select(null) })
</script>

<template>
  <div class="page stack">
    <PageHead title="Lehrer-Ansicht" sub="Teilnehmer verwalten und dokumentieren, wer was gemacht hat." />

    <div class="cols">
      <div class="stack">
        <section class="panel">
          <div class="panel-head"><h2>Teilnehmer</h2><span class="faint small">{{ users.length }} Konten</span></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Name</th><th>Rolle</th><th class="wide">Zuletzt aktiv</th><th class="num wide">Aktionen</th><th class="num" title="Zugang zum Dev-Bereich">Dev</th><th class="num" aria-label="Löschen"></th></tr></thead>
              <tbody>
                <tr class="clickable" :class="{ selected: !selected }" @click="select(null)"><td colspan="6"><strong>Alle anzeigen</strong></td></tr>
                <tr v-for="u in users" :key="u.id" class="clickable" :class="{ selected: selected?.id === u.id }" @click="select(u)">
                  <td>{{ u.display_name }}<div class="faint small mono">{{ u.username }}</div></td>
                  <td class="muted">{{ roleLabel[u.role] }}</td>
                  <td class="faint wide">{{ u.last_seen ? dateTime(u.last_seen) : 'nie' }}</td>
                  <td class="num wide">{{ u.activity_count }}</td>
                  <td class="num">
                    <Code2 v-if="u.role !== 'teilnehmer'" :size="15" class="faint" aria-label="Lehrer haben immer Zugang" />
                    <button v-else class="btn btn-sm devbtn" :class="{ on: u.dev_access }" :aria-pressed="u.dev_access"
                      :title="u.dev_access ? 'Dev-Rolle entziehen' : 'Dev-Rolle geben'" @click.stop="toggleDev(u)">
                      <Code2 :size="14" /> {{ u.dev_access ? 'an' : 'aus' }}
                    </button>
                  </td>
                  <td class="num nowrap">
                    <template v-if="u.role === 'teilnehmer'">
                      <span v-if="confirmDelete === u.id" class="confirm" @click.stop>
                        <span class="small">Wirklich löschen?</span>
                        <button class="btn btn-sm danger" @click.stop="removeUser(u)">Ja</button>
                        <button class="btn btn-sm" @click.stop="confirmDelete = null">Nein</button>
                      </span>
                      <button v-else class="btn btn-sm btn-ghost delbtn" :title="`${u.display_name} löschen`" :aria-label="`${u.display_name} löschen`" @click.stop="confirmDelete = u.id">
                        <Trash2 :size="15" />
                      </button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="devErr" class="error small pad">{{ devErr }}</p>
          <p class="faint small pad">Dev-Rolle: Zugang zum Dev-Bereich (Systemdaten, Tests, Datenbank). Nur an Schüler vergeben, denen du das zutraust. Gelöschte Konten können sich nicht mehr anmelden, ihr Protokoll bleibt erhalten.</p>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Teilnehmer anlegen</h2></div>
          <form class="panel-body" @submit.prevent="addUser">
            <label class="field"><span>Benutzername</span><input id="new-user" class="input" v-model="form.username" autocapitalize="none" required /></label>
            <label class="field"><span>Anzeigename</span><input id="new-name" class="input" v-model="form.display_name" required /></label>
            <label class="field"><span>Startpasswort (mind. 6 Zeichen)</span><input id="new-pass" class="input" v-model="form.password" required minlength="6" /></label>
            <p v-if="formErr" class="error small">{{ formErr }}</p>
            <p v-if="formMsg" class="success small">{{ formMsg }}</p>
            <button class="btn btn-primary"><UserPlus :size="16" /> Anlegen</button>
          </form>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>BrickLink-Shop</h2></div>
          <div class="panel-body stack-sm">
            <p class="muted small">Übernimmt das Lager aus dem BrickLink-Shop, mit Lot-IDs und dem Lagerort aus den Remarks. Danach können alle nach dem Umlagern die Änderungen per Knopf zu BrickLink übertragen. Noch nicht übertragene Umlagerungen werden dabei verworfen.</p>
            <div v-if="importAsk" class="confirm">
              <span class="small">Lager jetzt ersetzen?</span>
              <button class="btn btn-sm btn-primary" :disabled="importBusy" @click="importBrickLink">Ja, übernehmen</button>
              <button class="btn btn-sm" :disabled="importBusy" @click="importAsk = false">Abbrechen</button>
            </div>
            <button v-else class="btn" @click="importAsk = true"><RefreshCw :size="16" /> Lager aus BrickLink übernehmen</button>
            <p v-if="importErr" class="error small">{{ importErr }}</p>
            <p v-if="importMsg" class="success small">{{ importMsg }}</p>
          </div>
        </section>
      </div>

      <section class="panel">
        <div class="panel-head">
          <h2>Protokoll: {{ selected ? selected.display_name : 'alle Teilnehmer' }}</h2>
          <a v-if="!IS_DEMO" class="btn btn-sm" :href="csvUrl()"><Download :size="15" /> CSV</a>
          <button v-else class="btn btn-sm" @click="demoCsv"><Download :size="15" /> CSV</button>
        </div>
        <ActivityTable :rows="activity" :show-user="!selected" />
      </section>
    </div>
  </div>
</template>

<style scoped>
/* Teilnehmer-Liste breit genug für alle Spalten (Name bis Papierkorb), Protokoll daneben */
.cols { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: var(--gap); align-items: start; }
.devbtn.on { color: var(--accent-text); border-color: var(--accent); background: var(--accent-soft); }
.confirm { display: inline-flex; align-items: center; gap: 6px; }
.danger { color: var(--danger); border-color: var(--danger); }
.delbtn:hover { color: var(--danger); }
/* Handy: weniger Spalten, damit Name, Dev und Papierkorb ohne seitliches Scrollen passen */
@media (max-width: 700px) { .wide { display: none; } }
.pad { margin: 0; padding: 10px var(--pad); }
.stack-sm { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; }
.stack-sm p { margin: 0; }
.confirm { flex-wrap: wrap; }
@media (max-width: 1100px) { .cols { grid-template-columns: minmax(0, 1fr); } }
</style>
