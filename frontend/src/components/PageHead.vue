<script setup>
defineProps({ title: String, sub: String })
</script>

<template>
  <div class="page-head">
    <div>
      <h2 class="page-title">{{ title }}</h2>
      <p v-if="sub" class="page-sub">{{ sub }}</p>
    </div>
    <div class="toolbar"><slot /></div>
  </div>
</template>
