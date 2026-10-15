<script setup>
import { computed, ref, watch } from 'vue'
import { Shapes } from 'lucide-vue-next'

// Teilebild: zuerst das Bild der Erkennung (src), sonst BrickLink (mit Farbe, falls bekannt).
// Ohne Internet oder bei unbekannten Teilen erscheint ein Platzhalter.
const props = defineProps({ no: String, color: Number, src: String, size: { type: Number, default: 64 } })
const failed = ref(false)
const url = computed(() => props.src
  || (props.color !== undefined && props.color !== null
    ? `https://img.bricklink.com/ItemImage/PN/${props.color}/${encodeURIComponent(props.no)}.png`
    : `https://img.bricklink.com/ItemImage/PL/${encodeURIComponent(props.no)}.png`))
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
