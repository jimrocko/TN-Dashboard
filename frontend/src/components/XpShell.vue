<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Search, History, Settings, GraduationCap, Code2, Power, LogOut } from 'lucide-vue-next'
import { modules, moduleById } from '../modules'
import { pageTitle } from '../router'
import { useAuth } from '../stores/auth'
import { windowKey } from '../utils/windowing'
import { useSettings } from '../stores/settings'
import { leaveXp, xpTouch, xpIdleFor, XP_IDLE_MS } from '../utils/cheats'
import XpWindowView from './XpWindowView.vue'
import { xpApps } from '../xp/apps'
import logo from '../assets/icon.svg'

// Windows-XP-Oberfläche: Desktop mit App-Symbolen, mehrere Fenster gleichzeitig, Start-Menü und Taskleiste.
// Die aktuelle Adresse (Router) ist immer das aktive Fenster; „/“ heißt: kein Fenster aktiv, nur Desktop.
// Fenster lassen sich verschieben (Titelleiste), in der Größe ändern (Ecke unten rechts),
// minimieren, maximieren (auch per Doppelklick auf die Titelleiste) und schließen.
// Auf schmalen Bildschirmen (Handy, iPad hochkant) ist jedes Fenster bildschirmfüllend und nur das aktive sichtbar.
const route = useRoute()
const router = useRouter()
const auth = useAuth()

const TASKBAR = 44
const startOpen = ref(false)
const q = ref('')
const time = ref('')

const extras = computed(() => [
  { id: 'suche', title: 'Suche', icon: Search, path: '/suche', xpColor: '#2b8ad6' },
  { id: 'profil', title: 'Meine Aktivitäten', icon: History, path: '/profil', xpColor: '#6b7fa3' },
  { id: 'einstellungen', title: 'Einstellungen', icon: Settings, path: '/einstellungen', xpColor: '#7a7a7a' },
  ...(auth.isStaff ? [{ id: 'lehrer', title: 'Lehrer-Ansicht', icon: GraduationCap, path: '/lehrer', xpColor: '#b5651d' }] : []),
])
const desktopIcons = computed(() => [...xpApps, ...modules, ...extras.value])
const iconFor = (key) => (key.startsWith('/teil/') || key.startsWith('/lagerort/') ? moduleById('inventar')
  : desktopIcons.value.find((i) => key === i.path || key.startsWith(i.path + '/')) ?? null)

// Bildschirmbreite: schmal = Fenster immer bildschirmfüllend
const compactMq = typeof matchMedia === 'function' ? matchMedia('(max-width: 900px)') : null
const compact = ref(!!compactMq?.matches)
const onCompact = (e) => { compact.value = e.matches }

// ---- Fensterverwaltung ----
const windows = ref([]) // { key, fullPath, title, x, y, w, h, z, min, max }
let zTop = 0
let opened = 0
const activeKey = computed(() => (route.path === '/' || route.meta.public ? null : windowKey(route)))
// Stapelreihenfolge als z-index 10, 11, … (bleibt unter Start-Menü und Taskleiste)
const zIndex = (w) => 10 + [...windows.value].sort((a, b) => a.z - b.z).indexOf(w)

function placeNew() {
  const vw = window.innerWidth
  const vh = window.innerHeight - TASKBAR
  const step = (opened++ % 6) * 28
  const w = Math.min(1080, vw - 160)
  const h = Math.min(760, vh - 40)
  return { x: Math.max(8, Math.min(128 + step, vw - w - 8)), y: Math.max(8, Math.min(16 + step, vh - h - 8)), w, h }
}

watch(() => route.fullPath, () => {
  startOpen.value = false
  const key = activeKey.value
  if (!key) return
  let w = windows.value.find((x) => x.key === key)
  if (!w) {
    w = reactive({ key, fullPath: route.fullPath, title: '', z: 0, min: false, max: false, ...placeNew() })
    windows.value.push(w)
  }
  w.fullPath = route.fullPath
  w.title = pageTitle(route)
  w.min = false
  w.z = ++zTop
}, { immediate: true })

const topVisible = (except) => windows.value.filter((w) => w !== except && !w.min).sort((a, b) => b.z - a.z)[0]
function activateNext(except) {
  const next = topVisible(except)
  router.push(next ? next.fullPath : '/')
}
function focus(w) {
  if (activeKey.value !== w.key) router.push(w.fullPath)
}
function minimize(w) {
  w.min = true
  if (activeKey.value === w.key) activateNext(w)
}
function close(w) {
  windows.value = windows.value.filter((x) => x !== w)
  if (activeKey.value === w.key) activateNext(w)
}
function taskClick(w) {
  if (activeKey.value === w.key && !w.min) minimize(w)
  else focus(w)
}
function showDesktop() {
  windows.value.forEach((w) => { w.min = true })
  router.push('/')
}

