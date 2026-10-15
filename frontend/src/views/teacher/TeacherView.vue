<script setup>
import { onMounted, ref } from 'vue'
import { Download, UserPlus } from 'lucide-vue-next'
import ActivityTable from '../../components/ActivityTable.vue'
import { api, apiUrl } from '../../api'

const users = ref([])
const selected = ref(null)
const activity = ref([])
const form = ref({ username: '', display_name: '', password: '' })
const formMsg = ref('')
const formErr = ref('')

const roleLabel = { teilnehmer: 'Teilnehmer', lehrer: 'Lehrer', admin: 'Admin' }
const fmt = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : 'nie')

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
const csvUrl = () => apiUrl('activity', { format: 'csv', limit: 5000, ...(selected.value ? { user: selected.value.id } : {}) })

onMounted(async () => { await loadUsers(); await select(null) })
</script>

<template>
  <div class="page">
    <h1 class="page-title">Lehrer-Ansicht</h1>
    <p class="page-sub">Teilnehmer verwalten und dokumentieren, wer was gemacht hat.</p>

    <div class="cols">
      <div class="card">
        <h2>Teilnehmer</h2>
        <div class="table-wrap">
          <table class="list">
            <thead><tr><th>Name</th><th>Rolle</th><th>Zuletzt aktiv</th><th>Aktionen</th></tr></thead>
            <tbody>
              <tr class="pick" :class="{ on: !selected }" @click="select(null)"><td colspan="4"><strong>Alle anzeigen</strong></td></tr>
              <tr v-for="u in users" :key="u.id" class="pick" :class="{ on: selected?.id === u.id }" @click="select(u)">
                <td>{{ u.display_name }}<div class="muted small">{{ u.username }}</div></td>
                <td>{{ roleLabel[u.role] }}</td>
                <td>{{ fmt(u.last_seen) }}</td>
                <td>{{ u.activity_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h2 class="mt">Teilnehmer anlegen</h2>
        <form @submit.prevent="addUser">
          <label class="field"><span>Benutzername</span><input class="input" v-model="form.username" autocapitalize="none" required /></label>
          <label class="field"><span>Anzeigename</span><input class="input" v-model="form.display_name" required /></label>
          <label class="field"><span>Startpasswort (mind. 6 Zeichen)</span><input class="input" v-model="form.password" required minlength="6" /></label>
          <p v-if="formErr" class="error">{{ formErr }}</p>
          <p v-if="formMsg" class="ok">{{ formMsg }}</p>
          <button class="btn btn-primary"><UserPlus :size="18" /> Anlegen</button>
        </form>
      </div>

      <div class="card">
        <div class="bar">
          <h2>Protokoll: {{ selected ? selected.display_name : 'alle Teilnehmer' }}</h2>
          <a class="btn" :href="csvUrl()"><Download :size="18" /> CSV</a>
        </div>
        <ActivityTable :rows="activity" :show-user="!selected" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.cols { display: grid; grid-template-columns: minmax(320px, 2fr) 3fr; gap: 20px; align-items: start; }
@media (max-width: 1000px) { .cols { grid-template-columns: 1fr; } }
h2 { font-size: 18px; margin: 0 0 12px; }
.mt { margin-top: 28px; }
.bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; }
.bar h2 { margin: 0; }
.pick { cursor: pointer; }
.pick:hover td { background: var(--surface-hover); }
.pick.on td { background: #1a2433; }
.small { font-size: 12px; }
.ok { color: var(--ok); }
</style>
