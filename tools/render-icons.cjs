// PNG-Icons aus tools/app-icon*.svg rendern (Playwright nötig): node tools/render-icons.cjs
// App und Demo (nur Stein): apple-touch-icon.png (180, eckig) für „Zum Home-Bildschirm“ auf iPad/iPhone,
// icon-192/512 (abgerundet) für die PWA. Tutorial (mit Klemmi): tools/tutorial-icon-180/192.png, bettet scripts/inline-demo.mjs ein.
// Ohne eigenes Chromium: CHROMIUM_PATH=/pfad/zu/chromium setzen.
let pw
try { pw = require('playwright') } catch { pw = require('playwright-core') }
const { chromium } = pw
const fs = require('fs')
const path = require('path')

const pub = path.join(__dirname, '../frontend/public')
const tools = __dirname
const jobs = [
  ['app-icon.svg', path.join(pub, 'apple-touch-icon.png'), 180],
  ['app-icon-rounded.svg', path.join(pub, 'icon-192.png'), 192],
  ['app-icon-rounded.svg', path.join(pub, 'icon-512.png'), 512],
  ['app-icon-klemmi.svg', path.join(tools, 'tutorial-icon-180.png'), 180],
  ['app-icon-klemmi-rounded.svg', path.join(tools, 'tutorial-icon-192.png'), 192],
]
;(async () => {
  const b = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {})
  for (const [svg, out, size] of jobs) {
    const p = await (await b.newContext({ viewport: { width: size, height: size } })).newPage()
    const data = fs.readFileSync(path.join(__dirname, svg)).toString('base64')
    await p.setContent(`<style>html,body{margin:0;background:transparent}</style><img src="data:image/svg+xml;base64,${data}" width="${size}" height="${size}" style="display:block">`)
    await p.waitForTimeout(200)
    await p.screenshot({ path: out, omitBackground: true })
    console.log(`${path.relative(path.join(__dirname, '..'), out)} (${size} px)`)
  }
  await b.close()
})()
