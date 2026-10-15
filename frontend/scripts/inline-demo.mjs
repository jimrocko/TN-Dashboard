// Packt den Demo-Build (oder das Tutorial: `node scripts/inline-demo.mjs tutorial`) in eine einzige
// HTML-Datei (JS und CSS eingebettet), die ohne Server geöffnet oder als Seite veröffentlicht werden kann.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const tutorial = process.argv[2] === 'tutorial'
const cfg = tutorial
  ? { dir: 'tutorial-dist', file: 'tn-dashboard-tutorial.html', root: 'TN-Dashboard-Tutorial.html', title: 'TN Dashboard – Tutorial', label: 'Tutorial' }
  : { dir: 'demo-dist', file: 'tn-dashboard-demo.html', root: 'TN-Dashboard-Demo.html', title: 'TN Dashboard', label: 'Demo' }

const here = dirname(fileURLToPath(import.meta.url))
const out = resolve(here, '../..', cfg.dir)
const assets = resolve(out, 'assets')
const files = readdirSync(assets)
const js = files.filter((f) => f.endsWith('.js')).map((f) => readFileSync(resolve(assets, f), 'utf8')).join('\n')
const css = files.filter((f) => f.endsWith('.css')).map((f) => readFileSync(resolve(assets, f), 'utf8')).join('\n')

const html = `<title>${cfg.title}</title>
<meta name="theme-color" content="#0a0f17">
<style>${css}</style>
<div id="app"></div>
<script type="module">${js.replace(/<\/script/gi, '<\\/script')}</script>
`
writeFileSync(resolve(out, cfg.file), html)
console.log(`${cfg.label}: ${cfg.dir}/${cfg.file} (${Math.round(html.length / 1024)} KB)`)

// Vollständige HTML-Datei im Hauptordner des Repos: herunterladen und per Doppelklick im Browser öffnen.
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
writeFileSync(resolve(here, '../..', cfg.root), standalone)
console.log(`${cfg.label} zum Herunterladen: ${cfg.root} (Hauptordner)`)
