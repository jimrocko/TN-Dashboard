# TN Dashboard

Web-App (PWA) für die Teilnehmer im Lego-Shop. Sie bündelt Pickliste, Minifig-Finder, Inventar, Suche, Umlagern, Scanner, Auftragszentrale, Inventur und Analyse und protokolliert die Aktionen pro Teilnehmer für den Lehrer.

Die App läuft als eigene Seite auf dem Webserver des Lehrers und ist von seinem Lehrer-Dashboard getrennt: Sie hat eigene Tabellen mit dem Präfix `tn_` und ein eigenes Login.

## In der Schule einrichten
1. `cd frontend && npm install && npm run build` erzeugt den Ordner `dist/` mit Frontend und PHP-Backend.
2. Den Inhalt von `dist/` in einen Ordner auf dem Webserver hochladen, z. B. `/tn-dashboard/`.
3. `https://<server>/tn-dashboard/api/install.php` aufrufen.
   - Die Seite sucht die vorhandene **`.env`** des Servers automatisch: im App-Ordner, im Web-Root und eine Ebene darüber. Sie zeigt an, welche Werte gefunden wurden. Angezeigt werden nur die Namen, nie die Werte.
   - Danach legt sie die Tabellen (`tn_…`) und das erste Lehrer-Konto an.
4. Nur falls die `.env` woanders liegt oder die Variablen anders heißen: `api/config.example.php` nach `api/config.php` kopieren und dort `'env_file'` und `'env_map'` anpassen.
   Ohne `.env` können die Werte auch direkt in `api/config.php` eingetragen werden.

Erkannte Variablennamen (Standard): `DB_HOST`, `DB_PORT`, `DB_DATABASE`/`DB_NAME`, `DB_USERNAME`/`DB_USER`, `DB_PASSWORD`/`DB_PASS`, `BRICKLINK_CONSUMER_KEY`, `BRICKLINK_CONSUMER_SECRET`, `BRICKLINK_TOKEN`, `BRICKLINK_TOKEN_SECRET`.
Die `.env` wird nur gelesen und nie verändert. Eine `.htaccess` im App-Ordner sperrt den Zugriff auf versteckte Dateien wie `.env` über das Web.

Voraussetzungen: PHP 8.1+ mit PDO (MySQL) und cURL, MySQL/MariaDB.

## Lokale Entwicklung
```sh
cp api/config.example.php api/config.php   # dort 'driver' => 'sqlite' setzen
php api/install.php --demo                 # Demo-Konten: lehrer/lehrer123, max/max12345
php -S 127.0.0.1:8000 -t .                 # PHP-API
cd frontend && npm run dev                 # Vite mit Proxy auf /api
```
