<script setup>
import { computed, ref } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

// Spaß-Kalender mit erfundenen Klemmbaustein-Terminen. Neue Termine bleiben nur, solange das Fenster offen ist.
const today = new Date()
const view = ref(new Date(today.getFullYear(), today.getMonth(), 1))
const selected = ref(today.getDate())
const fixed = {
  1: ['Monatsinventur: alle 1×1 zählen (viel Glück)'],
  3: ['Mischkiste G563 aufräumen'],
  7: ['Geburtstag Minifig Klaus (bringt Kuchen-Teil mit)'],
  12: ['Tag der verlorenen Anleitungsseite'],
  13: ['Barfuß-Sicherheitstraining'],
  18: ['Weltnoppentag'],
  21: ['Sortier-Olympiade: G gegen N'],
  24: ['Pirat Piet sucht seinen Schatz (N150)'],
  28: ['Steine waschen (nicht in der Spülmaschine!)'],
}
const extra = ref({})
const events = (d) => [...(fixed[d] ?? []), ...(extra.value[`${view.value.getMonth()}-${d}`] ?? [])]
const title = computed(() => view.value.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' }))
const days = computed(() => {
  const y = view.value.getFullYear(), m = view.value.getMonth()
  const first = (new Date(y, m, 1).getDay() + 6) % 7 // Montag = 0
  const count = new Date(y, m + 1, 0).getDate()
  return [...Array(first).fill(null), ...Array.from({ length: count }, (_, i) => i + 1)]
})
const isToday = (d) => d === today.getDate() && view.value.getMonth() === today.getMonth() && view.value.getFullYear() === today.getFullYear()
const move = (n) => { view.value = new Date(view.value.getFullYear(), view.value.getMonth() + n, 1); selected.value = 1 }
const newEvent = ref('')
function addEvent() {
  const t = newEvent.value.trim()
  if (!t) return
  const k = `${view.value.getMonth()}-${selected.value}`
  extra.value = { ...extra.value, [k]: [...(extra.value[k] ?? []), t] }
  newEvent.value = ''
}
</script>

<template>
  <div class="cal">
    <div class="head">
      <button class="btn btn-sm" @click="move(-1)" aria-label="Vorheriger Monat"><ChevronLeft :size="16" /></button>
      <h2>{{ title }}</h2>
      <button class="btn btn-sm" @click="move(1)" aria-label="Nächster Monat"><ChevronRight :size="16" /></button>
    </div>
    <div class="grid">
      <div v-for="w in ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']" :key="w" class="wd">{{ w }}</div>
      <button v-for="(d, i) in days" :key="i" class="day" :class="{ blank: !d, today: d && isToday(d), sel: d === selected, has: d && events(d).length }"
        :disabled="!d" @click="selected = d">
        <span v-if="d">{{ d }}</span>
        <i v-if="d && events(d).length" class="dot"></i>
      </button>
    </div>
    <div class="agenda">
      <h3>{{ selected }}. {{ title }}</h3>
      <ul v-if="events(selected).length"><li v-for="e in events(selected)" :key="e">{{ e }}</li></ul>
      <p v-else class="faint small">Keine Termine. Zeit zum Bauen!</p>
      <form class="toolbar" @submit.prevent="addEvent">
        <input id="xp-event" class="input" v-model="newEvent" placeholder="Neuer Termin …" />
        <button class="btn btn-sm">Eintragen</button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.cal { padding: 14px; display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(220px, 1fr); gap: 14px; background: var(--surface); min-height: 100%; }
@media (max-width: 760px) { .cal { grid-template-columns: 1fr; } }
.head { grid-column: 1 / -1; display: flex; align-items: center; gap: 10px; }
.head h2 { margin: 0; font-size: 1.1rem; min-width: 160px; text-align: center; }
.grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 3px; align-content: start; }
.wd { text-align: center; font-size: 0.75rem; color: var(--text-3); padding: 4px 0; }
.day { position: relative; aspect-ratio: 1.2; min-height: 38px; border: 1px solid var(--border); background: var(--bg); border-radius: 3px; display: grid; place-items: center; }
.day.blank { border: 0; background: none; }
.day.today { border-color: var(--accent); font-weight: 700; }
.day.sel { background: var(--accent-soft); border-color: var(--accent-text); }
.dot { position: absolute; bottom: 4px; width: 6px; height: 6px; border-radius: 50%; background: #c0392b; }
.agenda h3 { margin: 0 0 8px; font-size: 1rem; }
.agenda ul { margin: 0 0 12px; padding-left: 18px; line-height: 1.6; }
</style>
