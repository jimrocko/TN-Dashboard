<script setup>
import { computed } from 'vue'
import { Lock } from 'lucide-vue-next'
import { progress, earnedCount, ACHIEVEMENTS } from '../utils/achievements'

// Abzeichen als Kacheln: erreichte farbig, offene grau mit Fortschritt (z. B. 3 / 25).
const props = defineProps({ stats: { type: Object, default: null } })
const list = computed(() => progress(props.stats))
const count = computed(() => earnedCount(props.stats))
</script>

<template>
  <section class="panel">
    <div class="panel-head"><h2>Abzeichen</h2><span class="faint small">{{ count }} von {{ ACHIEVEMENTS.length }} erreicht</span></div>
    <div class="grid">
      <div v-for="a in list" :key="a.id" class="badge" :class="{ done: a.done }" :title="a.text">
        <div class="medal"><component :is="a.done ? a.icon : Lock" :size="22" /></div>
        <div class="txt">
          <b>{{ a.title }}</b>
          <small>{{ a.text }}</small>
          <div v-if="!a.done && a.goal > 1" class="bar"><i :style="{ width: `${(a.have / a.goal) * 100}%` }"></i></div>
          <small v-if="!a.done && a.goal > 1" class="faint">{{ a.have }} / {{ a.goal }}</small>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; padding: var(--pad); }
.badge { display: flex; gap: 12px; align-items: flex-start; padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface-2); opacity: 0.6; }
.badge.done { opacity: 1; border-color: #c9a227; background: color-mix(in srgb, #f6d43c 10%, var(--surface)); }
.medal { flex: none; width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; background: var(--surface); color: var(--text-3); border: 1px solid var(--border); }
.done .medal { background: #f6d43c; color: #3a2a00; border-color: #c9a227; }
.txt { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.txt small { color: var(--text-3); font-size: 0.78rem; }
.bar { height: 5px; border-radius: 3px; background: var(--border); overflow: hidden; margin-top: 4px; }
.bar i { display: block; height: 100%; background: var(--accent); }
@media (max-width: 560px) { .grid { grid-template-columns: 1fr 1fr; } .badge { flex-direction: column; } }
</style>
