<?php
// Kopiere diese Datei nach config.php und trage eure Werte ein.
// config.php wird NICHT eingecheckt (siehe .gitignore).
return [
    'db' => [
        // 'mysql' für den Webserver, 'sqlite' für lokale Entwicklung
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'tn_dashboard',
        'user'     => 'tn_dashboard',
        'password' => '',
        // Präfix für alle Tabellen: hält die Schüler-App getrennt von bestehenden Tabellen
        // (z. B. dem Lehrer-Dashboard) in derselben Datenbank.
        'prefix'   => 'tn_',
        // nur für sqlite:
        'path'     => __DIR__ . '/data/tn_dashboard.sqlite',
    ],

    // BrickLink API – Zugangsdaten unter https://www.bricklink.com/v2/api/register_consumer.page
    'bricklink' => [
        'consumer_key'    => '',
        'consumer_secret' => '',
        'token'           => '',
        'token_secret'    => '',
        // Standard-Cachedauer für API-Antworten in Sekunden (24 h)
        'cache_ttl'       => 86400,
    ],

    // Sitzungsdauer in Sekunden (8 h = ein Kurstag)
    'session_lifetime' => 28800,
];
