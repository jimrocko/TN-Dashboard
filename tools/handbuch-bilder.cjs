// Bildschirmfotos für das Handbuch (tools/make-handbuch.py) mit den erfundenen Beispieldaten.
// Vorher: php dist/api/install.php --demo  und  php -S 127.0.0.1:8140 -t dist
// Aufruf: TN_URL=http://127.0.0.1:8140 CHROMIUM_PATH=… node tools/handbuch-bilder.cjs <ordner> [nur-diese-namen …]
const { chromium } = require('playwright-core');
const B = (process.env.TN_URL || 'http://127.0.0.1:8140') + '/#';
const OUT = (process.argv[2] || '/tmp/handbuch-bilder').replace(/\/?$/, '/');
const ONLY = process.argv.slice(3);
require('fs').mkdirSync(OUT, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium' });
  const mk = async (vp, user, pw) => {
    const ctx = await b.newContext({ viewport: vp, deviceScaleFactor: 2 });
    await ctx.addInitScript(() => localStorage.setItem('tn-boot-tag', `${new Date().getFullYear()}-${new Date().getMonth() + 1}-${new Date().getDate()}`));
    const p = await ctx.newPage(); p.on('pageerror', e => console.log('ERR', e.message));
    await p.goto(B + '/login'); await p.waitForTimeout(1500);
    if (user) { await p.locator('input').first().fill(user); await p.fill('input[type=password]', pw); await p.keyboard.press('Enter'); await p.waitForTimeout(2000) }
    return p;
  };
  const shot = async (p, name, path, fn, wait = 2500) => {
    if (ONLY.length && !ONLY.includes(name)) return;
    try {
      if (path) { await p.goto(B + path); await p.waitForTimeout(wait) }
      if (fn) await fn(p);
      await p.mouse.move(0, 0); await p.waitForTimeout(300);
      await p.screenshot({ path: OUT + name + '.png' }); console.log('ok', name);
    } catch (e) { console.log('FEHLER', name, e.message.split('\n')[0]) }
  };
  const ipad = { width: 1194, height: 834 };
  // abgemeldet
  let p = await mk(ipad);
  await shot(p, 'login', null);
  await p.context().close();
  // Schüler
  p = await mk(ipad, 'max', 'max12345');
  await shot(p, 'start', '/');
  await shot(p, 'suche', '/suche?q=3001');
  await shot(p, 'inventar', '/inventar');
  await shot(p, 'inventar-plan', '/inventar', async p => { await p.getByRole('button', { name: /Lagerplan/ }).first().click(); await p.waitForTimeout(800) });
  await shot(p, 'umlagern', '/umlagern', null, 4000);
  await shot(p, 'umlagern-teil', null, async p => { await p.getByRole('radio', { name: /Nach Teil/ }).first().click(); await p.waitForTimeout(800) });
  await shot(p, 'umlagern-plan', null, async p => { await p.locator('.t-plan').first().click(); await p.waitForTimeout(1000); await p.locator('.roomplan').scrollIntoViewIfNeeded() });
  await shot(p, 'umlagern-verlauf', null, async p => { await p.getByRole('radio', { name: /Verlauf/ }).first().click(); await p.waitForTimeout(800); await p.evaluate(() => scrollTo(0, 0)) });
  await shot(p, 'inventur', '/inventur');
  await shot(p, 'inventur-zaehlen', null, async p => {
    await p.locator('section.today').getByRole('button', { name: /Starten|Weiterzählen/ }).first().click(); await p.waitForTimeout(2000)
  });
  await shot(p, 'analyse', '/analyse', null, 3000);
  await shot(p, 'analyse-bestenliste', null, async p => { await p.getByText(/Bestenliste/).last().scrollIntoViewIfNeeded(); await p.waitForTimeout(500) });
  await shot(p, 'auftraege', '/auftraege');
  await shot(p, 'teil', '/teil/3001', null, 3000);
  await shot(p, 'lagerort', '/lagerort/G245', null, 3000);
  await shot(p, 'scanner', '/scanner');
  await shot(p, 'pickliste', '/pickliste', null, 3000);
  await shot(p, 'pickliste-auftrag', null, async p => {
    let best = 0, bi = 0; const cards = p.locator('button.order')
    for (let i = 0; i < await cards.count(); i++) { const n = Number((await cards.nth(i).innerText()).match(/(\d+) Positionen/)?.[1] ?? 0); if (n > best) { best = n; bi = i } }
    await cards.nth(bi).click(); await p.waitForTimeout(2500)
  });
  await shot(p, 'minifig', '/minifig', async p => { await p.locator('.tabbtn').first().waitFor({ timeout: 90000 }); await p.waitForTimeout(1000) }, 1000);
  await shot(p, 'minifig-liste', null, async p => { await p.locator('.mflist').scrollIntoViewIfNeeded(); await p.evaluate(() => { const el = document.querySelector('.mflist'); scrollTo(0, el.getBoundingClientRect().top + scrollY - 70) }); await p.waitForTimeout(500) });
  await shot(p, 'profil', '/profil', async p => { await p.evaluate(() => scrollTo(0, 0)); await p.waitForTimeout(300) }, 2500);
  await shot(p, 'einstellungen', '/einstellungen');
  await shot(p, 'hilfe', '/inventar', async p => {
    await p.locator('main').getByRole('button', { name: /^Hilfe/ }).first().click(); await p.waitForTimeout(300)
    await p.getByText('Diesen Bereich erklären').click(); await p.waitForTimeout(3500)
  });
  await p.context().close();
  // Lehrer
  p = await mk(ipad, 'lehrer', 'lehrer123');
  await shot(p, 'lehrer', '/lehrer', null, 3000);
  await shot(p, 'lehrer-2', null, async p => { await p.evaluate(() => scrollTo(0, innerHeight * 0.9)); await p.waitForTimeout(500) });
  await shot(p, 'lehrer-3', null, async p => { await p.evaluate(() => scrollTo(0, innerHeight * 1.8)); await p.waitForTimeout(500) });
  await shot(p, 'pick-statistik', '/pickliste/statistik', null, 3000);
  await shot(p, 'dev', '/dev', null, 3000);
  for (const t of ['Tests', 'Daten', 'API', 'Einstellungen']) {
    await shot(p, 'dev-' + t.toLowerCase(), null, async p => { await p.locator('main').getByRole('radio', { name: new RegExp('^' + t) }).first().click(); await p.waitForTimeout(1500); await p.evaluate(() => scrollTo(0, 0)) });
  }
  await p.context().close();
  // Handy
  p = await mk({ width: 390, height: 844 }, 'max', 'max12345');
  await shot(p, 'handy-start', '/');
  await shot(p, 'handy-pickliste', '/pickliste', null, 3000);
  await p.context().close();
  await b.close();
})();
