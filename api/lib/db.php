<?php

function config(): array
{
    static $config = null;
    if ($config === null) {
        // Eigene config.php, sonst die Vorlage mit Standardwerten; danach Werte aus der .env übernehmen.
        $file = __DIR__ . '/../config.php';
        $config = env_apply(require (is_file($file) ? $file : __DIR__ . '/../config.example.php'));
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
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        // 'mysql' und 'mariadb' (wie in Laravel-.env üblich) laufen beide über pdo_mysql.
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) ($c['port'] ?: 3306), $c['name']);
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
