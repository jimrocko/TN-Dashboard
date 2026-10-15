<?php
/**
 * Liest eine bestehende .env-Datei (z. B. die vom Schulserver) und überträgt die Werte in die Konfiguration.
 * Die .env wird nur gelesen, nie verändert.
 */

/** @return array<string, string> */
function env_parse(string $file): array
{
    $vars = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $value = $m[2];
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $end = strpos($value, $quote, 1);
            $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
            if ($quote === '"') {
                $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
            }
        } else {
            // Kommentar am Zeilenende abschneiden: KEY=wert # kommentar
            $value = trim(preg_replace('/\s+#.*$/', '', $value));
        }
        $vars[$m[1]] = $value;
    }
    return $vars;
}

/** Sucht die .env: entweder der eingestellte Pfad oder übliche Orte rund um die App. */
function env_find(?string $configured): ?string
{
    $candidates = $configured ? [$configured] : [
        __DIR__ . '/../.env',          // api/.env
        __DIR__ . '/../../.env',       // App-Ordner, z. B. /tn-dashboard/.env
        __DIR__ . '/../../../.env',    // Ordner darüber (Web-Root)
        __DIR__ . '/../../../../.env', // eine Ebene über dem Web-Root
    ];
    if (!$configured && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/../.env';
    }
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return realpath($path) ?: $path;
        }
    }
    return null;
}

/**
 * Überschreibt Konfigurationswerte mit Werten aus der .env.
 * $map: 'db.host' => ['DB_HOST', ...] – der erste vorhandene Name gewinnt.
 * Ergebnis-Metadaten (gefundene Datei, gesetzte/fehlende Namen) stehen in $config['_env'].
 */
function env_apply(array $config): array
{
    $file = env_find($config['env_file'] ?? null);
    $vars = $file ? env_parse($file) : [];
    $status = [];
    foreach ($config['env_map'] ?? [] as $path => $names) {
        $used = null;
        foreach ((array) $names as $name) {
            if (isset($vars[$name]) && $vars[$name] !== '') {
                $used = $name;
                [$section, $key] = explode('.', $path, 2);
                $config[$section][$key] = $vars[$name];
                break;
            }
        }
        $status[$path] = ['names' => (array) $names, 'used' => $used];
    }
    $config['_env'] = ['file' => $file, 'fields' => $status];
    return $config;
}
