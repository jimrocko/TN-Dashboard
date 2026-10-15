// Browser-Schnelltest für Agents: klickt die wichtigsten Wege durch und meldet Fehler.
//
//   npm i -D playwright      (einmal, im Ordner frontend/ oder einem Scratch-Ordner)
//   node docs/agents/smoke-test.mjs                       → Demo-Datei TN-Dashboard-Demo.html
//   node docs/agents/smoke-test.mjs http://127.0.0.1:8000/ → laufender PHP-Server (./start.sh)
//
// In der Claude-Cloud-Umgebung liegt Chromium unter /opt/pw-browsers/chromium (wird automatisch genutzt).
import { existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { chromium } from 'playwright'

const base = process.argv[2] ?? pathToFileURL(resolve('TN-Dashboard-Demo.html')).href
const exe = existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined
const browser = await chromium.launch({ executablePath: exe })
const errors = []
const results = []
const check = (name, ok, info = '') => { results.push({ name, ok, info }); if (!ok) process.exitCode = 1 }

async function login(user, pass) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
  page.on('pageerror', (e) => errors.push(e.message))
  await page.goto(base + '#/login')
  await page.locator('input').first().fill(user)
  await page.fill('input[type=password]', pass)
  await page.keyboard.press('Enter')
  await page.waitForTimeout(800)
  return page
}
// Navigation über den Router der App (die Demo hält Adressen nur im Speicher, #/… in der URL wirkt dort nicht).
const go = async (page, path) => {
  await page.evaluate((to) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(to), path)
  await page.waitForTimeout(700)
}
const here = (page) => page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$router.currentRoute.value.fullPath)

// Teilnehmer: Kacheln, Lagerort-Klick, Teile-Seite, kein Dev-Link
let p = await login('max', 'max12345')
check('Startseite mit Kacheln', (await p.locator('.grid a, .grid .tile, .grid > *').count()) >= 8)
check('Teilnehmer ohne Dev-Link', (await p.locator('a[href*="dev"]').count()) === 0)
await go(p, '/umlagern')
await p.locator('a.loc').first().click(); await p.waitForTimeout(700)
let at = await here(p)
check('Lagerort-Tag öffnet Lagerort-Seite', at.includes('/lagerort/'), at)
await p.locator('tbody tr.clickable').first().click(); await p.waitForTimeout(700)
at = await here(p)
check('Zeile öffnet Teile-Seite', at.includes('/teil/'), at)
await go(p, '/analyse')
await p.locator('a.row.go').first().click(); await p.waitForTimeout(700)
at = await here(p)
check('Analyse-Balken filtert Inventar', at.includes('/inventar?category='), decodeURIComponent(at))

// Lehrer: Dev-Bereich mit Code 0000, Tests, Dev-Rolle vergeben
p = await login('lehrer', 'lehrer123')
await go(p, '/dev')
check('Dev-Bereich zeigt Code-Sperre', (await p.locator('.gate').count()) === 1)
for (const d of '0000') await p.click(`.key:text-is("${d}")`)
await p.click('.key.ok'); await p.waitForTimeout(700)
check('Dev-Code 0000 entsperrt (Testbetrieb)', (await p.locator('.seg').count()) === 1)
await p.click('.seg button:has-text("Einstellungen")'); await p.waitForTimeout(300)
const devText = await p.locator('.page.stack').innerText()
check('Kein Stil-Umschalter im Dev-Menü (XP bleibt geheim)', !/\bStil\b|XP/.test(devText))
await go(p, '/lehrer')
check('Lehrer-Ansicht mit Dev-Spalte', (await p.locator('.devbtn').count()) >= 1)

for (const r of results) console.log(`${r.ok ? 'OK  ' : 'FEHL'} ${r.name}${r.info ? ` (${r.info})` : ''}`)
if (errors.length) { console.log('JS-Fehler:', errors); process.exitCode = 1 }
await browser.close()
