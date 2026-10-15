<script setup>
import { onMounted, ref } from 'vue'
import { useAuth } from '../stores/auth'
import { api } from '../api'

const auth = useAuth()
const bl = ref(null)
onMounted(async () => {
  try { bl.value = (await api.get('dashboard')).bricklink.configured } catch { bl.value = false }
})
</script>

<template>
  <div class="page">
    <h1 class="page-title">Einstellungen</h1>
    <p class="page-sub">Konto und Verbindungen</p>
    <div class="card">
      <dl>
        <dt>Name</dt><dd>{{ auth.user.display_name }}</dd>
        <dt>Benutzername</dt><dd>{{ auth.user.username }}</dd>
        <dt>Rolle</dt><dd>{{ auth.roleLabel }}</dd>
        <dt>BrickLink-API</dt>
        <dd>{{ bl === null ? '…' : bl ? 'Verbunden' : 'Nicht eingerichtet (Lehrer: api/config.php)' }}</dd>
      </dl>
    </div>
  </div>
</template>

<style scoped>
dl { display: grid; grid-template-columns: max-content 1fr; gap: 12px 24px; margin: 0; }
dt { color: var(--text-muted); }
dd { margin: 0; }
</style>
