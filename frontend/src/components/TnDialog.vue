<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import { X } from 'lucide-vue-next'

// Einfacher Dialog über der Seite (auch im XP-Stil über allen Fenstern). Schließen: X, Escape oder Klick daneben.
const props = defineProps({ title: String, wide: Boolean })
const emit = defineEmits(['close'])
const onKey = (e) => { if (e.key === 'Escape') emit('close') }
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="tn-overlay" @click.self="emit('close')">
      <section class="panel tn-dialog" :class="{ wide: props.wide }" role="dialog" aria-modal="true" :aria-label="title">
        <div class="panel-head">
          <h2>{{ title }}</h2>
          <button class="btn btn-ghost btn-sm" aria-label="Schließen" @click="emit('close')"><X :size="18" /></button>
        </div>
        <div class="panel-body"><slot /></div>
        <div v-if="$slots.actions" class="tn-actions"><slot name="actions" /></div>
      </section>
    </div>
  </Teleport>
</template>

<style>
.tn-overlay { position: fixed; inset: 0; z-index: 9000; display: grid; place-items: center; padding: 16px; background: rgba(0, 0, 0, 0.55); }
.tn-dialog { width: min(480px, 100%); max-height: calc(100dvh - 32px); overflow: auto; background: var(--surface); box-shadow: var(--shadow); }
.tn-dialog.wide { width: min(860px, 100%); }
.tn-actions { display: flex; justify-content: flex-end; gap: 8px; padding: 0 var(--pad) var(--pad); flex-wrap: wrap; }
</style>
