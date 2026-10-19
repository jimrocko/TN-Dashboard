<script setup>
import { computed, ref, watch } from 'vue'
import { Shapes } from 'lucide-vue-next'

// Teilebild: zuerst das Bild der Erkennung bzw. des Auftrags (src), sonst BrickLink (mit Farbe, falls bekannt).
// Scheitert ein Bild, kommt die nächste Adresse dran (z. B. Minifigur: BrickOwl-Bild → BrickLink MN → altes BrickLink-Bild).
// Ohne Internet oder bei unbekannten Teilen erscheint ein Platzhalter.
// type: BrickLink-Art (PART, MINIFIG, SET …); Minifiguren und Sets haben eigene Bildpfade ohne Farbe.
// eager: sofort laden. In aufklappbaren Teilelisten nötig, dort lädt Safari (iPad) Bilder mit loading="lazy" oft nie.
const props = defineProps({ no: String, color: Number, type: String, src: String, size: { type: Number, default: 64 }, eager: Boolean })
const OTHER = { MINIFIG: 'MN', M: 'MN', SET: 'SN', S: 'SN', GEAR: 'GN', BOOK: 'BN', INSTRUCTION: 'IN', I: 'IN' }
const urls = computed(() => {
  const no = encodeURIComponent(props.no ?? '')
  const other = OTHER[String(props.type ?? '').toUpperCase()]
  const list = [props.src]
  if (!no) return list.filter(Boolean)
  if (other) list.push(`https://img.bricklink.com/ItemImage/${other}/0/${no}.png`, `https://img.bricklink.com/${other[0]}/${no}.jpg`)
  else if (props.color !== undefined && props.color !== null) list.push(`https://img.bricklink.com/ItemImage/PN/${props.color}/${no}.png`)
  else list.push(`https://img.bricklink.com/ItemImage/PL/${no}.png`)
  return [...new Set(list.filter(Boolean))]
})
const tries = ref(0)
const url = computed(() => urls.value[tries.value] ?? null)
watch(urls, () => { tries.value = 0 })
</script>

<template>
  <span class="pimg" :style="{ width: `${size}px`, height: `${size}px` }">
    <img v-if="url" :key="url" :src="url" alt="" :loading="eager ? 'eager' : 'lazy'" referrerpolicy="no-referrer" @error="tries++" />
    <Shapes v-else :size="Math.round(size * 0.45)" class="faint" />
  </span>
</template>

<style scoped>
.pimg { display: grid; place-items: center; background: #fff; border-radius: var(--radius-sm); border: 1px solid var(--border); overflow: hidden; flex: none; }
.pimg img { width: 100%; height: 100%; object-fit: contain; }
</style>