// Verschieben und Größe ändern per Zeiger (Maus, Finger, Stift)
function startDrag(e, w, mode) {
  if (compact.value || w.max || e.button > 0) return
  if (mode === 'move' && e.target.closest('.wbtns')) return
  e.preventDefault()
  focus(w)
  const el = e.currentTarget
  el.setPointerCapture?.(e.pointerId)
  const start = { px: e.clientX, py: e.clientY, x: w.x, y: w.y, w: w.w, h: w.h }
  const move = (ev) => {
    const dx = ev.clientX - start.px
    const dy = ev.clientY - start.py
    const vw = window.innerWidth
    const vh = window.innerHeight - TASKBAR
    if (mode === 'move') {
      // Titelleiste bleibt immer erreichbar
      w.x = Math.min(vw - 80, Math.max(80 - w.w, start.x + dx))
      w.y = Math.min(vh - 40, Math.max(0, start.y + dy))
    } else {
      w.w = Math.max(360, Math.min(vw - w.x, start.w + dx))
      w.h = Math.max(240, Math.min(vh - w.y, start.h + dy))
    }
  }
  const up = () => {
    el.removeEventListener('pointermove', move)
    el.removeEventListener('pointerup', up)
    el.removeEventListener('pointercancel', up)
  }
  el.addEventListener('pointermove', move)
  el.addEventListener('pointerup', up)
  el.addEventListener('pointercancel', up)
}
const frameStyle = (w) => (compact.value || w.max
  ? { zIndex: zIndex(w) }
  : { zIndex: zIndex(w), left: `${w.x}px`, top: `${w.y}px`, width: `${w.w}px`, height: `${w.h}px` })

function search() {
  const term = q.value.trim()
  if (term) { router.push({ path: '/suche', query: { q: term } }); q.value = '' }
}
// „Ausschalten“: XP sofort verlassen, zurück zum normalen Design. Passiert auch nach 15 Minuten ohne Bedienung.
const settings = useSettings()
function shutDown() {
  startOpen.value = false
  windows.value = []
  leaveXp(settings)
}
let lastTouch = 0
function onActivity() {
  if (Date.now() - lastTouch < 10000) return
  lastTouch = Date.now()
  xpTouch()
}
function checkIdle() {
  if (xpIdleFor() >= XP_IDLE_MS) shutDown()
}

async function logout() {
  startOpen.value = false
  windows.value = []
  await auth.logout()
  router.push('/login')
}

