import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettings } from './stores/settings'
import { listenKonami } from './utils/cheats'
import './styles/theme.css'
import './styles/xp.css'

const app = createApp(App).use(createPinia())
const settings = useSettings()
settings.init()
listenKonami(settings)
app.use(router).mount('#app')
