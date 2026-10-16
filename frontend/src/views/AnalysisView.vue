<script setup>
import { onMounted, ref } from 'vue'
import PageHead from '../components/PageHead.vue'
import BarList from '../components/BarList.vue'
import LocationTag from '../components/LocationTag.vue'
import ColorSwatch from '../components/ColorSwatch.vue'
import { api } from '../api'
import { useAuth } from '../stores/auth'
import { num, eur, dateTime } from '../utils/format'
import { appSettings } from '../utils/appSettings'

const auth = useAuth()
const a = ref(null)
const error = ref('')
const metric = ref(appSettings.analyse.metric)
onMounted(async () => {
  try { a.value = await api.get('analysis') } catch (e) { error.value = e.message }
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Lager- & Auftragsanalyse" :sub="a ? `Stand: ${dateTime(a.generated_at)}` : ''" />
    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="a" class="grid-2">
      <section class="panel">
        <div class="panel-head">
          <h2>Bestand nach Kategorie</h2>
          <select class="select" v-model="metric" aria-label="Kennzahl">
            <option value="qty">Teile</option>
            <option value="value">Lagerwert</option>
            <option value="lots">Lots</option>
          </select>
        </div>
        <div class="panel-body">
          <BarList :rows="a.by_category" :value-key="metric" :format="metric === 'value' ? eur : num" :link="(r) => ({ path: '/inventar', query: { category: r.label } })" />
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Volle Regalplätze (Top 10)</h2><span class="faint small">Teile je Fach</span></div>
        <div class="panel-body"><BarList :rows="a.by_location" value-key="qty" :format="num" :link="(r) => `/lagerort/${r.label}`" /></div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Aufträge nach Status</h2></div>
        <div class="panel-body"><BarList :rows="a.orders_by_status" value-key="count" :format="num" :link="(r) => ({ path: '/auftraege', query: { status: r.label } })" /></div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Mischkisten</h2><span class="faint small">G-Kästchen mit mehr als 3 Lots, die vollsten zuerst</span></div>
        <div class="panel-body"><BarList :rows="a.mixed_bins" value-key="lots" :format="num" :link="(r) => `/lagerort/${r.label}`" /></div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Niedriger Bestand</h2><span class="faint small">Regalplätze unter 20 Stück</span></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Teil</th><th>Farbe</th><th>Lagerort</th><th class="num">Menge</th></tr></thead>
            <tbody>
              <tr v-for="i in a.low_stock" :key="i.id">
                <td><RouterLink class="mono" :to="`/teil/${i.item_no}`">{{ i.item_no }}</RouterLink> <span class="muted small">{{ i.name }}</span></td>
                <td class="nowrap"><ColorSwatch :name="i.color_name" /></td>
                <td><LocationTag :code="i.location_code" /></td>
                <td class="num low">{{ num(i.qty) }}</td>
              </tr>
              <tr v-if="!a.low_stock.length"><td colspan="4" class="empty">Kein Engpass.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="auth.isStaff" class="panel">
        <div class="panel-head"><h2>Aktivität pro Teilnehmer</h2><span class="faint small">Aktionen ohne An-/Abmelden</span></div>
        <div class="panel-body"><BarList :rows="a.activity_by_participant" value-key="count" :format="num" /></div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.nowrap { white-space: nowrap; }
.low { color: var(--warn); }
</style>
