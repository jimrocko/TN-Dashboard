<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Lock, Delete } from 'lucide-vue-next'
import { api } from '../../api'

// Code-Sperre vor dem Dev-Bereich. Ziffernblock für das iPad, am PC geht auch die Tastatur.
const props = defineProps({ status: Object })
const emit = defineEmits(['unlocked'])

const pin = ref('')
const error = ref('')
const busy = ref(false)
const shake = ref(false)
const newPin = ref('')
const newPin2 = ref('')

// Auf dem Server ohne gesetzten Code muss zuerst ein Lehrer einen festlegen.
const needsSetup = computed(() => !props.status.pin_set && !props.status.test_mode)

async function submit() {
  if (pin.value.length < 4 || busy.value) return
  busy.value = true
  error.value = ''
  try {
    emit('unlocked', await api.post('dev/unlock', { pin: pin.value }))
  } catch (e) {
    error.value = e.message
    shake.value = true
    setTimeout(() => { shake.value = false }, 400)
  } finally {
    pin.value = ''
    busy.value = false
  }
}
function press(d) {
  if (pin.value.length < 8) pin.value += d
  error.value = ''
}
const back = () => { pin.value = pin.value.slice(0, -1) }

async function setup() {
  error.value = ''
  if (newPin.value !== newPin2.value) { error.value = 'Die beiden Codes sind verschieden.'; return }
  busy.value = true
  try { emit('unlocked', await api.post('dev/pin', { pin: newPin.value })) } catch (e) { error.value = e.message } finally { busy.value = false }
}

function onKey(e) {
  if (needsSetup.value || e.target.closest?.('input')) return
  if (/^\d$/.test(e.key)) press(e.key)
  else if (e.key === 'Backspace') back()
  else if (e.key === 'Enter') submit()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="gate panel">
    <div class="icon"><Lock :size="28" /></div>
    <h2>Dev-Bereich gesperrt</h2>

    <template v-if="needsSetup">
      <p v-if="status.can_set_pin" class="muted">Es ist noch kein Dev-Code festgelegt. Lege jetzt einen fest (4 bis 8 Ziffern). Teilnehmer mit Dev-Rolle brauchen ihn auch.</p>
      <p v-else class="muted">Es ist noch kein Dev-Code festgelegt. Ein Lehrer muss ihn zuerst im Dev-Bereich festlegen.</p>
      <form v-if="status.can_set_pin" class="setup" @submit.prevent="setup">
        <input id="dev-new-pin" class="input mono" v-model="newPin" type="password" inputmode="numeric" pattern="\d{4,8}" placeholder="Neuer Dev-Code" autocomplete="new-password" required />
        <input id="dev-new-pin2" class="input mono" v-model="newPin2" type="password" inputmode="numeric" pattern="\d{4,8}" placeholder="Wiederholen" autocomplete="new-password" required />
        <button class="btn btn-primary" :disabled="busy">Festlegen und öffnen</button>
      </form>
    </template>

    <template v-else>
      <p class="muted">Dev-Code eingeben.<span v-if="status.test_mode && !status.pin_set"> Im Testbetrieb lautet er <span class="mono">0000</span>.</span></p>
      <div class="dots" :class="{ shake }" aria-live="polite" :aria-label="`${pin.length} Ziffern eingegeben`">
        <span v-for="i in Math.max(4, pin.length)" :key="i" :class="{ on: i <= pin.length }"></span>
      </div>
      <div class="pad">
        <button v-for="d in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="d" class="key" type="button" @click="press(d)">{{ d }}</button>
        <button class="key soft" type="button" aria-label="Löschen" @click="back"><Delete :size="22" /></button>
        <button class="key" type="button" @click="press('0')">0</button>
        <button class="key ok" type="button" :disabled="pin.length < 4 || busy" @click="submit">OK</button>
      </div>
    </template>
    <p v-if="error" class="error small">{{ error }}</p>
  </div>
</template>

<style scoped>
.gate { max-width: 360px; margin: 24px auto; padding: 28px 24px; display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; }
.icon { width: 56px; height: 56px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-2); color: var(--text-2); }
h2 { margin: 0; font-size: 1.2rem; }
p { margin: 0; }
.dots { display: flex; gap: 12px; margin: 8px 0; }
.dots span { width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--border-strong); }
.dots span.on { background: var(--text); border-color: var(--text); }
.shake { animation: shake 0.35s; }
@keyframes shake { 25% { transform: translateX(-8px); } 75% { transform: translateX(8px); } }
@media (prefers-reduced-motion: reduce) { .shake { animation: none; } }
.pad { display: grid; grid-template-columns: repeat(3, 72px); gap: 12px; }
.key { height: 64px; border-radius: 50%; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); font-size: 1.5rem; display: grid; place-items: center; }
.key:hover { background: var(--hover); }
.key.soft { background: transparent; border-color: transparent; color: var(--text-2); }
.key.ok { font-size: 1rem; font-weight: 700; background: var(--accent); color: var(--on-accent); border-color: transparent; }
.key:disabled { opacity: 0.4; }
.setup { display: flex; flex-direction: column; gap: 10px; width: 100%; }
</style>
