<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Search, ScanLine } from 'lucide-vue-next'

const props = defineProps({ initial: { type: String, default: '' } })
const q = ref(props.initial)
watch(() => props.initial, (v) => { q.value = v })
const router = useRouter()

function submit() {
  if (q.value.trim()) router.push({ path: '/suche', query: { q: q.value.trim() } })
}
</script>

<template>
  <form class="search" @submit.prevent="submit" role="search">
    <Search :size="30" class="icon" />
    <input id="global-search" v-model="q" type="search" enterkeyhint="search"
      placeholder="Suche nach Teil-ID, Farbe, Name, Kategorie, Lagerort, Auftrag …" aria-label="Suche" />
    <RouterLink to="/scanner" class="scan" title="Scannen"><ScanLine :size="30" /></RouterLink>
  </form>
</template>

<style scoped>
.search {
  display: flex; align-items: center; gap: 18px;
  height: 88px; padding: 0 32px; border-radius: 44px;
  background: var(--search-bg);
  border: 1px solid var(--border-strong);
}
.search:focus-within { border-color: var(--accent-text); }
.icon { color: var(--text-muted); flex: none; }
input { flex: 1; min-width: 0; background: none; border: 0; outline: 0; color: var(--text); font-size: 20px; }
input::placeholder { color: var(--text-faint); }
.scan { color: var(--text-muted); display: grid; place-items: center; }
.scan:hover { color: var(--text); }
@media (max-width: 1199px) { .search { height: 60px; padding: 0 22px; gap: 14px; border-radius: 30px; } input { font-size: 17px; } .icon, .scan svg { width: 24px; height: 24px; } }
@media (max-width: 560px) { .search { height: 52px; padding: 0 16px; gap: 10px; } input { font-size: 16px; } }
</style>
