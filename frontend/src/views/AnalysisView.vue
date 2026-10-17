<script setup>
import PartImage from '../components/PartImage.vue'
import { computed, onMounted, ref, watch } from 'vue'
import SegControl from '../components/SegControl.vue'
import { earnedCount } from '../utils/achievements'
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

// Bestenliste (Wunsch des Inhabers): abgetrennt unten, nur Teilnehmer, wahlweise Woche/Monat/gesamt
const period = ref('all')
const periods = [{ value: 'week', label: 'Diese Woche' }, { value: 'month', label: 'Dieser Monat' }, { value: 'all', label: 'Gesamt' }]
const board = ref([])
async function loadBoard() {
  try { board.value = (await api.get('achievements', { period: period.value })).board } catch { board.value = [] }
}
onMounted(loadBoard)
watch(period, loadBoard)
const top = (fn) => board.value.map((u) => ({ label: u.name, v: fn(u) })).filter((r) => r.v > 0).sort((x, y) => y.v - x.v || x.label.localeCompare(y.label)).slice(0, 10)
const leaders = computed(() => [
  { title: 'Meiste Picks', sub: 'Aufträge fertig gepackt', rows: top((u) => u.stats?.picks ?? 0) },
  { title: 'Meiste Umlagerungen', sub: 'Lots umgelagert', rows: top((u) => u.stats?.transfers ?? 0) },
  { title: 'Meiste Inventuren', sub: 'Inventuren abgeschlossen', rows: top((u) => u.stats?.stocktakes ?? 0) },
  { title: 'Meiste Abzeichen', sub: 'seit Beginn', rows: top((u) => earnedCount(u.all)) },
])
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
                <td><div class="partcell"><PartImage :no="i.item_no" :color="i.color_id" :type="i.item_type" :size="32" /><div><RouterLink class="mono" :to="`/teil/${i.item_no}`">{{ i.item_no }}</RouterLink> <span class="muted small">{{ i.name }}</span></div></div></td>
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

    <div class="leader-head">
      <div><h2 class="page-title">Bestenliste</h2><p class="page-sub">Wer hat am meisten geschafft? Abzeichen gibt es unter „Meine Aktivitäten“.</p></div>
      <SegControl v-model="period" :options="periods" label="Zeitraum" />
    </div>
    <div class="grid-2">
      <section v-for="l in leaders" :key="l.title" class="panel">
        <div class="panel-head"><h2>{{ l.title }}</h2><span class="faint small">{{ l.sub }}</span></div>
        <div class="panel-body">
          <BarList v-if="l.rows.length" :rows="l.rows" value-key="v" :format="num" />
          <p v-else class="faint small">Noch niemand. Wer wird der Erste?</p>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.nowrap { white-space: nowrap; }
.low { color: var(--warn); }
.leader-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-top: 18px; padding-top: 22px; border-top: 2px dashed var(--border-strong); }
.leader-head .page-title { margin: 0; }
</style>
