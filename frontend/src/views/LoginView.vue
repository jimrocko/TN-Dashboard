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
const fill = (u, p) => { username.value = u; password.value = p }
</script>

<template>
  <div class="wrap">
    <form class="panel box" @submit.prevent="submit">
      <div class="brand">
        <img :src="logo" alt="" />
        <div><div class="name">TN Dashboard</div><div class="faint small">Lego-Shop · Teilnehmer-Zugang</div></div>
      </div>
      <label class="field"><span>Benutzername</span>
        <input id="login-user" class="input" v-model="username" autocomplete="username" autocapitalize="none" required />
      </label>
      <label class="field"><span>Passwort</span>
        <input id="login-pass" class="input" v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="error" class="error small">{{ error }}</p>
      <button class="btn btn-primary full" :disabled="busy">{{ busy ? 'Anmelden …' : 'Anmelden' }}</button>
      <div v-if="IS_DEMO" class="demo">
        <div><strong>Demo-Version.</strong> Läuft ohne Server, alle Daten sind erfunden und bleiben in diesem Browser.</div>
        <div class="accounts">
          <button type="button" class="btn btn-sm" @click="fill('max', 'max12345')">Teilnehmer: max / max12345</button>
          <button type="button" class="btn btn-sm" @click="fill('lehrer', 'lehrer123')">Lehrer: lehrer / lehrer123</button>
        </div>
      </div>
    </form>
  </div>
</template>

<style scoped>
.wrap { min-height: 100dvh; display: grid; place-items: center; padding: 16px; }
.box { width: 100%; max-width: 380px; padding: 28px; }
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.brand img { width: 40px; height: 40px; border-radius: 9px; }
.name { font-weight: 600; font-size: 1.05rem; }
.full { width: 100%; height: 38px; margin-top: 4px; }
.input { height: 38px; }
.demo { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 0.85rem; color: var(--text-2); }
.demo strong { color: var(--text); }
.accounts { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
.accounts .btn { justify-content: flex-start; font-family: var(--mono); font-size: 0.8rem; }
</style>
