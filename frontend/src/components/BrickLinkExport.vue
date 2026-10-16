<script setup>
// Pop-up direkt nach dem Umlagern (Wunsch des Inhabers): ganzen Bestand (alle Lots mit Menge nach dem Abziehen)
// als BrickLink-XML in die Zwischenablage und BrickLink öffnen. Dort unten beim Upload einfügen und bestätigen.
// Gemeinsam für alle, nicht persönlich: Offene Umlagerungen und die laufende Übertragung sieht jeder, und jeder darf
// sie abschließen. Es ist immer nur eine Übertragung offen, damit kein älterer Bestand einen neueren überschreibt.
// Mit „Später“ geschlossen, bleibt der Kasten unten auf der Seite. Die App schreibt selbst nie zu BrickLink.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Upload, Check, Copy, X } from 'lucide-vue-next'
import { api, IS_TUTORIAL } from '../api'
import { num, dateTime } from '../utils/format'
import { BRICKLINK_XML_URL, buildStockXml, copyText } from '../utils/bricklinkXml'

const pending = ref([])  // offene Umlagerungen aller Nutzer
const exp = ref(null)    // laufende Übertragung (oder null)
const stock = ref(null)  // ganzer Bestand (wird vorher geladen, Kopieren muss direkt im Tipp passieren)
const copied = ref(false)
const doneMsg = ref('')
const error = ref('')
const busy = ref(false)
const popup = ref(false) // als Pop-up in der Mitte statt unten auf der Seite

/** Neu laden; mit { popup: true } nach einer Umlagerung als Pop-up öffnen (im Tutorial nie, sonst verdeckt es Klemmi) */
async function refresh({ popup: show = false } = {}) {
  try {
    const r = await api.get('bricklink/pending')
    pending.value = r.transfers
    exp.value = r.export
    copied.value = false
    stock.value = null
    if (pending.value.length || exp.value) {
      doneMsg.value = ''
      if (show && !IS_TUTORIAL) popup.value = true
      stock.value = await api.get('bricklink/stock')
    }
  } catch (e) { error.value = e.message }
}
defineExpose({ refresh })
onMounted(() => refresh())
function close() {
  popup.value = false
  doneMsg.value = ''
}
const onKey = (e) => { if (e.key === 'Escape' && popup.value) close() }
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

const xml = computed(() => (stock.value ? buildStockXml(stock.value)[0].xml : ''))
const lotCount = computed(() => (stock.value ? stock.value.lots.length + stock.value.deleted.length : 0))
// Umlagerungen, die nach dem Start der offenen Übertragung dazukamen (kommen in die nächste)
const newer = computed(() => (exp.value ? pending.value.filter((t) => !exp.value.transfer_ids.includes(t.id)).length : 0))

function copyAndOpen() {
  // Kopieren und Öffnen direkt im Tipp, sonst blockiert Safari beides
  copyText(xml.value)
  window.open(BRICKLINK_XML_URL, '_blank', 'noopener')
  copied.value = true
}
async function start() {
  error.value = ''
  copyAndOpen()
  try {
    exp.value = (await api.post('bricklink/start', { ids: stock.value.transfer_ids })).export
  } catch (e) {
    await refresh()
    error.value = e.message
  }
}
async function finish(done) {
  busy.value = true
  error.value = ''
  try {
    await api.post('bricklink/finish', { id: exp.value.id, done })
    await refresh()
    doneMsg.value = done ? 'Übertragen. BrickLink ist auf dem neuen Stand.' : 'Übertragung verworfen.'
  } catch (e) {
    await refresh()
    error.value = e.message
  } finally { busy.value = false }
}
</script>

