# TN Dashboard: Projektwissen für Agents

Diese Datei ist das gemeinsame Gedächtnis für alle Agents (Claude Code, Codex, Cursor usw.), die an diesem Repo weiterarbeiten.
Vor dem Start lesen, nach jeder Arbeitssitzung **„Verlauf“ und „Offene Punkte“ aktualisieren** und mit committen.
`AGENTS.md` verweist hierher.

## Worum es geht
- Web-App (PWA) für den Lego-Shop einer Schule. Nutzer: **Teilnehmer** (Schüler) und **Lehrer**.
- Bündelt Shop-Werkzeuge (Inventar, Umlagern, Inventur, Aufträge, Analyse, Teile-Scanner) und protokolliert jede Aktion pro Teilnehmer, damit der Lehrer sieht, wer was gemacht hat.
- Läuft auf iPad (Hauptgerät), PC und Handy. Auf dem Schulserver gibt es nur PHP + MySQL, **kein Node**.
- Der Projektinhaber schreibt Deutsch. **UI-Texte, Kommentare, Commit-Messages und Doku auf Deutsch.**

## Aufbau
```
api/                PHP 8.1+ ohne Framework
  index.php         Einziger Einstieg, Routing über ?r=<route> (kein URL-Rewriting nötig)
  install.php       Einrichtung im Browser oder CLI (php api/install.php --demo)
  lib/Shop.php      Lager, Aufträge, Umlagern, Inventur, Analyse, Teile-Seite (q() mit {tabelle}-Platzhaltern)
  lib/Brickognize.php  Teileerkennung per Foto (Proxy zu api.brickognize.com)
  lib/BrickLinkClient.php + Cache.php  BrickLink-API mit DB-Cache
  lib/Dev.php       Dev-Bereich: Zugang (Dev-Rolle), Dev-Code, Systemdaten, Selbsttests, Tabellen-Ansicht
  lib/env.php       Liest die vorhandene .env der Schule (nur lesen, nie schreiben)
  sql/              Schemas (MySQL + SQLite), demo-data.json = erfundene Beispieldaten
frontend/           Vue 3 + Vite + Pinia + vue-router (Hash-Routing)
  src/modules.js    Zentrale Liste der Bereiche (Kacheln, XP-Desktop, Status aktiv/extern/geplant)
  src/router.js     Routen; afterEach protokolliert Modulwechsel
  src/api.js        fetch-Wrapper; im Demo-Build ersetzt durch src/demo/backend.js
  src/demo/         Demo-Backend im Browser (gleiche Regeln wie Shop.php, Daten in localStorage)
  src/views/        Eine Ansicht pro Bereich
  src/components/XpShell.vue + XpWindowView.vue  Retro-Stil „Windows XP“ mit Fensterverwaltung
  src/views/dev/    Reiter des Dev-Bereichs (DevGate = Code-Sperre, System, Tests, Daten, API, Einstellungen)
  src/demo/dev.js   Dev-Bereich im Demo-Backend
  src/utils/devtools.js  Dev-Optionen (Debug-Anzeige, Verzögerung) und API-Protokoll
  src/styles/theme.css  Normales Design (Tokens), xp.css = XP-Stil (nur mit data-tn-style="xp")
TN-Dashboard-Demo.html  Komplette App als eine HTML-Datei mit Fake-Daten (npm run build:demo erzeugt sie, wird eingecheckt)
.github/workflows/ build.yml = Build-Prüfung (PHP-Lint, Build, Demo, install.php), demo-pages.yml = Demo auf GitHub Pages
start.sh / start.bat  Lokal starten (SQLite in local-data/, Test-Konten)
```

