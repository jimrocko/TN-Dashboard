<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../../api'

// Zählliste des Tages: wie viele Kästchen jeden Tag in der Inventur unter „Heute zählen“ stehen (gilt für alle).
const size = ref(5)
const msg = ref('')
const err = ref('')
onMounted(async () => { try { size.value = (await api.get('stocktakes/today')).size } catch (e) { err.value = e.message } })
async function save() {
  msg.value = err.value = ''
  try {
    size.value = (await api.post('stocktakes/daily-size', { size: size.value })).size
    msg.value = 'Gespeichert. Gilt ab morgen, die heutige Liste bleibt.'
  } catch (e) { err.value = e.message }
}
</script>

<template>
  <section class="panel">
    <div class="panel-head"><h2>Inventur: Zählliste des Tages</h2></div>
    <div class="panel-body stack-s">
      <p class="small muted">Jeden Tag stehen in der Inventur unter „Heute zählen“ einige Kästchen, gleich für alle. Zuerst Kästchen, bei denen in der Pickliste ein Problem gemeldet wurde, dann nie oder lange nicht gezählte, bei Gleichstand die wertvolleren.</p>
      <div class="toolbar">
        <label for="count-size" class="small">Kästchen pro Tag</label>
        <select id="count-size" v-model.number="size" class="select">
          <option v-for="n in [1, 2, 3, 4, 5, 6, 8, 10, 12, 15, 20, 25, 30]" :key="n" :value="n">{{ n }}</option>
        </select>
        <button class="btn btn-primary" @click="save">Speichern</button>
      </div>
      <p v-if="msg" class="success">{{ msg }}</p>
      <p v-if="err" class="error">{{ err }}</p>
    </div>
  </section>
</template>

<style scoped>
.stack-s { display: flex; flex-direction: column; gap: 10px; }
</style>
