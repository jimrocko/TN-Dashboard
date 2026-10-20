<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Klemmi from '../tutorial/Klemmi.vue'

// Startanimation: Der Logo-Stein setzt sich zusammen, Noppen ploppen auf, ein Ladebalken aus Noppen leuchtet,
// Klemmi winkt aus der Ecke, dann blendet alles zur App über. Antippen oder eine Taste überspringt sie.
// Bei „Bewegung reduzieren“ nur eine kurze Einblendung ohne Bewegung.
const emit = defineEmits(['done'])
const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches
const DAUER = reduced ? 1400 : 3600
const STUDS = ['#c91a09', '#f2cd37', '#0d5ccc', '#237841', '#fe8a18', '#c91a09', '#f2cd37', '#0d5ccc']
const TEXTE = ['Kästchen zählen …', 'Noppen polieren …', 'Bereit!']

const out = ref(false)
const klemmi = ref(false)
const text = ref(0)
const timers = []
const later = (ms, fn) => timers.push(setTimeout(fn, ms))

function finish() {
  if (out.value) return
  out.value = true
  later(reduced ? 200 : 420, () => emit('done'))
}
// Taste nicht weiterreichen: sonst löst z. B. Leertaste den Knopf „Jetzt abspielen“ gleich wieder aus
const onKey = (e) => { if (['Shift', 'Control', 'Alt', 'Meta'].includes(e.key)) return; e.preventDefault(); e.stopPropagation(); finish() }

onMounted(() => {
  document.activeElement?.blur?.()
  window.addEventListener('keydown', onKey, true)
  if (!reduced) {
    later(1500, () => { text.value = 1 })
    later(2350, () => { text.value = 2 })
    later(2000, () => { klemmi.value = true })
  }
  later(DAUER, finish)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey, true)
  timers.forEach(clearTimeout)
})
</script>

<template>
  <div class="boot" :class="{ out, reduced }" role="status" aria-label="TN Dashboard startet" @pointerdown="finish">
    <div class="glow" />
    <div class="center">
      <div class="logo">
        <span class="ring" />
        <svg viewBox="60 80 392 360" class="brick" aria-hidden="true">
          <g fill="none" stroke="#e8edf3" stroke-width="22" stroke-linejoin="round">
            <path class="face f-left" d="M112 190 V330 L256 400 V260 Z" fill="#c5ced9" />
            <path class="face f-right" d="M400 190 V330 L256 400 V260 Z" fill="#9aa6b6" />
            <path class="face f-top" d="M256 120 L400 190 L256 260 L112 190 Z" fill="#e8edf3" fill-opacity=".95" />
          </g>
          <g fill="#0a0f17" fill-opacity=".25">
            <ellipse class="stud s1" cx="216" cy="172" rx="26" ry="13" /><ellipse class="stud s2" cx="296" cy="172" rx="26" ry="13" />
            <ellipse class="stud s3" cx="216" cy="210" rx="26" ry="13" /><ellipse class="stud s4" cx="296" cy="210" rx="26" ry="13" />
          </g>
        </svg>
        <span class="sweep" />
      </div>
      <h1 class="title"><span v-for="(c, i) in 'TN Dashboard'" :key="i" :class="{ tn: i < 2 }" :style="{ animationDelay: `${1.05 + i * 0.035}s` }">{{ c === ' ' ? ' ' : c }}</span></h1>
      <div class="bar" aria-hidden="true">
        <i v-for="(c, i) in STUDS" :key="i" :style="{ '--c': c, animationDelay: `${1.45 + i * 0.13}s` }" />
      </div>
      <p class="sub"><Transition name="swap" mode="out-in"><span :key="text">{{ reduced ? 'Wird gestartet …' : TEXTE[text] }}</span></Transition></p>
    </div>
    <div v-if="klemmi" class="klemmi-corner"><Klemmi mood="wave" :size="96" /></div>
    <span class="skip">Antippen zum Überspringen</span>
  </div>
</template>

