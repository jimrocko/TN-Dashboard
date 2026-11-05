<?php

/**
 * Abgleich mit dem BrickLink-Shop.
 * - Nach dem Umlagern kopiert der Schüler den ganzen Bestand als XML (alle Lots mit der Menge nach dem
 *   Abziehen, Lagerort in den Remarks) und fügt ihn bei BrickLink unten beim Upload ein (Wunsch des Inhabers).
 *   Die App selbst schreibt nie zu BrickLink.
 * - Jede Umlagerung merkt sich, was sich in BrickLink ändert (Spalte bl_changes in tn_transfers). Daraus kommen
 *   die ganz weggeräumten Lots (Menge 0 in der XML) und der Hinweis, wer noch nicht übertragen hat.
 * - Abgeschlossene Inventuren mit Korrekturen zählen genauso als offene Änderung (Spalte bl_changes in tn_stocktakes,
 *   Wunsch des Inhabers): Nach „Abschließen“ kommt dasselbe Pop-up, der ganze Bestand enthält die gezählten Mengen.
 * - Der Lagerort steht in BrickLink in den Remarks des Lots.
 * - Import (nur Lehrer): Lager komplett aus dem BrickLink-Shop übernehmen, mit Lot-IDs.
 * Gleiche Regeln in frontend/src/demo/shop.js (blChanges), XML-Aufbau in frontend/src/utils/bricklinkXml.js.
 */
