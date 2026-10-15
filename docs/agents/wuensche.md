# Wünsche des Inhabers (Chronik)

Alle Anweisungen des Projektinhabers in der Reihenfolge, in der sie kamen, mit dem, was daraus wurde.
Wer weiterarbeitet: Diese Liste zeigt, **wie der Inhaber denkt**. Er schreibt kurz, oft per Sprache (Tippfehler wie
„Gesichter“ = „gesichert“, „knickbar“ = „klickbar“, „Eiso“ = „also“) und schickt Nachträge, während gearbeitet wird.
Nachträge gelten sofort und haben Vorrang vor dem ursprünglichen Plan.

## Sitzung 1 und 2 (vor dieser Doku, nur aus den Commits rekonstruiert)
- Grundgerüst: Vue-PWA, PHP-API, Login, Aktivitätsprotokoll pro Teilnehmer, BrickLink-Cache.
- Die `.env` des Schulservers soll eingelesen werden (nur lesen).
- Startskripte zum lokalen Testen (Mac/Linux und Windows), Demo-Version ohne Server, README mit Logo und Screenshots.
- Fake-Lagerdaten und Module: Inventar, Umlagern, Inventur, Auftragszentrale, Analyse, Suche.
- Erst ein schlichtes Firmen-Design, dann **zurück zum ersten Kachel-Design als Standard**, zusätzlich ein versteckter Retro-Stil „Windows XP“.

## Sitzung 3 (15.10.2026)
| # | Wunsch (sinngemäß) | Umsetzung |
|---|---|---|
| 1 | „Fahre fort mit der Entwicklung“ (Link auf alte Session, von hier nicht lesbar) | Stand aus dem Repo rekonstruiert; offener Punkt war der Teile-Scanner |
| 2 | „Die Windows-XP-Sachen sollen auch als Fenster gehen“ | Echte Fensterverwaltung in `XpShell.vue` (mehrere Fenster, verschieben, Größe, minimieren, Taskleiste), eigene Route pro Fenster |
| 3 | „Dokumentiere deine Memories im Repo“ | `CLAUDE.md` + `AGENTS.md` angelegt |
| 4 | „Keine Etiketten“ | Gebauter QR-Etikettendruck komplett entfernt (auch `qrcode`-Paket) |
| 5 | „Ein Brickscanner, der Teile erkennt, da gibt es kostenlose online“ | Teileerkennung per Foto über Brickognize |
| 6 | „Nur die Funktionen, die wir davor hatten“ → Rückfrage → „Scanner = Teileerkennung“ | Code-/QR-Scanner für Lagerorte, Aufträge, LOT-Codes wieder entfernt |
| 7 | „Nach dem Scannen soll das Teil aufgehen und wichtige Infos zeigen, z. B. Bestand“ | Teile-Seite `/teil/:no`, Scanner leitet direkt dorthin |
| 8 | „Genauso bei der normalen App, und mach mir die App hier als Test mit Fake-Datenbank an“ | Teile-Seite in beiden Designs; Demo als Claude-Artifact veröffentlicht |
| 9 | Dev-Bereich mit Code sichern: im Test `0000`, sonst aus der Datenbank, für Lehrer und für Schüler, denen die Rolle gegeben wird („wie bei mir“); Dev-Bereich soll **richtig viele** Einstellungen, Tests und Daten zeigen | Dev-Rolle + Dev-Code (`api/lib/Dev.php`), Reiter System, Tests, Daten, API, Einstellungen |
| 10 | „Mach das Repo so, dass es den Bereich erklärt und man darüber direkt die Demo öffnen kann“ | README erklärt alle Bereiche, Demo-Links |
| 11 | „Wir arbeiten später daran, es in den Webserver zu integrieren“ | Als offener Punkt notiert, nicht angefangen |
| 12 | „Mach das Repo fertig, als wäre es schon drin“ | README als fertige Produkt-Doku formuliert |
| 13 | „Ich kann es public machen“ | GitHub-Pages-Workflow ergänzt |
| 14 | „Öffne mir nebenbei die Demo als Datei wie vorher“ | Artifact aktualisiert und geöffnet |
| 15 | „Der versteckte Code muss immer noch versteckt sein, und der Stil soll im Menü nicht änderbar sein“ | Stil-Umschalter aus Dev-Einstellungen entfernt, versteckte Versionszeile (5× antippen) zurück, XP aus README/Screenshots entfernt |
| 16 | „Der Bereich soll sich nach Zeit oder Ausloggen wieder sperren“ | Dev-Sperre nach 15 Min. ohne Aktivität und beim Abmelden; Sitzungslücke beim Benutzerwechsel geschlossen |
| 17 | „Alles soll mehr clicky sein, z. B. bei Umlagern Lagerorte klickbar, damit ich sehe, was drin ist“ | Lagerort-Seite `/lagerort/:code`, Lagerort-Tags überall Links, Kategorien/Balken/Zeilen klickbar |
| 18 | „Das Windows-XP-Ding soll schnell wieder schließbar sein“ | „Ausschalten“ im XP-Startmenü |
| 19 | „… und nach Zeit oder Ausloggen wieder zurück“ | XP schaltet nach 15 Min. ohne Bedienung und bei An-/Abmeldung aufs normale Design zurück |
| 20 | „Demo im Repo öffnen ohne Installation, nur eine .html mit Fake-Daten“ → sofort korrigiert: „**nicht** mit beschränkten Funktionen, voll, aber Fake-Daten“ | `TN-Dashboard-Demo.html` im Hauptordner, volle Funktionen; CSV-Export auch in der Demo |
| 21 | „Machst du das Repo gleich fertig“ / „mit einem Klick auf eine Demo“ | Knopf „Demo starten“ (raw.githack) ganz oben im README |
| 22 | „Mach du“ (Repo öffentlich + PR mergen) | PR #1 gemerged; öffentlich gestellt hat es der Inhaber selbst („ist öffentlich“) |
| 23 | „Sichere jetzt deine Erinnerungen im Repo, damit andere Agents Kontext haben“ | Dieser Ordner `docs/agents/` |
