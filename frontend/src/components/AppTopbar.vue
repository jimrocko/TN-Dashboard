<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Menu, Search, ChevronDown, History, GraduationCap, Settings, LogOut } from 'lucide-vue-next'
import { useAuth } from '../stores/auth'
import { pageTitle } from '../router'

defineEmits(['menu'])
const auth = useAuth()
const route = useRoute()
const router = useRouter()
const q = ref('')
const open = ref(false)

watch(() => route.query.q, (v) => { if (route.path === '/suche') q.value = v ?? '' }, { immediate: true })

const initials = computed(() => (auth.user?.display_name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase())

function search() {
  const term = q.value.trim()
  if (term) router.push({ path: '/suche', query: { q: term } })
}
async function logout() {
  open.value = false
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <header class="topbar">
    <button class="btn btn-ghost menu" @click="$emit('menu')" aria-label="Menü öffnen"><Menu :size="20" /></button>
    <h1 class="title">{{ pageTitle(route) }}</h1>
    <form class="search" role="search" @submit.prevent="search">
      <Search :size="16" class="icon" />
      <input v-model="q" type="search" enterkeyhint="search" placeholder="Teil-Nr., Name, Farbe, Lagerort, Auftrag …" aria-label="Suche" />
    </form>
    <div class="user" v-if="auth.user">
      <button class="chip" @click="open = !open" :aria-expanded="open">
        <span class="avatar">{{ initials }}</span>
        <span class="who"><span class="name">{{ auth.user.display_name }}</span><span class="role">{{ auth.roleLabel }}</span></span>
        <ChevronDown :size="16" class="faint" />
      </button>
      <div v-if="open" class="menu-backdrop" @click="open = false"></div>
      <div v-if="open" class="dropdown panel" @click="open = false">
        <RouterLink to="/profil" class="mi"><History :size="16" /> Meine Aktivitäten</RouterLink>
        <RouterLink v-if="auth.isStaff" to="/lehrer" class="mi"><GraduationCap :size="16" /> Lehrer-Ansicht</RouterLink>
        <RouterLink to="/einstellungen" class="mi"><Settings :size="16" /> Einstellungen</RouterLink>
        <button class="mi" @click.stop="logout"><LogOut :size="16" /> Abmelden</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  position: sticky; top: 0; z-index: 30; display: flex; align-items: center; gap: 14px;
  height: 58px; padding: 0 clamp(16px, 2.5vw, 28px); padding-top: env(safe-area-inset-top, 0px);
  box-sizing: content-box;
  background: color-mix(in srgb, var(--bg) 88%, transparent); backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--border);
}
.menu { display: none; padding: 0 8px; }
.title { font-size: 1.05rem; font-weight: 600; margin: 0; white-space: nowrap; }
.search { flex: 1; max-width: 520px; margin-left: auto; position: relative; }
.search input {
  width: 100%; height: 36px; padding: 0 12px 0 34px; border-radius: var(--radius-sm);
  border: 1px solid var(--border); background: var(--surface); color: var(--text);
}
.search input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
.search .icon { position: absolute; left: 11px; top: 10px; color: var(--text-3); }
.user { position: relative; }
.chip { display: flex; align-items: center; gap: 10px; background: none; border: 0; padding: 4px 6px; border-radius: var(--radius-sm); }
.chip:hover { background: var(--hover); }
.avatar { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-2); border: 1px solid var(--border-strong); font-size: 0.8rem; font-weight: 600; color: var(--text-2); }
.who { display: flex; flex-direction: column; line-height: 1.2; text-align: left; }
.name { font-size: 0.88rem; font-weight: 500; }
.role { font-size: 0.75rem; color: var(--text-3); }
.menu-backdrop { position: fixed; inset: 0; z-index: 1; }
.dropdown { position: absolute; right: 0; top: calc(100% + 6px); z-index: 2; min-width: 220px; padding: 6px; box-shadow: var(--shadow); }
.mi { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0; border-radius: var(--radius-sm); background: none; text-align: left; font-size: 0.9rem; }
.mi:hover { background: var(--hover); }
@media (max-width: 900px) { .menu { display: inline-flex; } }
@media (max-width: 640px) {
  .who { display: none; }
  .title { display: none; }
  .search { margin-left: 0; }
}
</style>