final class BrickLinkSync
{
    /** Neue Spalten nachrüsten, damit Updates ohne erneutes install.php laufen. */
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $sqlite = (config()['db']['driver'] ?? 'mysql') === 'sqlite';
        $cols = [
            'inventory' => ['bl_lot_id' => $sqlite ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL',
                            'bl_condition' => $sqlite ? "TEXT NOT NULL DEFAULT 'U'" : "CHAR(1) NOT NULL DEFAULT 'U'"],
            'transfers' => ['bl_changes' => 'TEXT NULL',
                            'bl_done_at' => $sqlite ? 'TEXT NULL' : 'DATETIME NULL'],
            'stocktakes' => ['bl_changes' => 'TEXT NULL',
                             'bl_done_at' => $sqlite ? 'TEXT NULL' : 'DATETIME NULL'],
            'bl_exports' => ['stocktake_ids' => 'TEXT NULL'],
        ];
        // Übertragungen: open_flag = 1 nur bei der offenen; UNIQUE erlaubt mehrere NULL, aber nur eine offene
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('bl_exports') . ' (
            id ' . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY') . ',
            open_flag ' . ($sqlite ? 'INTEGER' : 'TINYINT') . ' NULL UNIQUE,
            transfer_ids TEXT NOT NULL,
            created_by ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' NOT NULL,
            created_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NOT NULL,
            closed_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NULL,
            state VARCHAR(16) NOT NULL DEFAULT \'offen\'
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
        foreach ($cols as $table => $add) {
            $have = $sqlite
                ? array_column(q('PRAGMA table_info(' . t($table) . ')')->fetchAll(), 'name')
                : array_column(q("SHOW COLUMNS FROM {{$table}}")->fetchAll(), 'Field');
            foreach ($add as $name => $type) {
                if (!in_array($name, $have, true)) q("ALTER TABLE {{$table}} ADD COLUMN {$name} {$type}");
            }
        }
    }

    /** Zustand bei BrickLink: N = neu, U = gebraucht. Für Beispieldaten aus dem Lagerort abgeleitet. */
    public static function conditionFor(string $code): string
    {
        return preg_match('/^(N|FN)\d+$/i', $code) ? 'N' : 'U';
    }

    /**
     * Was sich in BrickLink ändern muss, wenn $qty Stück von $lot nach $toCode gehen.
     * $existing = gleiches Teil + Farbe, das am Ziel schon liegt (oder null).
     * Einträge: update (lot_id, qty = +/- Menge, remarks, delete), upload (neues Lot), fehlt (Lot ohne BrickLink-Lot-ID).
     */
    public static function changes(array $lot, ?array $existing, int $qty, string $toCode): array
    {
        $upd = function ($row, array $set) {
            if (empty($row['bl_lot_id'])) {
                return ['type' => 'fehlt', 'item_no' => $row['item_no'], 'color_name' => $row['color_name'], 'local_id' => isset($row['id']) ? (int) $row['id'] : null];
            }
            return ['type' => 'update', 'lot_id' => (int) $row['bl_lot_id']] + $set;
        };
        $full = $qty === (int) $lot['qty'];
        if ($existing) {
            return [$upd($existing, ['qty' => $qty]), $upd($lot, $full ? ['delete' => true] : ['qty' => -$qty])];
        }
        if ($full) return [$upd($lot, ['remarks' => $toCode])];
        return [
            $upd($lot, ['qty' => -$qty]),
            ['type' => 'upload', 'item_type' => $lot['item_type'], 'item_no' => $lot['item_no'], 'color_id' => (int) $lot['color_id'],
             'qty' => $qty, 'price' => (float) $lot['price'], 'condition' => $lot['bl_condition'] ?? 'U', 'remarks' => $toCode],
        ];
    }

    /** Abgeschlossene Inventuren mit Korrekturen (von allen), die noch nicht zu BrickLink übertragen wurden. */
    public static function pendingStocktakes(): array
    {
        self::ensureSchema();
        $rows = q('SELECT s.id, s.closed_at, s.bl_changes, l.code AS location_code FROM {stocktakes} s
            JOIN {locations} l ON l.id = s.location_id WHERE s.bl_done_at IS NULL AND s.bl_changes IS NOT NULL ORDER BY s.id')->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['corrections'] = json_decode((string) $r['bl_changes'], true) ?: [];
            unset($r['bl_changes']);
        }
        return $rows;
    }

    /** Umlagerungen (von allen), die noch nicht zu BrickLink übertragen wurden. */
    public static function pending(): array
    {
        self::ensureSchema();
        $rows = q('SELECT t.id, t.item_no, t.color_name, t.name, t.qty, t.created_at, t.bl_changes, f.code AS from_code, l.code AS to_code
            FROM {transfers} t LEFT JOIN {locations} f ON f.id = t.from_location_id LEFT JOIN {locations} l ON l.id = t.to_location_id
            WHERE t.bl_done_at IS NULL AND t.bl_changes IS NOT NULL ORDER BY t.id')->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['qty'] = (int) $r['qty'];
            $r['changes'] = json_decode((string) $r['bl_changes'], true) ?: [];
            unset($r['bl_changes']);
        }
        return $rows;
    }

    /**
     * Was zu BrickLink muss, nur die Änderungen (der ganze Bestand ist für BrickLink zu groß, Grenze ca. 200 KB je Datei):
     * offene Umlagerungen und Inventuren, bei laufender Übertragung genau deren. Gleiche Lots werden zusammengerechnet.
     * - update: je BrickLink-Lot Mengenänderung (+/-), neuer Lagerort (Remarks) oder löschen (wenn das Lot leer ist; BrickLink
     *   nimmt kein Minus auf Menge 0 an). Für „Update Inventory“.
     * - upload: neue Lots aus Teilmengen (noch ohne BrickLink-Lot-Nr.) mit der aktuellen Menge. Für „Upload“.
     * - missing: Änderungen an Lots ohne BrickLink-Lot-Nr., die nicht übertragen werden können.
     */
    public static function changesForExport(): array
    {
        self::ensureSchema();
        $exp = self::openExport();
        $tIds = $exp ? $exp['transfer_ids'] : array_column(self::pending(), 'id');
        $sIds = $exp ? $exp['stocktake_ids'] : array_column(self::pendingStocktakes(), 'id');
        $agg = [];
        $uploads = [];
        $noLot = []; // Änderungen an Lots ohne BrickLink-Lot-Nr.
        $up = function (int $lotId) use (&$agg) { return $agg[$lotId] ??= ['lot_id' => $lotId, 'qty' => 0, 'remarks' => false, 'delete' => false]; };
        foreach ($tIds as $id) {
            $t = q('SELECT bl_changes FROM {transfers} WHERE id = ?', [(int) $id])->fetchColumn();
            foreach (json_decode((string) $t, true) ?: [] as $c) {
                if (($c['type'] ?? '') === 'update') {
                    $up((int) $c['lot_id']);
                    $agg[(int) $c['lot_id']]['qty'] += (int) ($c['qty'] ?? 0);
                    if (isset($c['remarks'])) $agg[(int) $c['lot_id']]['remarks'] = true;
                    if (!empty($c['delete'])) $agg[(int) $c['lot_id']]['delete'] = true;
                } elseif (($c['type'] ?? '') === 'upload') {
                    $uploads[] = $c;
                } elseif (($c['type'] ?? '') === 'fehlt') {
                    $noLot[] = ['item_no' => $c['item_no'] ?? '', 'color_name' => $c['color_name'] ?? '', 'local_id' => (int) ($c['local_id'] ?? 0)];
                }
            }
        }
        foreach ($sIds as $id) {
            $st = q('SELECT bl_changes FROM {stocktakes} WHERE id = ?', [(int) $id])->fetchColumn();
            foreach (json_decode((string) $st, true) ?: [] as $c) {
                $lotId = (int) ($c['lot_id'] ?? 0);
                if (!$lotId) {
                    // Ältere Inventuren ohne Lot-Nr.: über die gezählte Position suchen
                    $lotId = (int) q('SELECT i.bl_lot_id FROM {stocktake_items} s JOIN {inventory} i ON i.id = s.inventory_id
                        WHERE s.stocktake_id = ? AND s.item_no = ? AND s.color_name = ?', [(int) $id, $c['item_no'] ?? '', $c['color'] ?? ''])->fetchColumn();
                }
                if ($lotId) { $up($lotId); $agg[$lotId]['qty'] += (int) ($c['diff'] ?? 0); }
                else $noLot[] = ['item_no' => $c['item_no'] ?? '', 'color_name' => $c['color'] ?? '', 'local_id' => (int) ($c['local_id'] ?? 0)];
            }
        }
        // Aktueller Stand der betroffenen Lots: Lagerort und ob das Lot leer bzw. weg ist
        $update = [];
        foreach ($agg as $lotId => $a) {
            $lot = q('SELECT i.qty, l.code FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.bl_lot_id = ?', [$lotId])->fetch();
            if ($a['delete'] || !$lot || (int) $lot['qty'] <= 0) { $update[] = ['lot_id' => $lotId, 'delete' => true]; continue; }
            $row = ['lot_id' => $lotId];
            if ($a['qty'] !== 0) $row['qty'] = $a['qty'];
            if ($a['remarks'] && $lot['code'] !== null) $row['remarks'] = (string) $lot['code'];
            if (count($row) > 1) $update[] = $row;
        }
        // Neue Lots mit ihrer aktuellen Menge und ihrem aktuellen Kästchen (sie können seitdem gewachsen oder weitergezogen sein)
        $upload = [];
        $seen = [];
        foreach ($uploads as $c) {
            $ids = !empty($c['local_id']) ? [(int) $c['local_id']]
                : array_map('intval', q('SELECT i.id FROM {inventory} i JOIN {locations} l ON l.id = i.location_id
                    WHERE i.bl_lot_id IS NULL AND i.item_no = ? AND i.color_id = ? AND l.code = ?', [$c['item_no'], (int) $c['color_id'], $c['remarks']])->fetchAll(PDO::FETCH_COLUMN));
            foreach ($ids as $lid) {
                if (isset($seen[$lid])) continue;
                $seen[$lid] = true;
                $l = q('SELECT i.*, l.code FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.id = ? AND i.bl_lot_id IS NULL', [$lid])->fetch();
                if (!$l || (int) $l['qty'] <= 0) continue;
                $upload[] = ['item_type' => $l['item_type'], 'item_no' => $l['item_no'], 'color_id' => (int) $l['color_id'], 'qty' => (int) $l['qty'],
                    'price' => round((float) $l['price'], 3), 'condition' => ($l['bl_condition'] ?? 'U') === 'N' ? 'N' : 'U', 'remarks' => (string) ($l['code'] ?? '')];
            }
        }
        // Lots ohne BrickLink-Lot-Nr. sind nur dann kein Problem, wenn sie als neues Lot mit aktueller Menge in der Upload-Datei stehen
        $missing = [];
        foreach ($noLot as $m) if (!isset($seen[$m['local_id']])) $missing[] = ['item_no' => $m['item_no'], 'color_name' => $m['color_name']];
        usort($update, fn ($x, $y) => $x['lot_id'] <=> $y['lot_id']);
        return ['update' => $update, 'upload' => $upload, 'missing' => $missing,
            'transfer_ids' => array_values(array_map('intval', $tIds)), 'stocktake_ids' => array_values(array_map('intval', $sIds))];
    }

    /** Die offene Übertragung (oder null). Gemeinsam für alle, ohne Namen. */
    public static function openExport(): ?array
    {
        self::ensureSchema();
        $e = q('SELECT id, transfer_ids, stocktake_ids, created_at FROM {bl_exports} WHERE open_flag = 1')->fetch();
        if (!$e) return null;
        return ['id' => (int) $e['id'], 'transfer_ids' => json_decode((string) $e['transfer_ids'], true) ?: [],
                'stocktake_ids' => json_decode((string) ($e['stocktake_ids'] ?? ''), true) ?: [], 'created_at' => $e['created_at']];
    }

    /** Übertragung starten; schlägt fehl (409), wenn schon eine offen ist. */
    public static function start(array $ids, int $userId, array $stocktakeIds = []): array
    {
        self::ensureSchema();
        $clean = fn (array $a) => array_values(array_filter(array_map('intval', $a), fn ($i) => $i > 0));
        $ids = $clean($ids);
        $stIds = $clean($stocktakeIds);
        if (!$ids && !$stIds) json_error('Keine offenen Änderungen', 400);
        // Sonst könnte der ganze Bestand die Minus-Mengen der fertigen Minifiguren bei BrickLink wieder überschreiben
        if (Minifig::buildExportActive()) json_error('Gerade wird die Sammlung „Fertige Figuren“ im Minifig-Finder hochgeladen. Bitte die erst abschließen.', 409);
        try {
            q('INSERT INTO {bl_exports} (open_flag, transfer_ids, stocktake_ids, created_by, created_at) VALUES (1, ?, ?, ?, ?)',
                [json_encode($ids), json_encode($stIds), $userId, now()]);
        } catch (PDOException $e) {
            // UNIQUE auf open_flag: jemand anders war schneller
            json_error('Gerade läuft schon eine Übertragung. Bitte den kopierten Bestand nicht einfügen.', 409);
        }
        return self::openExport();
    }

    /** Offene Übertragung abschließen. $done = bei BrickLink eingefügt → Umlagerungen gelten als übertragen. */
    public static function finish(int $id, bool $done): int
    {
        self::ensureSchema();
        $e = q('SELECT id, transfer_ids, stocktake_ids FROM {bl_exports} WHERE id = ? AND open_flag = 1', [$id])->fetch();
        if (!$e) json_error('Diese Übertragung wurde schon abgeschlossen', 409);
        $t = now();
        $n = 0;
        $ids = array_map('intval', json_decode((string) $e['transfer_ids'], true) ?: []);
        if ($done && $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $n = q("UPDATE {transfers} SET bl_done_at = ? WHERE bl_done_at IS NULL AND id IN ($in)", array_merge([$t], $ids))->rowCount();
        }
        $stIds = array_map('intval', json_decode((string) ($e['stocktake_ids'] ?? ''), true) ?: []);
        if ($done && $stIds) {
            $in = implode(',', array_fill(0, count($stIds), '?'));
            $n += q("UPDATE {stocktakes} SET bl_done_at = ? WHERE bl_done_at IS NULL AND id IN ($in)", array_merge([$t], $stIds))->rowCount();
        }
        q('UPDATE {bl_exports} SET open_flag = NULL, closed_at = ?, state = ? WHERE id = ?', [$t, $done ? 'eingefuegt' : 'verworfen', $id]);
        return $n;
    }

    /**
     * Fehlerhaftes Pop-up entfernen (jeder mit Recht bricklink_uebertragen, Wunsch des Inhabers, Sitzung 8): alle offenen Umlagerungen und
     * Inventur-Korrekturen gelten als erledigt, ohne Übertragung; eine laufende Übertragung wird verworfen. BrickLink bleibt unverändert.
     * @return array{umlagerungen:int, inventuren:int}
     */
    public static function dismiss(): array
    {
        self::ensureSchema();
        $t = now();
        $tr = q('UPDATE {transfers} SET bl_done_at = ? WHERE bl_done_at IS NULL AND bl_changes IS NOT NULL', [$t])->rowCount();
        $st = q('UPDATE {stocktakes} SET bl_done_at = ? WHERE bl_done_at IS NULL AND bl_changes IS NOT NULL', [$t])->rowCount();
        q("UPDATE {bl_exports} SET open_flag = NULL, closed_at = ?, state = 'entfernt' WHERE open_flag = 1", [$t]);
        return ['umlagerungen' => $tr, 'inventuren' => $st];
    }

    /**
     * Lager komplett aus dem BrickLink-Shop übernehmen (Lots per Lot-ID abgleichen).
     * Lagerort = Remarks. Lots, die es in BrickLink nicht gibt, werden entfernt.
     * Offene, noch nicht übertragene Umlagerungen werden verworfen, weil BrickLink jetzt der Stand ist.
     * $bl = BrickLinkClient (oder ein Objekt mit get() für Tests).
     */
    public static function import(object $bl): array
    {
        self::ensureSchema();
        $items = $bl->get('/inventories');
        if (!is_array($items)) throw new RuntimeException('Unerwartete Antwort von BrickLink');
        // Kategorie-Namen ändern sich kaum: eine Woche aus der eigenen Datenbank
        $cats = [];
        foreach (Cache::remember('categories', 7 * 86400, fn () => $bl->get('/categories'))['data'] as $c) {
            $cats[(int) $c['category_id']] = (string) $c['category_name'];
        }
        return self::importItems($items, fn (array $it, ?string $old) => $cats[(int) ($it['item']['category_id'] ?? 0)] ?? ($old ?? ''));
    }

    /**
     * Bestand aus einer BrickStore-Datei (.bsx) übernehmen (Dev-Bereich → Einstellungen, nur Lehrer).
     * Ersetzt den Bestand wie der BrickLink-Import: gleiche Lot-Nr. behält ihre ID, was nicht in der Datei steht,
     * wird entfernt, Lagerort = Remarks. $preview = nur zählen, nichts ändern.
     */
    public static function importBsx(string $content, bool $preview): array
    {
        self::ensureSchema();
        [$rows] = Bsx::parse($content);
        $items = Bsx::toInventory($rows);
        $have = (int) q('SELECT COUNT(*) FROM {inventory}')->fetchColumn();
        // Gleiche Sicherheitsbremse wie beim Abgleich mit Ollies Dashboard
        if ($have >= 100 && count($items) < $have * 0.2) {
            throw new RuntimeException('Sicherheitsabbruch: Die Datei hat nur ' . count($items) . " Lots, im Lager sind es {$have}. Bestand bleibt unverändert.");
        }
        if (self::openExport()) {
            throw new RuntimeException('Gerade läuft eine BrickLink-Übertragung. Bitte die erst abschließen („Ja, ist eingefügt“ oder „Nein, verwerfen“).');
        }
        if (Minifig::buildExportActive()) {
            throw new RuntimeException('Gerade wird die Sammlung „Fertige Figuren“ im Minifig-Finder hochgeladen. Bitte die erst abschließen.');
        }
        $pending = count(self::pending()) + count(self::pendingStocktakes());
        $parts = array_sum(array_column($items, 'quantity'));

        if ($preview) {
            $known = array_flip(array_map('intval', q('SELECT bl_lot_id FROM {inventory} WHERE bl_lot_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
            $locs = array_flip(array_map('strtolower', q('SELECT code FROM {locations}')->fetchAll(PDO::FETCH_COLUMN)));
            $seen = [];
            $newLocs = [];
            $new = 0;
            $noLoc = 0;
            foreach ($items as $it) {
                $id = (int) $it['inventory_id'];
                if (isset($known[$id])) $seen[$id] = true; else $new++;
                $code = strtolower(substr(trim((string) $it['remarks']), 0, 32));
                if ($code === '') $noLoc++;
                elseif (!isset($locs[$code])) $newLocs[$code] = true;
            }
            return ['lots' => count($items), 'parts' => $parts, 'new' => $new, 'updated' => count($items) - $new,
                'removed' => $have - count($seen), 'new_locations' => count($newLocs), 'without_location' => $noLoc,
                'pending' => $pending, 'current_lots' => $have];
        }

        // Kategorie aus der Datei, Minifiguren wie beim Abgleich mit Ollies Dashboard als „Minifigur“
        $stats = self::importItems($items, function (array $it, ?string $old) {
            if ($it['item']['type'] === 'MINIFIG') return 'Minifigur';
            $cat = trim((string) ($it['item']['category_name'] ?? ''));
            return $cat !== '' ? $cat : ($old ?: OllieSync::categoryFromName((string) $it['item']['name'], 'PART'));
        });
        return $stats + ['parts' => $parts, 'pending' => $pending];
    }

    /**
     * Gemeinsamer Abgleich für BrickLink und Ollies Dashboard (OllieSync): $items im Format der BrickLink-API
     * (/inventories), $categoryOf(item, bisherige Kategorie des Lots oder null) liefert die Kategorie.
     */
    public static function importItems(array $items, callable $categoryOf): array
    {
        self::ensureSchema();
        $names = ['G' => 'Gebraucht', 'H' => 'Regal H', 'N' => 'Neu', 'FG' => 'Figuren gebraucht', 'FN' => 'Figuren neu',
                  'FT' => 'Figurenteile', 'PLATE' => 'Plates'];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $loc = [];
            foreach (q('SELECT id, code FROM {locations}')->fetchAll() as $l) $loc[strtolower($l['code'])] = (int) $l['id'];
            $known = [];
            $oldCat = [];
            foreach (q('SELECT id, bl_lot_id, category FROM {inventory} WHERE bl_lot_id IS NOT NULL')->fetchAll() as $r) {
                $known[(int) $r['bl_lot_id']] = (int) $r['id'];
                $oldCat[(int) $r['bl_lot_id']] = (string) $r['category'];
            }

            $t = now();
            $keep = [];
            $stats = ['lots' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0, 'new_locations' => 0, 'without_location' => 0];
            foreach ($items as $it) {
                $code = substr(trim((string) ($it['remarks'] ?? '')), 0, 32);
                $locId = null;
                if ($code === '') {
                    $stats['without_location']++;
                } elseif (isset($loc[strtolower($code)])) {
                    $locId = $loc[strtolower($code)];
                } else {
                    $area = Shop::areaOf($code);
                    $name = isset($names[$area]) ? $names[$area] . ', Kästchen ' . substr($code, strlen($area)) : $code;
                    q('INSERT INTO {locations} (code, name, kind) VALUES (?, ?, ?)', [$code, $name, 'regal']);
                    $locId = $loc[strtolower($code)] = (int) $pdo->lastInsertId();
                    $stats['new_locations']++;
                }
                $lotId = (int) $it['inventory_id'];
                $vals = [
                    (string) ($it['item']['type'] ?? 'PART'), (string) $it['item']['no'], (int) ($it['color_id'] ?? 0),
                    (string) ($it['color_name'] ?? ''), html_entity_decode((string) ($it['item']['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
                    (string) $categoryOf($it, $oldCat[$lotId] ?? null), $locId, (int) $it['quantity'],
                    (float) ($it['unit_price'] ?? 0), ($it['new_or_used'] ?? 'U') === 'N' ? 'N' : 'U', $t,
                ];
                $cond = $vals[9];
                if (isset($known[$lotId])) {
                    q('UPDATE {inventory} SET item_type = ?, item_no = ?, color_id = ?, color_name = ?, name = ?, category = ?,
                       location_id = ?, qty = ?, price = ?, bl_condition = ?, updated_at = ?, new_or_used = ?, bl_inventory_id = ? WHERE id = ?', array_merge($vals, [$cond, $lotId, $known[$lotId]]));
                    $keep[$known[$lotId]] = true;
                    $stats['updated']++;
                } else {
                    q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price,
                       bl_condition, updated_at, bl_lot_id, new_or_used, bl_inventory_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$lotId, $cond, $lotId]));
                    $keep[(int) $pdo->lastInsertId()] = true;
                    $stats['new']++;
                }
                $stats['lots']++;
            }
            foreach (q('SELECT id FROM {inventory}')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!isset($keep[(int) $id])) {
                    q('DELETE FROM {inventory} WHERE id = ?', [(int) $id]);
                    $stats['removed']++;
                }
            }
            q('UPDATE {transfers} SET bl_done_at = ? WHERE bl_done_at IS NULL AND bl_changes IS NOT NULL', [$t]);
            q('UPDATE {stocktakes} SET bl_done_at = ? WHERE bl_done_at IS NULL AND bl_changes IS NOT NULL', [$t]);
            q("UPDATE {bl_exports} SET open_flag = NULL, closed_at = ?, state = 'verworfen' WHERE open_flag = 1", [$t]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $stats;
    }
}
