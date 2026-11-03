<script setup>
import { computed, onMounted, ref } from 'vue'
import { ShieldCheck } from 'lucide-vue-next'
import { api } from '../../api'
import { PERM_KEYS } from '../../utils/permissions'

// Berechtigungen je Teilnehmer (Wunsch des Inhabers, Sitzung 8). Jeder Haken wird sofort gespeichert und kommt ins Protokoll.
// Ohne eigene Einstellung gelten die „Standardrechte für neue Teilnehmer“. Lehrer dürfen immer alles.
const emit = defineEmits(['changed'])
const data = ref(null)
const err = ref('')
const msg = ref('')
const busy = ref(false)
const open = ref(null)

const groups = computed(() => {
  const out = []
  for (const [key, group, label] of PERM_KEYS) {
    if (!out.length || out.at(-1).group !== group) out.push({ group, items: [] })
    out.at(-1).items.push({ key, label })
  }
  return out
})
const count = (p) => PERM_KEYS.filter(([k]) => p?.[k] !== false).length

async function load() {
  try { data.value = await api.get('permissions') } catch (e) { err.value = e.message }
}
async function save(target, perms) {
  busy.value = true
  err.value = msg.value = ''
  try {
    if (target === 'defaults') {
      data.value.defaults = (await api.post('permissions/defaults', { perms })).defaults
      msg.value = 'Standardrechte gespeichert.'
    } else {
      target.perms = (await api.post(`users/${target.id}/permissions`, { perms })).perms
      target.custom = true
      msg.value = `Rechte von ${target.display_name} gespeichert.`
    }
    emit('changed')
  } catch (e) { err.value = e.message } finally { busy.value = false }
}
const toggle = (target, key) => {
  const cur = target === 'defaults' ? data.value.defaults : target.perms
  save(target, { ...cur, [key]: cur[key] === false })
}
const setAll = (target, on) => save(target, Object.fromEntries(PERM_KEYS.map(([k]) => [k, on])))
async function toggleDev(u) {
  err.value = ''
  try { await api.post(`users/${u.id}/dev`, { enabled: !u.dev_access }); u.dev_access = !u.dev_access; emit('changed') } catch (e) { err.value = e.message }
}
defineExpose({ load })
onMounted(load)
</script>

<template>
  <section class="panel perms">
    <div class="panel-head"><h2><ShieldCheck :size="18" /> Berechtigungen</h2><span class="faint small">Wer was darf. Lehrer dürfen immer alles.</span></div>
    <div v-if="data" class="body">
      <p class="small muted top0">Haken weg = gesperrt: Der Bereich verschwindet von der Startseite bzw. der Knopf ist weg, und der Server lehnt es ab. Jede Änderung wird sofort gespeichert und steht im Verlauf.</p>

      <details class="who std" :open="open === 'defaults'" @toggle="$event.target.open ? (open = 'defaults') : open === 'defaults' && (open = null)">
        <summary><strong>Standard für neue Teilnehmer</strong><span class="pill">{{ count(data.defaults) }} von {{ PERM_KEYS.length }} erlaubt</span></summary>
        <div class="grid">
          <div class="allbtns"><button class="btn btn-sm" :disabled="busy" @click="setAll('defaults', true)">Alle an</button><button class="btn btn-sm" :disabled="busy" @click="setAll('defaults', false)">Alle aus</button></div>
          <fieldset v-for="g in groups" :key="g.group"><legend>{{ g.group }}</legend>
            <label v-for="it in g.items" :key="it.key" class="chk"><input type="checkbox" :checked="data.defaults[it.key] !== false" :disabled="busy" @change="toggle('defaults', it.key)" /> {{ it.label }}</label>
          </fieldset>
          <p class="small faint">Gilt für alle Teilnehmer ohne eigene Einstellung.</p>
        </div>
      </details>

      <details v-for="u in data.users" :key="u.id" class="who" :open="open === u.id" @toggle="$event.target.open ? (open = u.id) : open === u.id && (open = null)">
        <summary>
          <strong>{{ u.display_name }}</strong><span class="faint small mono">{{ u.username }}</span>
          <span class="pill" :class="count(u.perms) === PERM_KEYS.length ? 'ok' : 'warn'">{{ count(u.perms) }} von {{ PERM_KEYS.length }} erlaubt</span>
          <span v-if="!u.custom" class="small faint">Standard</span>
          <span v-if="u.dev_access" class="pill accent">Dev</span>
        </summary>
        <div class="grid">
          <div class="allbtns"><button class="btn btn-sm" :disabled="busy" @click="setAll(u, true)">Alle an</button><button class="btn btn-sm" :disabled="busy" @click="setAll(u, false)">Alle aus</button></div>
          <fieldset v-for="g in groups" :key="g.group"><legend>{{ g.group }}</legend>
            <label v-for="it in g.items" :key="it.key" class="chk"><input type="checkbox" :checked="u.perms[it.key] !== false" :disabled="busy" @change="toggle(u, it.key)" /> {{ it.label }}</label>
          </fieldset>
          <fieldset><legend>Technik</legend>
            <label class="chk"><input type="checkbox" :checked="u.dev_access" :disabled="busy" @change="toggleDev(u)" /> Dev-Bereich (Systemdaten, Tests, Datenbank)</label>
          </fieldset>
        </div>
      </details>
      <p v-if="!data.users.length" class="empty">Noch keine Teilnehmer.</p>
      <p v-if="err" class="error small">{{ err }}</p>
      <p v-if="msg" class="success small">{{ msg }}</p>
    </div>
    <p v-else-if="err" class="error small pad">{{ err }}</p>
  </section>
</template>

<style scoped>
.body { padding: 10px var(--pad, 14px) 14px; display: flex; flex-direction: column; gap: 8px; }
.top0 { margin: 0 0 4px; }
.panel-head h2 { display: inline-flex; align-items: center; gap: 8px; }
.who { border: 1px solid var(--border); border-radius: var(--radius-sm); }
.who.std { border-style: dashed; }
.who summary { cursor: pointer; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; padding: 10px 12px; }
.who[open] summary { border-bottom: 1px solid var(--border); }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px 16px; padding: 12px; }
.allbtns { grid-column: 1 / -1; display: flex; gap: 8px; }
fieldset { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
legend { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-3); margin-bottom: 4px; font-weight: 600; }
.chk { display: flex; gap: 8px; align-items: flex-start; line-height: 1.35; cursor: pointer; }
.chk input { width: 18px; height: 18px; flex: none; margin-top: 1px; }
.pad { padding: 10px var(--pad, 14px); }
</style>