## Wichtige Regeln und Entscheidungen
- **Zuerst `git pull`:** Mehrere Sitzungen arbeiten parallel auf demselben Branch (`claude/charming-einstein-8qzsen`). Vor jeder Arbeit den Remote-Stand holen und prüfen, ob der Wunsch schon umgesetzt ist (ist in Sitzung 4 passiert: Lagerort-Seite und XP-Fenster gab es schon).
- **Jede Backend-Funktion doppelt:** Was in `api/lib/Shop.php` / `api/index.php` neu ist, muss auch in `frontend/src/demo/shop.js` bzw. `demo/backend.js` nachgebaut werden, sonst bricht die Demo-Version.
- **Tabellen-Präfix `tn_`** für alles (getrennt vom Lehrer-Dashboard in derselben DB). Schema-Änderungen immer in **beiden** Dateien `schema.mysql.sql` und `schema.sqlite.sql`.
- **Protokoll:** Jede schreibende Aktion ruft `ActivityLog::log(userId, action, module, details)` auf. `module` muss in `MODULES` (api/index.php) stehen. Details-Schlüssel auf Deutsch (`teil`, `menge`, `lagerort`).
- **Alle Lager-/Auftragsdaten sind erfunden** (`api/sql/demo-data.json`). Keine echten Kunden oder Bestellungen ins Repo.
- **Keine Secrets ins Repo:** `.env`, `api/config.php`, `local-data/` sind in `.gitignore`.
- **Hash-Routing** (`#/inventar`), damit es auf jedem Webserver ohne Rewrite-Regeln läuft.
- **Designs:** Standard ist das Kachel-Design (dunkel). Der XP-Stil ist ein **Geheimnis**: nur über den versteckten Code (Dev-Bereich, Versionszeile unten 5× antippen → Diagnose-Feld; Codes in `src/utils/cheats.js`) oder den Konami-Code. **Nirgends einen Stil-Umschalter anbieten** (weder Einstellungen noch Dev-Einstellungen), den Stil nicht in Anzeigen verraten und im README weder XP-Stil noch Codes erwähnen. Ausdrücklicher Wunsch des Inhabers.
- **Dev-Bereich ist gesichert:** nur Lehrer und Teilnehmer mit Dev-Rolle (Tabelle `tn_dev_access`, vergeben in der Lehrer-Ansicht) plus Dev-Code. Code gehasht in `tn_settings` (`dev_pin_hash`, `dev_pin_version`). **Testbetrieb (SQLite, Demo) = `0000`**, solange kein Code gesetzt ist; auf dem Server (MySQL) legt ein Lehrer ihn fest. Alle Dev-Routen über `Dev::require()`. Dev-Bereich ist nur lesend für Daten (keine Tabellen bearbeiten); Geheimnisse (Passwort-Hashes, settings-Werte) nie ausgeben.
- **README als fertige Produkt-Doku:** Der Inhaber möchte, dass das Repo so beschrieben ist, als liefe die App schon auf dem Schulserver. Neue Bereiche im README unter „Die Bereiche“ erklären.
- **Externe Programme:** Minifig-Finder und Pickliste existieren als eigene Programme und sollen eingebunden werden (Status `extern`). Ihr Code liegt nicht in diesem Repo; nicht neu erfinden, ohne den Inhaber zu fragen.
- **Keine Etiketten / kein Etikettendruck.** Wurde gebaut und vom Inhaber ausdrücklich abgelehnt.
- **Teile-Scanner = Teileerkennung per Foto** (wie Brickscanner), nicht Barcode/QR. Ausdrücklicher Wunsch des Inhabers. Nach dem Erkennen öffnet sich direkt die Teile-Seite.
- **Neue Funktionen sparsam:** Der Inhaber möchte keine ungefragten Zusatzfunktionen. Lieber nachfragen.

