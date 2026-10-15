<script setup>
import { provide, shallowReactive, toRef, watch } from 'vue'
import { RouterView, routeLocationKey, useRouter } from 'vue-router'
import { WINDOW_VISIBLE } from '../utils/windowing'

// Inhalt eines XP-Fensters. Jedes Fenster bekommt seine eigene Route:
// useRoute() in den Ansichten liefert die Adresse dieses Fensters, nicht die des gerade aktiven.
// So behält z. B. die Auftragszentrale ihren ausgewählten Auftrag, während man in einem anderen Fenster arbeitet.
const props = defineProps({ fullPath: { type: String, required: true }, visible: { type: Boolean, default: true } })
const router = useRouter()

const route = shallowReactive({ ...router.resolve(props.fullPath) })
watch(() => props.fullPath, (p) => Object.assign(route, router.resolve(p)))

provide(routeLocationKey, route)
provide(WINDOW_VISIBLE, toRef(props, 'visible'))
</script>

<template>
  <RouterView :route="route" />
</template>
