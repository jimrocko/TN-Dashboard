<script setup>
import { moduleById } from '../modules'
import { dateTime } from '../utils/format'
defineProps({ rows: Array, showUser: Boolean, compact: Boolean })

const actionLabel = {
  login: 'Angemeldet', logout: 'Abgemeldet', open: 'Modul geöffnet', search: 'Gesucht', user_created: 'Konto angelegt', user_deleted: 'Konto gelöscht',
  transfer: 'Umgelagert', order_status: 'Auftragsstatus geändert', stocktake_created: 'Inventur gestartet',
  stocktake_closed: 'Inventur abgeschlossen', demo_seed: 'Beispieldaten geladen', recognize: 'Teil erkannt',
  dev_unlock: 'Dev-Bereich entsperrt', dev_unlock_failed: 'Falscher Dev-Code', dev_pin_changed: 'Dev-Code geändert',
  dev_test: 'Dev-Test ausgeführt', dev_granted: 'Dev-Rolle vergeben', dev_revoked: 'Dev-Rolle entzogen',
}
const moduleLabel = (id) => (id === 'suche' ? 'Suche' : moduleById(id)?.title ?? id ?? '')
const details = (d) => (d ? Object.entries(d).map(([k, v]) => `${k}: ${v}`).join(' · ') : '')
</script>

<template>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Zeit</th><th v-if="showUser">Teilnehmer</th><th>Aktion</th><th v-if="!compact">Bereich</th><th>Details</th></tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td class="nowrap faint">{{ dateTime(r.created_at) }}</td>
          <td v-if="showUser">{{ r.display_name }}</td>
          <td>{{ actionLabel[r.action] ?? r.action }}<span v-if="compact && r.module" class="faint"> · {{ moduleLabel(r.module) }}</span></td>
          <td v-if="!compact">{{ moduleLabel(r.module) }}</td>
          <td class="muted small">{{ details(r.details) }}</td>
        </tr>
        <tr v-if="!rows.length"><td :colspan="5" class="empty">Noch keine Einträge.</td></tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.nowrap { white-space: nowrap; }
</style>
