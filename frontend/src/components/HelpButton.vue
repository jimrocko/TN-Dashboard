<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { CircleHelp, Lightbulb, GraduationCap } from 'lucide-vue-next'
import { IS_TUTORIAL } from '../api'
import { useSettings } from '../stores/settings'
import { openHelp, tutorialUrl } from '../tutorial/help'

// Knopf „Hilfe“ (Wunsch des Inhabers): Klemmi erklärt nur diesen Bereich oder die große Rundführung (Tutorial).
// Nicht im Tutorial (dort läuft schon die Tour) und nicht im XP-Stil.
const props = defineProps({ topic: String, align: { type: String, default: 'right' } })
const settings = useSettings()
const open = ref(false)
const root = ref(null)
const explain = () => { open.value = false; openHelp(props.topic) }
const onDoc = (e) => { if (!root.value?.contains(e.target)) open.value = false }
watch(open, (v) => (v ? document.addEventListener('pointerdown', onDoc) : document.removeEventListener('pointerdown', onDoc)))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDoc))
</script>

<template>
  <div v-if="!IS_TUTORIAL && settings.style !== 'xp'" ref="root" class="helpwrap">
    <button type="button" class="helpbtn" :aria-expanded="open" aria-haspopup="menu" @click="open = !open">
      <CircleHelp :size="18" /> Hilfe
    </button>
    <div v-if="open" class="helpmenu panel" :class="align" role="menu">
      <button v-if="topic" type="button" class="item" role="menuitem" @click="explain">
        <Lightbulb :size="18" /><span><b>Diesen Bereich erklären</b><small>Klemmi zeigt kurz, was hier wofür ist.</small></span>
      </button>
      <a :href="tutorialUrl" target="_blank" rel="noopener" class="item" role="menuitem" @click="open = false">
        <GraduationCap :size="18" /><span><b>Rundführung durch die ganze App</b><small>Das Tutorial mit Übungsdaten, in neuem Tab.</small></span>
      </a>
    </div>
  </div>
</template>

<style scoped>
.helpwrap { position: relative; display: inline-flex; }
.helpbtn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 14px; border-radius: 17px; border: 1px solid var(--border-strong); background: var(--surface); color: var(--text-2); font-size: 0.9rem; }
.helpbtn:hover, .helpbtn[aria-expanded='true'] { color: var(--text); border-color: var(--accent-text); }
.helpmenu { position: absolute; top: calc(100% + 8px); z-index: 30; width: min(320px, calc(100vw - 32px)); padding: 6px; box-shadow: var(--shadow); }
.helpmenu.right { right: 0; }
.helpmenu.center { left: 50%; transform: translateX(-50%); top: auto; bottom: calc(100% + 8px); } /* Startseite: nach oben, über der Leiste unten */
.item { display: flex; gap: 12px; align-items: flex-start; width: 100%; padding: 10px 12px; border-radius: 8px; border: 0; background: none; color: var(--text); text-align: left; text-decoration: none; }
.item:hover { background: var(--hover); }
.item svg { flex: none; margin-top: 2px; color: var(--accent-text); }
.item span { display: flex; flex-direction: column; gap: 2px; }
.item small { color: var(--text-3); font-size: 0.8rem; }
</style>
