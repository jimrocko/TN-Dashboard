<script setup>
// Ungefähre Farbwerte der BrickLink-Farben, nur zur Orientierung in Tabellen.
const colors = {
  Black: '#05131d', White: '#ffffff', Red: '#c91a09', Blue: '#0055bf', Yellow: '#f2cd37',
  'Light Bluish Gray': '#a0a5a9', 'Dark Bluish Gray': '#6c6e68', 'Reddish Brown': '#582a12',
  Tan: '#e4cd9e', Green: '#237841', Orange: '#fe8a18',
}
defineProps({ name: String })
</script>

<template>
  <span class="sw" :class="{ trans: name?.startsWith('Trans') }" :style="{ background: colors[name] ?? 'transparent' }"></span>
  <span>{{ name }}</span>
</template>

<style scoped>
.sw { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: 7px; vertical-align: -1px; box-shadow: inset 0 0 0 1px rgba(127, 127, 127, 0.45); }
.trans { background: repeating-linear-gradient(45deg, transparent 0 2px, rgba(127, 127, 127, 0.35) 2px 3px) !important; }
</style>
