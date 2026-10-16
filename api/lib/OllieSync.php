<?php

/**
 * Abgleich mit Ollies Dashboard (bi-legoshop-dashboard, läuft auf demselben Rechner, Wunsch des Inhabers).
 * Ollies Dashboard holt den BrickLink-Bestand alle 4 Stunden selbst und speichert ihn in seiner SQLite-Datenbank
 * (data/legoshop.db, Tabelle inventory_bricklink). Wir lesen diese Datei nur (Read-only), ändern dort nie etwas
 * und brauchen dafür keinen einzigen BrickLink-Abruf. Übernommen wird wie beim BrickLink-Import (per Lot-ID,
 * Lagerort aus den Remarks, siehe BrickLinkSync::importItems).
 * Pfad: .env OLLIE_DB_PATH (oder 'ollie' => ['db_path' => …] in api/config.php). Leer = aus.
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

    /** Stand für den Dev-Bereich: eingerichtet? erreichbar? wie viele Lots, wann zuletzt von BrickLink geholt? */
    public static function status(): array
    {
        $r = ['configured' => self::path() !== null, 'ok' => false, 'lots' => null, 'parts' => null,
              'ollie_synced_at' => null, 'imported_at' => Dev::setting('ollie_last_import'), 'error' => null];
        if (!$r['configured']) return $r;
        try {
            $db = self::open();
            $row = $db->query('SELECT COUNT(*) AS lots, COALESCE(SUM(quantity), 0) AS parts FROM inventory_bricklink')->fetch();
            $r['lots'] = (int) $row['lots'];
            $r['parts'] = (int) $row['parts'];
            $st = $db->query("SELECT last_run FROM import_status WHERE import_name = 'bricklink_inventory'")->fetchColumn();
            $r['ollie_synced_at'] = $st === false ? null : (string) $st;
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
