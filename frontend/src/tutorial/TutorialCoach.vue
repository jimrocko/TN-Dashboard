<script setup>
// Tutorial-Begleiter: dunkelt alles ab außer dem Element, das man antippen soll, zeigt mit einem Pfeil darauf
// und lässt Klemmi erklären. Nur im Tutorial-Build (npm run build:tutorial) eingebunden.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { X, ArrowRight, RotateCcw, Compass, GraduationCap, Backpack } from 'lucide-vue-next'
import Klemmi from './Klemmi.vue'
import { introSteps, tracks, homeTab, accountButton } from './steps'

const route = useRoute()
const router = useRouter()

const active = ref(true)
const index = ref(0)
// Erst nach der Wahl (Lehrer oder Schüler) steht fest, welches Tutorial weiterläuft.
const track = ref(null)
const steps = computed(() => [...introSteps, ...(track.value ? tracks[track.value] : [])])
const step = computed(() => steps.value[index.value])
const total = computed(() => steps.value.length)
// Vor der Wahl: Fortschritt so zeigen, als ginge es mit dem kürzeren Schüler-Tutorial weiter
const shownTotal = computed(() => (track.value ? total.value : introSteps.length + tracks.schueler.length))
const isChoose = computed(() => step.value.wait === 'choose')
function choose(t) {
  track.value = t
  accountButton(t)?.click() // trägt das passende Demo-Konto ein
  index.value++
}

// Muss man erst zur Startseite zurück?
const reached = computed(() => !!step.value.wait?.route?.test(route.path))
const detour = computed(() => !!step.value.from && route.path !== step.value.from && !reached.value)
const text = computed(() => detour.value
  ? 'Erst mal zurück zur Startseite: Tipp unten links auf „Home“.'
  : step.value.say)

// ---------- Ziel finden und hervorheben ----------
const target = ref(null)
const rect = ref(null)
const view = ref({ w: innerWidth, h: innerHeight })
const missingSince = ref(0)
const stuck = ref(false)
let timer = null
let scrolledFor = null

function findTarget() {
  if (detour.value) return homeTab()
  return step.value.target?.() ?? null
}

// Ziel in den freien Bereich über Klemmi schieben, damit die Sprechblase nichts verdeckt.
const coachEl = ref(null)
function bringIntoView(el) {
  let r = el.getBoundingClientRect()
  // erst seitlich, z. B. Knöpfe am Ende breiter Tabellen auf dem Handy
  if (r.left < 0 || r.right > innerWidth) { el.scrollIntoView({ block: 'nearest', inline: 'center' }); r = el.getBoundingClientRect() }
  const coachH = (coachEl.value?.offsetHeight ?? 180) + 20
  const freeTop = 70
  const freeBottom = innerHeight - coachH
  const want = r.height <= freeBottom - freeTop ? freeTop + (freeBottom - freeTop - r.height) / 2 : freeTop
  const delta = r.top - want
  if (Math.abs(delta) > 6) window.scrollBy({ top: delta, behavior: 'smooth' })
}

function tick() {
  if (!active.value) return
  view.value = { w: innerWidth, h: innerHeight }
  const el = findTarget()
  target.value = el
  if (el) {
    if (scrolledFor !== el) {
      scrolledFor = el
      bringIntoView(el)
    }
    const r = el.getBoundingClientRect()
    const pad = 6
    const next = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 }
    const old = rect.value
    if (!old || Math.abs(old.top - next.top) + Math.abs(old.left - next.left) + Math.abs(old.width - next.width) + Math.abs(old.height - next.height) > 0.5) rect.value = next
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
    if (index.value < total.value - 1) index.value++
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
// Erklärschritte auch mit Enter oder Leertaste weiter (PC)
function onKey(e) {
  if (!active.value || !isInfo.value || isEnd.value || isChoose.value || !['Enter', ' '].includes(e.key)) return
  e.preventDefault()
  if (talking.value) finishTyping()
  else advance()
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
  }, 26)
}
const talking = computed(() => shown.value.length < text.value.length)
const finishTyping = () => { clearInterval(typer); shown.value = text.value }
watch(text, (t) => type(t))
watch(index, () => { scrolledFor = null; missingSince.value = 0; stuck.value = false; confirmQuit.value = false; nextTick(tick) })

