<script setup>
import { ref } from 'vue'
import { FileText, Image, FileArchive, Cog, RotateCcw, Trash2 } from 'lucide-vue-next'

// Spaß-Papierkorb mit erfundenen gelöschten Dateien.
const start = [
  { name: 'Barfuß-Unfall_2x4.jpg', size: '2,1 MB', icon: Image, from: 'Eigene Bilder' },
  { name: 'Anleitung_Seite_42_fehlt.pdf', size: '0 KB', icon: FileText, from: 'Eigene Dateien' },
  { name: 'Mischkiste_sortieren_TODO.txt', size: '1 KB', icon: FileText, from: 'Desktop' },
  { name: 'Alle_Steine_nach_Farbe.zip', size: '125.000 Teile', icon: FileArchive, from: 'Eigene Dateien' },
  { name: 'Steinzaehler.exe', size: '640 KB', icon: Cog, from: 'Programme' },
  { name: 'Schatzkarte_Piet_GEHEIM.jpg', size: '300 KB', icon: Image, from: 'Desktop' },
]
const items = ref([...start])
const msg = ref('')
function restore(i) {
  items.value = items.value.filter((x) => x !== i)
  msg.value = `„${i.name}“ wiederhergestellt nach „${i.from}“. (Zumindest in unserer Fantasie.)`
}
function empty() {
  items.value = []
  msg.value = 'Papierkorb geleert. *Knirsch*'
}
function refill() { items.value = [...start]; msg.value = '' }
</script>

<template>
  <div class="bin">
    <div class="bar">
      <button class="btn btn-sm" :disabled="!items.length" @click="empty"><Trash2 :size="14" /> Papierkorb leeren</button>
      <button v-if="!items.length" class="btn btn-sm" @click="refill">Alles wieder hineinwerfen</button>
      <span class="faint small">{{ items.length }} Objekt(e)</span>
    </div>
    <p v-if="msg" class="note small">{{ msg }}</p>
    <div class="grid">
      <div v-for="i in items" :key="i.name" class="item">
        <component :is="i.icon" :size="34" :stroke-width="1.6" class="ic" />
        <div class="nm">{{ i.name }}</div>
        <div class="faint small">{{ i.size }} · aus {{ i.from }}</div>
        <button class="btn btn-sm" @click="restore(i)"><RotateCcw :size="13" /> Wiederherstellen</button>
      </div>
      <p v-if="!items.length" class="faint">Der Papierkorb ist leer.</p>
    </div>
  </div>
</template>

<style scoped>
.bin { background: var(--surface); min-height: 100%; }
.bar { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.note { margin: 10px; padding: 8px 10px; background: var(--accent-soft); border-radius: 4px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; padding: 12px; }
.item { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; padding: 10px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); }
.ic { color: var(--accent-text); }
.nm { font-size: 0.85rem; word-break: break-all; }
</style>