const tick = () => { time.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) }
const onKey = (e) => { if (e.key === 'Escape') startOpen.value = false }
let timer
onMounted(() => {
  checkIdle() // z. B. App nach langer Pause wieder geöffnet
  xpTouch()
  tick(); timer = setInterval(() => { tick(); checkIdle() }, 15000)
  window.addEventListener('keydown', onKey)
  window.addEventListener('pointerdown', onActivity)
  window.addEventListener('keydown', onActivity)
  compactMq?.addEventListener?.('change', onCompact)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('pointerdown', onActivity)
  window.removeEventListener('keydown', onActivity)
  compactMq?.removeEventListener?.('change', onCompact)
})
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
    <section v-for="w in windows" :key="w.key" v-show="!w.min && (!compact || activeKey === w.key)"
      class="window" :class="{ max: w.max, active: activeKey === w.key }" :style="frameStyle(w)"
      role="dialog" :aria-label="w.title" @pointerdown="focus(w)">
      <header class="titlebar" @pointerdown="startDrag($event, w, 'move')" @dblclick="w.max = !w.max">
        <span class="ticon" :style="{ '--c': iconFor(w.key)?.xpColor ?? '#2b6fd6' }"><component :is="iconFor(w.key)?.icon ?? Code2" :size="14" :stroke-width="2.4" /></span>
        <span class="ttext">{{ w.title }}</span>
        <span class="wbtns">
          <button class="wbtn" title="Minimieren" aria-label="Minimieren" @click.stop="minimize(w)"><span class="i-min"></span></button>
          <button class="wbtn" :title="w.max ? 'Wiederherstellen' : 'Maximieren'" :aria-label="w.max ? 'Wiederherstellen' : 'Maximieren'" @click.stop="w.max = !w.max"><span :class="w.max ? 'i-restore' : 'i-max'"></span></button>
          <button class="wbtn close" title="Schließen" aria-label="Schließen" @click.stop="close(w)"><span class="i-close"></span></button>
        </span>
      </header>
      <div class="wbody"><XpWindowView :full-path="w.fullPath" :visible="!w.min" /></div>
      <span v-if="!w.max && !compact" class="grip" aria-hidden="true" @pointerdown.stop="startDrag($event, w, 'resize')"></span>
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
          <div class="sm-sep"></div>
          <RouterLink v-for="m in xpApps" :key="m.id" :to="m.path" class="sm-item">
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
          <RouterLink v-if="auth.canDev" to="/dev" class="sm-link"><Code2 :size="18" /> Dev-Bereich</RouterLink>
        </div>
      </div>
      <div class="sm-foot">
        <button class="sm-off" @click="logout"><span class="off-ic key"><LogOut :size="15" /></span> Abmelden</button>
        <button class="sm-off" @click="shutDown"><span class="off-ic"><Power :size="16" /></span> Ausschalten</button>
      </div>
    </div>

    <!-- Taskleiste -->
    <div class="taskbar">
      <button class="start" :class="{ on: startOpen }" @click="startOpen = !startOpen" :aria-expanded="startOpen" aria-label="Start-Menü">
        <img :src="logo" alt="" /> <span>Start</span>
      </button>
      <div class="tasks">
        <button v-for="w in windows" :key="w.key" class="task" :class="{ on: activeKey === w.key && !w.min }" :title="w.title" @click="taskClick(w)">
          <span class="ticon sm" :style="{ '--c': iconFor(w.key)?.xpColor ?? '#2b6fd6' }"><component :is="iconFor(w.key)?.icon ?? Code2" :size="11" :stroke-width="2.6" /></span>
          <span>{{ w.title }}</span>
        </button>
      </div>
      <div class="tray">
        <span class="who">{{ auth.user?.display_name }}</span>
        <span class="clock">{{ time }}</span>
        <button v-if="windows.length" class="showdesk" title="Desktop anzeigen" aria-label="Desktop anzeigen" @click="showDesktop"></button>
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
  position: absolute; display: flex; flex-direction: column;
  border: 3px solid var(--xp-frame); border-top: 0; border-radius: 8px 8px 3px 3px;
  background: var(--bg); box-shadow: 4px 6px 18px rgba(0, 0, 0, 0.45); overflow: hidden;
}
.window.max { top: 0; left: 0; right: 0; bottom: 44px; border-radius: 0; border-width: 0; }
.window:not(.active) { border-color: var(--xp-frame-inactive); box-shadow: 2px 3px 10px rgba(0, 0, 0, 0.3); }
.window:not(.active) .titlebar { background: var(--xp-title-inactive); }
.window:not(.active) .ttext { opacity: 0.85; }
.grip {
  position: absolute; right: 0; bottom: 0; width: 22px; height: 22px; cursor: nwse-resize; touch-action: none;
  background: repeating-linear-gradient(135deg, transparent 0 5px, var(--text-3) 5px 6px, transparent 6px 8px);
  clip-path: polygon(100% 0, 100% 100%, 0 100%);
}
@media (max-width: 900px) { .window { top: 0; left: 0; right: 0; bottom: 44px; border-radius: 0; border-width: 0; } }
.titlebar {
  display: flex; align-items: center; gap: 8px; height: 40px; padding: 0 6px 0 10px; flex: none;
  background: var(--xp-title); color: #fff; user-select: none; -webkit-user-select: none;
  padding-top: env(safe-area-inset-top, 0px); box-sizing: content-box; touch-action: none; cursor: default;
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
.sm-foot { display: flex; justify-content: flex-end; gap: 4px; padding: 6px 10px; background: var(--xp-title); }
.sm-off { display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 10px; background: none; border: 0; color: #fff; font-size: 14px; text-shadow: 1px 1px 0 rgba(0, 0, 0, 0.5); border-radius: 3px; }
.sm-off:hover { background: rgba(255, 255, 255, 0.15); }
.off-ic.key { background: linear-gradient(180deg, #ffd56a, #e2a31b); }
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
.tasks { flex: 1; min-width: 0; display: flex; align-items: center; gap: 4px; padding: 0 10px; overflow-x: auto; scrollbar-width: none; }
.task {
  display: flex; align-items: center; gap: 8px; max-width: 240px; height: 34px; padding: 0 12px; border-radius: 3px;
  border: 1px solid rgba(255, 255, 255, 0.25); background: rgba(255, 255, 255, 0.12); color: #fff; font-size: 13px; font-family: inherit;
  overflow: hidden; white-space: nowrap; box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
}
.task span { overflow: hidden; text-overflow: ellipsis; }
.task.on { background: rgba(0, 0, 0, 0.25); box-shadow: inset 1px 1px 3px rgba(0, 0, 0, 0.5); }
.ticon.sm { width: 16px; height: 16px; border-radius: 3px; flex: none; }
.showdesk { align-self: stretch; width: 12px; margin-right: -16px; border: 0; border-left: 1px solid rgba(255, 255, 255, 0.35); background: transparent; }
.showdesk:hover { background: rgba(255, 255, 255, 0.2); }
.tray {
  display: flex; align-items: center; gap: 14px; padding: 0 16px 0 18px; color: #fff; font-size: 13px;
  background: var(--xp-tray); border-left: 1px solid #0a3b8f; box-shadow: inset 1px 0 1px rgba(255, 255, 255, 0.25);
  padding-right: max(16px, env(safe-area-inset-right, 0px));
}
@media (max-width: 640px) {
  .who { display: none; }
  .task { max-width: 150px; flex: none; }
  .desktop { grid-auto-flow: row; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); grid-template-rows: none; grid-auto-columns: auto; }
}
</style>