const isEnd = computed(() => step.value.wait === 'end')
const isInfo = computed(() => !detour.value && (step.value.wait === 'next' || isEnd.value || isChoose.value))
const isAction = computed(() => !isInfo.value && !!rect.value)
// Bei Tipp-Schritten zeigt Klemmi mit dem Arm aufs Ziel
const mood = computed(() => (isAction.value ? 'point' : step.value.mood ?? 'idle'))

// Klemmi oben, wenn das Ziel unten liegt, sonst unten
const placeTop = computed(() => {
  const r = rect.value
  if (!r) return false
  const coachH = (coachEl.value?.offsetHeight ?? 180) + 20
  return r.top + r.height > view.value.h - coachH && r.top + r.height / 2 > view.value.h * 0.5
})

// Lichtkegel: ein Loch mit riesigem Schatten drumherum; ohne Ziel schrumpft das Loch in der Mitte zusammen.
const spot = computed(() => {
  const r = rect.value ?? { top: view.value.h / 2, left: view.value.w / 2, width: 0, height: 0 }
  return { top: `${r.top}px`, left: `${r.left}px`, width: `${r.width}px`, height: `${r.height}px` }
})
// Sperrflächen (unsichtbar): nur das Loch ist bei Tipp-Schritten antippbar.
const blockers = computed(() => {
  const r = rect.value
  if (!r || isInfo.value) return [{ top: 0, left: 0, width: '100vw', height: '100vh' }]
  const px = (n) => `${Math.max(0, n)}px`
  return [
    { top: 0, left: 0, width: '100vw', height: px(r.top) },
    { top: px(r.top + r.height), left: 0, width: '100vw', height: `calc(100vh - ${px(r.top + r.height)})` },
    { top: px(r.top), left: 0, width: px(r.left), height: px(r.height) },
    { top: px(r.top), left: px(r.left + r.width), width: `calc(100vw - ${px(r.left + r.width)})`, height: px(r.height) },
  ]
})

// Pfeil mit Schild „Hier tippen!“: über dem Ziel nach unten zeigend, sonst darunter nach oben.
const ARROW = 64
const arrow = computed(() => {
  if (!isAction.value) return null
  const r = rect.value
  const roomAbove = r.top > ARROW + 46
  const roomBelow = r.top + r.height + ARROW + 46 < view.value.h
  const down = placeTop.value ? !roomBelow && roomAbove : roomAbove || !roomBelow
  const x = Math.min(Math.max(r.left + r.width / 2, 70), view.value.w - 70)
  const y = down ? r.top - 8 : r.top + r.height + 8
  return { down, style: { left: `${x}px`, top: `${y}px` } }
})
const hint = computed(() => (detour.value ? 'Hier tippen!' : step.value.hint ?? 'Hier tippen!'))
// Tipp-Wellen nur auf kleinen Zielen (Knöpfe, Kacheln), nicht auf ganzen Flächen
const ripple = computed(() => {
  const r = rect.value
  if (!isAction.value || r.width > 360 || r.height > 200) return null
  return { left: `${r.left + r.width / 2}px`, top: `${r.top + r.height / 2}px` }
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
  w: `${10 + (i % 3) * 5}px`,
}))

