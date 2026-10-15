<script setup>
import { Home, Settings, Code2 } from 'lucide-vue-next'
</script>

<template>
  <nav class="bottom">
    <div class="page inner">
      <RouterLink to="/" class="tab" exact-active-class="active"><Home :size="26" /><span>Home</span></RouterLink>
      <div class="spacer"></div>
      <RouterLink to="/einstellungen" class="tab" active-class="active"><Settings :size="24" /><span>Einstellungen</span></RouterLink>
      <RouterLink to="/dev" class="tab" active-class="active"><Code2 :size="24" /><span>Dev-Bereich</span></RouterLink>
    </div>
  </nav>
</template>

<style scoped>
.bottom {
  position: fixed; inset: auto 0 0 0; z-index: 10;
  background: var(--nav-bg); backdrop-filter: blur(12px);
  border-top: 1px solid var(--border);
  padding-bottom: env(safe-area-inset-bottom);
}
.inner { display: flex; align-items: stretch; padding-top: 0; padding-bottom: 0; }
.spacer { flex: 1; }
.tab { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 14px 24px 12px; color: var(--text-muted); font-size: 14px; min-width: 110px; border-bottom: 2px solid transparent; }
.tab.active { color: var(--text); border-bottom-color: var(--text); }
@media (max-width: 560px) { .tab { min-width: 0; padding: 12px 14px; font-size: 12px; } }
</style>