<template>
  <!-- Als Pop-up an den body gehängt (auch im XP-Fenster mittig), sonst an Ort und Stelle; display: contents hält sticky intakt -->
  <Teleport to="body" :disabled="!popup">
  <div v-if="pending.length || exp || (popup && doneMsg)" :class="popup ? 'bl-overlay' : 'bl-inline'" @click.self="close">
  <section class="bl-export panel" :class="{ still: IS_TUTORIAL, modal: popup }" :role="popup ? 'dialog' : null" :aria-modal="popup ? 'true' : null" aria-live="polite">
    <button v-if="popup" class="btn btn-sm close" type="button" aria-label="Schließen" @click="close"><X :size="16" /></button>

    <!-- Laufende Übertragung: gemeinsam, jeder darf abschließen -->
    <template v-if="exp">
      <div class="head">
        <Upload :size="20" />
        <div>
          <h2>Übertragung läuft</h2>
          <p class="muted small">Seit {{ dateTime(exp.created_at) }} · {{ exp.transfer_ids.length }} {{ exp.transfer_ids.length === 1 ? 'Umlagerung' : 'Umlagerungen' }}.
            Wurde der kopierte Bestand bei BrickLink unten beim Upload eingefügt und bestätigt?</p>
        </div>
      </div>
      <div class="steps">
        <button class="btn btn-primary big" type="button" :disabled="busy" @click="finish(true)"><Check :size="20" /><span><b>Ja, ist eingefügt</b></span></button>
        <button class="btn big2" type="button" :disabled="busy" @click="finish(false)">Nein, verwerfen</button>
      </div>
      <button class="btn btn-sm again" type="button" :disabled="!stock" @click="copyAndOpen"><Copy :size="15" /> Bestand nochmal kopieren · BrickLink öffnen</button>
      <p v-if="copied" class="success small">Kopiert! Bei BrickLink unten beim Upload einfügen und bestätigen.</p>
      <p v-if="newer" class="faint small">{{ newer }} neuere {{ newer === 1 ? 'Umlagerung kommt' : 'Umlagerungen kommen' }} danach in die nächste Übertragung.</p>
    </template>

    <!-- Noch nichts gestartet -->
    <template v-else-if="pending.length">
      <div class="head">
        <Upload :size="20" />
        <div>
          <h2>Zu BrickLink übertragen</h2>
          <p class="muted small">{{ pending.length }} {{ pending.length === 1 ? 'Umlagerung ist' : 'Umlagerungen sind' }} noch nicht im BrickLink-Shop.
            <template v-if="stock">Kopiert wird der ganze Bestand: {{ num(lotCount) }} Lots mit der Menge nach dem Abziehen.</template></p>
        </div>
      </div>
      <div class="steps">
        <button v-if="stock" class="btn btn-primary big" type="button" @click="start"><Upload :size="20" /><span><b>Bestand kopieren</b> · BrickLink öffnen</span></button>
        <span v-else-if="!error" class="faint small">Bestand wird geladen …</span>
      </div>
      <p class="faint small">Der Knopf kopiert den Bestand und öffnet BrickLink. Dort unten beim Upload einfügen und bestätigen.</p>
    </template>

    <template v-else>
      <div class="head"><Check :size="20" /><div><h2>{{ doneMsg }}</h2></div></div>
    </template>

    <button v-if="popup" class="btn later" type="button" @click="close">{{ pending.length || exp ? 'Später' : 'Schließen' }}</button>
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
.later, .again { align-self: flex-start; }
/* Im Tutorial nicht kleben, sonst verdeckt der Kasten die nächsten Ziele */
.bl-export.still { position: static; box-shadow: none; }
.head { display: flex; align-items: flex-start; gap: 10px; }
.head h2 { margin: 0; font-size: 17px; }
.head p { margin: 2px 0 0; }
.steps { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.big { flex: 2 1 240px; min-width: 0; max-width: 100%; white-space: normal; justify-content: center; min-height: 52px; font-size: 16px; gap: 10px; }
.big2 { flex: 1 1 140px; min-height: 52px; justify-content: center; }
.big svg, .head svg { flex-shrink: 0; }
.success, .faint, .error { margin: 0; }
</style>
