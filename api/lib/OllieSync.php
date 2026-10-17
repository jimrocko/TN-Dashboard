<?php

/**
 * Abgleich mit Ollies Dashboard (bi-legoshop-dashboard, läuft auf demselben Rechner, Wunsch des Inhabers).
 * Ollies Dashboard holt den BrickLink-Bestand alle 4 Stunden selbst und speichert ihn in seiner SQLite-Datenbank
 * (data/legoshop.db, Tabelle inventory_bricklink). Wir lesen diese Datei nur (Read-only), ändern dort nie etwas
 * und brauchen dafür keinen einzigen BrickLink-Abruf. Übernommen wird wie beim BrickLink-Import (per Lot-ID,
 * Lagerort aus den Remarks, siehe BrickLinkSync::importItems).
 * Pfad: .env OLLIE_DB_PATH (oder 'ollie' => ['db_path' => …] in api/config.php). Leer = aus.
 * Neu laden (Wunsch des Inhabers): Unser Knopf ruft denselben Import auf wie Ollies Knopf „BrickLink-Inventar“
 * (GET /import/bricklink-inventory in seinem Dashboard, OLLIE_URL, Standard http://127.0.0.1:8000). Ollies Dashboard
 * lädt dann selbst von BrickLink (ein Abruf, zählt bei ihm als geladen) und wir übernehmen danach seinen Stand.
 * Immer gleicher Bestand (Wunsch des Inhabers):
 * - Hat Ollies Dashboard neu geladen (sein Knopf oder alle 4 h), übernehmen wir das automatisch (autoSync, beim Öffnen
 *   der Startseite oder des Inventars). Verglichen werden nur Ollies eigene Zeitstempel (import_status.last_run, UTC).
 * - Ausnahme: Solange Umlagerungen/Inventuren noch nicht bei BrickLink eingefügt sind, warten wir, sonst wären sie weg.
 *   Nach „Ja, ist eingefügt“ gilt Ollies Stand bis dahin als veraltet (markExported), und Ollie lädt neu (after-export).
 */
final class OllieSync
{
    public static function path(): ?string
    {
        $p = trim((string) (config()['ollie']['db_path'] ?? ''));
        // Eigene config.php ohne 'ollie' (ältere Vorlage): direkt in der .env nachsehen
        if ($p === '' && ($file = config()['_env']['file'] ?? null)) $p = trim((string) (env_parse($file)['OLLIE_DB_PATH'] ?? ''));
        return $p === '' ? null : $p;
    }

    /** Adresse von Ollies Dashboard (gleicher Rechner). */
    public static function url(): string
    {
        $u = trim((string) (config()['ollie']['url'] ?? ''));
        if ($u === '' && ($file = config()['_env']['file'] ?? null)) $u = trim((string) (env_parse($file)['OLLIE_URL'] ?? ''));
        return rtrim($u !== '' ? $u : 'http://127.0.0.1:8000', '/');
    }

    /**
     * Wie Ollies Knopf „BrickLink-Inventar“: sein Dashboard lädt den Bestand neu von BrickLink und speichert ihn.
     * Gibt die Anzahl Lots zurück, die Ollie eingelesen hat.
     */
    public static function triggerRefresh(): int
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Die PHP-Erweiterung cURL fehlt auf dem Server.');
        @set_time_limit(360);
        $ch = curl_init(self::url() . '/import/bricklink-inventory');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Ollies Dashboard antwortet nicht (' . self::url() . '). Läuft es? ' . $err);
        $data = json_decode((string) $body, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) ? (string) ($data['detail'] ?? $data['error'] ?? '') : '';
            throw new RuntimeException('Ollies Dashboard konnte den Bestand nicht laden (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . $msg : '.'));
        }
        if (isset($data['ok']) && $data['ok'] === false) throw new RuntimeException('BrickLink-Fehler in Ollies Dashboard: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        return (int) ($data['records'] ?? 0);
    }

