<script setup>
// Tutorial-Begleiter: dunkelt alles ab außer dem Element, das man antippen soll, und lässt Klemmi erklären.
// Nur im Tutorial-Build (npm run build:tutorial) eingebunden.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { X } from 'lucide-vue-next'
import Klemmi from './Klemmi.vue'
import { steps, homeTab } from './steps'

const route = useRoute()
const router = useRouter()

const active = ref(true)
const index = ref(0)
const step = computed(() => steps[index.value])
const total = steps.length

// Muss man erst zur Startseite zurück?
const reached = computed(() => !!step.value.wait?.route?.test(route.path))
const detour = computed(() => !!step.value.from && route.path !== step.value.from && !reached.value)
const text = computed(() => detour.value
  ? 'Erst mal zurück zur Startseite: Tipp unten links auf „Home“.'
  : step.value.say)

// ---------- Ziel finden und hervorheben ----------
const target = ref(null)
const rect = ref(null)
const missingSince = ref(0)
const stuck = ref(false)
let timer = null
let scrolledFor = null

function findTarget() {
  if (detour.value) return homeTab()
  return step.value.target?.() ?? null
}

function tick() {
  if (!active.value) return
  const el = findTarget()
  target.value = el
  if (el) {
    if (scrolledFor !== el) {
      scrolledFor = el
      const r = el.getBoundingClientRect()
      // auch seitlich, z. B. Knöpfe am Ende breiter Tabellen auf dem Handy
      if (r.top < 70 || r.bottom > innerHeight - 190 || r.left < 0 || r.right > innerWidth) el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' })
    }
    const r = el.getBoundingClientRect()
    const pad = 6
    rect.value = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 }
    missingSince.value = 0
    stuck.value = false
  } else {
    rect.value = null
    if (step.value.target || detour.value) {
      if (!missingSince.value) missingSince.value = Date.now()
      stuck.value = Date.now() - missingSince.value > 3500
    }
  }
  // Seitenwechsel als Bedingung
  if (reached.value) advance()
}

// ---------- Weiter-Bedingungen ----------
let advancing = false
function advance(delay = 0) {
  if (advancing) return
  advancing = true
  setTimeout(() => {
    advancing = false
    if (index.value < total - 1) index.value++
  }, delay)
}

function onClick(e) {
  if (!active.value || detour.value) return
  if (step.value.wait === 'click' && target.value?.contains(e.target)) advance(150)
}
function onApi(e) {
  const w = step.value.wait
  if (!active.value || !w?.api) return
  if (w.api.test(`${e.detail.method} ${e.detail.route}`)) advance(600)
}

// ---------- Sprechblase mit Schreibmaschinen-Effekt ----------
const shown = ref('')
let typer = null
function type(full) {
  clearInterval(typer)
  shown.value = ''
  let n = 0
  typer = setInterval(() => {
    n += 2
    shown.value = full.slice(0, n)
    if (n >= full.length) clearInterval(typer)
  }, 28)
}
const talking = computed(() => shown.value.length < text.value.length)
const finishTyping = () => { clearInterval(typer); shown.value = text.value }
watch(text, (t) => type(t))
watch(index, () => { scrolledFor = null; missingSince.value = 0; stuck.value = false; nextTick(tick) })

const mood = computed(() => (detour.value ? 'point' : step.value.mood ?? 'idle'))
const isEnd = computed(() => step.value.wait === 'end')
const isInfo = computed(() => !detour.value && (step.value.wait === 'next' || isEnd.value))

// Klemmi oben, wenn das Ziel unten liegt, sonst unten
const placeTop = computed(() => !!rect.value && rect.value.top + rect.value.height / 2 > innerHeight * 0.55)

