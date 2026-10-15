<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Search, History, Settings, GraduationCap, Code2, LogOut, Power } from 'lucide-vue-next'
import { modules } from '../modules'
import { pageTitle } from '../router'
import { useAuth } from '../stores/auth'
import logo from '../assets/icon.svg'

// Windows-XP-Oberfläche: Desktop mit App-Symbolen, Bereiche als Fenster, Start-Menü und Taskleiste.
const route = useRoute()
const router = useRouter()
const auth = useAuth()

const onDesktop = computed(() => route.path === '/')
const startOpen = ref(false)
const maximized = ref(false)
const lastWindow = ref(null) // zuletzt geöffnetes Fenster, für „Minimieren“ und Taskleisten-Knopf
const q = ref('')
const time = ref('')

const extras = computed(() => [
  { id: 'suche', title: 'Suche', icon: Search, path: '/suche', xpColor: '#2b8ad6' },
  { id: 'profil', title: 'Meine Aktivitäten', icon: History, path: '/profil', xpColor: '#6b7fa3' },
  { id: 'einstellungen', title: 'Einstellungen', icon: Settings, path: '/einstellungen', xpColor: '#7a7a7a' },
  ...(auth.isStaff ? [{ id: 'lehrer', title: 'Lehrer-Ansicht', icon: GraduationCap, path: '/lehrer', xpColor: '#b5651d' }] : []),
])
const desktopIcons = computed(() => [...modules, ...extras.value])
const current = computed(() => desktopIcons.value.find((i) => route.path === i.path || route.path.startsWith(i.path + '/')) ?? null)

watch(() => route.fullPath, (p) => {
  startOpen.value = false
  if (route.path !== '/') lastWindow.value = { path: p, title: pageTitle(route) }
})

function minimize() { router.push('/') }
function close() { lastWindow.value = null; router.push('/') }
function restore() { if (lastWindow.value) router.push(lastWindow.value.path) }
function search() {
  const term = q.value.trim()
  if (term) { router.push({ path: '/suche', query: { q: term } }); q.value = '' }
}
async function logout() {
  startOpen.value = false
  await auth.logout()
  router.push('/login')
}

