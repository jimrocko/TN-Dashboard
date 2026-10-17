<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import SegControl from '../../components/SegControl.vue'
import { api, IS_DEMO } from '../../api'
import { num, dateTime } from '../../utils/format'
import { useAuth } from '../../stores/auth'
import { devFlags, resetDevFlags } from '../../utils/devtools'
import { appSettings, resetAppSettings, isChanged } from '../../utils/appSettings'
import { homeModules } from '../../modules'
import { resetDemo } from '../../demo/backend'

// Einstellungen für Entwickler: Debug-Hilfen und lokale Daten. Der Stil ist hier bewusst nicht umschaltbar.
const router = useRouter()
const auth = useAuth()
const msg = ref('')
const err = ref('')

const say = (m) => { msg.value = m; err.value = ''; setTimeout(() => { if (msg.value === m) msg.value = '' }, 3000) }

const delays = [{ value: 0, label: 'Aus' }, { value: 300, label: '0,3 s' }, { value: 1000, label: '1 s' }, { value: 3000, label: '3 s' }]
const onOff = [{ value: true, label: 'An' }, { value: false, label: 'Aus' }]

function clearLocal(what) {
  try {
    if (what === 'scan') { sessionStorage.removeItem('tn-last-recognition'); say('Letzte Teileerkennung gelöscht.') }
    if (what === 'settings') { resetDevFlags(); say('Dev-Optionen zurückgesetzt.') }
  } catch { err.value = 'Browser-Speicher nicht verfügbar.' }
}
async function clearCaches() {
  try {
    const regs = (await navigator.serviceWorker?.getRegistrations?.()) ?? []
    await Promise.all(regs.map((r) => r.unregister()))
    const keys = (await caches?.keys?.()) ?? []
    await Promise.all(keys.map((k) => caches.delete(k)))
    say(`${regs.length} Service Worker und ${keys.length} Caches entfernt. Beim nächsten Laden wird alles neu geholt.`)
  } catch (e) { err.value = e.message }
}
// Einstellungen je Bereich: Reihenfolge wie auf der Startseite (nur Bereiche mit Kachel), externe Programme ohne eigene Einstellungen.
const n = (v) => v.toLocaleString('de-DE')
const appOptions = {
  inventar: [
    { key: 'limit', k: 'Lots pro Abruf', h: 'So viele Lots lädt die Liste höchstens. Mehr ist langsamer auf dem iPad.', options: [100, 500, 1000, 2000].map((v) => ({ value: v, label: n(v) })) },
    { key: 'sort', k: 'Sortierung beim Öffnen', h: 'Auch der Knopf „Zurücksetzen“ springt hierhin.', options: [{ value: 'item', label: 'Teil-Nr.' }, { value: 'location', label: 'Lagerort' }, { value: 'qty', label: 'Menge' }, { value: 'updated', label: 'Zuletzt geändert' }] },
    { key: 'plan', k: 'Lagerplan beim Öffnen', h: '„Merken“ = wie zuletzt auf diesem Gerät.', options: [{ value: 'merken', label: 'Merken' }, { value: 'offen', label: 'Offen' }, { value: 'zu', label: 'Zu' }] },
  ],
  umlagern: [
    { key: 'page', k: 'Vorschläge pro Seite', h: 'So viele Zeilen zeigt die Liste, bevor „Weitere anzeigen“ kommt.', options: [15, 30, 60, 100].map((v) => ({ value: v, label: String(v) })) },
    { key: 'reason', k: 'Filter beim Öffnen', h: 'Welche Vorschläge zuerst zu sehen sind.', options: [{ value: 'alle', label: 'Alle' }, { value: 'falsch', label: 'Falsch einsortiert' }, { value: 'farben', label: 'Farben' }, { value: 'art', label: 'Teile-Art' }] },
    { key: 'history', k: 'Letzte Umlagerungen', h: 'Länge der Liste unten auf der Seite.', options: [15, 30, 50].map((v) => ({ value: v, label: String(v) })) },
    { key: 'plan', k: 'Lagerplan anzeigen', h: 'Raumplan mit roten Mischkisten zwischen Vorschlägen und Umbuchen.', options: onOff },
    { key: 'popup', k: 'BrickLink-Pop-up nach dem Umlagern', h: 'Aus = kein Pop-up, die Übertragung steht nur unten auf der Seite.', options: onOff },
  ],
  inventur: [
    { key: 'blind', k: 'Blind zählen', h: 'Soll-Menge und Abweichungen bleiben verborgen, bis die Inventur abgeschlossen ist.', options: onOff },
  ],
  analyse: [
    { key: 'metric', k: 'Kennzahl „Bestand nach Kategorie“', h: 'Was die Balken beim Öffnen zeigen.', options: [{ value: 'qty', label: 'Teile' }, { value: 'value', label: 'Lagerwert' }, { value: 'lots', label: 'Lots' }] },
  ],
}
const appModules = homeModules.filter((m) => appOptions[m.id] || m.status === 'extern')

