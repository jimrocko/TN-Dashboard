<script setup>
import PartImage from '../components/PartImage.vue'
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHead from '../components/PageHead.vue'
import GlobalSearch from '../components/GlobalSearch.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import LocationTag from '../components/LocationTag.vue'
import { api, logActivity } from '../api'
import { num, eur, dateTime, orderStatusClass } from '../utils/format'

const route = useRoute()
const router = useRouter()
const r = ref({ parts: [], locations: [], orders: [] })
const loading = ref(false)
const error = ref('')

watch(() => route.query.q, async (q) => {
  if (!q) { r.value = { parts: [], locations: [], orders: [] }; return }
  loading.value = true
  try {
    r.value = await api.get('search', { q })
    logActivity('search', 'suche', { q })
  } catch (e) { error.value = e.message } finally { loading.value = false }
}, { immediate: true })
</script>

<template>
  <div class="page stack">
    <PageHead :title="route.query.q ? `Ergebnisse für „${route.query.q}“` : 'Suche'"
      :sub="route.query.q ? `${r.parts.length} Lose · ${r.locations.length} Lagerorte · ${r.orders.length} Aufträge` : 'Teil-Nr., Name, Farbe, Kategorie, Lagerort oder Auftrag eingeben.'" />
    <GlobalSearch :initial="route.query.q || ''" />
    <p v-if="error" class="error">{{ error }}</p>

    <section v-if="r.locations.length" class="panel">
      <div class="panel-head"><h2>Lagerorte</h2></div>
      <div class="panel-body chips">
        <RouterLink v-for="l in r.locations" :key="l.id" :to="`/lagerort/${l.code}`" class="btn btn-sm">
          <LocationTag :code="l.code" :kind="l.kind" :link="false" /> {{ l.name }}
        </RouterLink>
      </div>
    </section>

    <section v-if="r.parts.length" class="panel">
      <div class="panel-head"><h2>Teile</h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th></th><th>Teil-Nr.</th><th>Bezeichnung</th><th>Farbe</th><th>Lagerort</th><th class="num">Menge</th><th class="num">Wert</th></tr></thead>
          <tbody>
            <tr v-for="i in r.parts" :key="i.id" class="clickable" @click="router.push(`/teil/${i.item_no}`)">
              <td class="imgcell"><PartImage :no="i.item_no" :color="i.color_id" :type="i.item_type" :size="36" /></td>
              <td class="mono"><RouterLink :to="`/teil/${i.item_no}`" @click.stop>{{ i.item_no }}</RouterLink></td>
              <td>{{ i.name }}</td>
              <td class="nowrap"><ColorSwatch :name="i.color_name" /></td>
              <td><LocationTag :code="i.location_code" :kind="i.location_kind" /></td>
              <td class="num">{{ num(i.qty) }}</td>
              <td class="num">{{ eur(i.qty * i.price) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="r.orders.length" class="panel">
      <div class="panel-head"><h2>Aufträge</h2></div>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <tr v-for="o in r.orders" :key="o.id" class="clickable" @click="router.push({ path: '/auftraege', query: { status: '', id: o.id } })">
              <td class="mono"><RouterLink :to="{ path: '/auftraege', query: { status: '', id: o.id } }">{{ o.order_no }}</RouterLink></td>
              <td>{{ o.buyer }}</td>
              <td class="faint">{{ dateTime(o.ordered_at) }}</td>
              <td><span class="pill" :class="orderStatusClass(o.status)">{{ o.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="route.query.q && !loading && !r.parts.length && !r.locations.length && !r.orders.length" class="panel empty">
      Nichts gefunden. Probiere eine Teil-Nr. wie „3001“, eine Farbe wie „Red“ oder ein Kästchen wie „N004“.
    </div>
  </div>
</template>

<style scoped>
.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.nowrap { white-space: nowrap; }
</style>
