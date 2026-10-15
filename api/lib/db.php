<?php

function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) {
            json_error('Server nicht konfiguriert: api/config.php fehlt', 500);
        }
        $config = require $file;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config()['db'];
    if ($c['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['password']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

/** Tabellenname mit Präfix, z. B. t('users') => 'tn_users'. */
function t(string $table): string
{
    $prefix = config()['db']['prefix'] ?? 'tn_';
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
        throw new RuntimeException('Ungültiges Tabellen-Präfix');
    }
    return $prefix . $table;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
