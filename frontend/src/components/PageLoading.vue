<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { LoaderCircle } from 'lucide-vue-next'
import { pageLoading } from '../utils/pageLoading'

// Gemeinsame Ladeanzeige für alle Bereiche: erscheint erst nach 0,3 s (kein Flackern bei schnellen Seiten).
// Bereiche mit eigener Anzeige setzen meta.ownLoader; meta.loadingText ändert den Text (Minifig-Finder rechnet länger).
const route = useRoute()
const shown = ref(false)
let timer = null
const text = computed(() => route.meta.loadingText ?? 'Wird geladen …')
watch(() => pageLoading.pending > 0 && !route.meta.ownLoader, (busy) => {
  clearTimeout(timer)
  if (busy) timer = setTimeout(() => { shown.value = true }, 300)
  else shown.value = false
})
</script>

<template>
  <Transition name="fade">
    <div v-if="shown" class="pageload" role="status" aria-live="polite"><LoaderCircle :size="20" class="spin" /> {{ text }}</div>
  </Transition>
</template>

<style scoped>
.pageload {
  position: fixed; left: 50%; top: 96px; transform: translateX(-50%); z-index: 40;
  display: flex; align-items: center; gap: 10px; padding: 10px 18px; border-radius: 999px;
  background: var(--surface); border: 1px solid var(--border-strong); box-shadow: var(--shadow); color: var(--text-2); font-size: 0.95rem;
}
.spin { animation: spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
