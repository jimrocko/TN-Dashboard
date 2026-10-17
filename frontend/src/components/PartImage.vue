<script setup>
import { computed, ref, watch } from 'vue'
import { Shapes } from 'lucide-vue-next'

// Teilebild: zuerst das Bild der Erkennung (src), sonst BrickLink (mit Farbe, falls bekannt).
// Ohne Internet oder bei unbekannten Teilen erscheint ein Platzhalter.
// type: BrickLink-Art (PART, MINIFIG, SET …); Minifiguren und Sets haben eigene Bildpfade ohne Farbe.
const props = defineProps({ no: String, color: Number, type: String, src: String, size: { type: Number, default: 64 } })
const failed = ref(false)
const OTHER = { MINIFIG: 'MN', M: 'MN', SET: 'SN', S: 'SN', GEAR: 'GN', BOOK: 'BN', INSTRUCTION: 'IN', I: 'IN' }
const url = computed(() => {
  if (props.src) return props.src
  const no = encodeURIComponent(props.no ?? '')
  const other = OTHER[String(props.type ?? '').toUpperCase()]
  if (other) return `https://img.bricklink.com/ItemImage/${other}/0/${no}.png`
  return props.color !== undefined && props.color !== null
    ? `https://img.bricklink.com/ItemImage/PN/${props.color}/${no}.png`
    : `https://img.bricklink.com/ItemImage/PL/${no}.png`
})
watch(url, () => { failed.value = false })
</script>

<template>
  <span class="pimg" :style="{ width: `${size}px`, height: `${size}px` }">
    <img v-if="!failed" :src="url" alt="" loading="lazy" referrerpolicy="no-referrer" @error="failed = true" />
    <Shapes v-else :size="Math.round(size * 0.45)" class="faint" />
  </span>
</template>

<style scoped>
.pimg { display: grid; place-items: center; background: #fff; border-radius: var(--radius-sm); border: 1px solid var(--border); overflow: hidden; flex: none; }
.pimg img { width: 100%; height: 100%; object-fit: contain; }
</style>
