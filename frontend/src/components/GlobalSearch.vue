<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Search, ScanLine } from 'lucide-vue-next'

const props = defineProps({ initial: { type: String, default: '' } })
const q = ref(props.initial)
const router = useRouter()

function submit() {
  if (q.value.trim()) router.push({ path: '/suche', query: { q: q.value.trim() } })
}
</script>

<template>
  <form class="search" @submit.prevent="submit" role="search">
    <Search :size="30" class="icon" />
    <input v-model="q" type="search" enterkeyhint="search"
      placeholder="Suche nach Teil-ID, Farbe, Name, Kategorie, Lagerort, Set, Minifig …" aria-label="Suche" />
    <RouterLink to="/modul/scanner" class="scan" title="Scannen"><ScanLine :size="30" /></RouterLink>
  </form>
</template>

<style scoped>
.search {
  display: flex; align-items: center; gap: 18px;
  height: 88px; padding: 0 32px; border-radius: 44px;
  background: linear-gradient(180deg, #172131, #121a27);
  border: 1px solid var(--border-strong);
}
.search:focus-within { border-color: var(--accent); }
.icon { color: var(--text-muted); flex: none; }
input { flex: 1; min-width: 0; background: none; border: 0; outline: 0; color: var(--text); font-size: 20px; }
input::placeholder { color: var(--text-faint); }
.scan { color: var(--text-muted); display: grid; place-items: center; }
.scan:hover { color: var(--text); }
@media (max-width: 560px) { .search { height: 60px; padding: 0 18px; gap: 10px; } input { font-size: 16px; } }
</style>