onMounted(() => {
  type(text.value)
  timer = setInterval(tick, 100)
  document.addEventListener('click', onClick, true)
  document.addEventListener('keydown', onKey)
  window.addEventListener('tn-api', onApi)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(typer)
  document.removeEventListener('click', onClick, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('tn-api', onApi)
})
</script>

<template>
  <div v-if="active" class="tutorial">
    <div class="spot" :class="{ action: isAction, none: !rect }" :style="spot" aria-hidden="true"></div>
    <div v-for="(b, i) in blockers" :key="i" class="blocker" :style="b"></div>

    <div v-if="ripple" class="ripple" :style="ripple" aria-hidden="true"><i></i><i></i></div>
    <div v-if="arrow" :key="`a${index}-${detour}`" class="arrow" :class="arrow.down ? 'down' : 'up'" :style="arrow.style" aria-hidden="true">
      <span class="tag">{{ hint }}</span>
      <svg width="46" :height="ARROW - 10" viewBox="0 0 46 54">
        <path d="M15 2 H31 V30 H44 L23 52 L2 30 H15 Z" fill="#f6d43c" stroke="#3a2a00" stroke-width="3" stroke-linejoin="round" />
        <path d="M19 6 H23 V32" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.55" />
      </svg>
    </div>

    <div v-if="isEnd" class="confetti" aria-hidden="true">
      <i v-for="(c, i) in confetti" :key="i" :style="{ left: c.left, animationDelay: c.delay, animationDuration: c.duration, background: c.color, width: c.w }"></i>
    </div>

    <!-- Wahl am Anfang: allein in der Mitte, links Lehrer, rechts Schüler -->
    <div v-if="isChoose" class="choose" role="dialog" aria-label="Wer bist du?">
      <p class="q">Wer bist du?</p>
      <div class="pick">
        <button type="button" class="role lehrer" @click="choose('lehrer')"><GraduationCap :size="54" :stroke-width="1.6" /><span>Lehrer</span></button>
        <button type="button" class="role schueler" @click="choose('schueler')"><Backpack :size="54" :stroke-width="1.6" /><span>Schüler</span></button>
      </div>
    </div>

    <div v-else ref="coachEl" class="coach" :class="{ top: placeTop, end: isEnd }" role="dialog" aria-live="polite" aria-label="Tutorial mit Klemmi">
      <Klemmi :talking="talking" :mood="mood" :size="isEnd ? 160 : 118" class="fig" />
      <div :key="index" class="bubble" @click="finishTyping">
        <div class="who">
          <span class="chapter">{{ step.chapter }}</span>
          <span class="stepno">{{ index + 1 }} / {{ shownTotal }}</span>
          <span v-if="track" class="track">{{ track === 'lehrer' ? 'Lehrer-Tutorial' : 'Schüler-Tutorial' }}</span>
          <button class="close" type="button" title="Tutorial beenden" aria-label="Tutorial beenden" @click.stop="confirmQuit = !confirmQuit"><X :size="18" /></button>
        </div>
        <p class="say"><strong class="name">Klemmi:</strong> {{ shown }}<span v-if="talking" class="caret">▍</span></p>
        <div class="brick" :style="{ '--p': `${((index + 1) / shownTotal) * 100}%` }"><i></i></div>
        <div v-if="confirmQuit" class="actions">
          <span class="note">Wirklich aufhören? Danach kannst du frei herumprobieren.</span>
          <button class="btn btn-sm" type="button" @click.stop="confirmQuit = false">Weitermachen</button>
          <button class="btn btn-sm btn-primary" type="button" @click.stop="quit">Beenden</button>
        </div>
        <div v-else-if="isEnd" class="actions">
          <button class="btn" type="button" @click.stop="restart"><RotateCcw :size="16" /> Nochmal von vorn</button>
          <button class="btn btn-primary" type="button" @click.stop="quit"><Compass :size="16" /> Frei erkunden</button>
        </div>
        <div v-else class="actions">
          <button v-if="stuck" class="btn btn-sm" type="button" @click.stop="rescue">Ich finde es nicht</button>
          <span v-else-if="!isInfo" class="note turn"><i></i> Du bist dran!</span>
          <button v-if="isInfo" class="btn btn-primary next" type="button" :disabled="talking" @click.stop="advance()">Weiter <ArrowRight :size="16" /></button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tutorial { position: fixed; inset: 0; z-index: 9000; pointer-events: none; --dim: rgba(3, 7, 14, 0.66); --yellow: #f6d43c; --red: #d01c0a; }

/* Lichtkegel: gleitet weich von Ziel zu Ziel */
.spot {
  position: fixed; border-radius: 14px; box-shadow: 0 0 0 200vmax var(--dim), 0 0 0 3px var(--yellow), 0 0 26px 6px rgba(246, 212, 60, 0.45);
  transition: top 0.45s cubic-bezier(0.22, 1, 0.36, 1), left 0.45s cubic-bezier(0.22, 1, 0.36, 1), width 0.45s cubic-bezier(0.22, 1, 0.36, 1), height 0.45s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s;
}
.spot.none { box-shadow: 0 0 0 200vmax var(--dim); }
.spot.action { animation: glow 1.4s ease-in-out infinite; }
.blocker { position: fixed; pointer-events: auto; }

/* Pfeil, hüpft aufs Ziel zu */
.arrow { position: fixed; display: flex; flex-direction: column; align-items: center; transform: translate(-50%, -100%); animation: arrow-in 0.35s ease-out; }
.arrow.up { transform: translate(-50%, 0); flex-direction: column-reverse; }
.arrow.up svg { transform: rotate(180deg); }
.arrow svg { display: block; filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.5)); }
.arrow.down svg { animation: bounce-down 0.8s ease-in-out infinite; }
.arrow.up svg { animation: bounce-up 0.8s ease-in-out infinite; }
.tag {
  background: var(--red); color: #fff; font-weight: 700; font-size: 0.85rem; padding: 4px 10px; border-radius: 999px; white-space: nowrap;
  box-shadow: 0 3px 0 #8f1206, 0 6px 14px rgba(0, 0, 0, 0.4); margin: 4px 0;
}

