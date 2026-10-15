// Packt den Demo-Build in eine einzige HTML-Datei (JS und CSS eingebettet),
// die ohne Server geöffnet oder als Seite veröffentlicht werden kann.
import { mkdirSync, readFileSync, writeFileSync, readdirSync } from 'node:fs'
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

// Vollständige HTML-Datei fürs Repo (docs/demo/): herunterladen und per Doppelklick im Browser öffnen.
const standalone = `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
${html.split('<div id="app">')[0]}</head>
<body>
<div id="app">${html.split('<div id="app">')[1]}</body>
</html>
`
const docs = resolve(dirname(fileURLToPath(import.meta.url)), '../../docs/demo')
mkdirSync(docs, { recursive: true })
writeFileSync(resolve(docs, 'tn-dashboard-demo.html'), standalone)
console.log('Demo zum Herunterladen: docs/demo/tn-dashboard-demo.html')
