<script setup>
import { computed, ref } from 'vue'
import { ArrowLeft, ArrowRight, RotateCw, Home, Star, ShoppingCart } from 'lucide-vue-next'

// Spaß-Browser: drei erfundene Scherzartikel-Shops rund um Klemmbausteine. Nichts wird wirklich bestellt.
const sites = {
  'www.noppen-und-narren.de': {
    name: 'Noppen & Narren', slogan: 'Scherzartikel für Steinchen-Fans seit 1958 (behaupten wir)',
    products: [
      { name: 'Barfuß-Fußmatte mit echten 2×4-Steinen', price: 19.99, note: 'Für Leute, die gerne schreien.' },
      { name: 'Unsichtbarer Stein, Trans-Clear', price: 4.99, note: 'Wir haben ihn auch noch nicht gefunden.' },
      { name: 'Duftkerze „Frisch geöffnete Tüte“', price: 12.5, note: 'Riecht nach Plastik und Vorfreude.' },
      { name: 'Ersatz-Anleitungsseite 42', price: 2.0, note: 'Die, die immer fehlt.' },
    ],
  },
  'www.steinchen-spassladen.de': {
    name: 'Steinchen-Spaßladen', slogan: 'Alles, was die Welt nicht braucht – aber aus Noppen',
    products: [
      { name: 'Minifig-Perücke in Erwachsenengröße', price: 24.0, note: 'Passt garantiert nicht auf deinen Kopf.' },
      { name: 'Stein-Trenner aus purem Gold', price: 999.0, note: 'Trennt Steine genauso gut wie der normale.' },
      { name: 'Notfall-Set: 1×1-Plate (unter dem Sofa verloren)', price: 0.5, note: 'Mit Taschenlampe.' },
      { name: 'Hörbuch „Das Klicken“ (12 Stunden)', price: 9.99, note: 'Nur Klick-Geräusche. Sehr entspannend.' },
    ],
  },
  'www.klemmbaustein-kuriositaeten.de': {
    name: 'Klemmbaustein-Kuriositäten', slogan: 'Seltene Fundstücke aus aller Welt',
    products: [
      { name: 'Der allererste Stein (Nachbau, wahrscheinlich)', price: 49.0, note: 'Echtheitszertifikat aus Papier.' },
      { name: 'Mischkiste „Überraschung“ – 5 kg', price: 29.0, note: 'Sortieren ist Teil des Spaßes.' },
      { name: 'Minifig-Kaffeetasse mit Henkel-Klemme', price: 14.0, note: 'Hält nur 3 ml.' },
    ],
  },
}
const favs = Object.keys(sites)
const history = ref([favs[0]])
const pos = ref(0)
const url = computed(() => history.value[pos.value])
const site = computed(() => sites[url.value])
const address = ref(url.value)
const cart = ref([])
const msg = ref('')

function go(u) {
  history.value = [...history.value.slice(0, pos.value + 1), u]
  pos.value = history.value.length - 1
  address.value = u
  msg.value = ''
}
function submit() {
  const u = address.value.trim().replace(/^https?:\/\//, '')
  go(sites[u] ? u : u || favs[0])
}
const back = () => { if (pos.value > 0) { pos.value--; address.value = url.value } }
const fwd = () => { if (pos.value < history.value.length - 1) { pos.value++; address.value = url.value } }
function buy(p) {
  cart.value.push(p)
  msg.value = `„${p.name}“ liegt im Warenkorb. (Keine Sorge: Das ist nur ein Spaß, hier wird nichts bestellt.)`
}
const eur = (n) => n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })
</script>

