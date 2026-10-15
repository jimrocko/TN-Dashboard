<script setup>
import { moduleById } from '../modules'
defineProps({ rows: Array, showUser: Boolean })

const actionLabel = { login: 'Angemeldet', logout: 'Abgemeldet', open: 'Modul geöffnet', search: 'Gesucht', user_created: 'Konto angelegt' }
const fmt = (s) => new Date(s.replace(' ', 'T')).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })
const moduleLabel = (id) => (id === 'suche' ? 'Suche' : moduleById(id)?.title ?? id ?? '')
const details = (d) => (d ? Object.entries(d).map(([k, v]) => `${k}: ${v}`).join(', ') : '')
</script>

<template>
  <div class="table-wrap">
    <table class="list">
      <thead><tr><th>Zeit</th><th v-if="showUser">Teilnehmer</th><th>Aktion</th><th>Modul</th><th>Details</th></tr></thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td>{{ fmt(r.created_at) }}</td>
          <td v-if="showUser">{{ r.display_name }}</td>
          <td>{{ actionLabel[r.action] ?? r.action }}</td>
          <td>{{ moduleLabel(r.module) }}</td>
          <td class="muted">{{ details(r.details) }}</td>
        </tr>
        <tr v-if="!rows.length"><td :colspan="showUser ? 5 : 4" class="muted">Noch keine Einträge.</td></tr>
      </tbody>
    </table>
  </div>
</template>
