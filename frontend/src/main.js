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
app.use(router).mount('#app')
