<script setup>
import { computed, onMounted, ref } from 'vue'
import { ArrowRight } from 'lucide-vue-next'
import StatCard from '../components/StatCard.vue'
import ActivityTable from '../components/ActivityTable.vue'
import { modules, statusLabel } from '../modules'
import { api } from '../api'
import { useAuth } from '../stores/auth'
import { num, eur } from '../utils/format'

const auth = useAuth()
const d = ref(null)
const error = ref('')
const seeding = ref(false)

async function load() {
  try { d.value = await api.get('dashboard') } catch (e) { error.value = e.message }
}
async function seed() {
  seeding.value = true
  try { await api.post('demo/seed'); await load() } catch (e) { error.value = e.message } finally { seeding.value = false }
}
onMounted(load)

const today = new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 11 ? 'Guten Morgen' : h < 18 ? 'Guten Tag' : 'Guten Abend'
})
const moduleStat = (id) => {
  if (!d.value) return ''
  return {
    inventar: `${num(d.value.inventar.lots)} Lose`,
    umlagern: `${num(d.value.umlagern.suggestions)} Vorschläge`,
    inventur: `${num(d.value.inventur.open)} offen`,
    auftraege: `${num(d.value.auftraege.active)} aktiv`,
    analyse: `Stand ${d.value.analyse.last_run}`,
  }[id] ?? ''
}
</script>

<template>
  <div class="page stack">
    <div class="page-head">
      <div>
        <h2 class="page-title">{{ greeting }}, {{ auth.user.display_name.split(' ')[0] }}</h2>
        <p class="page-sub">{{ today }}</p>
      </div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <div v-if="d?.demo_seedable" class="panel seed">
      <div>
        <strong>Noch keine Lagerdaten vorhanden.</strong>
        <div class="muted small">Zum Ausprobieren können erfundene Beispieldaten geladen werden (Lagerorte, Teile, Aufträge, Inventuren).</div>
      </div>
      <button class="btn btn-primary" :disabled="seeding" @click="seed">Beispieldaten laden</button>
    </div>

    <div class="stats">
      <StatCard label="Gesamtbestand" :value="d ? num(d.inventar.total_parts) : '–'" :hint="d ? `${num(d.inventar.lots)} Lose · ${num(d.lagerorte.count)} Lagerorte` : ''" to="/inventar" />
      <StatCard label="Lagerwert" :value="d ? eur(d.inventar.value) : '–'" hint="Menge × Stückpreis" to="/analyse" />
      <StatCard label="Aktive Aufträge" :value="d ? num(d.auftraege.active) : '–'" hint="Neu, bezahlt oder verpackt" :to="{ path: '/auftraege', query: { status: 'aktiv' } }" />
      <StatCard label="Umlagerungsvorschläge" :value="d ? num(d.umlagern.suggestions) : '–'" hint="aus Mischkisten" to="/umlagern" />
      <StatCard label="Offene Inventuren" :value="d ? num(d.inventur.open) : '–'" hint="Lagerorte zu zählen" to="/inventur" />
    </div>

    <div class="cols">
      <section class="panel">
        <div class="panel-head"><h2>Bereiche</h2></div>
        <div class="mods">
          <RouterLink v-for="m in modules" :key="m.id" :to="m.path" class="mod">
            <span class="ic"><component :is="m.icon" :size="18" /></span>
            <span class="txt">
              <span class="t">{{ m.title }}</span>
              <span class="d">{{ m.text }}</span>
            </span>
            <span class="meta">
              <span v-if="m.status === 'aktiv'" class="faint small">{{ moduleStat(m.id) }}</span>
              <span v-else class="pill">{{ statusLabel[m.status] }}</span>
              <ArrowRight :size="16" class="faint" />
            </span>
          </RouterLink>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Letzte Aktivitäten</h2>
          <RouterLink :to="auth.isStaff ? '/lehrer' : '/profil'" class="btn btn-ghost btn-sm">Alle</RouterLink>
        </div>
        <ActivityTable :rows="d?.recent ?? []" :show-user="auth.isStaff" compact />
      </section>
    </div>
  </div>
</template>

<style scoped>
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--gap); }
@media (max-width: 640px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .stats > :last-child:nth-child(odd) { grid-column: span 2; } }
.cols { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: var(--gap); align-items: start; }
@media (max-width: 1100px) { .cols { grid-template-columns: minmax(0, 1fr); } }
.mods { display: flex; flex-direction: column; }
.mod { display: flex; align-items: center; gap: 14px; padding: 12px var(--pad); border-bottom: 1px solid var(--border); }
.mod:last-child { border-bottom: 0; }
.mod:hover { background: var(--hover); }
.ic { width: 34px; height: 34px; flex: none; border-radius: var(--radius-sm); display: grid; place-items: center; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-2); }
.txt { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.t { font-weight: 500; font-size: 0.93rem; }
.d { font-size: 0.83rem; color: var(--text-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.meta { display: flex; align-items: center; gap: 10px; flex: none; }
.seed { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px var(--pad); flex-wrap: wrap; border-color: var(--accent); }
@media (max-width: 640px) { .meta .small { display: none; } }
</style>
