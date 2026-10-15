// PNG-Icons aus tools/app-icon*.svg rendern (Playwright nötig): node tools/render-icons.cjs
// apple-touch-icon.png (180, eckig) für „Zum Home-Bildschirm“ auf iPad/iPhone, icon-192/512 (abgerundet) für die PWA.
const { chromium } = require('playwright')
const fs = require('fs')
const path = require('path')

const pub = path.join(__dirname, '../frontend/public')
const jobs = [
  ['app-icon.svg', 'apple-touch-icon.png', 180],
  ['app-icon-rounded.svg', 'icon-192.png', 192],
  ['app-icon-rounded.svg', 'icon-512.png', 512],
]
;(async () => {
  const b = await chromium.launch()
  for (const [svg, out, size] of jobs) {
    const p = await (await b.newContext({ viewport: { width: size, height: size } })).newPage()
    const data = fs.readFileSync(path.join(__dirname, svg)).toString('base64')
    await p.setContent(`<style>html,body{margin:0;background:transparent}</style><img src="data:image/svg+xml;base64,${data}" width="${size}" height="${size}" style="display:block">`)
    await p.waitForTimeout(200)
    await p.screenshot({ path: path.join(pub, out), omitBackground: true })
    console.log(`frontend/public/${out} (${size} px)`)
  }
  await b.close()
})()
