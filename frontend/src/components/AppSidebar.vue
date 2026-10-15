<script setup>
import { computed } from 'vue'
import { GraduationCap, Settings, Code2, X } from 'lucide-vue-next'
import { modules, navTop } from '../modules'
import { useAuth } from '../stores/auth'
import logo from '../assets/icon.svg'

defineProps({ open: Boolean })
defineEmits(['close'])
const auth = useAuth()

const groups = computed(() => {
  const out = []
  for (const m of modules) {
    let g = out.find((x) => x.name === m.group)
    if (!g) out.push((g = { name: m.group, items: [] }))
    g.items.push(m)
  }
  return out
})
</script>

<template>
  <div class="backdrop" :class="{ show: open }" @click="$emit('close')"></div>
  <aside class="sidebar" :class="{ open }">
    <div class="brand">
      <img :src="logo" alt="" />
      <div>
        <div class="name">TN Dashboard</div>
        <div class="sub">Lego-Shop</div>
      </div>
      <button class="btn btn-ghost btn-sm close" @click="$emit('close')" aria-label="Menü schließen"><X :size="18" /></button>
    </div>

    <nav>
      <RouterLink v-for="n in navTop" :key="n.path" :to="n.path" class="item" exact-active-class="active">
        <component :is="n.icon" :size="18" /> {{ n.title }}
      </RouterLink>

      <template v-for="g in groups" :key="g.name">
        <div class="group">{{ g.name }}</div>
        <RouterLink v-for="m in g.items" :key="m.id" :to="m.path" class="item" active-class="active">
          <component :is="m.icon" :size="18" />
          <span class="label">{{ m.title }}</span>
          <span v-if="m.status !== 'aktiv'" class="soon">{{ m.status === 'extern' ? 'Extern' : 'Geplant' }}</span>
        </RouterLink>
      </template>
    </nav>

    <div class="foot">
      <RouterLink v-if="auth.isStaff" to="/lehrer" class="item" active-class="active"><GraduationCap :size="18" /> Lehrer-Ansicht</RouterLink>
      <RouterLink to="/einstellungen" class="item" active-class="active"><Settings :size="18" /> Einstellungen</RouterLink>
      <RouterLink to="/dev" class="item" active-class="active"><Code2 :size="18" /> Dev-Bereich</RouterLink>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  width: var(--sidebar-w); flex: none; display: flex; flex-direction: column;
  background: var(--surface); border-right: 1px solid var(--border);
  position: sticky; top: 0; height: 100dvh; overflow-y: auto;
  padding-left: env(safe-area-inset-left, 0px);
}
.brand { display: flex; align-items: center; gap: 10px; padding: 16px 16px 14px; border-bottom: 1px solid var(--border); }
.brand img { width: 32px; height: 32px; border-radius: 7px; }
.name { font-weight: 600; font-size: 0.95rem; }
.sub { font-size: 0.78rem; color: var(--text-3); }
.close { display: none; margin-left: auto; }
nav { flex: 1; padding: 10px 8px; display: flex; flex-direction: column; gap: 1px; }
.group { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-3); padding: 14px 10px 6px; }
.item {
  display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-radius: var(--radius-sm);
  color: var(--text-2); font-size: 0.92rem;
}
.item:hover { background: var(--hover); color: var(--text); }
.item.active { background: var(--accent-soft); color: var(--accent-text); font-weight: 500; }
.label { flex: 1; min-width: 0; }
.soon { font-size: 0.68rem; color: var(--text-3); border: 1px solid var(--border-strong); border-radius: 4px; padding: 0 5px; white-space: nowrap; }
.foot { padding: 8px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 1px; }
.backdrop { display: none; }

@media (max-width: 900px) {
  .sidebar { position: fixed; left: 0; top: 0; bottom: 0; z-index: 40; transform: translateX(-100%); transition: transform .2s; box-shadow: var(--shadow); }
  .sidebar.open { transform: none; }
  .close { display: inline-flex; }
  .backdrop { display: block; position: fixed; inset: 0; z-index: 39; background: rgba(0, 0, 0, .45); opacity: 0; pointer-events: none; transition: opacity .2s; }
  .backdrop.show { opacity: 1; pointer-events: auto; }
}
</style>
