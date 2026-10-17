<script setup>
import { ref } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import LocationTag from './LocationTag.vue'
import PartImage from './PartImage.vue'

// Aufklappbare Teileliste einer Minifigur: Bild, BrickLink-Katalog, jedes Teil mit Lots und Lagerort.
const props = defineProps({ row: Object, open: Boolean })
const shown = ref(props.open)
</script>

<template>
  <details class="figdetails" :open="shown" @toggle="shown = $event.target.open">
    <summary>{{ (row.missing ?? []).length ? 'Bild, Fehlteil & Lagerorte' : 'Teile & Lagerorte' }} <span class="faint">({{ (row.pick_list ?? []).length }})</span></summary>
    <div v-if="shown" class="wrap">
      <div class="fig">
        <img v-if="row.image_url" :src="row.image_url" :alt="row.minifig_no" loading="lazy" referrerpolicy="no-referrer" @error="$event.target.style.display = 'none'" />
        <a v-if="row.catalog_url" :href="row.catalog_url" target="_blank" rel="noopener" class="small">BrickLink-Katalog <ExternalLink :size="12" /></a>
      </div>
      <div class="parts">
        <div v-for="(p, i) in row.pick_list ?? []" :key="i" class="part" :class="{ missing: (p.shortfall ?? 0) > 0 }">
          <PartImage :no="p.item_no" :color="p.color_id" :size="40" />
          <div class="pinfo">
            <div>
              <RouterLink class="mono" :to="`/teil/${encodeURIComponent(p.item_no)}`">{{ p.item_no }}</RouterLink>
              · {{ p.color_name ? `${p.color_name} (Farbe ${p.color_id})` : `Farbe ${p.color_id}` }}
              <span v-if="(p.shortfall ?? 0) > 0" class="pill danger">fehlt {{ p.shortfall }}×</span>
            </div>
            <div class="small muted">{{ p.item_name }}</div>
            <div class="small faint">benötigt {{ p.quantity }}<template v-if="p.stock_before_figure !== undefined"> · verfügbar vor Reservierung {{ p.stock_before_figure }}</template><template v-else> · zugeordnet {{ p.available ?? 0 }}</template></div>
            <div v-for="(s, k) in p.sources" :key="k" class="src small">
              <span class="faint">Lot {{ s.inventory_id }} · {{ s.quantity }}×<template v-if="s.lot_quantity"> von {{ s.lot_quantity }}</template></span>
              <LocationTag v-if="s.remarks" :code="s.remarks" />
              <span v-else class="warnc">Lagerort fehlt</span>
              <span v-if="s.new_or_used" class="faint">{{ s.new_or_used === 'N' ? 'neu' : s.new_or_used === 'U' ? 'gebraucht' : s.new_or_used }}</span>
            </div>
            <div v-if="!(p.sources ?? []).length && !(p.shortfall > 0)" class="small warnc">Kein aktuelles Lot vorhanden</div>
          </div>
        </div>
        <div v-if="!(row.pick_list ?? []).length" class="small faint">Keine Teileliste verfügbar.</div>
      </div>
    </div>
  </details>
</template>

<style scoped>
.figdetails summary { cursor: pointer; color: var(--accent-text); font-size: 0.88rem; }
.wrap { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 12px; margin-top: 8px; }
.fig { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.fig img { width: 110px; height: 110px; object-fit: contain; background: #fff; border-radius: var(--radius-sm); border: 1px solid var(--border); }
.parts { display: flex; flex-direction: column; gap: 8px; }
.part { display: flex; gap: 10px; align-items: flex-start; padding: 6px 8px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.part.missing { border-color: var(--danger); }
.pinfo { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.src { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.warnc { color: var(--warn); }
@media (max-width: 600px) { .wrap { grid-template-columns: minmax(0, 1fr); } }
</style>
