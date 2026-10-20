<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { User, ChevronDown, LogOut, GraduationCap, History, Settings } from 'lucide-vue-next'
import { useAuth } from '../stores/auth'
import { offline } from '../utils/offline'

const auth = useAuth()
const router = useRouter()
const route = useRoute()
// Großer Titel mit Gruß nur auf der Startseite (Wunsch des Inhabers), mittig auf Höhe von Stein und Profil
const isHome = computed(() => route.path === '/')
const greeting = computed(() => { const h = new Date().getHours(); return h < 11 ? 'Guten Morgen' : h < 17 ? 'Hallo' : 'Guten Abend' })
const firstName = computed(() => String(auth.user?.display_name ?? '').trim().split(/\s+/)[0])
const open = ref(false)

async function logout() {
  open.value = false
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <header class="page header">
    <!-- Oben links nur der Stein aus dem Logo, ohne Hintergrund (Wunsch des Inhabers) -->
    <RouterLink to="/" class="brand" aria-label="TN Dashboard, Startseite">
      <svg viewBox="96 104 320 312" class="brick" aria-hidden="true">
        <g stroke="var(--brick-edge)" stroke-width="22" stroke-linejoin="round">
          <path d="M112 190 V330 L256 400 V260 Z" fill="#c5ced9" />
          <path d="M400 190 V330 L256 400 V260 Z" fill="#9aa6b6" />
          <path d="M256 120 L400 190 L256 260 L112 190 Z" fill="#e8edf3" />
        </g>
        <g fill="#0a0f17" fill-opacity=".25">
          <ellipse cx="216" cy="172" rx="26" ry="13" /><ellipse cx="296" cy="172" rx="26" ry="13" />
          <ellipse cx="216" cy="210" rx="26" ry="13" /><ellipse cx="296" cy="210" rx="26" ry="13" />
        </g>
      </svg>
    </RouterLink>

    <div v-if="isHome" class="title">
      <h1><span class="tn">TN</span> Dashboard</h1>
      <p v-if="auth.user" class="hi">{{ greeting }}, {{ firstName }}!</p>
    </div>

    <div class="user" v-if="auth.user">
      <button class="chip" @click="open = !open" :aria-expanded="open">
        <span class="avatar"><User :size="22" /></span>
        <span class="who">
          <span class="role">{{ auth.roleLabel }}</span>
          <span class="name">{{ auth.user.display_name }}</span>
          <span class="online" :class="{ off: !offline.online }"><i></i>{{ offline.online ? 'Online' : 'Offline' }}</span>
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
.header { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 12px; padding-top: calc(16px + env(safe-area-inset-top, 0px)); padding-bottom: 16px; }
.brand { justify-self: start; display: grid; place-items: center; }
.brick { width: 48px; height: 47px; filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.25)); }
/* Titel nur auf der Startseite: einmal kurz eingeblendet */
.title { grid-column: 2; text-align: center; animation: title-in 0.5s ease-out both; }
.title h1 { margin: 0; font-size: clamp(1.55rem, 4.2vw, 2.7rem); font-weight: 800; letter-spacing: -0.01em; line-height: 1.05; white-space: nowrap; }
.title .tn { color: var(--brand); font-style: italic; }
.title .hi { margin: 4px 0 0; color: var(--text-2); font-size: clamp(0.85rem, 1.8vw, 1rem); white-space: nowrap; }
@keyframes title-in { from { opacity: 0; transform: translateY(5px); } }
.user { position: relative; grid-column: 3; justify-self: end; }
.chip { display: flex; align-items: center; gap: 12px; background: none; border: 0; padding: 4px; text-align: left; }
.avatar { width: 50px; height: 50px; border-radius: 50%; background: var(--surface-hover); border: 1px solid var(--border); display: grid; place-items: center; }
.who { display: flex; flex-direction: column; line-height: 1.3; }
.role { font-size: 12px; color: var(--text-muted); }
.name { font-size: 16px; }
.online { font-size: 13px; color: var(--ok); display: flex; align-items: center; gap: 6px; }
.online i { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); }
.online.off { color: var(--warn); }
.online.off i { background: var(--warn); }
.chev { color: var(--text-muted); }
.menu-backdrop { position: fixed; inset: 0; z-index: 19; }
.menu { position: absolute; right: 0; top: calc(100% + 8px); padding: 6px; min-width: 230px; z-index: 20; box-shadow: var(--shadow); }
.item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 11px 12px; border-radius: 8px; background: none; border: 0; text-align: left; }
.item:hover { background: var(--surface-hover); }
@media (max-width: 560px) { .who { display: none; } .brick { width: 40px; height: 39px; } }
</style>
