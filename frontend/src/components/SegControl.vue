<script setup>
defineProps({ modelValue: [String, Boolean, Number], options: Array, label: String })
defineEmits(['update:modelValue'])
</script>

<template>
  <div class="seg" role="radiogroup" :aria-label="label">
    <button v-for="o in options" :key="String(o.value)" type="button" role="radio" :aria-checked="modelValue === o.value"
      :class="{ on: modelValue === o.value }" @click="$emit('update:modelValue', o.value)">
      <component v-if="o.icon" :is="o.icon" :size="15" /> {{ o.label }}
    </button>
  </div>
</template>

<style scoped>
.seg { display: inline-flex; flex-wrap: wrap; padding: 3px; gap: 2px; border-radius: var(--radius-sm); background: var(--bg); border: 1px solid var(--border); }
button { display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 12px; border: 0; border-radius: 4px; background: none; color: var(--text-2); font-size: 0.86rem; }
button:hover { color: var(--text); }
button.on { background: var(--surface-2); color: var(--text); box-shadow: 0 0 0 1px var(--border-strong); font-weight: 500; }
</style>
