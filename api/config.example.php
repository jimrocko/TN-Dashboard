<?php
// Konfiguration des TN Dashboards.
//
// Variante A (Schule): Die Werte kommen aus der bestehenden .env auf dem Server.
//   Diese Datei muss dann nicht kopiert werden. Nur wenn die .env woanders liegt oder
//   die Variablen anders heißen, nach config.php kopieren und 'env_file' / 'env_map' anpassen.
// Variante B: nach config.php kopieren und die Werte direkt unten eintragen.
// config.php und .env werden NICHT eingecheckt (siehe .gitignore).
return [
    // Pfad zur .env. null = automatisch suchen (App-Ordner, Web-Root, eine Ebene darüber).
    'env_file' => null,

    // Welche .env-Variable füllt welchen Wert? Der erste vorhandene Name gewinnt.
    // Heißen die Variablen in der Schule anders, hier die Namen ändern.
    'env_map' => [
        'db.driver'                 => ['TN_DB_DRIVER', 'DB_CONNECTION', 'DB_DRIVER'],
        'db.host'                   => ['DB_HOST', 'DB_HOSTNAME', 'MYSQL_HOST'],
        'db.port'                   => ['DB_PORT', 'MYSQL_PORT'],
        'db.name'                   => ['DB_DATABASE', 'DB_NAME', 'MYSQL_DATABASE'],
        'db.user'                   => ['DB_USERNAME', 'DB_USER', 'MYSQL_USER'],
        'db.password'               => ['DB_PASSWORD', 'DB_PASS', 'MYSQL_PASSWORD'],
        'db.prefix'                 => ['TN_DB_PREFIX'],
        'db.path'                   => ['TN_DB_PATH'],
        'bricklink.consumer_key'    => ['BRICKLINK_CONSUMER_KEY', 'BL_CONSUMER_KEY'],
        'bricklink.consumer_secret' => ['BRICKLINK_CONSUMER_SECRET', 'BL_CONSUMER_SECRET'],
        'bricklink.token'           => ['BRICKLINK_TOKEN', 'BRICKLINK_TOKEN_VALUE', 'BL_TOKEN'],
        'bricklink.token_secret'    => ['BRICKLINK_TOKEN_SECRET', 'BL_TOKEN_SECRET'],
    ],

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

    // Teileerkennung per Foto (Teile-Scanner) über den kostenlosen Dienst Brickognize (https://brickognize.com).
    // Fotos werden zur Erkennung an diesen Dienst geschickt. 'url' = null schaltet die Erkennung ab.
    'brickognize' => [
        'url'     => 'https://api.brickognize.com/predict/',
        'timeout' => 30,
    ],

    // Sitzungsdauer in Sekunden (8 h = ein Kurstag)
    'session_lifetime' => 28800,
];