// Ollies Dashboard auf demselben Rechner: Bestand aus seiner Datenbank übernehmen (nur lesend, kein BrickLink-Abruf).
const ollie = ref(null)
const ollieBusy = ref(false)
const ollieConfirm = ref(null) // 'refresh' oder 'import', solange die Rückfrage offen ist
const ollieNewer = () => !!ollie.value?.ollie_synced_at && (!ollie.value.imported_at || ollie.value.ollie_synced_at > ollie.value.imported_at)
async function loadOllie() {
  try { ollie.value = await api.get('ollie/status') } catch (e) { ollie.value = { configured: false, error: e.message } }
}
// 'refresh' = wie Ollies Knopf „BrickLink-Inventar“ (sein Dashboard lädt neu von BrickLink), dann übernehmen;
// 'import' = nur seinen letzten Stand übernehmen
async function importOllie(kind) {
  ollieBusy.value = kind
  ollieConfirm.value = null
  err.value = ''
  try {
    const r = await api.post(`ollie/${kind}`)
    say(`${kind === 'refresh' ? 'Bei BrickLink neu geladen und übernommen' : 'Bestand aus Ollies Dashboard übernommen'}: ${num(r.lots)} Lots (${num(r.new)} neu, ${num(r.removed)} entfernt).`)
    await loadOllie()
  } catch (e) { err.value = e.message } finally { ollieBusy.value = null }
}
onMounted(loadOllie)
const ollieActions = [
  { kind: 'refresh', k: 'Bei BrickLink neu laden', btn: 'Neu laden', busy: 'Lädt bei BrickLink …',
    h: 'Wie Ollies Knopf „BrickLink-Inventar“: Sein Dashboard holt den Bestand frisch von BrickLink (ein Abruf, zählt bei ihm als geladen), danach übernehmen wir ihn. Dauert bis zu einer Minute.' },
  { kind: 'import', k: 'Letzten Stand übernehmen', btn: 'Übernehmen', busy: 'Übernehme …',
    h: 'Ohne neuen BrickLink-Abruf: nimmt den Stand, den Ollies Dashboard zuletzt geholt hat (automatisch alle 4 Stunden).' },
]

const confirmDemo = ref(false)
async function resetDemoData() {
  resetDemo()
  auth.user = null
  auth.checked = false
  router.push('/login')
}

</script>

