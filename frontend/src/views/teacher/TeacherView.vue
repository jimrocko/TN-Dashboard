<script setup>
import { onMounted, ref } from 'vue'
import { Download, UserPlus } from 'lucide-vue-next'
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
              <thead><tr><th>Name</th><th>Rolle</th><th>Zuletzt aktiv</th><th class="num">Aktionen</th></tr></thead>
              <tbody>
                <tr class="clickable" :class="{ selected: !selected }" @click="select(null)"><td colspan="4"><strong>Alle anzeigen</strong></td></tr>
                <tr v-for="u in users" :key="u.id" class="clickable" :class="{ selected: selected?.id === u.id }" @click="select(u)">
                  <td>{{ u.display_name }}<div class="faint small mono">{{ u.username }}</div></td>
                  <td class="muted">{{ roleLabel[u.role] }}</td>
                  <td class="faint">{{ u.last_seen ? dateTime(u.last_seen) : 'nie' }}</td>
                  <td class="num">{{ u.activity_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
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
      </div>

      <section class="panel">
        <div class="panel-head">
          <h2>Protokoll: {{ selected ? selected.display_name : 'alle Teilnehmer' }}</h2>
          <a v-if="!IS_DEMO" class="btn btn-sm" :href="csvUrl()"><Download :size="15" /> CSV</a>
        </div>
        <ActivityTable :rows="activity" :show-user="!selected" />
      </section>
    </div>
  </div>
</template>

<style scoped>
.cols { display: grid; grid-template-columns: minmax(300px, 2fr) 3fr; gap: var(--gap); align-items: start; }
@media (max-width: 1100px) { .cols { grid-template-columns: minmax(0, 1fr); } }
</style>
