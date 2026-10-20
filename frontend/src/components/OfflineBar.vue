<script setup>
import { computed } from 'vue'
import { WifiOff, RefreshCw, TriangleAlert, X } from 'lucide-vue-next'
import { offline, flush, dismissProblems } from '../utils/offline'

// Hinweis bei schlechtem WLAN: offline, wartende Änderungen, vom Server abgelehnte Änderungen (siehe utils/offline.js)
// low: ohne Leiste unten (z. B. Anmeldung) sitzt der Hinweis ganz unten
defineProps({ low: Boolean })
const waiting = computed(() => offline.queue.length)
const text = (n) => (n === 1 ? '1 Änderung' : `${n} Änderungen`)
</script>

<template>
  <div v-if="!offline.online || waiting || offline.problems.length" class="offbar" :class="{ low }" role="status">
    <div v-if="!offline.online || waiting" class="line" :class="{ off: !offline.online }">
      <WifiOff v-if="!offline.online" :size="16" /><RefreshCw v-else :size="16" :class="{ spin: offline.syncing }" />
      <span v-if="!offline.online"><strong>Offline</strong><template v-if="waiting"> · {{ text(waiting) }} warten und werden automatisch geschickt</template><template v-else> · zuletzt geladene Daten</template></span>
      <span v-else>{{ text(waiting) }} werden übertragen …</span>
      <button v-if="waiting && !offline.syncing" class="btn btn-sm btn-ghost" @click="flush()">Jetzt senden</button>
    </div>
    <div v-if="offline.problems.length" class="line warn">
      <TriangleAlert :size="16" />
      <span><strong>Nicht übernommen</strong> (inzwischen von jemand anderem geändert):
        {{ offline.problems.map((p) => `${p.label || 'Änderung'}: ${p.message}`).join(' · ') }}</span>
      <button class="btn btn-sm btn-ghost" title="Hinweis schließen" @click="dismissProblems"><X :size="15" /></button>
    </div>
  </div>
</template>

<style scoped>
.offbar { position: fixed; left: 50%; transform: translateX(-50%); bottom: calc(92px + env(safe-area-inset-bottom, 0px)); z-index: 40;
  width: min(720px, calc(100% - 24px)); display: flex; flex-direction: column; gap: 4px; filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.35)); }
.offbar.low { bottom: calc(14px + env(safe-area-inset-bottom, 0px)); }
.line { display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: var(--radius-sm); font-size: 0.88rem;
  background: color-mix(in srgb, var(--accent) 16%, var(--surface)); border: 1px solid color-mix(in srgb, var(--accent) 40%, var(--border)); }
.line.off { background: color-mix(in srgb, var(--warn) 16%, var(--surface)); border-color: color-mix(in srgb, var(--warn) 50%, var(--border)); }
.line.warn { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); border-color: color-mix(in srgb, var(--danger) 50%, var(--border)); }
.line span { flex: 1; min-width: 0; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
