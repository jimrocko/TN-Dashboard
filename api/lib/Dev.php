<?php

/**
 * Dev-Bereich: Zugang, Dev-Code, Systemdaten, Selbsttests und Datenbank-Ansicht.
 *
 * Zugang haben Lehrer/Admins und Teilnehmer, denen ein Lehrer die Dev-Rolle gegeben hat (Tabelle dev_access).
 * Zusätzlich muss der Dev-Code eingegeben werden (pro Sitzung). Der Code liegt gehasht in der Tabelle settings.
 * Testbetrieb (SQLite, z. B. ./start.sh): Solange kein Code gesetzt ist, gilt 0000.
 */
final class Dev
{
    public const TEST_PIN = '0000';
    private const MAX_TRIES = 5;
    private const LOCK_SECONDS = 60;

    /** Tabellen auch auf bestehenden Installationen anlegen, ohne dass install.php neu laufen muss. */
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $sqlite = self::isSqlite();
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('settings') . ' (
            setting_key ' . ($sqlite ? 'TEXT' : 'VARCHAR(64)') . ' NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NOT NULL
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('dev_access') . ' (
            user_id ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' NOT NULL PRIMARY KEY,
            granted_by ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' NULL,
            granted_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NOT NULL
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    }

    public static function isSqlite(): bool
    {
        return (config()['db']['driver'] ?? 'mysql') === 'sqlite';
    }

    /** Testbetrieb = lokale SQLite-Datenbank. Auf dem Schulserver (MySQL) gibt es keinen Standard-Code. */
    public static function testMode(): bool
    {
        return self::isSqlite();
    }

    public static function setting(string $key): ?string
    {
        self::ensureSchema();
        $v = q('SELECT setting_value FROM {settings} WHERE setting_key = ?', [$key])->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::ensureSchema();
        q('DELETE FROM {settings} WHERE setting_key = ?', [$key]);
        q('INSERT INTO {settings} (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', [$key, $value, now()]);
    }

    public static function allowed(array $user): bool
    {
        if (in_array($user['role'], ROLES_STAFF, true)) return true;
        self::ensureSchema();
        return (bool) q('SELECT 1 FROM {dev_access} WHERE user_id = ?', [(int) $user['id']])->fetchColumn();
    }

    public static function accessIds(): array
    {
        self::ensureSchema();
        return array_map('intval', q('SELECT user_id FROM {dev_access}')->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function grant(int $userId, bool $on, int $by): void
    {
        self::ensureSchema();
        q('DELETE FROM {dev_access} WHERE user_id = ?', [$userId]);
        if ($on) q('INSERT INTO {dev_access} (user_id, granted_by, granted_at) VALUES (?, ?, ?)', [$userId, $by, now()]);
    }

    public static function pinSet(): bool
    {
        return self::setting('dev_pin_hash') !== null;
    }

    public static function setPin(string $pin): void
    {
        if (!preg_match('/^\d{4,8}$/', $pin)) json_error('Der Dev-Code muss aus 4 bis 8 Ziffern bestehen');
        self::setSetting('dev_pin_hash', password_hash($pin, PASSWORD_DEFAULT));
        // Neue Version: alle anderen Sitzungen müssen den neuen Code eingeben.
        self::setSetting('dev_pin_version', (string) ((int) self::setting('dev_pin_version') + 1));
        start_session();
        $_SESSION['dev_unlocked'] = self::pinVersion() + 1;
    }

    private static function pinVersion(): int
    {
        return (int) (self::setting('dev_pin_version') ?? 0);
    }

    public static function unlocked(): bool
    {
        start_session();
        // Gespeichert ist die Code-Version beim Entsperren (+1, damit Version 0 nicht als „leer“ gilt).
        return isset($_SESSION['dev_unlocked']) && $_SESSION['dev_unlocked'] === self::pinVersion() + 1;
    }

    public static function status(array $user): array
    {
        start_session();
        return [
            'allowed'     => self::allowed($user),
            'unlocked'    => self::unlocked(),
            'pin_set'     => self::pinSet(),
            'test_mode'   => self::testMode(),
            'can_set_pin' => in_array($user['role'], ROLES_STAFF, true),
            'locked_for'  => max(0, (int) ($_SESSION['dev_locked_until'] ?? 0) - time()),
        ];
    }

    /** Code prüfen. Nach 5 Fehlversuchen 60 Sekunden Sperre (pro Sitzung). */
    public static function unlock(string $pin): bool
    {
        start_session();
        if (($_SESSION['dev_locked_until'] ?? 0) > time()) {
            json_error('Zu viele Fehlversuche. Bitte ' . ($_SESSION['dev_locked_until'] - time()) . ' Sekunden warten.', 429);
        }
        $hash = self::setting('dev_pin_hash');
        if ($hash === null && !self::testMode()) {
            json_error('Es ist noch kein Dev-Code gesetzt. Ein Lehrer muss ihn zuerst festlegen.', 409);
        }
        $ok = $hash === null ? hash_equals(self::TEST_PIN, $pin) : password_verify($pin, $hash);
        if ($ok) {
            $_SESSION['dev_unlocked'] = self::pinVersion() + 1;
            $_SESSION['dev_tries'] = 0;
            return true;
        }
        $_SESSION['dev_tries'] = ($_SESSION['dev_tries'] ?? 0) + 1;
        if ($_SESSION['dev_tries'] >= self::MAX_TRIES) {
            $_SESSION['dev_tries'] = 0;
            $_SESSION['dev_locked_until'] = time() + self::LOCK_SECONDS;
        }
        return false;
    }

    public static function lock(): void
    {
        start_session();
        unset($_SESSION['dev_unlocked']);
    }

    /** Für alle Dev-Routen: Dev-Rolle und eingegebener Code. */
    public static function require(): array
    {
        $user = require_user();
        if (!self::allowed($user)) json_error('Kein Zugang zum Dev-Bereich', 403);
        if (!self::unlocked()) json_error('Dev-Bereich ist gesperrt. Bitte Dev-Code eingeben.', 423);
        return $user;
    }

    // ---- Daten ----

    public const TABLES = ['users', 'activity_log', 'locations', 'inventory', 'orders', 'order_items', 'transfers',
        'stocktakes', 'stocktake_items', 'bl_cache', 'settings', 'dev_access'];
    /** Spalten, die nie angezeigt werden. */
    private const HIDDEN = ['password_hash', 'setting_value'];

    public static function tables(): array
    {
        self::ensureSchema();
        $out = [];
        foreach (self::TABLES as $tb) {
            try {
                $out[] = ['name' => $tb, 'table' => t($tb), 'rows' => (int) q("SELECT COUNT(*) FROM {{$tb}}")->fetchColumn()];
            } catch (Throwable $e) {
                $out[] = ['name' => $tb, 'table' => t($tb), 'rows' => null, 'error' => 'fehlt'];
            }
        }
        return $out;
    }

    public static function table(string $name, int $page, int $per): array
    {
        if (!in_array($name, self::TABLES, true)) json_error('Unbekannte Tabelle', 404);
        self::ensureSchema();
        $per = max(1, min($per, 200));
        $page = max(1, $page);
        $total = (int) q("SELECT COUNT(*) FROM {{$name}}")->fetchColumn();
        $rows = q("SELECT * FROM {{$name}} ORDER BY 1 DESC LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();
        foreach ($rows as &$r) {
            foreach (self::HIDDEN as $h) {
                if (array_key_exists($h, $r)) $r[$h] = '••••••';
            }
            foreach ($r as $k => $v) {
                if (is_string($v) && strlen($v) > 300) $r[$k] = substr($v, 0, 300) . ' … (' . strlen($v) . ' Zeichen)';
            }
        }
        unset($r);
        return ['name' => $name, 'total' => $total, 'page' => $page, 'per' => $per,
            'columns' => $rows ? array_keys($rows[0]) : [], 'rows' => $rows];
    }

    // ---- System ----

    public static function system(): array
    {
        $c = config();
        $sqlite = self::isSqlite();
        $dbVersion = null;
        $dbSize = null;
        try {
            $dbVersion = $sqlite ? db()->query('SELECT sqlite_version()')->fetchColumn() : db()->query('SELECT VERSION()')->fetchColumn();
            if ($sqlite) {
                $dbSize = is_file($c['db']['path']) ? filesize($c['db']['path']) : null;
            } else {
                $dbSize = (int) q('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name LIKE ?', [t('') . '%'])->fetchColumn();
            }
        } catch (Throwable $e) { /* nur Anzeige */ }
        $env = $c['_env'] ?? [];
        return [
            'app' => ['version' => '0.4', 'server_time' => now(), 'timezone' => date_default_timezone_get(), 'test_mode' => self::testMode()],
            'php' => [
                'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY,
                'memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'), 'max_execution_time' => ini_get('max_execution_time'),
                'extensions' => array_map(fn ($e) => ['name' => $e, 'loaded' => extension_loaded($e)],
                    ['pdo_mysql', 'pdo_sqlite', 'curl', 'json', 'mbstring', 'openssl', 'gd', 'fileinfo']),
            ],
            'db' => ['driver' => $sqlite ? 'sqlite' : 'mysql', 'version' => $dbVersion, 'size_bytes' => $dbSize,
                'prefix' => $c['db']['prefix'] ?? 'tn_', 'host' => $sqlite ? null : ($c['db']['host'] ?? null),
                'name' => $sqlite ? basename((string) $c['db']['path']) : ($c['db']['name'] ?? null)],
            'services' => [
                'bricklink_configured' => (new BrickLinkClient($c['bricklink']))->isConfigured(),
                'bricklink_cache_ttl'  => (int) ($c['bricklink']['cache_ttl'] ?? 0),
                'brickognize_url'      => array_key_exists('url', $c['brickognize'] ?? []) ? $c['brickognize']['url'] : Brickognize::DEFAULT_URL,
            ],
            // Nur welche Felder aus welcher .env-Variable kommen, nie die Werte.
            'env' => ['file' => !empty($env['file']) ? basename((string) $env['file']) : null,
                'fields' => array_map(fn ($path, $f) => ['field' => $path, 'from' => $f['used']], array_keys($env['fields'] ?? []), $env['fields'] ?? [])],
            'session' => ['lifetime' => (int) ($c['session_lifetime'] ?? 0), 'id_prefix' => substr(session_id(), 0, 6) . '…',
                'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'],
            'request' => ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null],
        ];
    }

    // ---- Selbsttests (nur lesend bzw. mit sofortigem Aufräumen) ----

    public const TESTS = ['db_read', 'db_write', 'session', 'bricklink', 'brickognize', 'tmp_write'];

    public static function test(string $name): array
    {
        if (!in_array($name, self::TESTS, true)) json_error('Unbekannter Test', 404);
        $t0 = microtime(true);
        try {
            $detail = match ($name) {
                'db_read'     => self::tDbRead(),
                'db_write'    => self::tDbWrite(),
                'session'     => self::tSession(),
                'bricklink'   => self::tBrickLink(),
                'brickognize' => self::tBrickognize(),
                'tmp_write'   => self::tTmp(),
            };
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
            $detail = $e->getMessage();
        }
        return ['name' => $name, 'ok' => $ok, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'detail' => $detail];
    }

    private static function tDbRead(): string
    {
        $n = (int) q('SELECT COUNT(*) FROM {users}')->fetchColumn();
        return "$n Benutzer gelesen";
    }

    private static function tDbWrite(): string
    {
        $key = 'selftest_' . bin2hex(random_bytes(4));
        self::setSetting($key, 'ok');
        $v = self::setting($key);
        q('DELETE FROM {settings} WHERE setting_key = ?', [$key]);
        if ($v !== 'ok') throw new RuntimeException('Geschriebener Wert kam nicht zurück');
        return 'Schreiben, Lesen und Löschen in ' . t('settings') . ' ok';
    }

    private static function tSession(): string
    {
        start_session();
        if (session_status() !== PHP_SESSION_ACTIVE) throw new RuntimeException('Sitzung nicht aktiv');
        return 'Sitzung aktiv (' . session_save_path() . ')';
    }

    private static function tBrickLink(): string
    {
        $bl = new BrickLinkClient(config()['bricklink']);
        if (!$bl->isConfigured()) throw new RuntimeException('Keine BrickLink-Zugangsdaten in .env/config.php');
        $r = Cache::remember('item:PART:3001', (int) (config()['bricklink']['cache_ttl'] ?? 86400),
            fn () => $bl->get('/items/PART/3001'));
        return 'Teil 3001 abgerufen' . (!empty($r['cached']) ? ' (aus dem Cache)' : '');
    }

    private static function tBrickognize(): string
    {
        $url = array_key_exists('url', config()['brickognize'] ?? []) ? config()['brickognize']['url'] : Brickognize::DEFAULT_URL;
        if (!$url) throw new RuntimeException('Teileerkennung ist abgeschaltet');
        $parts = parse_url($url);
        $ch = curl_init($parts['scheme'] . '://' . $parts['host'] . '/health/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($status === 0) throw new RuntimeException('Nicht erreichbar: ' . $err);
        return $parts['host'] . " antwortet (HTTP $status)";
    }

    private static function tTmp(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'tn');
        if ($f === false || file_put_contents($f, 'ok') !== 2) throw new RuntimeException('Temp-Ordner nicht beschreibbar');
        unlink($f);
        return 'Temp-Ordner beschreibbar (für Foto-Uploads)';
    }
}