    /** Ollies Datenbank nur lesend öffnen. */
    private static function open(): PDO
    {
        $path = self::path();
        if ($path === null) throw new RuntimeException('Ollies Dashboard ist nicht eingerichtet (OLLIE_DB_PATH in der .env).');
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Die PHP-Erweiterung pdo_sqlite fehlt auf dem Server.');
        if (!is_file($path)) throw new RuntimeException('Ollies Datenbank wurde nicht gefunden: ' . $path);
        if (!is_readable($path)) throw new RuntimeException('Ollies Datenbank ist nicht lesbar (Rechte des Webservers prüfen).');
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10];
        if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
        return new PDO('sqlite:' . $path, null, null, $opts);
    }

    /** Ollies letzter Ladezeitpunkt, so wie er ihn speichert (UTC, „YYYY-MM-DD HH:MM:SS“), oder null. */
    private static function run(PDO $db): ?string
    {
        $st = $db->query("SELECT last_run FROM import_status WHERE import_name = 'bricklink_inventory'")->fetchColumn();
        return $st === false || $st === null || $st === '' ? null : (string) $st;
    }

    /** Ollies UTC-Zeit in unsere Ortszeit (wie now()). */
    private static function local(?string $utc): ?string
    {
        if ($utc === null) return null;
        try {
            return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $utc;
        }
    }

    /** Gibt es bei uns Änderungen, die noch nicht bei BrickLink eingefügt sind? */
    private static function hasPending(): bool
    {
        return BrickLinkSync::pending() || BrickLinkSync::pendingStocktakes() || BrickLinkSync::openExport();
    }

    /** aktuell | neu (wird gleich übernommen) | wartet (offene Übertragung) | veraltet (Ollie hat noch nicht neu geladen) */
    private static function state(?string $run): string
    {
        if ($run === null || $run === Dev::setting('ollie_imported_run')) return 'aktuell';
        $block = Dev::setting('ollie_block_run');
        if ($block !== null && $run <= $block) return 'veraltet';
        return self::hasPending() ? 'wartet' : 'neu';
    }

    /**
     * Automatisch übernehmen, wenn Ollies Dashboard neu geladen hat. Nie mit Fehler: die Startseite soll immer laden.
     * Gibt die Statistik zurück, wenn übernommen wurde, sonst null.
     */
    public static function autoSync(?int $userId): ?array
    {
        if (self::path() === null) return null;
        $lock = @fopen(sys_get_temp_dir() . '/tn_ollie_sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null; // läuft schon (anderes Gerät)
        try {
            $run = self::run(self::open());
            if (self::state($run) !== 'neu') return null;
            $r = self::import();
            if ($userId) ActivityLog::log($userId, 'ollie_auto_import', null, ['lose' => $r['lots'], 'neu' => $r['new'], 'entfernt' => $r['removed']]);
            return $r;
        } catch (Throwable $e) {
            error_log('Ollie-Abgleich: ' . $e->getMessage());
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Nach „Ja, ist eingefügt“: Ollies bisheriger Stand kennt unsere Änderungen noch nicht, also nicht übernehmen. */
    public static function markExported(): void
    {
        if (self::path() === null) return;
        try {
            $run = self::run(self::open());
            if ($run !== null) Dev::setSetting('ollie_block_run', $run);
        } catch (Throwable) {
            // Ollie nicht erreichbar: dann gibt es auch nichts Veraltetes zu übernehmen
        }
    }

    /** Stand für den Dev-Bereich: eingerichtet? erreichbar? wie viele Lots, wann zuletzt von BrickLink geholt? */
    public static function status(): array
    {
        $r = ['configured' => self::path() !== null, 'url' => self::url(), 'ok' => false, 'lots' => null, 'parts' => null,
              'ollie_synced_at' => null, 'imported_at' => Dev::setting('ollie_last_import'), 'error' => null];
        if (!$r['configured']) return $r;
        try {
            $db = self::open();
            $row = $db->query('SELECT COUNT(*) AS lots, COALESCE(SUM(quantity), 0) AS parts FROM inventory_bricklink')->fetch();
            $r['lots'] = (int) $row['lots'];
            $r['parts'] = (int) $row['parts'];
            $run = self::run($db);
            $r['ollie_synced_at'] = self::local($run);
            $r['state'] = self::state($run);
            $r['ok'] = true;
        } catch (Throwable $e) {
            $r['error'] = $e->getMessage();
        }
        return $r;
    }

    /** Ganzen Bestand aus Ollies Datenbank übernehmen (ersetzt den Bestand wie der BrickLink-Import). */
    public static function import(): array
    {
        $db = self::open();
        $run = self::run($db);
        $cols = array_column($db->query('PRAGMA table_info(inventory_bricklink)')->fetchAll(), 'name');
        $remarks = in_array('remarks', $cols, true) ? 'remarks' : "'' AS remarks";
        $rows = $db->query("SELECT inventory_id, item_no, item_name, item_type, color_id, color_name, quantity, new_or_used,
            unit_price, {$remarks} FROM inventory_bricklink WHERE inventory_id IS NOT NULL")->fetchAll();
        // Gleiche Sicherheitsbremse wie bei Ollie: leerer Bestand ersetzt nie unser Lager
        if (!$rows) throw new RuntimeException('Ollies Dashboard hat 0 Lots. Bestand bleibt unverändert.');
        $have = (int) q('SELECT COUNT(*) FROM {inventory}')->fetchColumn();
        if ($have >= 100 && count($rows) < $have * 0.2) {
            throw new RuntimeException('Sicherheitsabbruch: Ollies Dashboard hat nur ' . count($rows) . " Lots, bei uns sind es {$have}.");
        }

        // Ollies Tabelle hat keine Kategorie: bisherige behalten, sonst gleiche Teil-Nr. bei uns, sonst aus dem Namen
        $byItem = [];
        foreach (q("SELECT item_no, category FROM {inventory} WHERE category <> ''")->fetchAll() as $r) $byItem[$r['item_no']] ??= $r['category'];
        $items = array_map(fn ($r) => [
            'inventory_id' => (int) $r['inventory_id'],
            'item' => ['no' => (string) $r['item_no'], 'name' => (string) $r['item_name'], 'type' => strtoupper((string) ($r['item_type'] ?: 'PART'))],
            'color_id' => (int) $r['color_id'], 'color_name' => (string) $r['color_name'], 'quantity' => (int) $r['quantity'],
            'unit_price' => (float) $r['unit_price'], 'new_or_used' => strtoupper((string) $r['new_or_used']) === 'N' ? 'N' : 'U',
            'remarks' => (string) $r['remarks'],
        ], $rows);
        $stats = BrickLinkSync::importItems($items, function (array $it, ?string $old) use ($byItem) {
            if ($old !== null && $old !== '') return $old;
            return $byItem[$it['item']['no']] ?? self::categoryFromName((string) $it['item']['name'], (string) $it['item']['type']);
        });
        Dev::setSetting('ollie_last_import', now());
        if ($run !== null) Dev::setSetting('ollie_imported_run', $run);
        return $stats;
    }

    /** „Plate, Round 1 x 1“ → „Plate, Round“; Minifiguren → „Minifigur“; sonst „Sonstiges“. */
    public static function categoryFromName(string $name, string $type): string
    {
        if ($type === 'MINIFIG') return 'Minifigur';
        $head = trim(preg_split('/\d/', html_entity_decode($name, ENT_QUOTES | ENT_HTML5), 2)[0], " ,-");
        return $head !== '' && mb_strlen($head) <= 30 ? $head : 'Sonstiges';
    }
}
