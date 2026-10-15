// Packt den Demo-Build in eine einzige HTML-Datei (JS und CSS eingebettet),
// die ohne Server geöffnet oder als Seite veröffentlicht werden kann.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const out = resolve(dirname(fileURLToPath(import.meta.url)), '../../demo-dist')
const assets = resolve(out, 'assets')
const files = readdirSync(assets)
const js = files.filter((f) => f.endsWith('.js')).map((f) => readFileSync(resolve(assets, f), 'utf8')).join('\n')
const css = files.filter((f) => f.endsWith('.css')).map((f) => readFileSync(resolve(assets, f), 'utf8')).join('\n')

const html = `<title>TN Dashboard</title>
<meta name="theme-color" content="#0a0f17">
<style>${css}</style>
<div id="app"></div>
<script type="module">${js.replace(/<\/script/gi, '<\\/script')}</script>
`
writeFileSync(resolve(out, 'tn-dashboard-demo.html'), html)
console.log(`Demo: demo-dist/tn-dashboard-demo.html (${Math.round(html.length / 1024)} KB)`)
