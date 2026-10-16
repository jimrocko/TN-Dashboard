<script setup>
// Großer Knopf nach dem Umlagern: Änderungen als BrickLink-XML in die Zwischenablage und BrickLink öffnen.
// Die App schreibt selbst nie zu BrickLink; eingefügt und bestätigt wird im BrickLink-Shop (Mass Update / Mass Upload).
import { computed, onMounted, ref } from 'vue'
import { Upload, Check, Copy } from 'lucide-vue-next'
import { api, IS_TUTORIAL } from '../api'
import { BRICKLINK_XML_URL, buildBrickLinkXml, copyText } from '../utils/bricklinkXml'

const pending = ref([])
const clicked = ref({})
const copied = ref('')
const finished = ref(null) // nach dem Übertragen: XML zum erneuten Kopieren
const error = ref('')

async function refresh() {
  try {
    pending.value = (await api.get('bricklink/pending')).transfers
    clicked.value = {}
    copied.value = ''
    if (pending.value.length) finished.value = null
  } catch (e) { error.value = e.message }
}
defineExpose({ refresh })
onMounted(refresh)

const xml = computed(() => buildBrickLinkXml(pending.value))
const steps = computed(() => {
  const s = []
  if (xml.value.update) s.push({ key: 'update', mode: 'Mass Update', label: `${xml.value.updateCount} ${xml.value.updateCount === 1 ? 'Lot ändern' : 'Lots ändern'}`, xml: xml.value.update })
  if (xml.value.upload) s.push({ key: 'upload', mode: 'Mass Upload', label: `${xml.value.uploadCount} ${xml.value.uploadCount === 1 ? 'neues Lot' : 'neue Lots'} anlegen`, xml: xml.value.upload })
  return s
})

function open(step) {
  // Kopieren und Öffnen direkt im Tipp, sonst blockiert Safari beides
  copyText(step.xml)
  window.open(BRICKLINK_XML_URL, '_blank', 'noopener')
  clicked.value = { ...clicked.value, [step.key]: true }
  copied.value = step.mode
  if (steps.value.every((s) => clicked.value[s.key])) markDone()
}
async function markDone() {
  const done = steps.value
  try {
    await api.post('bricklink/done', { ids: pending.value.map((t) => t.id) })
    finished.value = done
    pending.value = []
  } catch (e) { error.value = e.message }
}
function again(step) {
  copyText(step.xml)
  window.open(BRICKLINK_XML_URL, '_blank', 'noopener')
  copied.value = step.mode
}
</script>

<template>
  <section v-if="pending.length || finished" class="bl-export panel" :class="{ still: IS_TUTORIAL }" aria-live="polite">
    <template v-if="pending.length">
      <div class="head">
        <Upload :size="20" />
        <div>
          <h2>Zu BrickLink übertragen</h2>
          <p class="muted small">{{ pending.length }} {{ pending.length === 1 ? 'Umlagerung ist' : 'Umlagerungen sind' }} noch nicht im BrickLink-Shop.</p>
        </div>
      </div>
      <div class="steps">
        <button v-for="(s, i) in steps" :key="s.key" class="btn btn-primary big" :class="{ done: clicked[s.key] }" type="button" @click="open(s)">
          <Check v-if="clicked[s.key]" :size="20" /><Upload v-else :size="20" />
          <span><b>{{ steps.length > 1 ? `${i + 1}. ` : '' }}{{ s.mode }}</b> · {{ s.label }}</span>
        </button>
        <button v-if="!steps.length" class="btn big" type="button" @click="markDone">Verstanden</button>
      </div>
      <p v-if="copied" class="success small">Kopiert! Bei BrickLink „{{ copied }}“ wählen, ins Feld einfügen und bestätigen.</p>
      <p v-else class="faint small">Der Knopf kopiert die Änderungen und öffnet BrickLink. Dort einfügen und bestätigen.</p>
      <p v-if="xml.missing.length" class="warn-text small">
        Ohne BrickLink-Lot-ID (bitte von Hand ändern, beim nächsten Import stimmt es wieder):
        {{ xml.missing.map((m) => `${m.item_no} ${m.color_name}`.trim()).join(', ') }}
      </p>
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
        <button v-for="s in finished" :key="s.key" class="btn" type="button" @click="again(s)"><Copy :size="16" /> {{ s.mode }} nochmal kopieren</button>
        <button class="btn" type="button" @click="finished = null">Schließen</button>
      </div>
      <p v-if="copied" class="success small">Kopiert! Bei BrickLink „{{ copied }}“ wählen und einfügen.</p>
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
.steps { display: flex; flex-wrap: wrap; gap: 10px; }
.big { flex: 1 1 260px; justify-content: center; min-height: 52px; font-size: 16px; gap: 10px; }
.big.done { opacity: .75; }
.success, .faint, .warn-text, .error { margin: 0; }
.warn-text { color: var(--warn); }
</style>
