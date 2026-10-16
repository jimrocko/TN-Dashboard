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
                return ['type' => 'fehlt', 'item_no' => $row['item_no'], 'color_name' => $row['color_name']];
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
     * Ganzer Bestand für die XML: alle Lots mit aktueller Menge und Lagerort, dazu die Lot-IDs, die beim Umlagern
     * ganz weggeräumt wurden (noch nicht übertragen), und die IDs aller offenen Umlagerungen (von allen Nutzern,
     * denn der ganze Bestand enthält auch ihre Änderungen).
     */
    public static function stock(): array
    {
        self::ensureSchema();
        $lots = q('SELECT i.bl_lot_id, i.item_type, i.item_no, i.color_id, i.qty, i.price, i.bl_condition, l.code
            FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id
            ORDER BY CASE WHEN i.bl_lot_id IS NULL THEN 1 ELSE 0 END, i.bl_lot_id, i.id')->fetchAll();
        $have = [];
        foreach ($lots as &$l) {
            $l['bl_lot_id'] = $l['bl_lot_id'] === null ? null : (int) $l['bl_lot_id'];
            $l['color_id'] = (int) $l['color_id'];
            $l['qty'] = (int) $l['qty'];
            $l['price'] = round((float) $l['price'], 3);
            if ($l['bl_lot_id']) $have[$l['bl_lot_id']] = true;
        }
        unset($l);
        $ids = [];
        $deleted = [];
        foreach (q('SELECT id, bl_changes FROM {transfers} WHERE bl_done_at IS NULL AND bl_changes IS NOT NULL ORDER BY id')->fetchAll() as $t) {
            $ids[] = (int) $t['id'];
            foreach (json_decode((string) $t['bl_changes'], true) ?: [] as $c) {
                if (($c['type'] ?? '') === 'update' && !empty($c['delete']) && !isset($have[(int) $c['lot_id']])) $deleted[(int) $c['lot_id']] = true;
            }
        }
        $stIds = array_map('intval', q('SELECT id FROM {stocktakes} WHERE bl_done_at IS NULL AND bl_changes IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        return ['lots' => $lots, 'deleted' => array_keys($deleted), 'transfer_ids' => $ids, 'stocktake_ids' => $stIds];
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
                if (isset($known[$lotId])) {
                    q('UPDATE {inventory} SET item_type = ?, item_no = ?, color_id = ?, color_name = ?, name = ?, category = ?,
                       location_id = ?, qty = ?, price = ?, bl_condition = ?, updated_at = ? WHERE id = ?', array_merge($vals, [$known[$lotId]]));
                    $keep[$known[$lotId]] = true;
                    $stats['updated']++;
                } else {
                    q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price,
                       bl_condition, updated_at, bl_lot_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$lotId]));
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
