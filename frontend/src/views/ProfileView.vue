<script setup>
import { onMounted, ref } from 'vue'
import { Download } from 'lucide-vue-next'
import ActivityTable from '../components/ActivityTable.vue'
import { api, apiUrl } from '../api'
import { useAuth } from '../stores/auth'

const auth = useAuth()
const rows = ref([])
const error = ref('')
onMounted(async () => {
  try { rows.value = (await api.get('activity')).activity } catch (e) { error.value = e.message }
})
</script>

<template>
  <div class="page">
    <h1 class="page-title">Meine Aktivitäten</h1>
    <p class="page-sub">{{ auth.user.display_name }} · Dieses Protokoll sieht auch dein Lehrer.</p>
    <a class="btn" :href="apiUrl('activity', { format: 'csv', limit: 5000 })"><Download :size="18" /> Als CSV herunterladen</a>
    <div class="card spaced">
      <p v-if="error" class="error">{{ error }}</p>
      <ActivityTable :rows="rows" />
    </div>
  </div>
</template>

<style scoped>
.spaced { margin-top: 18px; }
</style>
