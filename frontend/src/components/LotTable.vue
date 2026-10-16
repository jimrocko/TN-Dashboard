<script setup>
import { useRouter } from 'vue-router'
import { ArrowLeftRight } from 'lucide-vue-next'
import ColorSwatch from './ColorSwatch.vue'
import LocationTag from './LocationTag.vue'
import { num, eur, date } from '../utils/format'

// Tabelle der Lots im Inventar (ganze Liste oder eine aufgeklappte Kategorie).
defineProps({ items: Array, showCategory: { type: Boolean, default: true } })
const router = useRouter()
</script>

<template>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Teil-Nr.</th><th>Bezeichnung</th><th>Farbe</th><th v-if="showCategory">Kategorie</th><th>Lagerort</th>
          <th class="num">Menge</th><th class="num">Stückpreis</th><th class="num">Wert</th><th>Geändert</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="i in items" :key="i.id" class="clickable" @click="router.push(`/teil/${i.item_no}`)">
          <td class="mono"><RouterLink :to="`/teil/${i.item_no}`" @click.stop>{{ i.item_no }}</RouterLink></td>
          <td>{{ i.name }}</td>
          <td class="nowrap"><ColorSwatch :name="i.color_name" /></td>
          <td v-if="showCategory"><RouterLink class="muted catlink" :to="{ path: '/inventar', query: { category: i.category } }" @click.stop>{{ i.category }}</RouterLink></td>
          <td><LocationTag :code="i.location_code" :kind="i.location_kind" /></td>
          <td class="num" :class="{ low: i.qty < 20 && i.location_kind === 'regal' }">{{ num(i.qty) }}</td>
          <td class="num muted">{{ eur(i.price) }}</td>
          <td class="num">{{ eur(i.qty * i.price) }}</td>
          <td class="faint nowrap">{{ date(i.updated_at) }}</td>
          <td><button class="btn btn-ghost btn-sm" title="Umlagern" @click.stop="router.push({ path: '/umlagern', query: { lot: i.id } })"><ArrowLeftRight :size="15" /></button></td>
        </tr>
        <slot />
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.nowrap { white-space: nowrap; }
.low { color: var(--warn); }
.catlink:hover { color: var(--accent-text); text-decoration: underline; }
</style>