<template>
  <div class="browser">
    <div class="bar">
      <button class="btn btn-sm" :disabled="pos === 0" @click="back" aria-label="Zurück"><ArrowLeft :size="15" /></button>
      <button class="btn btn-sm" :disabled="pos >= history.length - 1" @click="fwd" aria-label="Vor"><ArrowRight :size="15" /></button>
      <button class="btn btn-sm" @click="msg = ''" aria-label="Neu laden"><RotateCw :size="15" /></button>
      <button class="btn btn-sm" @click="go(favs[0])" aria-label="Startseite"><Home :size="15" /></button>
      <form class="addr" @submit.prevent="submit">
        <span class="faint small">Adresse</span>
        <input id="xp-address" class="input" v-model="address" spellcheck="false" autocapitalize="none" />
        <button class="btn btn-sm">Wechseln zu</button>
      </form>
      <span class="cart"><ShoppingCart :size="15" /> {{ cart.length }}</span>
    </div>
    <div class="favs">
      <Star :size="14" class="faint" />
      <button v-for="f in favs" :key="f" class="fav" :class="{ on: f === url }" @click="go(f)">{{ sites[f].name }}</button>
    </div>
    <div class="page-area">
      <template v-if="site">
        <header class="hero">
          <h1>{{ site.name }}</h1>
          <p>{{ site.slogan }}</p>
        </header>
        <p v-if="msg" class="note">{{ msg }}</p>
        <div class="products">
          <article v-for="p in site.products" :key="p.name" class="product">
            <div class="brick" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            <h2>{{ p.name }}</h2>
            <p class="muted small">{{ p.note }}</p>
            <div class="buy"><strong>{{ eur(p.price) }}</strong><button class="btn btn-sm btn-primary" @click="buy(p)">In den Warenkorb</button></div>
          </article>
        </div>
        <footer class="faint small">Erfundener Spaß-Shop. Keine echten Produkte, keine echten Bestellungen.</footer>
      </template>
      <div v-else class="notfound">
        <h1>Die Seite kann nicht angezeigt werden</h1>
        <p class="muted">„{{ url }}“ gibt es hier nicht. Probier einen der Favoriten oben.</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.browser { display: flex; flex-direction: column; min-height: 100%; background: var(--bg); }
.bar { display: flex; align-items: center; gap: 6px; padding: 8px 10px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.addr { flex: 1; display: flex; align-items: center; gap: 6px; min-width: 220px; }
.addr .input { flex: 1; min-width: 0; height: 32px; }
.cart { display: inline-flex; align-items: center; gap: 4px; font-size: 0.85rem; }
.favs { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border-bottom: 1px solid var(--border); flex-wrap: wrap; font-size: 0.85rem; }
.fav { background: none; border: 1px solid transparent; border-radius: 3px; padding: 4px 8px; color: var(--accent-text); }
.fav:hover, .fav.on { border-color: var(--border-strong); background: var(--surface); }
.page-area { flex: 1; background: #fff; color: #222; padding: 18px; }
.hero { background: linear-gradient(90deg, #ffcf00, #ff8a00); color: #3a1d00; padding: 16px 18px; border-radius: 6px; }
.hero h1 { margin: 0; font-family: 'Comic Sans MS', 'Trebuchet MS', sans-serif; font-size: 1.6rem; }
.hero p { margin: 4px 0 0; }
.note { background: #e8f5e9; border: 1px solid #81c784; padding: 8px 12px; border-radius: 4px; color: #1b5e20; }
.products { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
.product { border: 1px solid #ddd; border-radius: 6px; padding: 12px; display: flex; flex-direction: column; gap: 6px; background: #fafafa; }
.product h2 { font-size: 0.95rem; margin: 0; color: #222; }
.product .muted { color: #666; margin: 0; }
.buy { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.brick { height: 46px; border-radius: 4px; background: #d32f2f; display: grid; grid-template-columns: repeat(4, 1fr); align-items: start; padding: 4px 8px; gap: 6px; }
.brick i { height: 12px; border-radius: 50%; background: #e57373; box-shadow: inset 0 -2px 0 rgba(0, 0, 0, 0.2); }
.product:nth-child(2) .brick { background: #1976d2; } .product:nth-child(2) .brick i { background: #64b5f6; }
.product:nth-child(3) .brick { background: #fbc02d; } .product:nth-child(3) .brick i { background: #fff176; }
.product:nth-child(4) .brick { background: #388e3c; } .product:nth-child(4) .brick i { background: #81c784; }
footer { color: #888; }
.notfound h1 { font-size: 1.2rem; color: #003399; }
</style>