<style scoped>
.boot {
  position: fixed; inset: 0; z-index: 10000; display: grid; place-items: center; overflow: hidden; cursor: pointer;
  background: radial-gradient(ellipse at 50% 42%, #142033 0%, #0a0f17 62%, #05080d 100%); color: #e8edf3;
  animation: fade-in 0.25s ease-out both; user-select: none; -webkit-user-select: none; touch-action: manipulation;
}
/* Noppen-Raster im Hintergrund, kaum sichtbar */
.boot::before {
  content: ''; position: absolute; inset: -40px; opacity: 0;
  background-image: radial-gradient(circle, rgba(232, 237, 243, 0.07) 0 5px, transparent 6px); background-size: 36px 36px;
  animation: grid-in 1.6s ease-out 0.1s forwards;
}
.boot.out { animation: fade-out 0.42s ease-in forwards; }
.boot.out .center { animation: zoom-out 0.42s ease-in forwards; }
.glow { position: absolute; width: 520px; height: 520px; border-radius: 50%; background: radial-gradient(circle, rgba(77, 144, 254, 0.28), transparent 65%); opacity: 0; animation: glow 1.2s ease-out 0.85s forwards; }
.center { position: relative; display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 16px; }

.logo { position: relative; width: 170px; height: 156px; }
.brick { width: 100%; height: 100%; overflow: visible; filter: drop-shadow(0 12px 28px rgba(0, 0, 0, 0.55)); animation: click 0.35s ease-out 0.88s; }
.face { transform-box: view-box; opacity: 0; animation: 0.7s cubic-bezier(0.2, 1.4, 0.4, 1) forwards; }
.f-left { animation-name: from-left; animation-delay: 0.15s; }
.f-right { animation-name: from-right; animation-delay: 0.27s; }
.f-top { animation-name: from-top; animation-delay: 0.42s; }
.stud { transform-box: fill-box; transform-origin: center; transform: scale(0); animation: pop 0.32s cubic-bezier(0.3, 1.8, 0.5, 1) forwards; }
.s1 { animation-delay: 0.95s; } .s2 { animation-delay: 1.02s; } .s3 { animation-delay: 1.09s; } .s4 { animation-delay: 1.16s; }
.ring { position: absolute; left: 50%; top: 52%; width: 120px; height: 120px; margin: -60px 0 0 -60px; border-radius: 50%; border: 3px solid rgba(232, 237, 243, 0.55); opacity: 0; animation: ring 0.75s ease-out 0.9s; }
/* Lichtstreifen über den Stein */
.sweep { position: absolute; inset: 0; overflow: hidden; pointer-events: none;
  -webkit-mask: radial-gradient(ellipse at 50% 55%, #000 55%, transparent 72%); mask: radial-gradient(ellipse at 50% 55%, #000 55%, transparent 72%); }
.sweep::after { content: ''; position: absolute; top: -20%; bottom: -20%; width: 40%; left: -60%; transform: skewX(-20deg);
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent); animation: sweep 0.8s ease-in-out 1.3s; }

.title { margin: 4px 0 0; font-size: clamp(1.7rem, 6vw, 2.4rem); font-weight: 800; letter-spacing: 0.01em; white-space: pre; }
.title span { display: inline-block; opacity: 0; transform: translateY(14px); animation: rise 0.45s cubic-bezier(0.2, 1.3, 0.4, 1) forwards; }
.title .tn { color: #f2cd37; }

.bar { display: flex; gap: 9px; padding: 9px 12px; border-radius: 10px; background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(232, 237, 243, 0.12); opacity: 0; animation: fade-in 0.3s ease-out 1.3s forwards; }
.bar i { width: 16px; height: 16px; border-radius: 50%; background: #1d2939; box-shadow: inset 0 -2px 0 rgba(0, 0, 0, 0.35); animation: stud-on 0.3s ease-out forwards; }
.sub { margin: 0; min-height: 1.3em; font-size: 0.92rem; color: #9aa6b6; opacity: 0; animation: fade-in 0.3s ease-out 1.4s forwards; }
.swap-enter-active, .swap-leave-active { transition: opacity 0.15s, transform 0.15s; }
.swap-enter-from { opacity: 0; transform: translateY(5px); }
.swap-leave-to { opacity: 0; transform: translateY(-5px); }

.klemmi-corner { position: absolute; right: max(10px, 4vw); bottom: -8px; animation: peek 0.6s cubic-bezier(0.2, 1.3, 0.4, 1) both; }
.skip { position: absolute; bottom: max(14px, env(safe-area-inset-bottom)); left: 0; right: 0; text-align: center; font-size: 0.75rem; color: #5c6b7f; opacity: 0; animation: fade-in 0.4s ease-out 1.8s forwards; }

@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes fade-out { to { opacity: 0; } }
@keyframes zoom-out { to { transform: scale(1.08); } }
@keyframes grid-in { to { opacity: 1; } }
@keyframes glow { 0% { opacity: 0; transform: scale(0.6); } 40% { opacity: 1; } 100% { opacity: 0.55; transform: scale(1); } }
@keyframes from-left { from { opacity: 0; transform: translate(-260px, 160px) rotate(-18deg); } to { opacity: 1; transform: none; } }
@keyframes from-right { from { opacity: 0; transform: translate(260px, 160px) rotate(18deg); } to { opacity: 1; transform: none; } }
@keyframes from-top { from { opacity: 0; transform: translateY(-300px); } to { opacity: 1; transform: none; } }
@keyframes click { 0% { transform: scale(1); } 35% { transform: scale(0.94, 1.04); } 70% { transform: scale(1.04, 0.97); } 100% { transform: scale(1); } }
@keyframes pop { to { transform: scale(1); } }
@keyframes ring { 0% { opacity: 0.9; transform: scale(0.6); } 100% { opacity: 0; transform: scale(2.1); } }
@keyframes sweep { from { left: -60%; } to { left: 130%; } }
@keyframes rise { to { opacity: 1; transform: none; } }
@keyframes stud-on { to { background: var(--c); box-shadow: inset 0 -2px 0 rgba(0, 0, 0, 0.25), 0 0 10px var(--c); } }
@keyframes peek { from { transform: translateY(120%); } to { transform: translateY(0); } }

/* Bewegung reduzieren: alles sofort sichtbar, nur Ein- und Ausblenden */
.reduced *, .reduced::before { animation: none !important; }
.reduced .face, .reduced .title span, .reduced .bar, .reduced .sub, .reduced .skip { opacity: 1; transform: none; }
.reduced .stud { transform: none; }
.reduced::before { opacity: 1; }
.reduced .bar i { background: var(--c); }
.reduced .glow { opacity: 0.55; }
</style>
