import { createRouter, createWebHashHistory, createMemoryHistory } from 'vue-router'
import { useAuth } from './stores/auth'
import { logActivity, IS_DEMO } from './api'
import { moduleById } from './modules'

import HomeView from './views/HomeView.vue'
import LoginView from './views/LoginView.vue'
import ModuleView from './views/ModuleView.vue'
import SearchView from './views/SearchView.vue'
import InventoryView from './views/InventoryView.vue'
import OrdersView from './views/OrdersView.vue'
import TransferView from './views/TransferView.vue'
import StocktakeView from './views/StocktakeView.vue'
import AnalysisView from './views/AnalysisView.vue'
import ProfileView from './views/ProfileView.vue'
import SettingsView from './views/SettingsView.vue'
import DevView from './views/DevView.vue'
import TeacherView from './views/teacher/TeacherView.vue'

// Hash-Routing (#/…): funktioniert auf jedem Webserver ohne Rewrite-Regeln.
// Die Demo läuft eingebettet und hält die Navigation nur im Speicher.
const router = createRouter({
  history: IS_DEMO ? createMemoryHistory() : createWebHashHistory(),
  routes: [
    { path: '/login', component: LoginView, meta: { public: true } },
    { path: '/', component: HomeView, meta: { title: 'Übersicht' } },
    { path: '/suche', component: SearchView, meta: { title: 'Suche' } },
    { path: '/inventar', component: InventoryView, meta: { title: 'Inventar', module: 'inventar' } },
    { path: '/auftraege', component: OrdersView, meta: { title: 'Auftragszentrale', module: 'auftraege' } },
    { path: '/umlagern', component: TransferView, meta: { title: 'Umlagern', module: 'umlagern' } },
    { path: '/inventur', component: StocktakeView, meta: { title: 'Inventur & Bestandsprüfung', module: 'inventur' } },
    { path: '/analyse', component: AnalysisView, meta: { title: 'Lager- & Auftragsanalyse', module: 'analyse' } },
    {
      path: '/modul/:id', component: ModuleView, meta: { title: 'Modul' },
      beforeEnter: (to) => {
        const m = moduleById(to.params.id)
        if (!m || m.status === 'aktiv') return m ? m.path : '/'
      },
    },
    { path: '/profil', component: ProfileView, meta: { title: 'Meine Aktivitäten' } },
    { path: '/einstellungen', component: SettingsView, meta: { title: 'Einstellungen' } },
    { path: '/dev', component: DevView, meta: { title: 'Dev-Bereich' } },
    { path: '/lehrer', component: TeacherView, meta: { title: 'Lehrer-Ansicht', staff: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  await auth.check()
  if (!to.meta.public && !auth.user) return { path: '/login', query: { next: to.fullPath } }
  if (to.path === '/login' && auth.user) return '/'
  if (to.meta.staff && !auth.isStaff) return '/'
})

// Jeder Modulaufruf landet im Protokoll (nur beim Wechsel des Moduls, nicht bei jedem Filter).
const moduleOf = (r) => r.meta?.module ?? (r.path?.startsWith('/modul/') ? r.params.id : null)
router.afterEach((to, from) => {
  const m = moduleOf(to)
  if (m && m !== moduleOf(from)) logActivity('open', m)
})

/** Seitentitel, auch für die Modul-Platzhalter (/modul/:id). */
export const pageTitle = (r) => (r.path.startsWith('/modul/') ? moduleById(r.params.id)?.title : r.meta.title) ?? ''

export default router
