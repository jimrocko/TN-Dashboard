import { createRouter, createWebHashHistory, createMemoryHistory } from 'vue-router'
import { useAuth } from './stores/auth'
import { logActivity, IS_DEMO } from './api'
import { moduleById } from './modules'

import HomeView from './views/HomeView.vue'
import LoginView from './views/LoginView.vue'
import ModuleView from './views/ModuleView.vue'
import SearchView from './views/SearchView.vue'
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
    { path: '/', component: HomeView },
    { path: '/modul/:id', component: ModuleView, beforeEnter: (to) => (moduleById(to.params.id) ? true : '/') },
    { path: '/suche', component: SearchView },
    { path: '/profil', component: ProfileView },
    { path: '/einstellungen', component: SettingsView },
    { path: '/dev', component: DevView },
    { path: '/lehrer', component: TeacherView, meta: { staff: true } },
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

router.afterEach((to) => {
  if (to.path.startsWith('/modul/')) logActivity('open', to.params.id)
})

export default router
