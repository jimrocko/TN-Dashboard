<script setup>
import { ArrowRight } from 'lucide-vue-next'
defineProps({ module: Object, data: Object })
</script>

<template>
  <RouterLink :to="module.path" class="tile">
    <component :is="module.icon" :size="48" :stroke-width="1.5" class="icon" />
    <h2>{{ module.title }}</h2>
    <p>{{ module.text }}</p>
    <div class="foot">
      <span class="status"><i class="dot" :class="module.status"></i>{{ module.tile(data) }}</span>
      <span class="arrow"><ArrowRight :size="18" /></span>
    </div>
  </RouterLink>
</template>

<style scoped>
.tile {
  display: flex; flex-direction: column; min-height: 318px;
  padding: 34px 22px 22px 40px; border-radius: var(--radius);
  background: linear-gradient(180deg, var(--surface) 0%, var(--surface-end) 100%);
  border: 1px solid var(--border);
  transition: border-color .15s, transform .15s;
}
.tile:hover { border-color: var(--border-strong); transform: translateY(-2px); }
.tile:active { transform: none; }
.icon { color: var(--icon); margin-bottom: 26px; }
h2 { position: relative; margin: 0 0 18px; font-size: clamp(20px, 2vw, 26px); font-weight: 500; }
h2::before { content: ''; position: absolute; left: -18px; top: 3px; bottom: 3px; width: 3px; border-radius: 2px; background: var(--accent-text); opacity: .8; }
p { margin: 0; color: var(--text-muted); font-size: 16px; line-height: 1.5; }
.foot { margin-top: auto; padding-top: 20px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.status { font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }
.dot { width: 8px; height: 8px; border-radius: 50%; flex: none; display: none; }
.dot.aktiv { display: block; background: var(--ok); }
.dot.extern { display: block; background: var(--warn); }
.arrow { width: 40px; height: 40px; flex: none; border-radius: 50%; display: grid; place-items: center; background: var(--surface-hover); border: 1px solid var(--border); }
@media (max-width: 900px) { .tile { min-height: 0; padding: 24px 18px 18px 30px; } h2 { font-size: 21px; } .icon { margin-bottom: 16px; } }
</style>
