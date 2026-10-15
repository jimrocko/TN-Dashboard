<script setup>
import { computed, ref } from 'vue'

// Spaß-Editor (wie der Windows-Editor). Text bleibt auf diesem Gerät gespeichert.
const KEY = 'tn-xp-editor'
const start = `Einkaufsliste
- 500 × 1×1 Plate (die gehen immer verloren)
- Hausschuhe (wegen der Steine am Boden)
- neue Mischkiste? NEIN. Erst G563 aufräumen.

Notiz an mich:
Nicht vergessen, Pirat Piet NICHT zu verraten, wo der Schatz ist.
`
const read = () => { try { return localStorage.getItem(KEY) ?? start } catch { return start } }
const text = ref(read())
const saved = ref(true)
const wrap = ref(true)
function save() {
  try { localStorage.setItem(KEY, text.value) } catch { /* nur in diesem Fenster */ }
  saved.value = true
}
const stats = computed(() => `${text.value.split('\n').length} Zeilen · ${text.value.length} Zeichen`)
</script>

<template>
  <div class="notepad">
    <div class="menu">
      <button @click="save">Speichern</button>
      <button @click="text = ''; saved = false">Neu</button>
      <button @click="text = start; saved = false">Beispiel</button>
      <label><input type="checkbox" v-model="wrap" /> Zeilenumbruch</label>
      <span class="faint small">{{ saved ? 'Gespeichert' : 'Nicht gespeichert' }}</span>
    </div>
    <textarea id="xp-notepad" v-model="text" :class="{ nowrap: !wrap }" spellcheck="false" @input="saved = false" aria-label="Text"></textarea>
    <div class="status small faint">{{ stats }}</div>
  </div>
</template>

<style scoped>
.notepad { display: flex; flex-direction: column; height: 100%; min-height: 360px; background: var(--surface); }
.menu { display: flex; align-items: center; gap: 4px; padding: 4px 6px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.menu button { background: none; border: 1px solid transparent; padding: 6px 10px; border-radius: 3px; min-height: 36px; }
.menu button:hover { border-color: var(--border-strong); background: var(--hover); }
.menu label { display: inline-flex; align-items: center; gap: 4px; padding: 0 8px; font-size: 0.9rem; }
textarea { flex: 1; resize: none; border: 0; outline: 0; padding: 10px; font: 15px/1.5 'Lucida Console', 'Courier New', monospace; background: #fff; color: #111; min-height: 300px; }
textarea.nowrap { white-space: pre; overflow-x: auto; }
.status { padding: 4px 10px; border-top: 1px solid var(--border); }
</style>
