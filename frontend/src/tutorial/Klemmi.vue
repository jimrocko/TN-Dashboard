<script setup>
// Klemmi: die rote Standard-Minifigur, die durchs Tutorial führt (reines SVG, keine Bilder).
// mood: 'idle' | 'wave' (winkt) | 'point' (zeigt) | 'cheer' (jubelt mit beiden Armen)
defineProps({
  talking: { type: Boolean, default: false },
  mood: { type: String, default: 'idle' },
  size: { type: Number, default: 110 },
})
</script>

<template>
  <svg class="klemmi" :class="[`m-${mood}`, { talking }]" :width="size * 0.72" :height="size" viewBox="0 0 100 140" role="img" aria-label="Klemmi, die Lego-Figur">
    <g class="body">
      <!-- Beine und Hüfte -->
      <rect x="22" y="94" width="56" height="9" rx="2" fill="#0a4fb0" />
      <rect x="23" y="102" width="26" height="30" rx="2" fill="#0d5ccc" />
      <rect x="51" y="102" width="26" height="30" rx="2" fill="#0d5ccc" />
      <rect x="21" y="128" width="29" height="10" rx="3" fill="#0a4fb0" />
      <rect x="50" y="128" width="29" height="10" rx="3" fill="#0a4fb0" />
      <rect x="47" y="102" width="6" height="12" fill="#083f8c" />

      <!-- Linker Arm (vom Betrachter aus) -->
      <g class="arm arm-l">
        <path d="M30 50 L20 54 Q14 70 15 84 L25 85 Q25 70 31 60 Z" fill="#c91a09" stroke="#8f1206" stroke-width="1" />
        <rect x="16" y="83" width="8" height="5" fill="#f2cd37" />
        <circle cx="20" cy="93" r="5" fill="none" stroke="#f2cd37" stroke-width="4" stroke-dasharray="24 8" transform="rotate(60 20 93)" />
      </g>
      <!-- Rechter Arm: winkt und zeigt -->
      <g class="arm arm-r">
        <path d="M70 50 L80 54 Q86 70 85 84 L75 85 Q75 70 69 60 Z" fill="#c91a09" stroke="#8f1206" stroke-width="1" />
        <rect x="76" y="83" width="8" height="5" fill="#f2cd37" />
        <circle cx="80" cy="93" r="5" fill="none" stroke="#f2cd37" stroke-width="4" stroke-dasharray="24 8" transform="rotate(60 80 93)" />
      </g>

      <!-- Oberkörper -->
      <path d="M31 46 L69 46 L77 95 L23 95 Z" fill="#d01c0a" stroke="#8f1206" stroke-width="1.2" />
      <path d="M36 50 L44 50 L41 90 L30 90 Z" fill="#fff" opacity="0.08" />

      <!-- Kopf mit Noppe -->
      <g class="head">
        <rect x="41" y="38" width="18" height="9" fill="#e9c22f" />
        <rect x="39" y="3" width="22" height="8" rx="2" fill="#e9c22f" stroke="#c49d10" stroke-width="1" />
        <rect x="29" y="9" width="42" height="32" rx="10" fill="#f6d43c" stroke="#c49d10" stroke-width="1.2" />
        <rect x="34" y="12" width="7" height="22" rx="3.5" fill="#fff" opacity="0.18" />
        <g class="eyes">
          <ellipse cx="42.5" cy="21" rx="2.6" ry="3.6" fill="#1b1b1b" />
          <ellipse cx="57.5" cy="21" rx="2.6" ry="3.6" fill="#1b1b1b" />
        </g>
        <path class="smile" d="M40 29 Q50 37 60 29" fill="none" stroke="#1b1b1b" stroke-width="2.2" stroke-linecap="round" />
        <ellipse class="mouth" cx="50" cy="31" rx="6" ry="4" fill="#3a1010" />
      </g>
    </g>
  </svg>
</template>

<style scoped>
.klemmi { display: block; overflow: visible; filter: drop-shadow(0 6px 10px rgba(0, 0, 0, 0.35)); }
.body { animation: bob 4s ease-in-out infinite; transform-origin: 50% 100%; }
.eyes { transform-box: fill-box; transform-origin: center; animation: blink 4.2s infinite; }
.mouth { opacity: 0; transform-box: fill-box; transform-origin: center; }
.talking .smile { opacity: 0; }
.talking .mouth { opacity: 1; animation: talk 0.18s ease-in-out infinite alternate; }
.talking .head { animation: nod 0.5s ease-in-out infinite alternate; transform-box: fill-box; transform-origin: 50% 100%; }

.arm { transform-box: view-box; }
.arm-l { transform-origin: 28px 52px; }
.arm-r { transform-origin: 72px 52px; }
.m-wave .arm-r { animation: wave 3s ease-in-out 1; }
.m-point .arm-r { transform: rotate(-95deg); transition: transform 0.3s; }
.m-cheer .arm-r { animation: cheer-r 3s ease-in-out 1 forwards; }
.m-cheer .arm-l { animation: cheer-l 3s ease-in-out 1 forwards; }
.m-cheer .body { animation: jump 3s ease-in-out 1; }

@keyframes bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
@keyframes blink { 0%, 92%, 100% { transform: scaleY(1); } 95% { transform: scaleY(0.1); } }
@keyframes talk { from { transform: scaleY(0.25); } to { transform: scaleY(1); } }
@keyframes nod { from { transform: rotate(-2deg); } to { transform: rotate(2deg); } }
@keyframes wave { 0%, 100% { transform: rotate(0); } 15%, 45%, 75% { transform: rotate(-150deg); } 30%, 60% { transform: rotate(-115deg); } 85% { transform: rotate(-150deg); } }
@keyframes cheer-r { 0% { transform: rotate(0); } 12%, 40%, 70%, 100% { transform: rotate(-150deg); } 26%, 55% { transform: rotate(-165deg); } }
@keyframes cheer-l { 0% { transform: rotate(0); } 12%, 40%, 70%, 100% { transform: rotate(150deg); } 26%, 55% { transform: rotate(165deg); } }
@keyframes jump { 0%, 40%, 100% { transform: translateY(0); } 15% { transform: translateY(-10px); } 28% { transform: translateY(0); } 34% { transform: translateY(-5px); } }

:global([data-tn-motion='reduced']) .klemmi *, :global([data-tn-motion='reduced']) .klemmi { animation: none !important; }
@media (prefers-reduced-motion: reduce) { .klemmi, .klemmi * { animation: none !important; } }
</style>