## Wie Dinge funktionieren
### Lagerzimmer und Kästchen-Codes (Skizze des Inhabers, Sitzung 4)
- Ein Raum mit Regalen voller kleiner Kästchen. Jeder Lagerort ist **ein Kästchen**, Code = Bereich + Nummer ohne Bindestrich: `G245`, `H012`, `N005`, `FT3`, `Plate2`. **G, H, N immer dreistellig mit führenden Nullen** (Wunsch des Inhabers: „N005“), FG/FN/FT/Plate ohne Nullen; Codes nur über `formatCode()` bauen. Mischkisten bleiben `MK-1` usw. (nicht im Raum eingezeichnet).
- Lage im Raum: **linke Wand G100–G999** (G = gebraucht), **rechte Wand oben H0–H999** (Bedeutung von H unbekannt, beim Inhaber nachfragen), **rechte Wand unten N0–N999** (N = neu), **unten links FG, FN, FT, Plate** mit Nummern (FT = Figurenteile; FG/FN als „Figuren gebraucht/neu“ angenommen, nicht bestätigt).
- Zentral in `frontend/src/utils/areas.js` (`AREAS`, `parseCode`). Raumplan: `components/RoomPlan.vue` (in Umlagern): Bereich → Hunderter-Block → 10×10 Kästchen → `/lagerort/<code>`. Nicht angelegte Kästchen sind grau und nicht klickbar.
- **Mischkiste (Begriff des Inhabers):** ein G-Kästchen mit **mehr als 3 verschiedenen Losen**, also noch nicht aufgeteilt. Wird im Raumplan (Bereich, Hunderter-Block, Kästchen) und auf der Lagerort-Seite **rot** markiert. Regel steht als `maxLots: 3` an Bereich G in `utils/areas.js` (`isMixedBox`); für andere Bereiche dort ergänzen. Die alten `MK-1…3` (kind `mischkiste`) aus den Beispieldaten sind ein anderes, älteres Konzept; offen, ob sie bleiben (Inhaber fragen).
- Codes immer **natürlich sortieren** (N4 vor N150): PHP `natcasesort`/`strnatcasecmp`, JS `localeCompare(…, 'de', { numeric: true })`. SQL `ORDER BY code` reicht nicht.
- Beispieldaten (`demo-data.json`) nutzen diese Codes; alte A-/B-/C-Fächer wurden auf G/H/N umgeschrieben.
### Teile-Scanner (`/scanner`, `ScannerView.vue`)
1. Foto per Live-Kamera („Erkennen“) oder Datei/Kamera-App („Foto wählen“, `capture="environment"`). Bild wird auf max. 1024 px JPEG verkleinert (`utils/camera.js`).
2. Upload `POST api/?r=recognize` (multipart, Feld `image`) → `Brickognize::predict()` schickt es an `https://api.brickognize.com/predict/` (Feld `query_image`), Antwort wird normalisiert (`id, name, type, category, score, img_url, bricklink_url`) und mit eigenen Losen verknüpft (`Shop::lotsByItem`).
3. Bestes Ergebnis ist ein Teil → `rememberRecognition()` (sessionStorage) und Weiterleitung auf `/teil/<nr>?scan=1&t=<zeit>`.
4. Demo-Version ruft Brickognize direkt aus dem Browser (`utils/brickognize.js`); im Artifact blockiert die CSP das, daher gibt es dort den Knopf „Demo: Erkennung ohne Foto testen“.
- Konfiguration: `brickognize.url` in `api/config.php` (null = aus). Kamera braucht auf dem Server **HTTPS**.
### Teile-Seite (`/teil/:no`, `PartView.vue`, `GET api/?r=part/<nr>`)
- Kennzahlen (Bestand, Farben, Wert, in offenen Aufträgen reserviert), Bestand nach Farbe und Lagerort mit Umlagern-Knopf, Aufträge mit dem Teil, letzte Umlagerungen, Alternativen aus der Erkennung.
- Bilder: `PartImage.vue` nimmt das Erkennungsbild, sonst BrickLink (`img.bricklink.com/ItemImage/PN/<farbe>/<nr>.png`; `color_id` sind BrickLink-Farb-IDs), sonst Platzhalter.
- Teil-Nummern in Inventar, Suche, Aufträgen und Analyse verlinken hierher.
### Lagerort-Seite (`/lagerort/:code`, `LocationView.vue`, `GET api/?r=location/<code>`)
- Inhalt, Kennzahlen, Inventur-Stand, Vorschläge rein/raus, offene Aufträge mit Teilen von hier, Umlagerungen, Blättern zum Nachbarfach.
- **Alles soll anklickbar sein** (Wunsch des Inhabers): `LocationTag` ist standardmäßig ein Link hierher (`:link="false"` nur innerhalb von Knöpfen/Links). Inventar liest `?category=`, `?location=`, `?q=` aus der Adresse; `BarList` hat eine `link`-Funktion; Tabellenzeilen mit `class="clickable"` öffnen die Teile-Seite, innere Links brauchen `@click.stop`.
### Dev-Bereich (`/dev`, `DevView.vue`, `api/lib/Dev.php`)
- `GET dev/status` → `allowed, unlocked, pin_set, test_mode, can_set_pin, locked_for`. `POST dev/unlock {pin}` (5 Fehlversuche → 60 s Sperre), `dev/lock`, `dev/pin {pin}` (nur Lehrer; ist schon ein Code gesetzt, muss entsperrt sein; neue Version sperrt alle anderen Sitzungen).
- `GET dev/system`, `dev/tables`, `dev/table?name=&page=&per=`, `POST dev/test {name}` (db_read, db_write, session, tmp_write, bricklink, brickognize). `POST users/<id>/dev {enabled}` vergibt die Dev-Rolle.
- `public_user()` liefert `can_dev`; Router-Guard `meta.dev`; Dev-Links (Leiste unten, XP-Startmenü) nur bei `auth.canDev`.
- **Automatische Sperre:** nach `dev_lock_minutes` (Standard 15) ohne Dev-Aktivität (`$_SESSION['dev_seen']`, jede Dev-Route und `dev/status` verlängern) sowie beim Abmelden; `login()` leert die Sitzung, damit nichts vom Vorgänger übrig bleibt. `DevView.vue` hat einen Timer bis `expires_in`, fragt bei Aktivität höchstens einmal pro Minute nach und springt bei jeder 423-Antwort zurück zur Code-Eingabe. Demo: gleiche Logik in `demo/dev.js` (`devLock` bei Login/Logout).
- Neue Tabellen legt `Dev::ensureSchema()` selbst an, damit Updates ohne erneutes install.php laufen.
### XP-Fenster (`XpShell.vue`, geheim, siehe Regeln)
- Mehrere Fenster gleichzeitig; verschieben (Titelleiste), Größe ändern (Ecke unten rechts), minimieren, maximieren (auch Doppelklick), schließen, Taskleiste mit allen Fenstern, Knopf „Desktop anzeigen“ rechts.
- Die Router-Adresse ist immer das **aktive** Fenster; `/` = Desktop. Fenster-Schlüssel = Pfad (`utils/windowing.js`), Query gehört zum Fenster.
- Jedes Fenster rendert `<RouterView :route>` und stellt per `provide(routeLocationKey, …)` eine **eigene Route** bereit (`XpWindowView.vue`). Deshalb behalten Ansichten (z. B. ausgewählter Auftrag) ihren Zustand, wenn man das Fenster wechselt. Ansichten müssen `useRoute()` verwenden, nicht `router.currentRoute`.
- `inject(WINDOW_VISIBLE)` sagt einer Ansicht, ob ihr Fenster minimiert ist (Scanner stoppt dann die Kamera).
- Unter 900 px Breite: Fenster bildschirmfüllend, nur das aktive sichtbar.
- **Schnell wieder weg:** Startmenü → „Ausschalten“ schaltet aufs normale Design zurück; automatisch nach 15 Minuten ohne Bedienung (`XP_IDLE_MS`, `tn-xp-seen` in localStorage, gilt auch nach Neuladen) und bei jeder An- oder Abmeldung (`leaveXp()` im Auth-Store).