// Abdunkeln: vier Flächen um das Loch herum. Bei reinen Erklärschritten ist auch das Loch gesperrt.
const shades = computed(() => {
  const r = rect.value
  if (!r) return [{ top: 0, left: 0, width: '100vw', height: '100vh' }]
  const px = (n) => `${Math.max(0, n)}px`
  return [
    { top: 0, left: 0, width: '100vw', height: px(r.top) },
    { top: px(r.top + r.height), left: 0, width: '100vw', height: `calc(100vh - ${px(r.top + r.height)})` },
    { top: px(r.top), left: 0, width: px(r.left), height: px(r.height) },
    { top: px(r.top), left: px(r.left + r.width), width: `calc(100vw - ${px(r.left + r.width)})`, height: px(r.height) },
  ]
})
const ring = computed(() => rect.value && {
  top: `${rect.value.top}px`, left: `${rect.value.left}px`, width: `${rect.value.width}px`, height: `${rect.value.height}px`,
})

function rescue() {
  if (step.value.go && route.fullPath !== step.value.go) router.push(step.value.go)
  else advance()
}

// ---------- Ende ----------
const confirmQuit = ref(false)
function quit() { active.value = false; clearInterval(typer) }
// Neu laden: main.js setzt die Tutorial-Daten zurück, man startet abgemeldet bei Schritt 1.
const restart = () => location.reload()
const confetti = Array.from({ length: 70 }, (_, i) => ({
  left: `${(i * 37) % 100}%`,
  delay: `${(i % 14) * 0.12}s`,
  duration: `${2.4 + (i % 5) * 0.35}s`,
  color: ['#d01c0a', '#f6d43c', '#0d5ccc', '#2e9b3e', '#ffffff', '#f08a24'][i % 6],
  w: `${8 + (i % 3) * 4}px`,
}))

onMounted(() => {
  type(text.value)
  timer = setInterval(tick, 120)
  document.addEventListener('click', onClick, true)
  window.addEventListener('tn-api', onApi)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(typer)
  document.removeEventListener('click', onClick, true)
  window.removeEventListener('tn-api', onApi)
})
</script>

<template>
  <div v-if="active" class="tutorial" :class="{ info: isInfo }">
    <div v-for="(s, i) in shades" :key="i" class="shade" :style="s"></div>
    <div v-if="ring" class="ring" :class="{ action: !isInfo }" :style="ring"></div>
    <!-- Erklärschritt: das hervorgehobene Element ist zu sehen, aber nicht antippbar -->
    <div v-if="isInfo && ring" class="block" :style="ring"></div>

    <div v-if="isEnd" class="confetti" aria-hidden="true">
      <i v-for="(c, i) in confetti" :key="i" :style="{ left: c.left, animationDelay: c.delay, animationDuration: c.duration, background: c.color, width: c.w }"></i>
    </div>

    <div class="coach" :class="{ top: placeTop, end: isEnd }" role="dialog" aria-live="polite" aria-label="Tutorial">
      <Klemmi :talking="talking" :mood="mood" :size="isEnd ? 150 : 112" class="fig" />
      <div class="bubble" @click="finishTyping">
        <div class="who">
          <strong>Klemmi</strong>
          <span class="faint small">Schritt {{ index + 1 }} von {{ total }}</span>
          <button class="close" type="button" title="Tutorial beenden" aria-label="Tutorial beenden" @click.stop="confirmQuit = !confirmQuit"><X :size="18" /></button>
        </div>
        <p class="say">{{ shown }}<span v-if="talking" class="caret">▍</span></p>
        <div class="progress"><i :style="{ width: `${((index + 1) / total) * 100}%` }"></i></div>
        <div v-if="confirmQuit" class="actions">
          <span class="small">Wirklich aufhören? Du kannst danach frei herumprobieren.</span>
          <button class="btn btn-sm" type="button" @click.stop="confirmQuit = false">Weitermachen</button>
          <button class="btn btn-sm btn-primary" type="button" @click.stop="quit">Beenden</button>
        </div>
        <div v-else-if="isEnd" class="actions">
          <button class="btn" type="button" @click.stop="restart">Nochmal von vorn</button>
          <button class="btn btn-primary" type="button" @click.stop="quit">Demo frei erkunden</button>
        </div>
        <div v-else class="actions">
          <button v-if="stuck" class="btn btn-sm" type="button" @click.stop="rescue">Ich finde es nicht</button>
          <span v-else-if="!isInfo" class="faint small hint">Du bist dran: Tipp auf das Leuchtende.</span>
          <button v-if="isInfo" class="btn btn-primary" type="button" :disabled="talking" @click.stop="advance()">Weiter</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tutorial { position: fixed; inset: 0; z-index: 9000; pointer-events: none; }
