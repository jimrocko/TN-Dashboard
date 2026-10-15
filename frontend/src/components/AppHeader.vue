<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { User, ChevronDown, LogOut, GraduationCap, History, Settings } from 'lucide-vue-next'
import { useAuth } from '../stores/auth'
import logo from '../assets/icon.svg'

const auth = useAuth()
const router = useRouter()
const open = ref(false)

async function logout() {
  open.value = false
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <header class="page header">
    <RouterLink to="/" class="brand">
      <img :src="logo" alt="" class="logo" />
      <div><div class="brand-tn">TN</div><div class="brand-sub">Dashboard</div></div>
    </RouterLink>

    <div class="user" v-if="auth.user">
      <button class="chip" @click="open = !open" :aria-expanded="open">
        <span class="avatar"><User :size="22" /></span>
        <span class="who">
          <span class="role">{{ auth.roleLabel }}</span>
          <span class="name">{{ auth.user.display_name }}</span>
          <span class="online"><i></i>Online</span>
        </span>
        <ChevronDown :size="18" class="chev" />
      </button>
      <div v-if="open" class="menu-backdrop" @click="open = false"></div>
      <div class="menu panel" v-if="open" @click="open = false">
        <RouterLink to="/profil" class="item"><History :size="18" /> Meine Aktivitäten</RouterLink>
        <RouterLink v-if="auth.isStaff" to="/lehrer" class="item"><GraduationCap :size="18" /> Lehrer-Ansicht</RouterLink>
        <RouterLink to="/einstellungen" class="item"><Settings :size="18" /> Einstellungen</RouterLink>
        <button class="item" @click.stop="logout"><LogOut :size="18" /> Abmelden</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.header { display: flex; align-items: center; justify-content: space-between; padding-top: calc(20px + env(safe-area-inset-top, 0px)); padding-bottom: 20px; }
.brand { display: flex; align-items: center; gap: 12px; }
.logo { width: 52px; height: 52px; border-radius: 12px; }
.brand-tn { font-weight: 800; font-style: italic; font-size: 24px; line-height: 1; }
.brand-sub { color: var(--text-muted); font-size: 18px; }
.user { position: relative; }
.chip { display: flex; align-items: center; gap: 12px; background: none; border: 0; padding: 4px; text-align: left; }
.avatar { width: 50px; height: 50px; border-radius: 50%; background: var(--surface-hover); border: 1px solid var(--border); display: grid; place-items: center; }
.who { display: flex; flex-direction: column; line-height: 1.3; }
.role { font-size: 12px; color: var(--text-muted); }
.name { font-size: 16px; }
.online { font-size: 13px; color: var(--ok); display: flex; align-items: center; gap: 6px; }
.online i { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); }
.chev { color: var(--text-muted); }
.menu-backdrop { position: fixed; inset: 0; z-index: 19; }
.menu { position: absolute; right: 0; top: calc(100% + 8px); padding: 6px; min-width: 230px; z-index: 20; box-shadow: var(--shadow); }
.item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 11px 12px; border-radius: 8px; background: none; border: 0; text-align: left; }
.item:hover { background: var(--surface-hover); }
@media (max-width: 560px) { .who { display: none; } .logo { width: 42px; height: 42px; } }
</style>
