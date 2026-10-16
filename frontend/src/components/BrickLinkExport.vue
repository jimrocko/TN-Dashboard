<script setup>
// Pop-up direkt nach dem Umlagern (Wunsch des Inhabers): ganzen Bestand (alle Lots mit Menge nach dem Abziehen) als BrickLink-XML
// in die Zwischenablage und BrickLink öffnen. Dort unten beim Upload einfügen und bestätigen.
// Mit „Später“ geschlossen, bleibt der Knopf unten auf der Seite stehen. Die App schreibt selbst nie zu BrickLink.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Upload, Check, Copy, X } from 'lucide-vue-next'
import { api, IS_TUTORIAL } from '../api'
import { num } from '../utils/format'
import { BRICKLINK_XML_URL, buildStockXml, copyText } from '../utils/bricklinkXml'

const pending = ref([])  // eigene, noch nicht übertragene Umlagerungen
const stock = ref(null)  // ganzer Bestand (wird vorher geladen, Kopieren muss direkt im Tipp passieren)
const clicked = ref({})
const copied = ref(0)
const finished = ref(null) // nach dem Übertragen: Teile zum erneuten Kopieren
const error = ref('')
const popup = ref(false)   // als Pop-up in der Mitte statt unten auf der Seite

/** Neu laden; mit { popup: true } nach einer Umlagerung als Pop-up öffnen (im Tutorial nie, sonst verdeckt es Klemmi) */
async function refresh({ popup: show = false } = {}) {
  try {
    pending.value = (await api.get('bricklink/pending')).transfers
    clicked.value = {}
    copied.value = 0
    stock.value = null
    if (pending.value.length) {
      finished.value = null
      if (show && !IS_TUTORIAL) popup.value = true
      stock.value = await api.get('bricklink/stock')
    }
  } catch (e) { error.value = e.message }
}
defineExpose({ refresh })
onMounted(() => refresh())
function close() {
  popup.value = false
  if (!pending.value.length) finished.value = null
}
const onKey = (e) => { if (e.key === 'Escape' && popup.value) close() }
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

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
  <!-- Als Pop-up an den body gehängt (auch im XP-Fenster mittig), sonst an Ort und Stelle; display: contents hält sticky intakt -->
  <Teleport to="body" :disabled="!popup">
  <div v-if="pending.length || finished" :class="popup ? 'bl-overlay' : 'bl-inline'" @click.self="close">
  <section class="bl-export panel" :class="{ still: IS_TUTORIAL, modal: popup }" :role="popup ? 'dialog' : null" :aria-modal="popup ? 'true' : null" aria-live="polite">
    <button v-if="popup" class="btn btn-sm close" type="button" aria-label="Schließen" @click="close"><X :size="16" /></button>
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
      <button v-if="popup" class="btn later" type="button" @click="close">Später</button>
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
        <button class="btn" type="button" @click="finished = null; popup = false">Schließen</button>
      </div>
      <p v-if="copied" class="success small">Kopiert! Bei BrickLink unten beim Upload einfügen.</p>
    </template>
    <p v-if="error" class="error small">{{ error }}</p>
  </section>
  </div>
  </Teleport>
</template>

<style scoped>
/* Bleibt beim Scrollen unten sichtbar, über der Leiste unten */
.bl-export { position: sticky; bottom: calc(84px + env(safe-area-inset-bottom)); z-index: 5; padding: 14px var(--pad); display: flex; flex-direction: column; gap: 10px; border-color: var(--accent); box-shadow: 0 8px 30px rgba(0, 0, 0, .35); }
:global([data-tn-style="xp"]) .bl-export { bottom: 8px; }
/* Pop-up: abgedunkelter Hintergrund, Kasten in der Mitte */
.bl-inline { display: contents; }
.bl-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 16px; background: rgba(3, 7, 14, .6); }
.bl-export.modal { position: relative; bottom: auto; width: 100%; max-width: 560px; max-height: calc(100dvh - 32px); overflow: auto; padding-top: 18px; }
.close { position: absolute; top: 10px; right: 10px; width: 34px; height: 34px; padding: 0; justify-content: center; }
.modal .head { padding-right: 40px; }
.later { align-self: flex-start; }
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
