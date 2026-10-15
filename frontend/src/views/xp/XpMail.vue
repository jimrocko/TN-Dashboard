<script setup>
import { computed, ref } from 'vue'
import { Inbox, Send, Trash2, Reply, Star } from 'lucide-vue-next'

// Spaß-Postfach: Post von erfundenen Minifiguren. Antworten wird nur angezeigt, nichts verschickt.
const day = (d) => new Date(Date.now() - d * 86400000).toLocaleDateString('de-DE')
const mails = ref([
  { id: 1, from: 'Bauarbeiter Bernd', subject: 'Wo ist mein 1×1?', date: day(0), read: false,
    body: 'Hallo,\n\nich suche seit drei Wochen meinen 1×1-Stein in Hellgrau. Zuletzt gesehen in G563. Falls er bei euch auftaucht: Ich zahle Finderlohn (zwei Noppen).\n\nGruß, Bernd' },
  { id: 2, from: 'Astronautin Ana', subject: 'Grüße aus dem Weltraum', date: day(1), read: false,
    body: 'Hier oben ist alles grau. Light Bluish Gray, um genau zu sein. Könnt ihr mir bitte ein paar Steine in Orange schicken? Die Station sieht so traurig aus.\n\nAna' },
  { id: 3, from: 'Pirat Piet', subject: 'Schatzkarte (geheim!!)', date: day(2), read: true,
    body: 'Arrr!\n\nDer Schatz liegt in Kästchen N150, unter den blauen 2×4. Nicht weitersagen. Besonders nicht Polizist Paul.\n\nPiet' },
  { id: 4, from: 'Polizist Paul', subject: 'Re: Schatzkarte (geheim!!)', date: day(2), read: true,
    body: 'Ich lese alle E-Mails mit, Piet.\n\nPaul' },
  { id: 5, from: 'Gewinnspiel-Team', subject: 'Sie haben 1.000.000 Noppen gewonnen!!!', date: day(3), read: true, spam: true,
    body: 'Herzlichen Glückwunsch! Bitte senden Sie uns Ihren Lieblingsstein und Ihre Schuhgröße, um den Gewinn abzuholen.\n\n(Das ist Spam. Bitte nicht antworten.)' },
  { id: 6, from: 'Ritter Rudi', subject: 'Burg zu klein', date: day(5), read: true,
    body: 'Werte Damen und Herren,\n\nmeine Burg hat nur 3 Steine. Laut eurer Regel ist das die Höchstzahl pro Kästchen. Bitte um Ausnahmegenehmigung.\n\nHochachtungsvoll, Rudi' },
  { id: 7, from: 'Koch Karl', subject: 'Rezept: Steinsuppe', date: day(8), read: true,
    body: 'Man nehme 12 runde 1×1-Plates in Gelb, einen Topf (Teil 3?) und viel Geduld. Nicht essen.\n\nKarl' },
])
const folder = ref('posteingang')
const sent = ref([])
const selectedId = ref(mails.value[0].id)
const list = computed(() => (folder.value === 'gesendet' ? sent.value : mails.value.filter((m) => (folder.value === 'spam' ? m.spam : !m.spam))))
const selected = computed(() => list.value.find((m) => m.id === selectedId.value) ?? list.value[0] ?? null)
const unread = computed(() => mails.value.filter((m) => !m.read && !m.spam).length)
const reply = ref('')

function open(m) { selectedId.value = m.id; m.read = true; reply.value = '' }
function send() {
  if (!reply.value.trim() || !selected.value) return
  sent.value.unshift({ id: Date.now(), from: 'Ich', subject: `Re: ${selected.value.subject}`, date: day(0), read: true, body: reply.value })
  reply.value = ''
  folder.value = 'gesendet'
}
function remove(m) { mails.value = mails.value.filter((x) => x !== m) }
</script>

<template>
  <div class="mail">
    <nav class="folders">
      <button :class="{ on: folder === 'posteingang' }" @click="folder = 'posteingang'"><Inbox :size="15" /> Posteingang <b v-if="unread">({{ unread }})</b></button>
      <button :class="{ on: folder === 'gesendet' }" @click="folder = 'gesendet'"><Send :size="15" /> Gesendet</button>
      <button :class="{ on: folder === 'spam' }" @click="folder = 'spam'"><Trash2 :size="15" /> Spam</button>
    </nav>
    <div class="list">
      <button v-for="m in list" :key="m.id" class="row" :class="{ on: selected?.id === m.id, unread: !m.read }" @click="open(m)">
        <span class="from">{{ m.from }}</span><span class="date faint small">{{ m.date }}</span>
        <span class="subj">{{ m.subject }}</span>
      </button>
      <p v-if="!list.length" class="faint small pad">Keine Nachrichten.</p>
    </div>
    <article v-if="selected" class="read">
      <header>
        <h2>{{ selected.subject }}</h2>
        <div class="small muted">Von: {{ selected.from }} · {{ selected.date }}</div>
      </header>
      <pre class="body">{{ selected.body }}</pre>
      <div v-if="folder !== 'gesendet'" class="actions">
        <textarea id="xp-reply" class="input" v-model="reply" rows="3" placeholder="Antwort schreiben …"></textarea>
        <div class="toolbar">
          <button class="btn btn-sm btn-primary" @click="send"><Reply :size="14" /> Antworten</button>
          <button class="btn btn-sm" @click="remove(selected)"><Trash2 :size="14" /> Löschen</button>
          <span class="faint small"><Star :size="12" /> Spaß-Postfach: Antworten landen nur unter „Gesendet“.</span>
        </div>
      </div>
    </article>
  </div>
</template>

<style scoped>
.mail { display: grid; grid-template-columns: 170px minmax(200px, 1fr) minmax(0, 1.4fr); min-height: 100%; background: var(--surface); }
@media (max-width: 760px) { .mail { grid-template-columns: 1fr; } }
.folders { display: flex; flex-direction: column; gap: 2px; padding: 8px; border-right: 1px solid var(--border); background: var(--bg); }
.folders button { display: flex; align-items: center; gap: 6px; padding: 8px; border: 0; background: none; text-align: left; border-radius: 3px; min-height: 40px; }
.folders button.on, .folders button:hover { background: var(--accent-soft); }
.list { border-right: 1px solid var(--border); overflow: auto; }
.row { display: grid; grid-template-columns: 1fr auto; gap: 2px 8px; width: 100%; padding: 8px 10px; border: 0; border-bottom: 1px solid var(--border); background: none; text-align: left; min-height: 52px; }
.row:hover { background: var(--hover); }
.row.on { background: var(--accent-soft); }
.row.unread .from, .row.unread .subj { font-weight: 700; }
.subj { grid-column: 1 / -1; font-size: 0.85rem; color: var(--text-2); }
.read { padding: 14px; display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.read h2 { margin: 0; font-size: 1.05rem; }
.body { white-space: pre-wrap; font-family: inherit; margin: 0; padding: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 4px; }
.actions { display: flex; flex-direction: column; gap: 8px; }
.actions textarea { height: auto; padding: 8px; }
.pad { padding: 10px; }
</style>
