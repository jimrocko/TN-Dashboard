<?php

/**
 * Abgleich mit dem BrickLink-Shop.
 * - Jede Umlagerung merkt sich, was sich in BrickLink ändern muss (Spalte bl_changes in tn_transfers).
 *   Der Schüler kopiert danach eine XML für „Mass Update“ bzw. „Mass Upload“ und fügt sie bei BrickLink ein.
 *   Die App selbst schreibt nie zu BrickLink.
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
        ];
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

    /** Umlagerungen dieses Nutzers, die noch nicht zu BrickLink übertragen wurden. */
    public static function pending(int $userId): array
    {
        self::ensureSchema();
        $rows = q('SELECT t.id, t.item_no, t.color_name, t.name, t.qty, t.created_at, t.bl_changes, f.code AS from_code, l.code AS to_code
            FROM {transfers} t LEFT JOIN {locations} f ON f.id = t.from_location_id LEFT JOIN {locations} l ON l.id = t.to_location_id
            WHERE t.user_id = ? AND t.bl_done_at IS NULL AND t.bl_changes IS NOT NULL ORDER BY t.id', [$userId])->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['qty'] = (int) $r['qty'];
            $r['changes'] = json_decode((string) $r['bl_changes'], true) ?: [];
            unset($r['bl_changes']);
        }
        return $rows;
    }

    /** Als übertragen markieren (nur eigene Umlagerungen). */
    public static function done(int $userId, array $ids): int
    {
        self::ensureSchema();
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($i) => $i > 0));
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        return q("UPDATE {transfers} SET bl_done_at = ? WHERE user_id = ? AND bl_done_at IS NULL AND id IN ($in)",
            array_merge([now()], [$userId], $ids))->rowCount();
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
        $names = ['G' => 'Gebraucht', 'H' => 'Regal H', 'N' => 'Neu', 'FG' => 'Figuren gebraucht', 'FN' => 'Figuren neu',
                  'FT' => 'Figurenteile', 'PLATE' => 'Plates'];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $loc = [];
            foreach (q('SELECT id, code FROM {locations}')->fetchAll() as $l) $loc[strtolower($l['code'])] = (int) $l['id'];
            $known = [];
            foreach (q('SELECT id, bl_lot_id FROM {inventory} WHERE bl_lot_id IS NOT NULL')->fetchAll() as $r) $known[(int) $r['bl_lot_id']] = (int) $r['id'];

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
                    $cats[(int) ($it['item']['category_id'] ?? 0)] ?? '', $locId, (int) $it['quantity'],
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
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $stats;
    }
}
