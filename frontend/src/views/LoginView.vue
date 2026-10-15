<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'

const auth = useAuth()
const route = useRoute()
const router = useRouter()
const username = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)
const logo = import.meta.env.BASE_URL + 'icon.svg'

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
    </form>
  </div>
</template>

<style scoped>
.wrap { min-height: 100dvh; display: grid; place-items: center; padding: 16px; }
.box { width: 100%; max-width: 400px; padding: 32px; }
.logo { width: 64px; height: 64px; border-radius: 14px; }
h1 { margin: 16px 0 4px; }
.muted { margin: 0 0 24px; }
.full { width: 100%; justify-content: center; margin-top: 8px; padding: 12px; }
</style>
