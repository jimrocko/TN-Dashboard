# TN Dashboard

Web-App (PWA) für die Teilnehmer im Lego-Shop. Sie bündelt Pickliste, Minifig-Finder, Inventar, Suche, Umlagern, Scanner, Auftragszentrale, Inventur und Analyse und protokolliert die Aktionen pro Teilnehmer für den Lehrer.

Die App läuft als eigene Seite auf dem Webserver des Lehrers und ist von seinem Lehrer-Dashboard getrennt: Sie hat eigene Tabellen mit dem Präfix `tn_` und ein eigenes Login.

## Installation auf dem Webserver
1. `cd frontend && npm install && npm run build` erzeugt den Ordner `dist/` mit Frontend und PHP-Backend.
2. Den Inhalt von `dist/` in einen Ordner auf dem Webserver hochladen, z. B. `/tn-dashboard/`.
3. `api/config.example.php` nach `api/config.php` kopieren und die Datenbank-Zugangsdaten eintragen, optional auch die BrickLink-API-Schlüssel.
4. `https://<server>/tn-dashboard/api/install.php` aufrufen. Das legt die Tabellen und das erste Lehrer-Konto an.

Voraussetzungen: PHP 8.1+ mit PDO (MySQL) und cURL, MySQL/MariaDB.

## Lokale Entwicklung
```sh
cp api/config.example.php api/config.php   # dort 'driver' => 'sqlite' setzen
php api/install.php --demo                 # Demo-Konten: lehrer/lehrer123, max/max12345
php -S 127.0.0.1:8000 -t .                 # PHP-API
cd frontend && npm run dev                 # Vite mit Proxy auf /api
```
