import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettings } from './stores/settings'
import { listenKonami } from './utils/cheats'
import { IS_TUTORIAL } from './api'
import { resetDemo } from './demo/backend'
import './styles/theme.css'
import './styles/xp.css'

// Das Tutorial startet immer frisch (abgemeldet, Beispieldaten wie neu).
if (IS_TUTORIAL) resetDemo()

const app = createApp(App).use(createPinia())
const settings = useSettings()
settings.init()
if (!IS_TUTORIAL) listenKonami(settings)
// v-perm="'schluessel'": Knopf ausblenden, wenn die Berechtigung fehlt (Lehrer-Ansicht → Berechtigungen)
import { useAuth } from './stores/auth'
const permShow = (el, b) => { el.style.display = useAuth().can(b.value) ? '' : 'none' }
app.directive('perm', { mounted: permShow, updated: permShow })
app.use(router).mount('#app')
