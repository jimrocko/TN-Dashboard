<script setup>
// Großer Knopf nach dem Umlagern: ganzen Bestand (alle Lots mit Menge nach dem Abziehen) als BrickLink-XML
// in die Zwischenablage und BrickLink öffnen. Dort unten beim Upload einfügen und bestätigen.
// Die App schreibt selbst nie zu BrickLink.
import { computed, onMounted, ref } from 'vue'
import { Upload, Check, Copy } from 'lucide-vue-next'
import { api, IS_TUTORIAL } from '../api'
import { num } from '../utils/format'
import { BRICKLINK_XML_URL, buildStockXml, copyText } from '../utils/bricklinkXml'

const pending = ref([])  // eigene, noch nicht übertragene Umlagerungen
const stock = ref(null)  // ganzer Bestand (wird vorher geladen, Kopieren muss direkt im Tipp passieren)
const clicked = ref({})
const copied = ref(0)
const finished = ref(null) // nach dem Übertragen: Teile zum erneuten Kopieren
const error = ref('')

async function refresh() {
  try {
    pending.value = (await api.get('bricklink/pending')).transfers
    clicked.value = {}
    copied.value = 0
    stock.value = null
    if (pending.value.length) {
      finished.value = null
      stock.value = await api.get('bricklink/stock')
    }
  } catch (e) { error.value = e.message }
}
defineExpose({ refresh })
onMounted(refresh)

const parts = computed(() => (stock.value ? buildStockXml(stock.value) : []))
const lotCount = computed(() => (stock.value ? stock.value.lots.length + stock.value.deleted.length : 0))

function open(i, list) {
  // Kopieren und Öffnen direkt im Tipp, sonst blockiert Safari beides
  copyText(list[i].xml)
  window.open(BRICKLINK_XML_URL, '_blank', 'noopener')
  copied.value = i + 1
  if (finished.value) return
  clicked.value = { ...clicked.value, [i]: true }
  if (parts.value.every((_, k) => clicked.value[k])) markDone()
}
async function markDone() {
  const done = parts.value
  try {
    await api.post('bricklink/done', { ids: stock.value.transfer_ids })
    finished.value = done
    pending.value = []
  } catch (e) { error.value = e.message }
}
const label = (i, list) => (list.length > 1 ? `Teil ${i + 1} von ${list.length} kopieren` : 'Bestand kopieren')
</script>

<template>
  <section v-if="pending.length || finished" class="bl-export panel" :class="{ still: IS_TUTORIAL }" aria-live="polite">
    <template v-if="pending.length">
      <div class="head">
        <Upload :size="20" />
        <div>
          <h2>Zu BrickLink übertragen</h2>
          <p class="muted small">{{ pending.length }} {{ pending.length === 1 ? 'Umlagerung ist' : 'Umlagerungen sind' }} noch nicht im BrickLink-Shop.
            <template v-if="stock">Kopiert wird der ganze Bestand: {{ num(lotCount) }} Lots mit der Menge nach dem Abziehen.</template></p>
        </div>
      </div>
      <div class="steps">
        <button v-for="(p, i) in parts" :key="i" class="btn btn-primary big" :class="{ done: clicked[i] }" type="button" @click="open(i, parts)">
          <Check v-if="clicked[i]" :size="20" /><Upload v-else :size="20" />
          <span><b>{{ label(i, parts) }}</b> · BrickLink öffnen</span>
        </button>
        <span v-if="!stock && !error" class="faint small">Bestand wird geladen …</span>
      </div>
      <p v-if="copied" class="success small">{{ parts.length > 1 ? `Teil ${copied} kopiert!` : 'Kopiert!' }} Bei BrickLink unten beim Upload einfügen und bestätigen.</p>
      <p v-else class="faint small">Der Knopf kopiert den Bestand und öffnet BrickLink. Dort unten beim Upload einfügen und bestätigen.</p>
    </template>
    <template v-else>
      <div class="head">
        <Check :size="20" />
        <div>
          <h2>Übertragen</h2>
          <p class="muted small">Falls bei BrickLink etwas nicht geklappt hat, hier nochmal kopieren.</p>
        </div>
      </div>
      <div class="steps">
        <button v-for="(p, i) in finished" :key="i" class="btn" type="button" @click="open(i, finished)"><Copy :size="16" /> {{ label(i, finished) }}</button>
        <button class="btn" type="button" @click="finished = null">Schließen</button>
      </div>
      <p v-if="copied" class="success small">Kopiert! Bei BrickLink unten beim Upload einfügen.</p>
    </template>
    <p v-if="error" class="error small">{{ error }}</p>
  </section>
</template>

<style scoped>
/* Bleibt beim Scrollen unten sichtbar, über der Leiste unten */
.bl-export { position: sticky; bottom: calc(84px + env(safe-area-inset-bottom)); z-index: 5; padding: 14px var(--pad); display: flex; flex-direction: column; gap: 10px; border-color: var(--accent); box-shadow: 0 8px 30px rgba(0, 0, 0, .35); }
:global([data-tn-style="xp"]) .bl-export { bottom: 8px; }
/* Im Tutorial nicht kleben, sonst verdeckt der Knopf die nächsten Ziele */
.bl-export.still { position: static; box-shadow: none; }
.head { display: flex; align-items: flex-start; gap: 10px; }
.head h2 { margin: 0; font-size: 17px; }
.head p { margin: 2px 0 0; }
.steps { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.big { flex: 1 1 260px; min-width: 0; max-width: 100%; white-space: normal; text-align: left; justify-content: center; min-height: 52px; font-size: 16px; gap: 10px; }
.big svg, .head svg { flex-shrink: 0; }
.big.done { opacity: .75; }
.success, .faint, .error { margin: 0; }
</style>
