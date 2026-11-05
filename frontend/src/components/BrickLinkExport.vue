<script setup>
// Pop-up direkt nach dem Umlagern (Wunsch des Inhabers): die Änderungen als BrickLink-XML in die Zwischenablage und
// BrickLink öffnen. Nur die Änderungen, nicht der ganze Bestand (für BrickLink zu groß, Sitzung 8): eine Update-Datei
// (Mengen, Lagerort, leere Lots löschen) und, wenn neue Lots entstanden sind, eine Upload-Datei. Sehr große Mengen werden
// auf mehrere Dateien verteilt. Gemeinsam für alle, nicht persönlich: Offene Umlagerungen und die laufende Übertragung
// sieht jeder, und jeder darf sie abschließen. Es ist immer nur eine Übertragung offen.
// Mit „Später“ geschlossen, bleibt der Kasten unten auf der Seite. Die App schreibt selbst nie zu BrickLink.
// Auch abgeschlossene Inventuren mit Korrekturen kommen hier hinein (gleiches Pop-up nach „Abschließen“).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Upload, Check, Copy, X } from 'lucide-vue-next'
import { api, IS_TUTORIAL } from '../api'
import { num, dateTime } from '../utils/format'
import { useAuth } from '../stores/auth'
import { BRICKLINK_XML_URL, buildChangeFiles, copyText } from '../utils/bricklinkXml'

