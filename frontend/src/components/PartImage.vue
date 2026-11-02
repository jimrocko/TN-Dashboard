<script setup>
import { computed, ref, watch } from 'vue'
import { Shapes } from 'lucide-vue-next'
import { openZoom } from '../utils/imageZoom'

// Teilebild: zuerst das Bild der Erkennung bzw. des Auftrags (src), sonst BrickLink (mit Farbe, falls bekannt).
// Scheitert ein Bild, kommt die nächste Adresse dran (z. B. Minifigur: BrickOwl-Bild → BrickLink MN → altes BrickLink-Bild).
// Ohne Internet oder bei unbekannten Teilen erscheint ein Platzhalter.
// type: BrickLink-Art (PART, MINIFIG, SET …); Minifiguren und Sets haben eigene Bildpfade ohne Farbe.
// Antippen öffnet die Großansicht (ImageZoom.vue) mit Nummer, Name und Links; noZoom schaltet das ab
// (z. B. wenn das Bild selbst in einem Link oder Knopf mit eigener Aufgabe steckt). name/colorName nur für die Großansicht.
// eager: sofort laden. In aufklappbaren Teilelisten nötig, dort lädt Safari (iPad) Bilder mit loading="lazy" oft nie.
const props = defineProps({ no: String, color: Number, type: String, src: String, size: { type: Number, default: 64 }, eager: Boolean, noZoom: Boolean, name: String, colorName: String })
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
// Klick abfangen, damit eine anklickbare Tabellenzeile nicht zusätzlich die Seite wechselt
const open = (e) => {
  if (props.noZoom || !props.no) return
  e.preventDefault(); e.stopPropagation()
  openZoom({ no: props.no, color: props.color, type: props.type, src: url.value ?? props.src, name: props.name, colorName: props.colorName })
}
</script>

<template>
  <span class="pimg" :class="{ zoomable: !noZoom && no }" :style="{ width: `${size}px`, height: `${size}px` }"
    :role="!noZoom && no ? 'button' : undefined" :tabindex="!noZoom && no ? 0 : undefined" :title="!noZoom && no ? 'Bild vergrößern' : undefined"
    @click="open" @keydown.enter="open">
    <img v-if="url" :key="url" :src="url" alt="" :loading="eager ? 'eager' : 'lazy'" referrerpolicy="no-referrer" @error="tries++" />
    <Shapes v-else :size="Math.round(size * 0.45)" class="faint" />
  </span>
</template>

<style scoped>
.pimg { position: relative; display: grid; place-items: center; background: #fff; border-radius: var(--radius-sm); border: 1px solid var(--border); overflow: hidden; flex: none; }
/* absolut, damit hohe Bilder (Minifiguren) nie über den Rahmen hinauswachsen und abgeschnitten werden */
.pimg img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
.pimg.zoomable { cursor: zoom-in; }
</style>
