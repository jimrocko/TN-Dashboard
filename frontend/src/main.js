import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettings } from './stores/settings'
import './styles/theme.css'

const app = createApp(App).use(createPinia())
useSettings().init()
app.use(router).mount('#app')
