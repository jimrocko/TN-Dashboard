<script setup>
import { onMounted, ref } from 'vue'
import { Download } from 'lucide-vue-next'
import PageHead from '../components/PageHead.vue'
import ActivityTable from '../components/ActivityTable.vue'
import AchievementGrid from '../components/AchievementGrid.vue'
import { api, apiUrl, IS_DEMO } from '../api'
import { useAuth } from '../stores/auth'

const auth = useAuth()
const rows = ref([])
const error = ref('')
const stats = ref(null)
onMounted(async () => {
  try { rows.value = (await api.get('activity')).activity } catch (e) { error.value = e.message }
  try { stats.value = (await api.get('achievements')).me } catch { /* Abzeichen sind nur Zugabe */ }
})
</script>

<template>
  <div class="page stack">
    <PageHead title="Meine Aktivitäten" :sub="`${auth.user.display_name} · Dieses Protokoll sieht auch dein Lehrer.`">
      <a v-if="!IS_DEMO" class="btn" :href="apiUrl('activity', { format: 'csv', limit: 5000 })"><Download :size="16" /> CSV herunterladen</a>
    </PageHead>
    <p v-if="error" class="error">{{ error }}</p>
    <AchievementGrid :stats="stats" />
    <section class="panel"><ActivityTable :rows="rows" /></section>
  </div>
</template>
