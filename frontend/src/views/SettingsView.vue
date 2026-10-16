<script setup>
import { onMounted, ref } from 'vue'
import { Moon, Sun, Monitor, GraduationCap, CircleHelp } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import SegControl from '../components/SegControl.vue'
import { useAuth } from '../stores/auth'
import { useSettings } from '../stores/settings'
import { api, IS_DEMO, IS_TUTORIAL } from '../api'
import { tutorialUrl } from '../tutorial/help'

const auth = useAuth()
const s = useSettings()
const bl = ref(null)
onMounted(async () => {
  try { bl.value = (await api.get('dashboard')).bricklink.configured } catch { bl.value = false }
})

const themes = [
  { value: 'dark', label: 'Dunkel', icon: Moon },
  { value: 'light', label: 'Hell', icon: Sun },
  { value: 'system', label: 'Wie System', icon: Monitor },
]
const densities = [{ value: 'comfortable', label: 'Komfortabel' }, { value: 'compact', label: 'Kompakt' }]
const fonts = [{ value: 'normal', label: 'Normal' }, { value: 'large', label: 'Groß' }]
// Tutorial mit Klemmi (Wunsch des Inhabers): eigene Datei mit Übungsdaten, öffnet in neuem Tab (Adresse in help.js).
const motion = [{ value: false, label: 'An' }, { value: true, label: 'Aus' }]
</script>

<template>
  <div class="page stack narrow">
    <PageHead title="Einstellungen" sub="Darstellung gilt für dieses Gerät und wird im Browser gespeichert." />

    <section class="panel">
      <div class="panel-head"><h2>Darstellung</h2><button class="btn btn-ghost btn-sm" @click="s.set({ theme: 'dark', density: 'comfortable', fontSize: 'normal', reduceMotion: false })">Zurücksetzen</button></div>
      <div class="rows">
        <div class="row">
          <div><div class="k">Design</div><div class="h">{{ s.style === 'xp' ? 'Hell = blaue Luna-Optik, Dunkel = schwarze Variante.' : 'Dunkel ist Standard.' }} „Wie System“ folgt dem Gerät.</div></div>
          <SegControl :model-value="s.theme" :options="themes" label="Design" @update:model-value="s.set({ theme: $event })" />
        </div>
        <div class="row">
          <div><div class="k">Dichte</div><div class="h">Kompakt zeigt mehr Zeilen in Tabellen.</div></div>
          <SegControl :model-value="s.density" :options="densities" label="Dichte" @update:model-value="s.set({ density: $event })" />
        </div>
        <div class="row">
          <div><div class="k">Schriftgröße</div><div class="h">Größere Schrift für Tablets und Lesbarkeit.</div></div>
          <SegControl :model-value="s.fontSize" :options="fonts" label="Schriftgröße" @update:model-value="s.set({ fontSize: $event })" />
        </div>
        <div class="row">
          <div><div class="k">Animationen</div><div class="h">Übergänge, z. B. beim Öffnen des Menüs.</div></div>
          <SegControl :model-value="s.reduceMotion" :options="motion" label="Animationen" @update:model-value="s.set({ reduceMotion: $event })" />
        </div>
      </div>
    </section>

    <section v-if="!IS_TUTORIAL" class="panel">
      <div class="panel-head"><h2>Hilfe</h2></div>
      <div class="rows">
        <div class="row">
          <div><div class="k">Tutorial mit Klemmi</div><div class="h">Geführte Tour durch die ganze App, mit Übungsdaten. Öffnet sich in einem neuen Tab, echte Daten bleiben unberührt.</div></div>
          <a class="btn btn-primary" :href="tutorialUrl" target="_blank" rel="noopener"><GraduationCap :size="16" /> Tutorial starten</a>
        </div>
        <div class="row">
          <div><div class="k">Hilfe in jedem Bereich</div><div class="h">„<CircleHelp :size="14" class="inl" /> Hilfe“ oben rechts in jedem Bereich (und unten auf der Startseite): Klemmi erklärt kurz nur diesen Bereich oder startet die Rundführung.</div></div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Konto</h2></div>
      <div class="rows">
        <div class="row"><div class="k">Name</div><div>{{ auth.user.display_name }}</div></div>
        <div class="row"><div class="k">Benutzername</div><div class="mono">{{ auth.user.username }}</div></div>
        <div class="row"><div class="k">Rolle</div><div>{{ auth.roleLabel }}</div></div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Verbindungen</h2></div>
      <div class="rows">
        <div class="row">
          <div class="k">BrickLink-API</div>
          <span v-if="bl === null" class="faint">…</span>
          <span v-else class="pill" :class="bl ? 'ok' : ''">{{ bl ? 'Verbunden' : 'Nicht eingerichtet' }}</span>
        </div>
        <div class="row">
          <div class="k">Betrieb</div>
          <span class="pill" :class="IS_DEMO ? 'warn' : 'ok'">{{ IS_DEMO ? 'Demo (Daten nur im Browser)' : 'Schulserver' }}</span>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.narrow { max-width: 900px; }
.inl { vertical-align: -2px; }
.rows { display: flex; flex-direction: column; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px var(--pad); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.row:last-child { border-bottom: 0; }
.k { font-weight: 500; font-size: 0.92rem; }
.h { font-size: 0.83rem; color: var(--text-3); margin-top: 2px; }
</style>
