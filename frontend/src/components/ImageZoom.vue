<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { X, ExternalLink, ArrowRight } from 'lucide-vue-next'
import PartImage from './PartImage.vue'
import { zoom, closeZoom, catalogUrl, hasPartPage } from '../utils/imageZoom'
import { htmlText } from '../utils/format'

// Großansicht für jedes Teile- und Figurenbild: Bild groß, Nummer, Name, weiter zur Teile-Seite oder zu BrickLink.
// Schließen: X, Esc, Tippen daneben oder Seitenwechsel.
const route = useRoute()
const it = computed(() => zoom.item)
const size = computed(() => Math.max(160, Math.min(360, Math.floor(window.innerWidth - 80), Math.floor(window.innerHeight - 260))))
const partPath = computed(() => (it.value && hasPartPage(it.value.type) ? `/teil/${encodeURIComponent(it.value.no)}` : null))
const onPart = computed(() => partPath.value && decodeURIComponent(route.path) === `/teil/${it.value.no}`)
const key = (e) => { if (e.key === 'Escape' && zoom.item) { e.stopPropagation(); closeZoom() } }
onMounted(() => window.addEventListener('keydown', key, true))
onBeforeUnmount(() => window.removeEventListener('keydown', key, true))
watch(() => route.fullPath, () => closeZoom())
</script>

<template>
  <Teleport to="body">
    <div v-if="it" class="zoombg" @click.self="closeZoom">
      <div class="zoombox" role="dialog" aria-modal="true" :aria-label="`Bild ${it.no}`">
        <button class="btn btn-ghost btn-sm close" aria-label="Schließen" @click="closeZoom"><X :size="18" /></button>
        <PartImage :no="it.no" :color="it.color" :type="it.type" :src="it.src" :size="size" eager no-zoom />
        <div class="info">
          <strong class="mono">{{ it.no }}</strong>
          <span v-if="it.colorName || (it.color !== undefined && it.color !== null)" class="small muted">{{ it.colorName ?? `Farbe ${it.color}` }}</span>
          <div v-if="it.name" class="small">{{ htmlText(it.name) }}</div>
        </div>
        <div class="acts">
          <RouterLink v-if="partPath && !onPart" class="btn btn-primary" :to="partPath" @click="closeZoom">Teile-Seite öffnen <ArrowRight :size="15" /></RouterLink>
          <a class="btn" :href="catalogUrl(it)" target="_blank" rel="noopener">Bei BrickLink ansehen <ExternalLink :size="14" /></a>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.zoombg { position: fixed; inset: 0; z-index: 9500; background: rgba(0, 0, 0, 0.6); display: grid; place-items: center; padding: 16px; }
.zoombox { position: relative; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 44px 18px 18px; display: flex; flex-direction: column; align-items: center; gap: 12px; max-width: min(440px, 100%); box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4); }
.close { position: absolute; top: 6px; right: 6px; }
.info { text-align: center; display: flex; flex-direction: column; gap: 2px; }
.acts { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
</style>