## Testen
- Lokal: `./start.sh` (baut, legt SQLite + Test-Konten an, startet `php -S`). Konten: `lehrer/lehrer123`, `max/max12345`, `erika/erika123`.
- Nach Änderungen: `cd frontend && npm run build` und `npm run build:demo` müssen fehlerfrei laufen; `php -l` für geänderte PHP-Dateien.
- Demo: `npm run build:demo` schreibt `demo-dist/tn-dashboard-demo.html` (Artifact-Format) und **`TN-Dashboard-Demo.html` im Hauptordner** (vollständige Datei, wird eingecheckt; nach Frontend-Änderungen neu bauen und committen). Die Demo hat **alle Funktionen**, nur mit Fake-Daten (Wunsch des Inhabers, keine „eingeschränkte“ Demo); einzig BrickLink-Abrufe brauchen den Server.
- Ein-Klick-Demo im README: https://raw.githack.com/jimrocko/TN-Dashboard/HEAD/TN-Dashboard-Demo.html (braucht öffentliches Repo).
- Weitere Demo-Links: GitHub Pages https://jimrocko.github.io/TN-Dashboard/ (Workflow `demo-pages.yml`, braucht öffentliches Repo und Pages-Quelle „GitHub Actions“). Zusätzlich Claude-Artifacts (privat, gehören zu verschiedenen Sitzungen; zum Aktualisieren `demo-dist/tn-dashboard-demo.html` mit der jeweiligen URL erneut veröffentlichen, Link bleibt): https://claude.ai/artifact/D8tjj1fC192NUEocyTMZ9R und https://claude.ai/artifact/29wfoDHbYM184kcHwZECaP.
- Browser-Tests mit Playwright: Chromium liegt in `/opt/pw-browsers/chromium` (Cloud-Umgebung). XP-Stil im Test aktivieren: `localStorage['tn-dashboard-settings'] = '{"style":"xp"}'`.
- In der Cloud-Sandbox sind Brickognize und BrickLink-Bilder gesperrt; Erkennung dort nur über den Demo-Knopf testbar.