const tick = () => { time.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) }
const onKey = (e) => { if (e.key === 'Escape') startOpen.value = false }
let timer
onMounted(() => { tick(); timer = setInterval(tick, 15000); window.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { clearInterval(timer); window.removeEventListener('keydown', onKey) })
</script>

<template>
  <div class="xp">
    <div class="wallpaper" aria-hidden="true"></div>

    <!-- Desktop -->
    <nav class="desktop" aria-label="Desktop">
      <RouterLink v-for="i in desktopIcons" :key="i.id" :to="i.path" class="dicon">
        <span class="glyph" :style="{ '--c': i.xpColor }"><component :is="i.icon" :size="30" :stroke-width="2" /></span>
        <span class="lbl" lang="de">{{ i.title }}</span>
      </RouterLink>
    </nav>

    <!-- Fenster -->
    <section v-if="!onDesktop" class="window" :class="{ max: maximized }" role="dialog" :aria-label="pageTitle(route)">
      <header class="titlebar" @dblclick="maximized = !maximized">
        <span class="ticon" :style="{ '--c': current?.xpColor ?? '#2b6fd6' }"><component :is="current?.icon ?? Code2" :size="14" :stroke-width="2.4" /></span>
        <span class="ttext">{{ pageTitle(route) }}</span>
        <span class="wbtns">
          <button class="wbtn" title="Minimieren" aria-label="Minimieren" @click="minimize"><span class="i-min"></span></button>
          <button class="wbtn" :title="maximized ? 'Wiederherstellen' : 'Maximieren'" :aria-label="maximized ? 'Wiederherstellen' : 'Maximieren'" @click="maximized = !maximized"><span :class="maximized ? 'i-restore' : 'i-max'"></span></button>
          <button class="wbtn close" title="Schließen" aria-label="Schließen" @click="close"><span class="i-close"></span></button>
        </span>
      </header>
      <div class="wbody"><RouterView /></div>
    </section>

    <!-- Start-Menü -->
    <div v-if="startOpen" class="start-backdrop" @click="startOpen = false"></div>
    <div v-if="startOpen" class="startmenu" role="menu">
      <div class="sm-head">
        <img :src="logo" alt="" class="sm-avatar" />
        <div><div class="sm-name">{{ auth.user?.display_name }}</div><div class="sm-role">{{ auth.roleLabel }}</div></div>
      </div>
      <div class="sm-body">
        <div class="sm-left">
          <form class="sm-search" role="search" @submit.prevent="search">
            <Search :size="16" />
            <input id="start-search" v-model="q" type="search" enterkeyhint="search" placeholder="Teile, Lagerorte, Aufträge suchen" aria-label="Suchen" />
          </form>
          <RouterLink v-for="m in modules" :key="m.id" :to="m.path" class="sm-item">
            <span class="glyph sm" :style="{ '--c': m.xpColor }"><component :is="m.icon" :size="18" :stroke-width="2" /></span>
            <span>{{ m.title }}</span>
          </RouterLink>
        </div>
        <div class="sm-right">
          <RouterLink to="/suche" class="sm-link"><Search :size="18" /> Suchen</RouterLink>
          <RouterLink to="/profil" class="sm-link"><History :size="18" /> Meine Aktivitäten</RouterLink>
          <RouterLink v-if="auth.isStaff" to="/lehrer" class="sm-link"><GraduationCap :size="18" /> Lehrer-Ansicht</RouterLink>
          <div class="sm-sep"></div>
          <RouterLink to="/einstellungen" class="sm-link"><Settings :size="18" /> Einstellungen</RouterLink>
          <RouterLink to="/dev" class="sm-link"><Code2 :size="18" /> Dev-Bereich</RouterLink>
        </div>
      </div>
      <div class="sm-foot">
        <button class="sm-off" @click="logout"><span class="off-ic"><Power :size="16" /></span> Abmelden</button>
      </div>
    </div>

    <!-- Taskleiste -->
    <div class="taskbar">
      <button class="start" :class="{ on: startOpen }" @click="startOpen = !startOpen" :aria-expanded="startOpen" aria-label="Start-Menü">
        <img :src="logo" alt="" /> <span>Start</span>
      </button>
      <div class="tasks">
        <button v-if="lastWindow" class="task" :class="{ on: !onDesktop }" @click="onDesktop ? restore() : minimize()">
          <img :src="logo" alt="" /> <span>{{ lastWindow.title }}</span>
        </button>
      </div>
      <div class="tray">
        <span class="who">{{ auth.user?.display_name }}</span>
        <span class="clock">{{ time }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.xp { position: fixed; inset: 0; overflow: hidden; font-family: Tahoma, Verdana, 'Segoe UI', sans-serif; }

/* Hintergrund: Himmel, Wolken und grüner Hügel (an das bekannte XP-Hintergrundbild angelehnt) */
.wallpaper {
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 22% 7% at 22% 20%, rgba(255, 255, 255, 0.75), transparent 70%),
    radial-gradient(ellipse 30% 8% at 70% 14%, rgba(255, 255, 255, 0.6), transparent 70%),
    radial-gradient(ellipse 18% 5% at 48% 30%, rgba(255, 255, 255, 0.5), transparent 70%),
    radial-gradient(ellipse 140% 55% at 35% 108%, var(--xp-hill-light) 0%, var(--xp-hill) 45%, var(--xp-hill-dark) 62%, transparent 62.5%),
    radial-gradient(ellipse 90% 40% at 95% 110%, var(--xp-hill) 0%, var(--xp-hill-dark) 60%, transparent 60.5%),
    linear-gradient(180deg, var(--xp-sky-top) 0%, var(--xp-sky-mid) 45%, var(--xp-sky-low) 75%);
}

/* Desktop-Symbole: Spalten von links oben, wie unter Windows */
.desktop {
  position: absolute; inset: 0 0 44px 0; padding: 14px; padding-top: max(14px, env(safe-area-inset-top, 0px));
  display: grid; grid-auto-flow: column; grid-template-rows: repeat(auto-fill, 112px); grid-auto-columns: 112px; gap: 6px 10px;
  align-content: start;
}
.dicon { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 8px 4px; border-radius: 3px; text-align: center; border: 1px dotted transparent; }
.dicon:hover { background: rgba(49, 106, 197, 0.35); border-color: rgba(255, 255, 255, 0.5); }
.dicon:focus-visible { outline: none; background: rgba(49, 106, 197, 0.55); border-color: #fff; }
.lbl { color: #fff; font-size: 13px; line-height: 1.2; text-shadow: 1px 1px 2px #000, 0 0 3px rgba(0, 0, 0, 0.7); hyphens: auto; -webkit-hyphens: auto; overflow-wrap: break-word; }
.glyph {
  width: 52px; height: 52px; border-radius: 10px; display: grid; place-items: center; color: #fff; flex: none;
  background: linear-gradient(160deg, color-mix(in srgb, var(--c) 55%, #fff) 0%, var(--c) 45%, color-mix(in srgb, var(--c) 70%, #000) 100%);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6), inset 0 -2px 4px rgba(0, 0, 0, 0.25), 1px 2px 4px rgba(0, 0, 0, 0.45);
  filter: drop-shadow(0 0 0.5px rgba(0, 0, 0, 0.6));
}
.glyph.sm { width: 32px; height: 32px; border-radius: 7px; }

/* Fenster */
.window {
  position: absolute; z-index: 5; display: flex; flex-direction: column;
  top: 16px; left: 128px; right: 24px; bottom: 60px;
  border: 3px solid var(--xp-frame); border-top: 0; border-radius: 8px 8px 3px 3px;
  background: var(--bg); box-shadow: 4px 6px 18px rgba(0, 0, 0, 0.45); overflow: hidden;
}
.window.max { top: 0; left: 0; right: 0; bottom: 44px; border-radius: 0; border-width: 0; }
@media (max-width: 900px) { .window { top: 0; left: 0; right: 0; bottom: 44px; border-radius: 0; border-width: 0; } }
.titlebar {
  display: flex; align-items: center; gap: 8px; height: 40px; padding: 0 6px 0 10px; flex: none;
  background: var(--xp-title); color: #fff; user-select: none; -webkit-user-select: none;
  padding-top: env(safe-area-inset-top, 0px); box-sizing: content-box;
}
.window:not(.max) .titlebar { border-radius: 7px 7px 0 0; }
@media (max-width: 900px) { .window .titlebar { border-radius: 0 !important; } }
.ticon { width: 20px; height: 20px; border-radius: 4px; display: grid; place-items: center; background: var(--c); color: #fff; box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5); }
.ttext { flex: 1; min-width: 0; font-family: 'Trebuchet MS', Tahoma, sans-serif; font-weight: 700; font-size: 15px; text-shadow: 1px 1px 0 rgba(0, 0, 0, 0.55); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wbtns { display: flex; gap: 3px; }
.wbtn {
  width: 34px; height: 30px; border-radius: 4px; border: 1px solid #fff; padding: 0; position: relative;
  background: linear-gradient(180deg, #5e9bff 0%, #2f6fe8 45%, #1d55d4 100%); box-shadow: inset 0 0 2px rgba(255, 255, 255, 0.8);
}
.wbtn:hover { filter: brightness(1.12); }
.wbtn.close { background: linear-gradient(180deg, #f2987a 0%, #e0532f 45%, #c1391b 100%); }
.i-min { position: absolute; left: 10px; bottom: 7px; width: 10px; height: 3px; background: #fff; }
.i-max { position: absolute; left: 9px; top: 7px; width: 14px; height: 13px; border: 2px solid #fff; border-top-width: 4px; box-sizing: border-box; }
.i-restore { position: absolute; left: 9px; top: 6px; width: 15px; height: 15px; }
.i-restore::before, .i-restore::after { content: ''; position: absolute; width: 10px; height: 9px; border: 2px solid #fff; border-top-width: 3px; box-sizing: border-box; }
.i-restore::before { right: 0; top: 0; }
.i-restore::after { left: 0; bottom: 0; background: #2f6fe8; }
.i-close { position: absolute; inset: 0; }
.i-close::before, .i-close::after { content: ''; position: absolute; left: 50%; top: 50%; width: 15px; height: 3px; background: #fff; border-radius: 1px; }
.i-close::before { transform: translate(-50%, -50%) rotate(45deg); }
.i-close::after { transform: translate(-50%, -50%) rotate(-45deg); }
.wbody { flex: 1; min-height: 0; overflow: auto; -webkit-overflow-scrolling: touch; }

/* Start-Menü */
.start-backdrop { position: absolute; inset: 0 0 44px 0; z-index: 20; }
.startmenu {
  position: absolute; left: 0; bottom: 44px; z-index: 21; width: min(460px, 100vw);
  max-height: calc(100dvh - 60px); display: flex; flex-direction: column;
  border: 2px solid var(--xp-frame); border-bottom: 0; border-radius: 8px 8px 0 0; overflow: hidden; box-shadow: 3px -2px 12px rgba(0, 0, 0, 0.45);
}
.sm-head { display: flex; align-items: center; gap: 12px; padding: 10px 12px; background: var(--xp-title); color: #fff; text-shadow: 1px 1px 0 rgba(0, 0, 0, 0.5); }
.sm-avatar { width: 46px; height: 46px; border-radius: 5px; border: 2px solid #fff; box-shadow: 1px 1px 3px rgba(0, 0, 0, 0.4); }
.sm-name { font-weight: 700; font-size: 16px; font-family: 'Trebuchet MS', Tahoma, sans-serif; }
.sm-role { font-size: 12px; opacity: 0.85; }
.sm-body { display: grid; grid-template-columns: 1.15fr 1fr; min-height: 0; overflow: auto; border-top: 2px solid #f0a33a; }
.sm-left { background: var(--surface); padding: 8px 6px; display: flex; flex-direction: column; gap: 2px; }
.sm-right { background: var(--xp-pane-body); padding: 8px 6px; display: flex; flex-direction: column; gap: 2px; border-left: 1px solid var(--border-strong); }
.sm-search { display: flex; align-items: center; gap: 6px; margin: 0 4px 6px; padding: 0 8px; height: 40px; border: 1px solid var(--border-strong); background: var(--surface); color: var(--text-3); }
.sm-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: none; color: var(--text); font-size: 15px; }
.sm-item { display: flex; align-items: center; gap: 10px; padding: 5px 8px; min-height: 44px; border-radius: 3px; color: var(--text); font-size: 14px; }
.sm-link { display: flex; align-items: center; gap: 10px; padding: 8px 10px; min-height: 44px; border-radius: 3px; color: var(--xp-pane-link); font-weight: 700; font-size: 14px; }
.sm-item:hover, .sm-link:hover { background: var(--xp-select); color: #fff; }
.sm-sep { height: 1px; background: var(--border-strong); margin: 6px 4px; }
.sm-foot { display: flex; justify-content: flex-end; padding: 6px 10px; background: var(--xp-title); }
.sm-off { display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 10px; background: none; border: 0; color: #fff; font-size: 14px; text-shadow: 1px 1px 0 rgba(0, 0, 0, 0.5); border-radius: 3px; }
.sm-off:hover { background: rgba(255, 255, 255, 0.15); }
.off-ic { width: 26px; height: 26px; border-radius: 4px; display: grid; place-items: center; background: linear-gradient(180deg, #f6a46c, #e05a19); border: 1px solid #fff; }
@media (max-width: 480px) { .sm-body { grid-template-columns: 1fr; } .sm-right { border-left: 0; border-top: 1px solid var(--border-strong); } }

/* Taskleiste */
.taskbar {
  position: absolute; left: 0; right: 0; bottom: 0; z-index: 30; display: flex; align-items: stretch;
  height: calc(44px + env(safe-area-inset-bottom, 0px)); padding-bottom: env(safe-area-inset-bottom, 0px);
  background: var(--xp-task); border-top: 1px solid #0c2f8a;
}
.start {
  display: flex; align-items: center; gap: 8px; padding: 0 24px 0 12px; border: 0;
  border-radius: 0 14px 14px 0; background: var(--xp-start); color: #fff;
  font-family: 'Trebuchet MS', Tahoma, sans-serif; font-style: italic; font-weight: 700; font-size: 1.2rem;
  text-shadow: 1px 1px 1px rgba(0, 0, 0, 0.55); box-shadow: inset -1px 0 2px rgba(0, 0, 0, 0.35), 2px 0 4px rgba(0, 0, 0, 0.25);
}
.start:hover, .start.on { filter: brightness(1.1); }
.start.on { box-shadow: inset 1px 2px 4px rgba(0, 0, 0, 0.5); }
.start img { width: 22px; height: 22px; border-radius: 4px; }
.tasks { flex: 1; min-width: 0; display: flex; align-items: center; padding: 0 10px; }
.task {
  display: flex; align-items: center; gap: 8px; max-width: 240px; height: 34px; padding: 0 12px; border-radius: 3px;
  border: 1px solid rgba(255, 255, 255, 0.25); background: rgba(255, 255, 255, 0.12); color: #fff; font-size: 13px; font-family: inherit;
  overflow: hidden; white-space: nowrap; box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
}
.task span { overflow: hidden; text-overflow: ellipsis; }
.task.on { background: rgba(0, 0, 0, 0.25); box-shadow: inset 1px 1px 3px rgba(0, 0, 0, 0.5); }
.task img { width: 16px; height: 16px; border-radius: 3px; flex: none; }
.tray {
  display: flex; align-items: center; gap: 14px; padding: 0 16px 0 18px; color: #fff; font-size: 13px;
  background: var(--xp-tray); border-left: 1px solid #0a3b8f; box-shadow: inset 1px 0 1px rgba(255, 255, 255, 0.25);
  padding-right: max(16px, env(safe-area-inset-right, 0px));
}
@media (max-width: 640px) {
  .who { display: none; }
  .task { max-width: 150px; }
  .desktop { grid-auto-flow: row; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); grid-template-rows: none; grid-auto-columns: auto; }
}
</style>