/* Tipp-Wellen in der Mitte des Ziels */
.ripple { position: fixed; width: 0; height: 0; }
.ripple i { position: absolute; left: -22px; top: -22px; width: 44px; height: 44px; border-radius: 50%; border: 3px solid var(--yellow); animation: ripple 1.6s ease-out infinite; }
.ripple i:nth-child(2) { animation-delay: 0.8s; }

/* Wahl Lehrer / Schüler */
.choose { position: fixed; inset: 0; background: radial-gradient(circle at 50% 45%, #141c2b, #05080e 70%); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; pointer-events: auto; padding: 16px; }
.q { margin: 0; color: #fff; font-size: 1.5rem; font-weight: 700; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.6); animation: pop 0.4s ease-out; }
.pick { display: flex; gap: 22px; }
.role {
  width: 190px; height: 190px; border-radius: 22px; border: 3px solid var(--yellow); background: #fffdf3; color: #1d1d1d;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; font-size: 1.35rem; font-weight: 700;
  box-shadow: 0 7px 0 #d9b622, 0 16px 34px rgba(0, 0, 0, 0.5); cursor: pointer; transition: transform 0.15s, box-shadow 0.15s;
  animation: pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.role.schueler { animation-delay: 0.08s; }
.role.lehrer svg { color: #0d5ccc; }
.role.schueler svg { color: var(--red); }
.role:hover { transform: translateY(-4px); box-shadow: 0 11px 0 #d9b622, 0 20px 38px rgba(0, 0, 0, 0.5); }
.role:active { transform: translateY(5px); box-shadow: 0 2px 0 #d9b622, 0 8px 20px rgba(0, 0, 0, 0.5); }
@media (max-width: 460px) { .role { width: 42vw; height: 42vw; font-size: 1.15rem; } .pick { gap: 12px; } }

/* Klemmi und Sprechblase */
.coach {
  position: fixed; left: 12px; right: 12px; bottom: 12px; margin: 0 auto; max-width: 660px;
  display: flex; align-items: flex-end; gap: 4px; pointer-events: auto; animation: slide-in 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}
.coach.top { bottom: auto; top: 12px; align-items: flex-start; }
.coach.end { bottom: max(12px, calc(50vh - 120px)); }
.fig { flex: none; }
.bubble {
  position: relative; flex: 1; min-width: 0; background: #fffdf3; color: #1d1d1d; border-radius: 18px; padding: 10px 14px 12px;
  box-shadow: 0 6px 0 #d9b622, 0 14px 34px rgba(0, 0, 0, 0.5); border: 3px solid var(--yellow); animation: pop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.bubble::before { content: ''; position: absolute; left: -15px; bottom: 34px; border: 9px solid transparent; border-right: 12px solid var(--yellow); }
.coach.top .bubble::before { bottom: auto; top: 30px; }
.who { display: flex; align-items: center; gap: 8px; }
.chapter { white-space: nowrap; background: var(--red); color: #fff; font-weight: 700; font-size: 0.78rem; letter-spacing: 0.02em; padding: 3px 10px; border-radius: 999px; box-shadow: 0 2px 0 #8f1206; }
.stepno, .track { color: #6d6a5e; font-size: 0.8rem; font-variant-numeric: tabular-nums; }
.track::before { content: '· '; }
.close { margin-left: auto; background: none; border: 0; color: #6d6a5e; padding: 4px; border-radius: 8px; min-width: 34px; min-height: 34px; display: grid; place-items: center; }
.close:hover { background: rgba(0, 0, 0, 0.06); }
.say { margin: 8px 0 10px; font-size: 1.02rem; line-height: 1.5; min-height: 3em; }
.name { color: var(--red); }
.caret { color: var(--red); animation: caret 0.8s steps(1) infinite; }

/* Fortschritt als Lego-Stein mit Noppen */
.brick { position: relative; height: 12px; background: #ece3bf; border-radius: 4px; overflow: hidden; margin-bottom: 10px; }
.brick i {
  position: absolute; inset: 0 auto 0 0; width: var(--p); border-radius: 4px; transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
  background: radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.35) 0 3px, transparent 3.5px) 0 0 / 14px 12px, linear-gradient(#e2311d, #b81707);
}
.actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.note { margin-right: auto; color: #57544a; font-size: 0.88rem; }
.turn { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #8f1206; }
.turn i { width: 10px; height: 10px; border-radius: 50%; background: var(--red); animation: blink 1s ease-in-out infinite; }
.bubble .btn:not(.btn-primary) { background: #fff; color: #1d1d1d; border-color: #d8d2b8; }
.next { gap: 6px; }

.confetti { position: fixed; inset: 0; overflow: hidden; pointer-events: none; }
.confetti i { position: absolute; top: -20px; height: 10px; border-radius: 2px; animation: fall linear infinite; opacity: 0.95;
  background-image: radial-gradient(circle, rgba(255, 255, 255, 0.4) 0 2px, transparent 2.5px) !important; background-size: 6px 10px !important; }

@keyframes glow { 0%, 100% { box-shadow: 0 0 0 200vmax var(--dim), 0 0 0 3px var(--yellow), 0 0 14px 2px rgba(246, 212, 60, 0.4); } 50% { box-shadow: 0 0 0 200vmax var(--dim), 0 0 0 5px var(--yellow), 0 0 34px 10px rgba(246, 212, 60, 0.7); } }
@keyframes bounce-down { 0%, 100% { transform: translateY(-6px); } 50% { transform: translateY(4px); } }
@keyframes bounce-up { 0%, 100% { transform: rotate(180deg) translateY(-6px); } 50% { transform: rotate(180deg) translateY(4px); } }
@keyframes arrow-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes ripple { from { transform: scale(0.4); opacity: 1; } to { transform: scale(2.4); opacity: 0; } }
@keyframes slide-in { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: none; } }
@keyframes pop { from { opacity: 0; transform: scale(0.94); } to { opacity: 1; transform: none; } }
@keyframes caret { 50% { opacity: 0; } }
@keyframes blink { 50% { opacity: 0.3; } }
@keyframes fall { from { transform: translateY(0) rotate(0); } to { transform: translateY(110vh) rotate(720deg); } }

@media (max-width: 560px) {
  .coach { left: 6px; right: 6px; bottom: 8px; gap: 0; }
  .coach.top { top: 8px; }
  .fig { width: 60px; height: 84px; }
  .say { font-size: 0.95rem; }
  .bubble::before { display: none; }
  .track { display: none; }
}
:global([data-tn-motion='reduced']) .spot, :global([data-tn-motion='reduced']) .arrow svg, :global([data-tn-motion='reduced']) .ripple i { animation: none; transition: none; }
:global([data-tn-motion='reduced']) .confetti { display: none; }
@media (prefers-reduced-motion: reduce) {
  .spot, .arrow svg, .ripple i, .bubble, .coach { animation: none; transition: none; }
  .confetti { display: none; }
}
</style>