const pending = ref([])  // offene Umlagerungen aller Nutzer
const pendingSt = ref([]) // abgeschlossene Inventuren mit Korrekturen, noch nicht übertragen
const exp = ref(null)    // laufende Übertragung (oder null)
const changes = ref(null) // was zu BrickLink muss (wird vorher geladen, Kopieren muss direkt im Tipp passieren)
const copied = ref(new Set()) // Nummern der schon kopierten Dateien
const doneMsg = ref('')
const error = ref('')
const busy = ref(false)
const popup = ref(false) // als Pop-up in der Mitte statt unten auf der Seite
const auth = useAuth()
// Fehlerhaftes Pop-up ohne Übertragung entfernen: jeder mit dem Recht, bewusst unauffällig (Wunsch des Inhabers, Sitzung 8)
async function dismiss() {
  const u = pending.value.length, i = pendingSt.value.length
  if (!confirm(`Alle offenen Änderungen (${u} Umlagerungen, ${i} Inventuren) als erledigt markieren, OHNE sie zu BrickLink zu übertragen?\n\nBei BrickLink ändert sich nichts. Fehlendes dort von Hand korrigieren.`)) return
  busy.value = true
  error.value = ''
  try {
    await api.post('bricklink/dismiss')
    await refresh()
    close()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

/** Neu laden; mit { popup: true } nach einer Umlagerung als Pop-up öffnen (im Tutorial nie, sonst verdeckt es Klemmi) */
async function refresh({ popup: show = false } = {}) {
  try {
    const r = await api.get('bricklink/pending')
    pending.value = r.transfers
    pendingSt.value = r.stocktakes ?? []
    exp.value = r.export
    copied.value = new Set()
    changes.value = null
    if (openCount.value || exp.value) {
      doneMsg.value = ''
      if (show && !IS_TUTORIAL) popup.value = true
      changes.value = await api.get('bricklink/changes')
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

const files = computed(() => (changes.value ? buildChangeFiles(changes.value) : []))
const fileLabel = (f) => `${f.kind === 'update' ? 'Update' : 'Upload'}-Datei${f.parts > 1 ? ` ${f.part}/${f.parts}` : ''}`
const fileHint = (f) => (f.kind === 'update' ? 'bei BrickLink unter „Update“ einfügen' : 'bei BrickLink unter „Upload“ einfügen')
const missing = computed(() => changes.value?.missing ?? [])
const openCount = computed(() => pending.value.length + pendingSt.value.length)
// „2 Umlagerungen und 1 Inventur“
const what = (u, i) => [u && `${u} ${u === 1 ? 'Umlagerung' : 'Umlagerungen'}`, i && `${i} ${i === 1 ? 'Inventur' : 'Inventuren'}`].filter(Boolean).join(' und ')
// Änderungen, die nach dem Start der offenen Übertragung dazukamen (kommen in die nächste)
const newer = computed(() => (exp.value
  ? pending.value.filter((t) => !exp.value.transfer_ids.includes(t.id)).length + pendingSt.value.filter((x) => !(exp.value.stocktake_ids ?? []).includes(x.id)).length
  : 0))

function copyAndOpen(i) {
  // Kopieren und Öffnen direkt im Tipp, sonst blockiert Safari beides
  copyText(files.value[i].xml)
  window.open(BRICKLINK_XML_URL, '_blank', 'noopener')
  copied.value = new Set([...copied.value, i])
}
/** Erste Datei kopieren startet die Übertragung (danach sieht jeder, dass sie läuft). Ohne Dateien: nur starten. */
async function start(i) {
  error.value = ''
  if (i !== undefined) copyAndOpen(i)
  try {
    exp.value = (await api.post('bricklink/start', { ids: changes.value.transfer_ids, stocktake_ids: changes.value.stocktake_ids ?? [] })).export
  } catch (e) {
    await refresh()
    error.value = e.message
  }
}
async function finish(done) {
  busy.value = true
  error.value = ''
  try {
    const r = await api.post('bricklink/finish', { id: exp.value.id, done })
    await refresh()
    doneMsg.value = done ? 'Übertragen. BrickLink ist auf dem neuen Stand.' : 'Übertragung verworfen.'
    // Ollies Dashboard (gleicher Rechner) lädt im Hintergrund neu, damit beide denselben Bestand haben
    if (done && r.ollie) {
      doneMsg.value = 'Übertragen. Ollies Dashboard lädt den neuen Stand …'
      api.post('ollie/after-export')
        .then(() => { if (doneMsg.value.startsWith('Übertragen.')) doneMsg.value = 'Übertragen. BrickLink und Ollies Dashboard sind auf dem neuen Stand.' })
        .catch((e) => { if (doneMsg.value.startsWith('Übertragen.')) doneMsg.value = `Übertragen. Ollies Dashboard konnte nicht neu laden: ${e.message}` })
    }
  } catch (e) {
    await refresh()
    error.value = e.message
  } finally { busy.value = false }
}
</script>

<template>
  <!-- Als Pop-up an den body gehängt (auch im XP-Fenster mittig), sonst an Ort und Stelle; display: contents hält sticky intakt -->
  <Teleport to="body" :disabled="!popup">
  <div v-if="openCount || exp || (popup && doneMsg)" :class="popup ? 'bl-overlay' : 'bl-inline'" @click.self="close">
  <section class="bl-export panel" :class="{ still: IS_TUTORIAL, modal: popup }" :role="popup ? 'dialog' : null" :aria-modal="popup ? 'true' : null" aria-live="polite">
    <button v-if="popup" class="btn btn-sm close" type="button" aria-label="Schließen" @click="close"><X :size="16" /></button>

    <!-- Laufende Übertragung: gemeinsam, jeder darf abschließen -->
    <template v-if="exp">
      <div class="head">
        <Upload :size="20" />
        <div>
          <h2>Übertragung läuft</h2>
          <p class="muted small">Seit {{ dateTime(exp.created_at) }} · {{ what(exp.transfer_ids.length, (exp.stocktake_ids ?? []).length) }}.
            <template v-if="files.length">Wurde{{ files.length > 1 ? 'n alle Dateien' : ' die Datei' }} bei BrickLink eingefügt und bestätigt?</template>
            <template v-else>Bei BrickLink ist nichts einzufügen.</template></p>
        </div>
      </div>
      <div class="steps">
        <button v-perm="'bricklink_uebertragen'" class="btn btn-primary big" type="button" :disabled="busy" @click="finish(true)"><Check :size="20" /><span><b>Ja, ist eingefügt</b></span></button>
        <button v-perm="'bricklink_uebertragen'" class="btn big2" type="button" :disabled="busy" @click="finish(false)">Nein, verwerfen</button>
      </div>
      <div v-if="files.length" class="files">
        <button v-for="(f, i) in files" :key="i" class="btn btn-sm again" type="button" @click="copyAndOpen(i)">
          <Copy :size="15" /> {{ fileLabel(f) }} {{ copied.has(i) ? 'nochmal ' : '' }}kopieren · {{ num(f.lots) }} Lots
        </button>
      </div>
      <p v-if="copied.size" class="success small">Kopiert! Jetzt {{ fileHint(files[[...copied].at(-1)]) }} und bestätigen.</p>
      <p v-if="newer" class="faint small">{{ newer }} {{ newer === 1 ? 'neuere Änderung kommt' : 'neuere Änderungen kommen' }} danach in die nächste Übertragung.</p>
    </template>

    <!-- Noch nichts gestartet -->
    <template v-else-if="openCount">
      <div class="head">
        <Upload :size="20" />
        <div>
          <h2>Zu BrickLink übertragen</h2>
          <p class="muted small">{{ what(pending.length, pendingSt.length) }} {{ openCount === 1 ? 'ist' : 'sind' }} noch nicht im BrickLink-Shop.
            <template v-if="changes && files.length">Kopiert werden nur die Änderungen: {{ files.map((f) => `${fileLabel(f)} (${num(f.lots)} Lots)`).join(', ') }}.</template></p>
        </div>
      </div>
      <div class="steps">
        <template v-if="changes">
          <button v-perm="'bricklink_uebertragen'" v-for="(f, i) in files" :key="i" class="btn big" :class="{ 'btn-primary': i === 0 }" type="button" @click="start(i)">
            <Upload :size="20" /><span><b>{{ files.length > 1 ? `${i + 1}. ` : '' }}{{ fileLabel(f) }} kopieren</b> · BrickLink öffnen</span>
          </button>
          <button v-perm="'bricklink_uebertragen'" v-if="!files.length" class="btn btn-primary big" type="button" @click="start()"><Check :size="20" /><span><b>Nichts einzufügen</b> · abschließen</span></button>
        </template>
        <span v-else-if="!error" class="faint small">Änderungen werden geladen …</span>
      </div>
      <p v-if="files.length" class="faint small">Der Knopf kopiert die Datei und öffnet BrickLink. Update-Datei unter „Update“, Upload-Datei unter „Upload“ einfügen und bestätigen.</p>
      <p v-if="missing.length" class="error small">{{ missing.length }} {{ missing.length === 1 ? 'Änderung betrifft ein Lot' : 'Änderungen betreffen Lots' }} ohne BrickLink-Lot-Nr.
        ({{ missing.slice(0, 3).map((m) => `${m.item_no} ${m.color_name}`.trim()).join(', ') }}{{ missing.length > 3 ? ' …' : '' }}). Bitte bei BrickLink von Hand nachtragen.</p>
    </template>

    <template v-else>
      <div class="head"><Check :size="20" /><div><h2>{{ doneMsg }}</h2></div></div>
    </template>

    <button v-if="popup" class="btn later" type="button" @click="close">{{ openCount || exp ? 'Später' : 'Schließen' }}</button>
    <p v-if="error" class="error small">{{ error }}</p>
    <button v-if="auth.can('bricklink_uebertragen') && (openCount || exp) && !IS_TUTORIAL" class="dismiss" type="button" :disabled="busy" @click="dismiss">Fehlerhaft? Ohne Übertragung entfernen</button>
  </section>
  </div>
  </Teleport>
</template>

<style scoped>
.dismiss { align-self: flex-end; background: none; border: 0; padding: 0; font: inherit; font-size: 0.72rem; color: var(--text-3); opacity: 0.55; cursor: pointer; text-decoration: underline; }
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
.files { display: flex; flex-wrap: wrap; gap: 8px; }
</style>
