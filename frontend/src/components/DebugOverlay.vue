<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { devFlags, apiLog } from '../utils/devtools'
import { useSettings } from '../stores/settings'

// Debug-Anzeige (im Dev-Bereich einschaltbar): Adresse, Fenstergröße, Stil und letzter API-Aufruf.
const route = useRoute()
const settings = useSettings()
const size = ref('')
const measure = () => { size.value = `${innerWidth}×${innerHeight}` }
onMounted(() => { measure(); addEventListener('resize', measure) })
onBeforeUnmount(() => removeEventListener('resize', measure))
</script>

<template>
  <div v-if="devFlags.overlay" class="dbg mono" aria-hidden="true">
    <span>{{ route.fullPath }}</span>
    <span>{{ size }} · {{ settings.resolvedTheme }}</span>
    <span v-if="apiLog[0]" :class="{ bad: apiLog[0].status >= 400 || !apiLog[0].status }">{{ apiLog[0].route }} {{ apiLog[0].status || 'Fehler' }} · {{ apiLog[0].ms }} ms</span>
    <span v-if="devFlags.delay">+{{ devFlags.delay }} ms Verzögerung</span>
  </div>
</template>

<style scoped>
.dbg {
  position: fixed; z-index: 100; pointer-events: none;
  left: calc(8px + env(safe-area-inset-left, 0px)); bottom: calc(52px + env(safe-area-inset-bottom, 0px));
  display: flex; flex-direction: column; gap: 2px; padding: 6px 9px; border-radius: 6px;
  background: rgba(0, 0, 0, 0.78); color: #d9f99d; font-size: 11px; line-height: 1.35; max-width: min(420px, 90vw);
}
.dbg span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bad { color: #fca5a5; }
</style>
