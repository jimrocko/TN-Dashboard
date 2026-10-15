# Arbeitsweise für Agents

Wie an diesem Repo gearbeitet wird: Kommunikation, Git, Testen, Demo, Grenzen der Cloud-Umgebung.

## Mit dem Inhaber reden
- **Deutsch**, kurz und direkt. Er schreibt knapp und oft per Spracheingabe; Tippfehler mitdenken („knickbar“ = klickbar, „Gesichter“ = gesichert).
- **Antwort zuerst, dann Erklärung.** Keine Gedankenstriche (—) in Texten, die er liest.
- Er schickt **Nachträge während der Arbeit**. Sofort berücksichtigen, kurz bestätigen („Verstanden: …“), dann weiter.
- Ist ein Wunsch **wirklich mehrdeutig** (z. B. „nur die Funktionen, die wir davor hatten“), eine Rückfrage mit 2 bis 3 konkreten Optionen stellen. Sonst einfach machen.
- **Keine ungefragten Zusatzfunktionen.** Was er nicht wollte, wieder entfernen, wenn er es sagt (siehe `entscheidungen.md`).
- Am Ende sagen, was getestet wurde und was nicht (z. B. Brickognize in der Sandbox gesperrt).
- Er möchte zum Ausprobieren **immer eine laufende Demo** sehen: nach Änderungen die Demo neu bauen und das Claude-Artifact aktualisieren (siehe unten).

## Git und GitHub
- Repo: `jimrocko/TN-Dashboard`, **öffentlich**. Standard-Branch heißt `claude/charming-einstein-8qzsen` (nicht `main`).
- Arbeits-Branches von Claude-Sessions: `claude/<name>`. Ist der PR eines Branches gemerged, den Branch für neue Arbeit neu vom Standard-Branch starten (`git checkout -B <branch> origin/claude/charming-einstein-8qzsen`), nie auf gemergte Historie stapeln.
- PRs als Entwurf anlegen; Beschreibung auf Deutsch mit „Inhalt“ und „Getestet“ (inkl. „Nicht getestet“).
- Commit-Nachrichten auf Deutsch: Titelzeile + Stichpunkte.
- **CI:** `.github/workflows/build.yml` (PHP-Lint, `npm ci`, Build, Demo-Build, `install.php --demo` mit SQLite) muss grün sein. `demo-pages.yml` veröffentlicht die Demo auf GitHub Pages und schlägt fehl, solange Pages im Repo nicht eingeschaltet ist (Settings → Pages → Source „GitHub Actions“, braucht Admin-Rechte).
- Nicht ins Repo: `.env`, `api/config.php`, `local-data/`, `dist/`, `demo-dist/`.

## Nach jeder Änderung
1. `cd frontend && npm run build` (baut `dist/` inkl. PHP-Backend) und `npm run build:demo` (schreibt `TN-Dashboard-Demo.html` im Hauptordner und `demo-dist/tn-dashboard-demo.html`).
2. `find api -name '*.php' -print0 | xargs -0 -n1 php -l`
3. Neue Backend-Route? Auch in `frontend/src/demo/` nachbauen (Regel in `CLAUDE.md`).
4. Browser-Test: `node docs/agents/smoke-test.mjs` (Demo) und `node docs/agents/smoke-test.mjs http://127.0.0.1:8000/` (Server). Beide müssen alle Prüfungen mit „OK“ melden.
5. `TN-Dashboard-Demo.html` mitcommitten, README/`CLAUDE.md`/`docs/agents/` aktualisieren.
6. Demo-Artifact aktualisieren: dieselbe Datei `demo-dist/tn-dashboard-demo.html` erneut veröffentlichen, der Link bleibt https://claude.ai/artifact/D8tjj1fC192NUEocyTMZ9R (privat, gehört dem Inhaber).

## Lokal starten und testen
- `./start.sh` legt `.env` (SQLite in `local-data/`) an, baut, erstellt Konten und Fake-Daten, startet `php -S`. Ohne Skript: `.env` mit `TN_DB_DRIVER=sqlite` und `TN_DB_PATH=<pfad>` anlegen, `php dist/api/install.php --demo`, `php -S 127.0.0.1:8000 -t dist`.
- **Der PHP-Server liefert `dist/` aus.** Nach Änderungen in `api/` erst `npm run build`, sonst testet man die alte Kopie.
- Konten: `lehrer/lehrer123`, `max/max12345`, `erika/erika123`. Dev-Code im Test: `0000`.
- Dev-Zustand zurücksetzen (Code und Dev-Rollen): `php -r '$p=new PDO("sqlite:local-data/tn_dashboard.sqlite");$p->exec("delete from tn_settings; delete from tn_dev_access");'`
- API direkt: `curl -c jar -H 'Content-Type: application/json' -d '{"username":"lehrer","password":"lehrer123"}' 'http://127.0.0.1:8000/api/?r=auth/login'`, danach `curl -b jar 'http://127.0.0.1:8000/api/?r=dev/status'`.

## Test-Tricks (Playwright)
- **Demo-Navigation:** Die Demo nutzt Speicher-Navigation; `#/pfad` in der URL wirkt nicht. Über den Router steuern: `document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/pfad')`; aktuelle Seite über `…$router.currentRoute.value.fullPath`.
- **XP-Stil im Test:** nach dem Login `localStorage['tn-dashboard-settings']` mit `"style":"xp"` setzen und `localStorage['tn-xp-seen'] = Date.now()`, dann neu laden. (Jede Anmeldung schaltet XP ab, also erst nach dem Login setzen.)
- **Zeitabläufe** (15-Minuten-Sperren) ohne Warten: `await page.clock.install()` vor dem Laden, dann `page.clock.runFor(16 * 60 * 1000)`. Echte Netzwerkaufrufe laufen in Echtzeit weiter; nach Klicks, die den Server fragen, kurz `page.waitForTimeout(500)`.
- **Server-Sitzung altern lassen** (Dev-Sperre am Server prüfen): in der PHP-Sitzungsdatei (`session_save_path()/sess_<id>`) `dev_seen|i:<zeit>` zurücksetzen.
- Kamera im Test: Chromium mit `--use-fake-ui-for-media-stream --use-fake-device-for-media-stream` starten.

## Grenzen der Claude-Cloud-Umgebung
- Gesperrt: `api.brickognize.com`, `img.bricklink.com`, `raw.githack.com`, `*.github.io`. Erkennung und Teilebilder lassen sich dort nicht echt testen (Demo-Knopf „Erkennung ohne Foto testen“ nutzen).
- Erreichbar: `raw.githubusercontent.com`, GitHub-API über die MCP-Tools.
- Chromium: `/opt/pw-browsers/chromium` (kein `playwright install`).
- Im Claude-Artifact blockiert die Seiten-Sicherheitsrichtlinie Kamera, externe Bilder und Brickognize; auf GitHub Pages bzw. raw.githack gilt das nicht.