.shade { position: fixed; background: rgba(4, 8, 14, 0.62); pointer-events: auto; transition: all 0.25s ease; }
.ring { position: fixed; border-radius: 12px; box-shadow: 0 0 0 3px #f6d43c, 0 0 22px 4px rgba(246, 212, 60, 0.55); transition: all 0.25s ease; }
.ring.action { animation: pulse 1.3s ease-in-out infinite; }
.block { position: fixed; pointer-events: auto; }

.coach {
  position: fixed; left: 12px; right: 12px; bottom: 12px; margin: 0 auto; max-width: 640px;
  display: flex; align-items: flex-end; gap: 6px; pointer-events: auto;
  animation: pop 0.35s ease-out;
}
.coach.top { bottom: auto; top: 12px; align-items: flex-start; }
.coach.end { bottom: max(12px, calc(50vh - 110px)); }
.fig { flex: none; }
.bubble {
  position: relative; flex: 1; min-width: 0; background: #fffdf3; color: #1d1d1d; border-radius: 16px; padding: 12px 14px 12px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45); border: 3px solid #f6d43c; cursor: default;
}
.bubble::before {
  content: ''; position: absolute; left: -14px; bottom: 26px; border: 8px solid transparent; border-right: 8px solid #f6d43c; border-right-width: 10px;
}
.coach.top .bubble::before { bottom: auto; top: 30px; }
.who { display: flex; align-items: center; gap: 10px; }
.who strong { color: #c91a09; font-size: 1.02rem; }
.who .faint { color: #6d6a5e; }
.close { margin-left: auto; background: none; border: 0; color: #6d6a5e; padding: 4px; border-radius: 6px; min-width: 32px; min-height: 32px; display: grid; place-items: center; }
.close:hover { background: rgba(0, 0, 0, 0.06); }
.say { margin: 6px 0 10px; font-size: 1rem; line-height: 1.45; min-height: 3em; }
.caret { color: #c91a09; animation: caret 0.8s steps(1) infinite; }
.progress { height: 5px; background: #eee6c4; border-radius: 3px; overflow: hidden; margin-bottom: 10px; }
.progress i { display: block; height: 100%; background: #d01c0a; transition: width 0.4s ease; }
.actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.actions .small, .hint { margin-right: auto; color: #57544a; }
.bubble .btn:not(.btn-primary) { background: #fff; color: #1d1d1d; border-color: #d8d2b8; }

.confetti { position: fixed; inset: 0; overflow: hidden; pointer-events: none; }
.confetti i { position: absolute; top: -20px; height: 10px; border-radius: 2px; animation: fall linear infinite; opacity: 0.9; }

@keyframes pulse { 0%, 100% { box-shadow: 0 0 0 3px #f6d43c, 0 0 14px 2px rgba(246, 212, 60, 0.45); } 50% { box-shadow: 0 0 0 5px #f6d43c, 0 0 30px 8px rgba(246, 212, 60, 0.7); } }
@keyframes pop { from { opacity: 0; transform: translateY(14px) scale(0.97); } to { opacity: 1; transform: none; } }
@keyframes caret { 50% { opacity: 0; } }
@keyframes fall { from { transform: translateY(0) rotate(0); } to { transform: translateY(110vh) rotate(720deg); } }

@media (max-width: 560px) {
  .coach { left: 8px; right: 8px; bottom: 8px; gap: 2px; }
  .coach.top { top: 8px; }
  .fig { width: 58px; height: 80px; }
  .say { font-size: 0.95rem; }
}
:global([data-tn-motion='reduced']) .ring.action { animation: none; }
:global([data-tn-motion='reduced']) .confetti { display: none; }
@media (prefers-reduced-motion: reduce) { .ring.action { animation: none; } .confetti { display: none; } }
</style>
