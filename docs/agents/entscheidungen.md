# Entscheidungen und verworfene Ideen

Warum Dinge so sind, wie sie sind. Bevor du etwas davon änderst: Inhaber fragen.

## Festgelegt vom Inhaber
- **Kein Etikettendruck, keine QR-/Barcode-Codes für Lagerorte.** Wurde komplett gebaut (QR-Etiketten, Code-Scanner für `A-01`, `LOT-12`, Auftragsnummern) und auf Wunsch wieder entfernt.
- **Teile-Scanner = Foto-Teileerkennung** wie ein Brickscanner. Nach dem Erkennen sofort die Teile-Seite öffnen.
- **XP-Stil ist geheim.** Kein Umschalter in Einstellungen oder Dev-Einstellungen, keine Erwähnung im README, in Screenshots oder in Anzeigen (System-Infos, Debug-Leiste zeigen den Stil nicht). Zugang nur über die versteckte Versionszeile im Dev-Bereich (5× antippen → Feld „Diagnose-Code“) oder den Konami-Code. Codes in `frontend/src/utils/cheats.js`.
- **XP muss schnell weg sein:** „Ausschalten“ im Startmenü, automatisch nach 15 Minuten ohne Bedienung, bei jeder An- und Abmeldung.
- **Dev-Bereich gesichert:** Lehrer und Teilnehmer mit Dev-Rolle, plus Dev-Code. Test (SQLite/Demo) `0000`, auf dem Server legt ein Lehrer den Code fest. Sperrt sich nach 15 Minuten ohne Aktivität und beim Abmelden.
- **Dev-Bereich zeigt viel**, ist aber für Daten nur lesend.
- **Alles anklickbar:** Lagerorte, Teile, Aufträge, Kategorien, Balken.
- **Demo hat alle Funktionen**, nur mit Fake-Daten. Ausdrücklich keine „eingeschränkte“ Demo, kein Einschränkungs-Banner.
- **Ein Klick zur Demo** im README.
- **README so schreiben, als liefe die App schon auf dem Schulserver** (Produkt-Doku, keine „geplant“-Sprache für fertige Teile).
- **Integration in den echten Schul-Webserver kommt später**, gemeinsam mit dem Inhaber.
- **Minifig-Finder und Pickliste** sind bestehende externe Programme; nicht nachbauen.

## Technische Entscheidungen (von Agents, mit Begründung)
- **Brickognize über den eigenen PHP-Server** (`api/lib/Brickognize.php`): keine CORS-Probleme, Treffer werden direkt mit dem Lager verknüpft, Logging im Protokoll. Nur die Demo ruft Brickognize direkt aus dem Browser.
- **Fotos auf max. 1024 px JPEG verkleinern** vor dem Upload (Schul-WLAN).
- **Jedes XP-Fenster hat eine eigene Route** (`provide(routeLocationKey)` in `XpWindowView.vue`). Sonst würden alle Fenster auf die globale Adresse reagieren und ihren Zustand verlieren.
- **Dev-Code gehasht** (`password_hash`) in `tn_settings`; `dev_pin_version` macht alte Entsperrungen ungültig, wenn der Code geändert wird.
- **`login()` leert `$_SESSION`**: Vorher blieb beim Anmelden ohne Abmelden die Dev-Entsperrung des Vorgängers erhalten (Sicherheitslücke, gefunden und geschlossen).
- **Neue Tabellen legt `Dev::ensureSchema()` selbst an**, damit Updates auf dem Server ohne erneutes `install.php` laufen.
- **Demo-Datei wird eingecheckt** (`TN-Dashboard-Demo.html`), damit der Ein-Klick-Link ohne Build funktioniert. Nach jeder Frontend-Änderung `npm run build:demo` und mitcommitten.
- **Ein-Klick-Link über raw.githack.com** statt nur GitHub Pages: funktioniert, sobald das Repo öffentlich ist, ohne Pages-Einrichtung.
- **Hash-Routing**, damit die App auf jedem Webspace ohne Rewrite-Regeln läuft.

## Verworfen
| Idee | Warum verworfen |
|---|---|
| QR-Etiketten für Lagerorte und Lose | Inhaber: „keine Etiketten“ |
| Code-Scanner (Lagerort-, Auftrags-, LOT-Codes per Kamera/Handscanner) | Inhaber wollte Teileerkennung statt Codes |
| `jsqr`, `qrcode` als Abhängigkeiten | mit den beiden Funktionen entfernt |
| Stil-Umschalter im Dev-Bereich | Inhaber: Stil darf im Menü nicht änderbar sein |
| Einschränkungs-Hinweis in der Demo | Inhaber: Demo soll voll funktionieren |
| Demo unter `docs/demo/` | in den Hauptordner verschoben, damit man sie sofort findet |
