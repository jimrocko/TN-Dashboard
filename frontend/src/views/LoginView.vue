<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'
import { IS_DEMO } from '../api'
import logo from '../assets/icon.svg'

const auth = useAuth()
const route = useRoute()
const router = useRouter()
const username = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)


async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.login(username.value.trim(), password.value)
    router.replace(typeof route.query.next === 'string' ? route.query.next : '/')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="wrap">
    <form class="card box" @submit.prevent="submit">
      <img :src="logo" alt="" class="logo" />
      <h1>TN Dashboard</h1>
      <p class="muted">Melde dich mit deinem Teilnehmer-Konto an.</p>
      <label class="field"><span>Benutzername</span>
        <input class="input" v-model="username" autocomplete="username" autocapitalize="none" required />
      </label>
      <label class="field"><span>Passwort</span>
        <input class="input" v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="btn btn-primary full" :disabled="busy">{{ busy ? 'Anmelden …' : 'Anmelden' }}</button>
      <div v-if="IS_DEMO" class="demo">
        <strong>Demo-Version</strong>: läuft ohne Server, alle Daten bleiben in diesem Browser.
        <div class="accounts">
          <button type="button" @click="username = 'max'; password = 'max12345'">Teilnehmer: max / max12345</button>
          <button type="button" @click="username = 'lehrer'; password = 'lehrer123'">Lehrer: lehrer / lehrer123</button>
        </div>
      </div>
    </form>
  </div>
</template>

<style scoped>
.wrap { min-height: 100dvh; display: grid; place-items: center; padding: 16px; }
.box { width: 100%; max-width: 400px; padding: 32px; }
.logo { width: 64px; height: 64px; border-radius: 14px; }
h1 { margin: 16px 0 4px; }
.muted { margin: 0 0 24px; }
.demo { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 14px; color: var(--text-muted); line-height: 1.5; }
.demo strong { color: var(--text); }
.accounts { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.accounts button { text-align: left; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-strong); background: var(--surface-hover); font-family: ui-monospace, Menlo, monospace; font-size: 13px; }
.accounts button:hover { border-color: var(--accent); }
.full { width: 100%; justify-content: center; margin-top: 8px; padding: 12px; }
</style>