<template>
  <div class="stack narrow">
    <p v-if="msg" class="success">{{ msg }}</p>
    <p v-if="err" class="error">{{ err }}</p>

    <section class="panel">
      <div class="panel-head"><h2>Debug-Hilfen</h2><span class="faint small">nur dieses Gerät</span></div>
      <div class="rows">
        <div class="row">
          <div><div class="k">Debug-Anzeige</div><div class="h">Kleine Leiste unten links mit Adresse, Fenstergröße, Farbschema und letztem API-Aufruf.</div></div>
          <SegControl v-model="devFlags.overlay" :options="onOff" label="Debug-Anzeige" />
        </div>
        <div class="row">
          <div><div class="k">Langsames Netz simulieren</div><div class="h">Jeder API-Aufruf wartet zusätzlich. Gut, um Ladeanzeigen zu prüfen.</div></div>
          <SegControl v-model="devFlags.delay" :options="delays" label="Verzögerung" />
        </div>
        <div class="row">
          <div><div class="k">API-Aufrufe mitschreiben</div><div class="h">Liste unter „API“. Bleibt nur bis zum Neuladen.</div></div>
          <SegControl v-model="devFlags.logApi" :options="onOff" label="API-Protokoll" />
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Bereiche</h2>
        <span class="faint small">nur dieses Gerät · gilt beim nächsten Öffnen des Bereichs</span>
      </div>
      <div v-for="m in appModules" :key="m.id" class="app">
        <div class="app-head">
          <component :is="m.icon" :size="18" class="app-icon" :style="{ color: m.xpColor }" />
          <h3>{{ m.title }}</h3>
          <button v-if="appOptions[m.id] && isChanged(m.id)" class="btn btn-sm btn-ghost" type="button" @click="resetAppSettings(m.id)">Standard</button>
        </div>
        <div v-if="!appOptions[m.id]" class="row"><div class="h">Eigenes Programm. Einstellungen kommen mit der Einbindung.</div></div>
        <div v-for="o in appOptions[m.id]" v-else :key="o.key" class="row">
          <div><div class="k">{{ o.k }}</div><div class="h">{{ o.h }}</div></div>
          <SegControl v-model="appSettings[m.id][o.key]" :options="o.options" :label="`${m.title}: ${o.k}`" />
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Ollies Dashboard</h2><span class="faint small">gleicher Rechner · seine Daten werden nie verändert</span></div>
      <div class="rows">
        <div class="row">
          <div>
            <div class="k">Verbindung</div>
            <div v-if="!ollie" class="h">Wird geprüft …</div>
            <div v-else-if="ollie.demo" class="h">In der Demo nicht vorhanden. Auf dem Schulserver liest die App hier Ollies Datenbank (data/legoshop.db).</div>
            <div v-else-if="!ollie.configured" class="h">Noch nicht eingerichtet: in der .env <code>OLLIE_DB_PATH=</code> mit dem Pfad zu Ollies <code>data/legoshop.db</code> eintragen.</div>
            <div v-else-if="ollie.ok" class="h">{{ num(ollie.lots) }} Lots · {{ num(ollie.parts) }} Teile · Ollie hat zuletzt {{ dateTime(ollie.ollie_synced_at) }} von BrickLink geholt.</div>
            <div v-else class="h error">{{ ollie.error }}</div>
          </div>
          <span v-if="ollie && !ollie.demo" class="pill" :class="ollie.ok ? 'ok' : ''">{{ ollie.ok ? 'Verbunden' : ollie.configured ? 'Fehler' : 'Aus' }}</span>
        </div>
        <div v-for="o in ollieActions" :key="o.kind" class="row">
          <div>
            <div class="k">{{ o.k }}</div>
            <div class="h">{{ o.h }} Unser Lager wird dabei durch Ollies Stand ersetzt (per Lot-ID, Lagerort aus den Remarks); noch nicht übertragene Umlagerungen und Inventuren werden verworfen.
              <template v-if="o.kind === 'import' && ollie?.imported_at"> Zuletzt übernommen: {{ dateTime(ollie.imported_at) }}.</template>
              <b v-if="o.kind === 'import' && ollie?.ok && ollieNewer()"> Ollies Stand ist neuer.</b></div>
          </div>
          <div class="inline">
            <span v-if="!auth.isStaff" class="faint small">nur Lehrer</span>
            <button v-else-if="ollieConfirm !== o.kind" class="btn btn-sm" :class="{ 'btn-primary': o.kind === 'refresh' }" :disabled="!!ollieBusy || (!ollie?.ok && !IS_DEMO)" @click="ollieConfirm = o.kind">{{ ollieBusy === o.kind ? o.busy : o.btn }}</button>
            <template v-else>
              <button class="btn btn-sm danger" @click="importOllie(o.kind)">Ja, {{ o.btn.toLowerCase() }}</button>
              <button class="btn btn-sm btn-ghost" @click="ollieConfirm = null">Abbrechen</button>
            </template>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Lokale Daten</h2></div>
      <div class="rows">
        <div class="row">
          <div><div class="k">Letzte Teileerkennung</div><div class="h">Bild, Treffer und Alternativen des Teile-Scanners.</div></div>
          <button class="btn btn-sm" @click="clearLocal('scan')">Löschen</button>
        </div>
        <div class="row">
          <div><div class="k">Dev-Optionen</div><div class="h">Debug-Anzeige, Verzögerung und API-Protokoll auf Standard.</div></div>
          <button class="btn btn-sm" @click="clearLocal('settings')">Zurücksetzen</button>
        </div>
        <div class="row">
          <div><div class="k">Einstellungen der Bereiche</div><div class="h">Alle Einstellungen unter „Bereiche“ auf Standard.</div></div>
          <button class="btn btn-sm" @click="resetAppSettings(); say('Einstellungen der Bereiche zurückgesetzt.')">Zurücksetzen</button>
        </div>
        <div class="row">
          <div><div class="k">App-Cache</div><div class="h">Service Worker und gespeicherte Dateien entfernen, falls eine alte Version hängt.</div></div>
          <button class="btn btn-sm" @click="clearCaches">Leeren</button>
        </div>
        <div v-if="IS_DEMO" class="row">
          <div><div class="k">Demo-Daten</div><div class="h">Lager, Aufträge, Protokoll und Konten auf den Anfang zurücksetzen. Danach neu anmelden.</div></div>
          <div class="inline">
            <button v-if="!confirmDemo" class="btn btn-sm" @click="confirmDemo = true">Zurücksetzen</button>
            <template v-else>
              <button class="btn btn-sm danger" @click="resetDemoData">Ja, zurücksetzen</button>
              <button class="btn btn-sm btn-ghost" @click="confirmDemo = false">Abbrechen</button>
            </template>
          </div>
        </div>
      </div>
    </section>

  </div>
</template>

<style scoped>
.narrow { max-width: 900px; }
.rows { display: flex; flex-direction: column; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px var(--pad); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.row:last-child { border-bottom: 0; }
.row > div:first-child { flex: 1; min-width: 220px; }
.k { font-weight: 600; }
.h { color: var(--text-3); font-size: 0.85rem; margin-top: 2px; }
.inline { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.short { width: 120px; }
.app { border-bottom: 1px solid var(--border); }
.app:last-child { border-bottom: 0; }
.app .row { border-bottom: 0; padding-top: 8px; padding-bottom: 8px; padding-left: calc(var(--pad) + 26px); }
.app-head { display: flex; align-items: center; gap: 8px; padding: 14px var(--pad) 4px; }
.app-head h3 { margin: 0; font-size: 0.98rem; flex: 1; }
.app-icon { flex: none; }
.danger { color: var(--danger); border-color: var(--danger); }
</style>