## Offene Punkte
- **Integration in den Schul-Webserver** macht der Inhaber später gemeinsam mit einem Agent (Hochladen von `dist/`, `.env`, HTTPS, Firewall für Brickognize).
- Repo öffentlich machen (Inhaber) und unter Settings → Pages „GitHub Actions“ wählen, damit der Demo-Link funktioniert.
- Minifig-Finder und Pickliste einbinden (Status `extern`). Braucht Zugang zu den bestehenden Programmen.
- Teileerkennung im echten Schulnetz testen (Firewall für `api.brickognize.com`, HTTPS für Kamera).
- Optional: Brickognize-Ergebnisse cachen (gleiche Fotos), Lizenz/Fair-Use von Brickognize beachten.

## Verlauf
- **Sitzung 1** (`claude/charming-einstein-8qzsen`, `claude/eager-maxwell-hyvb72`): Grundgerüst (Vue-PWA, PHP-API, Login, Protokoll, BrickLink-Cache), App-Icons, .env der Schule einlesen, Startskripte, Demo-Version, README mit Screenshots.
- **Sitzung 2**: Fake-Lagerdaten, Module Inventar/Umlagern/Inventur/Aufträge/Analyse/Suche, Firmen-Design, dann zurück zum ersten Kachel-Design als Standard plus versteckter XP-Stil.
- **Sitzung 3** (2026-10-15): XP-Stil mit echter Fensterverwaltung; Teile-Scanner als Foto-Teileerkennung über Brickognize; neue Teile-Seite `/teil/:no`; Teil-Nummern überall verlinkt; diese Agent-Doku angelegt. Verworfen auf Wunsch: Code-/QR-Scanner für Lagerorte und Etikettendruck.
- **Sitzung 3, Teil 2** (2026-10-15): Dev-Bereich gesichert (Dev-Rolle + Dev-Code, Test = 0000) und stark erweitert (System, Tests, Daten, API, Einstellungen, Debug-Anzeige); Lehrer-Ansicht mit Dev-Spalte; README als fertige Produkt-Doku mit Demo-Link; GitHub Actions für Build und Demo auf Pages; Demo-Datei in `docs/demo/`.
- **Sitzung 3, Teil 3**: Stil-Umschalter und Code-Feld aus den Dev-Einstellungen entfernt, versteckte Versionszeile (5× antippen) wiederhergestellt, XP-Stil aus README und Screenshots entfernt.
- **Sitzung 3, Teil 4**: Dev-Bereich sperrt sich nach 15 Minuten ohne Aktivität und beim Abmelden; Lücke geschlossen (Anmelden ohne Abmelden übernahm die Entsperrung des Vorgängers).
- **Sitzung 3, Teil 5**: Lagerort-Seite und überall anklickbare Lagerorte, Teile, Kategorien, Balken; XP mit „Ausschalten“, automatischem Zurückschalten nach 15 Minuten und bei An-/Abmeldung; CSV-Export auch in der Demo; Demo als `TN-Dashboard-Demo.html` im Hauptordner mit Ein-Klick-Link im README.
- **Sitzung 4** (2026-10-15): Lagerplan in Umlagern (`TransferView.vue`): alle Fächer nach Regal gruppiert, Mischkisten mit Zähler offener Vorschläge, jede Kachel öffnet `/lagerort/<code>` (im XP-Stil als eigenes Fenster). Nutzt nur `inventory/meta` und `transfers/suggestions`, keine Backend-Änderung. Danach Raumplan nach der Skizze des Lagerzimmers (`RoomPlan.vue`, `utils/areas.js`), Beispieldaten auf echte Kästchen-Codes (G/H/N dreistellig wie N005, FG/FN/FT/Plate) umgestellt, Demo-Speicher-Schlüssel auf v3 (alte Browser-Daten werden verworfen), G-Kästchen mit mehr als 3 Losen rot als Mischkiste, natürliche Sortierung für Nachbar-Kästchen und Laufweg in Aufträgen. KI-Gedächtnis: Regel „zuerst git pull“.
