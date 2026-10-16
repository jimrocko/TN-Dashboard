<?php

/** SQL mit Tabellen-Platzhaltern {name} ausführen, z. B. 'SELECT * FROM {inventory}'. */
function q(string $sql, array $params = []): PDOStatement
{
    $sql = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => t($m[1]), $sql);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Zahlen-Spalten typisieren (MySQL liefert DECIMAL als Text). */
function typed(array $rows, array $ints = [], array $floats = []): array
{
    foreach ($rows as &$r) {
        foreach ($ints as $c) {
            if (array_key_exists($c, $r) && $r[$c] !== null) $r[$c] = (int) $r[$c];
        }
        foreach ($floats as $c) {
            if (array_key_exists($c, $r) && $r[$c] !== null) $r[$c] = round((float) $r[$c], 3);
        }
    }
    return $rows;
}

const LOT_INTS = ['id', 'color_id', 'location_id', 'qty'];
const ORDER_STATUSES = ['Neu', 'Bezahlt', 'Verpackt', 'Versandt', 'Abgeschlossen'];
// Mischkiste: Kästchen in Bereich G mit mehr als 3 verschiedenen Losen (Regel des Inhabers)
const MIXED_AREA_PATTERN = '/^G\\d+$/i';
const MIXED_MAX_LOTS = 3;
// Beim Zusammenlegen von Farben bekommt ein Kästchen höchstens so viele Lose
const SLOT_MAX_LOTS = 3;
const ORDER_ACTIVE = ['Neu', 'Bezahlt', 'Verpackt'];

final class Shop
{
    private const LOT_SELECT = 'SELECT i.id, i.item_type, i.item_no, i.color_id, i.color_name, i.name, i.category,
            i.location_id, i.qty, i.price, i.updated_at, l.code AS location_code, l.kind AS location_kind
        FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id';

    public static function dashboard(): array
    {
        $inv = q('SELECT COUNT(*) AS lots, COALESCE(SUM(qty), 0) AS parts, COALESCE(SUM(qty * price), 0) AS value FROM {inventory}')->fetch();
        $in = implode(',', array_fill(0, count(ORDER_ACTIVE), '?'));
        return [
            'inventar'  => ['total_parts' => (int) $inv['parts'], 'lots' => (int) $inv['lots'], 'value' => round((float) $inv['value'], 2)],
            'auftraege' => ['active' => (int) q("SELECT COUNT(*) FROM {orders} WHERE status IN ($in)", ORDER_ACTIVE)->fetchColumn()],
            'umlagern'  => ['suggestions' => count(self::suggestions())],
            'inventur'  => ['open' => (int) q("SELECT COUNT(*) FROM {stocktakes} WHERE status = 'offen'")->fetchColumn()],
            'analyse'   => ['last_run' => date('d.m.Y')],
            'lagerorte' => ['count' => (int) q('SELECT COUNT(*) FROM {locations}')->fetchColumn()],
        ];
    }

    public static function inventory(array $f): array
    {
        $where = [];
        $p = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = '(i.item_no LIKE ? OR i.name LIKE ? OR i.color_name LIKE ? OR i.category LIKE ? OR l.code LIKE ?)';
            array_push($p, ...array_fill(0, 5, '%' . $f['q'] . '%'));
        }
        if (!empty($f['location'])) {
            $where[] = 'i.location_id = ?';
            $p[] = (int) $f['location'];
        }
        if (!empty($f['id'])) { // ein bestimmtes Los (z. B. Umlagern aus dem Inventar)
            $where[] = 'i.id = ?';
            $p[] = (int) $f['id'];
        }
        if (($f['category'] ?? '') !== '') {
            $where[] = 'i.category = ?';
            $p[] = $f['category'];
        }
        $sorts = ['item' => 'i.item_no, i.color_name', 'qty' => 'i.qty DESC', 'location' => 'l.code, i.item_no', 'updated' => 'i.updated_at DESC'];
        $sql = self::LOT_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ' . ($sorts[$f['sort'] ?? ''] ?? $sorts['item']) . ' LIMIT ' . max(1, min((int) ($f['limit'] ?? 500), 2000));
        return typed(q($sql, $p)->fetchAll(), LOT_INTS, ['price']);
    }

    public static function meta(): array
    {
        $locs = q('SELECT l.id, l.code, l.name, l.kind, COUNT(i.id) AS lots, COALESCE(SUM(i.qty), 0) AS qty
            FROM {locations} l LEFT JOIN {inventory} i ON i.location_id = l.id GROUP BY l.id, l.code, l.name, l.kind ORDER BY l.code')->fetchAll();
        return [
            'categories' => q('SELECT DISTINCT category FROM {inventory} ORDER BY category')->fetchAll(PDO::FETCH_COLUMN),
            'locations'  => typed($locs, ['id', 'lots', 'qty']),
        ];
    }

    public static function search(string $term): array
    {
        $like = '%' . $term . '%';
        return [
            'parts'     => self::inventory(['q' => $term, 'limit' => 50]),
            'locations' => typed(q('SELECT id, code, name, kind FROM {locations} WHERE code LIKE ? OR name LIKE ? ORDER BY code LIMIT 20', [$like, $like])->fetchAll(), ['id']),
            'orders'    => self::orders(null, $term),
        ];
    }

    public static function orders(?string $status = null, ?string $term = null): array
    {
        $where = [];
        $p = [];
        if ($status === 'aktiv') {
            $where[] = 'o.status IN (' . implode(',', array_fill(0, count(ORDER_ACTIVE), '?')) . ')';
            array_push($p, ...ORDER_ACTIVE);
        } elseif ($status !== null && $status !== '') {
            $where[] = 'o.status = ?';
            $p[] = $status;
        }
        if ($term !== null && $term !== '') {
            $where[] = '(o.order_no LIKE ? OR o.buyer LIKE ?)';
            array_push($p, "%$term%", "%$term%");
        }
        $sql = 'SELECT o.id, o.platform, o.order_no, o.buyer, o.status, o.ordered_at, o.total,
                (SELECT COUNT(*) FROM {order_items} x WHERE x.order_id = o.id) AS positions,
                (SELECT COALESCE(SUM(qty), 0) FROM {order_items} x WHERE x.order_id = o.id) AS parts
            FROM {orders} o' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.ordered_at DESC LIMIT 200';
        return typed(q($sql, $p)->fetchAll(), ['id', 'positions', 'parts'], ['total']);
    }

    public static function order(int $id): ?array
    {
        $o = q('SELECT id, platform, order_no, buyer, status, ordered_at, total FROM {orders} WHERE id = ?', [$id])->fetch();
        if (!$o) return null;
        $o = typed([$o], ['id'], ['total'])[0];
        $o['items'] = typed(q('SELECT x.id, x.inventory_id, x.item_type, x.item_no, x.color_id, x.color_name, x.name, x.qty, x.price,
                COALESCE(l.code, x.remarks) AS location_code, i.qty AS stock
            FROM {order_items} x LEFT JOIN {inventory} i ON i.id = x.inventory_id LEFT JOIN {locations} l ON l.id = i.location_id
            WHERE x.order_id = ? ORDER BY l.code, x.item_no', [$id])->fetchAll(), ['id', 'inventory_id', 'color_id', 'qty', 'stock'], ['price']);
        // Laufweg: Kästchen in natürlicher Reihenfolge (N4 vor N150)
        usort($o['items'], fn ($a, $b) => strnatcasecmp((string) $a['location_code'], (string) $b['location_code']) ?: strcmp($a['item_no'], $b['item_no']));
        return $o;
    }

    public static function setOrderStatus(int $id, string $status): void
    {
        if (!in_array($status, ORDER_STATUSES, true)) json_error('Unbekannter Status');
        if (q('UPDATE {orders} SET status = ? WHERE id = ?', [$status, $id])->rowCount() === 0
            && !q('SELECT 1 FROM {orders} WHERE id = ?', [$id])->fetch()) {
            json_error('Auftrag nicht gefunden', 404);
        }
    }

    /** Bereich eines Kästchens (G, H, N, FG, FN, FT, Plate); sonst der Code selbst. Entspricht parseCode() in utils/areas.js. */
    public static function areaOf(string $code): string
    {
        return preg_match('/^(FG|FN|FT|Plate|G|H|N)\d+$/i', $code, $m) ? strtoupper($m[1]) : strtoupper($code);
    }

    /**
     * Umlagerungsvorschläge, zwei Arten (immer nur innerhalb eines Bereichs, also gebraucht nie zu neu):
     *  1. 'falsch'   – gleiches Teil in gleicher Farbe liegt in mehreren Kästchen: alles in das Kästchen mit der größten Menge.
     *  2. 'farben'   – gleiches Teil in anderen Farben liegt verstreut: in ein Kästchen legen, das nur dieses Teil enthält,
     *                  damit Slots „reiner“ werden. Ziel bekommt höchstens SLOT_MAX_LOTS Lose,
     *                  Quelle muss auch andere Teile enthalten.
     *  3. 'art'      – gleiche Teile-Art (Kategorie, z. B. Brick, Plate) verstreut: in ein Kästchen, das nur diese Art
     *                  enthält, höchstens SLOT_MAX_LOTS Lose; Quelle enthält auch andere Arten.
     * Gleiche Regeln und Reihenfolge wie suggestions() in frontend/src/demo/shop.js.
     */
    public static function suggestions(): array
    {
        $all = typed(q(self::LOT_SELECT . ' WHERE l.code IS NOT NULL ORDER BY i.id')->fetchAll(), LOT_INTS, ['price']);
        $out = [];
        $moved = [];
        $add = function (array $lot, array $target, string $reason, array $extra = []) use (&$out, &$moved) {
            $out[] = ['lot' => $lot, 'to_location_id' => $target['location_id'], 'to_location_code' => $target['location_code'],
                'target_qty' => $target['qty'], 'reason' => $reason] + $extra;
            $moved[$lot['id']] = true;
        };
        $cmpCode = fn ($a, $b) => strnatcasecmp($a['location_code'], $b['location_code']) ?: $a['id'] <=> $b['id'];

        // 1. Falsch einsortiert: gleiche Nummer + Farbe im selben Bereich in mehreren Kästchen
        $byCombo = [];
        foreach ($all as $l) $byCombo[self::areaOf($l['location_code']) . '|' . $l['item_no'] . '|' . $l['color_id']][] = $l;
        ksort($byCombo, SORT_STRING);
        foreach ($byCombo as $lots) {
            if (count($lots) < 2) continue;
            usort($lots, fn ($a, $b) => $b['qty'] <=> $a['qty'] ?: $cmpCode($a, $b));
            $home = $lots[0];
            foreach (array_slice($lots, 1) as $l) $add($l, $home, 'falsch');
        }

        // 2. Farben zusammenlegen
        $planned = []; // Los-ID => Ziel-Kästchen der Farben-Vorschläge (für Schritt 3)
        $boxLots = [];   // location_id => Anzahl Lose
        $boxItems = [];  // location_id => [item_no => Anzahl Lose]
        foreach ($all as $l) {
            if (isset($moved[$l['id']])) continue;
            $boxLots[$l['location_id']] = ($boxLots[$l['location_id']] ?? 0) + 1;
            $boxItems[$l['location_id']][$l['item_no']] = ($boxItems[$l['location_id']][$l['item_no']] ?? 0) + 1;
        }
        // Per Referenz, damit die Reinheit nach jedem Vorschlag aktuell ist (fn () => würde eine Kopie einfrieren)
        $purity = function ($loc, $item) use (&$boxItems, &$boxLots) {
            return ($boxItems[$loc][$item] ?? 0) / max(1, $boxLots[$loc] ?? 0);
        };
        $byPart = [];
        foreach ($all as $l) {
            if (!isset($moved[$l['id']])) $byPart[self::areaOf($l['location_code']) . '|' . $l['item_no']][] = $l;
        }
        ksort($byPart, SORT_STRING);
        foreach ($byPart as $lots) {
            $item = $lots[0]['item_no'];
            $boxes = [];
            foreach ($lots as $l) $boxes[$l['location_id']] ??= $l;
            if (count($boxes) < 2) continue;
            $targets = array_values($boxes);
            usort($targets, fn ($a, $b) => $purity($b['location_id'], $item) <=> $purity($a['location_id'], $item)
                ?: ($boxItems[$b['location_id']][$item] ?? 0) <=> ($boxItems[$a['location_id']][$item] ?? 0) ?: $cmpCode($a, $b));
            $sources = $lots;
            usort($sources, $cmpCode);
            foreach ($targets as $t) {
                $tid = $t['location_id'];
                if ($purity($tid, $item) < 1) continue; // Ziel enthält nur dieses Teil (sauberer Slot)
                foreach ($sources as $src) {
                    if (($boxLots[$tid] ?? 0) >= SLOT_MAX_LOTS) break;
                    $sid = $src['location_id'];
                    if (isset($moved[$src['id']]) || $sid === $tid) continue;
                    $pureSource = ($boxItems[$sid][$item] ?? 0) === ($boxLots[$sid] ?? 0);
                    if ($pureSource || $purity($sid, $item) >= $purity($tid, $item)) continue;
                    $colors = [];
                    foreach ($lots as $x) if ($x['location_id'] === $tid && !isset($moved[$x['id']])) $colors[] = $x['color_name'];
                    $add($src, $t, 'farben', ['target_colors' => $colors]);
                    $planned[$src['id']] = $tid;
                    $boxLots[$tid]++; $boxItems[$tid][$item] = ($boxItems[$tid][$item] ?? 0) + 1;
                    $boxLots[$sid]--; $boxItems[$sid][$item]--;
                }
            }
        }

        // 3. Gleiche Teile-Art (Kategorie, z. B. Brick, Plate, Tile): in ein Kästchen, das nur diese Art enthält
        $catLots = [];  // location_id => Anzahl Lose (nach den Vorschlägen aus 1 und 2)
        $catCount = []; // location_id => [Kategorie => Anzahl Lose]
        $namesIn = [];  // location_id => [Teil-Namen]
        foreach ($all as $l) {
            if (isset($moved[$l['id']]) && !isset($planned[$l['id']])) continue; // zusammengeführt (falsch)
            $loc = $planned[$l['id']] ?? $l['location_id'];
            $catLots[$loc] = ($catLots[$loc] ?? 0) + 1;
            $catCount[$loc][$l['category']] = ($catCount[$loc][$l['category']] ?? 0) + 1;
            $namesIn[$loc][] = $l['name'];
        }
        $catPure = function ($loc, $cat) use (&$catLots, &$catCount) {
            return ($catLots[$loc] ?? 0) > 0 && ($catCount[$loc][$cat] ?? 0) === $catLots[$loc];
        };
        $byCat = [];
        foreach ($all as $l) {
            if (!isset($moved[$l['id']])) $byCat[self::areaOf($l['location_code']) . '|' . $l['category']][] = $l;
        }
        ksort($byCat, SORT_STRING);
        foreach ($byCat as $lots) {
            $cat = $lots[0]['category'];
            $boxes = [];
            foreach ($lots as $l) $boxes[$l['location_id']] ??= $l;
            if (count($boxes) < 2) continue;
            $targets = array_values(array_filter($boxes, fn ($b) => $catPure($b['location_id'], $cat)));
            usort($targets, fn ($a, $b) => ($catCount[$b['location_id']][$cat] ?? 0) <=> ($catCount[$a['location_id']][$cat] ?? 0) ?: $cmpCode($a, $b));
            $sources = $lots;
            usort($sources, $cmpCode);
            foreach ($targets as $t) {
                $tid = $t['location_id'];
                foreach ($sources as $src) {
                    if (($catLots[$tid] ?? 0) >= SLOT_MAX_LOTS) break;
                    $sid = $src['location_id'];
                    if (isset($moved[$src['id']]) || $sid === $tid || $catPure($sid, $cat)) continue;
                    $add($src, $t, 'art', ['target_parts' => array_values(array_unique($namesIn[$tid] ?? []))]);
                    $catLots[$tid]++; $catCount[$tid][$cat] = ($catCount[$tid][$cat] ?? 0) + 1; $namesIn[$tid][] = $src['name'];
                    $catLots[$sid]--; $catCount[$sid][$cat]--;
                }
            }
        }
        return $out;
    }

    /** Menge eines Loses an einen anderen Lagerort buchen. Gleiches Teil am Ziel wird zusammengeführt. */
    public static function transfer(int $lotId, int $toId, int $qty, int $userId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lot = q('SELECT * FROM {inventory} WHERE id = ?', [$lotId])->fetch();
            if (!$lot) json_error('Los nicht gefunden', 404);
            $to = q('SELECT * FROM {locations} WHERE id = ?', [$toId])->fetch();
            if (!$to) json_error('Ziel-Lagerort nicht gefunden', 404);
            if ((int) $lot['location_id'] === $toId) json_error('Quelle und Ziel sind gleich');
            if ($qty < 1 || $qty > (int) $lot['qty']) json_error('Menge muss zwischen 1 und ' . $lot['qty'] . ' liegen');

            $t = now();
            $existing = q('SELECT id FROM {inventory} WHERE location_id = ? AND item_no = ? AND color_id = ?',
                [$toId, $lot['item_no'], $lot['color_id']])->fetchColumn();
            if ($existing) {
                q('UPDATE {inventory} SET qty = qty + ?, updated_at = ? WHERE id = ?', [$qty, $t, $existing]);
            } elseif ($qty === (int) $lot['qty']) {
                q('UPDATE {inventory} SET location_id = ?, updated_at = ? WHERE id = ?', [$toId, $t, $lotId]);
            } else {
                q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price, new_or_used, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$lot['item_type'], $lot['item_no'], $lot['color_id'], $lot['color_name'],
                   $lot['name'], $lot['category'], $toId, $qty, $lot['price'], $lot['new_or_used'] ?? '', $t]);
            }
            if ($existing || $qty < (int) $lot['qty']) {
                if ($qty === (int) $lot['qty']) {
                    q('DELETE FROM {inventory} WHERE id = ?', [$lotId]);
                } else {
                    q('UPDATE {inventory} SET qty = qty - ?, updated_at = ? WHERE id = ?', [$qty, $t, $lotId]);
                }
            }
            q('INSERT INTO {transfers} (item_no, color_name, name, from_location_id, to_location_id, qty, user_id, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$lot['item_no'], $lot['color_name'], $lot['name'], $lot['location_id'], $toId, $qty, $userId, $t]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $from = q('SELECT code FROM {locations} WHERE id = ?', [$lot['location_id']])->fetchColumn();
        return ['item_no' => $lot['item_no'], 'color' => $lot['color_name'], 'qty' => $qty, 'from' => $from, 'to' => $to['code']];
    }

    public static function transfers(int $limit = 30): array
    {
        return typed(q('SELECT t.id, t.item_no, t.color_name, t.name, t.qty, t.created_at,
                lf.code AS from_code, lt.code AS to_code, u.display_name
            FROM {transfers} t LEFT JOIN {locations} lf ON lf.id = t.from_location_id
            LEFT JOIN {locations} lt ON lt.id = t.to_location_id LEFT JOIN {users} u ON u.id = t.user_id
            ORDER BY t.id DESC LIMIT ' . max(1, min($limit, 500)))->fetchAll(), ['id', 'qty']);
    }

    public static function stocktakes(): array
    {
        return typed(q("SELECT s.id, s.status, s.created_at, s.closed_at, l.code AS location_code, l.name AS location_name,
                (SELECT COUNT(*) FROM {stocktake_items} x WHERE x.stocktake_id = s.id) AS positions,
                (SELECT COUNT(*) FROM {stocktake_items} x WHERE x.stocktake_id = s.id AND x.counted IS NOT NULL) AS counted,
                (SELECT COUNT(*) FROM {stocktake_items} x WHERE x.stocktake_id = s.id AND x.counted IS NOT NULL AND x.counted <> x.expected) AS deviations,
                u.display_name AS closed_by_name
            FROM {stocktakes} s JOIN {locations} l ON l.id = s.location_id LEFT JOIN {users} u ON u.id = s.closed_by
            ORDER BY CASE WHEN s.status = 'offen' THEN 0 ELSE 1 END, s.created_at DESC")->fetchAll(), ['id', 'positions', 'counted', 'deviations']);
    }

    public static function stocktake(int $id): ?array
    {
        $s = array_values(array_filter(self::stocktakes(), fn ($x) => $x['id'] === $id))[0] ?? null;
        if (!$s) return null;
        $s['items'] = typed(q('SELECT s.id, s.inventory_id, i.item_type, s.item_no, i.color_id, s.color_name, s.name, s.expected, s.counted
            FROM {stocktake_items} s LEFT JOIN {inventory} i ON i.id = s.inventory_id
            WHERE s.stocktake_id = ? ORDER BY s.item_no, s.color_name', [$id])->fetchAll(), ['id', 'inventory_id', 'color_id', 'expected', 'counted']);
        return $s;
    }

    public static function createStocktake(int $locationId): int
    {
        if (!q('SELECT 1 FROM {locations} WHERE id = ?', [$locationId])->fetch()) json_error('Lagerort nicht gefunden', 404);
        if (q("SELECT 1 FROM {stocktakes} WHERE location_id = ? AND status = 'offen'", [$locationId])->fetch()) {
            json_error('Für diesen Lagerort gibt es schon eine offene Prüfung', 409);
        }
        q("INSERT INTO {stocktakes} (location_id, status, created_at) VALUES (?, 'offen', ?)", [$locationId, now()]);
        $id = (int) db()->lastInsertId();
        foreach (q('SELECT * FROM {inventory} WHERE location_id = ?', [$locationId])->fetchAll() as $lot) {
            q('INSERT INTO {stocktake_items} (stocktake_id, inventory_id, item_no, color_name, name, expected) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $lot['id'], $lot['item_no'], $lot['color_name'], $lot['name'], $lot['qty']]);
        }
        return $id;
    }

    public static function count(int $stocktakeId, int $itemId, ?int $counted): void
    {
        $st = q('SELECT status FROM {stocktakes} WHERE id = ?', [$stocktakeId])->fetchColumn();
        if ($st !== 'offen') json_error('Prüfung ist nicht mehr offen', 409);
        if ($counted !== null && $counted < 0) json_error('Menge darf nicht negativ sein');
        q('UPDATE {stocktake_items} SET counted = ? WHERE id = ? AND stocktake_id = ?', [$counted, $itemId, $stocktakeId]);
    }

    /** Prüfung abschließen: gezählte Abweichungen werden als Korrektur auf den Bestand gebucht. */
    public static function closeStocktake(int $id, int $userId): array
    {
        $s = self::stocktake($id);
        if (!$s) json_error('Prüfung nicht gefunden', 404);
        if ($s['status'] !== 'offen') json_error('Prüfung ist bereits abgeschlossen', 409);
        $corrections = [];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $t = now();
            foreach ($s['items'] as $it) {
                if ($it['counted'] === null || $it['counted'] === $it['expected'] || !$it['inventory_id']) continue;
                $current = q('SELECT qty FROM {inventory} WHERE id = ?', [$it['inventory_id']])->fetchColumn();
                if ($current === false) continue;
                // Differenz anwenden, damit zwischenzeitliche Buchungen nicht überschrieben werden.
                $diff = $it['counted'] - $it['expected'];
                q('UPDATE {inventory} SET qty = ?, updated_at = ? WHERE id = ?', [max(0, (int) $current + $diff), $t, $it['inventory_id']]);
                $corrections[] = ['item_no' => $it['item_no'], 'color' => $it['color_name'], 'diff' => $diff];
            }
            q("UPDATE {stocktakes} SET status = 'abgeschlossen', closed_at = ?, closed_by = ? WHERE id = ?", [$t, $userId, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['location' => $s['location_code'], 'corrections' => $corrections];
    }

    /**
     * Alles zu einem Lagerort: Inhalt, Kennzahlen, Inventur-Stand, Umlagerungen und Vorschläge (rein bzw. raus),
     * offene Aufträge, die hier Teile holen müssen.
     */
    public static function location(string $code): ?array
    {
        $loc = q('SELECT id, code, name, kind FROM {locations} WHERE UPPER(code) = UPPER(?)', [$code])->fetch();
        if (!$loc) return null;
        $loc = typed([$loc], ['id'])[0];
        $id = $loc['id'];
        $lots = typed(q(self::LOT_SELECT . ' WHERE i.location_id = ? ORDER BY i.item_no, i.color_name', [$id])->fetchAll(), LOT_INTS, ['price']);
        $st = q("SELECT id, status, created_at, closed_at FROM {stocktakes} WHERE location_id = ? ORDER BY CASE WHEN status = 'offen' THEN 0 ELSE 1 END, created_at DESC LIMIT 1", [$id])->fetch();
        $in = implode(',', array_fill(0, count(ORDER_ACTIVE), '?'));
        $picks = typed(q("SELECT o.id, o.order_no, o.status, x.item_type, x.item_no, x.color_id, x.color_name, x.name, x.qty
            FROM {order_items} x JOIN {orders} o ON o.id = x.order_id JOIN {inventory} i ON i.id = x.inventory_id
            WHERE i.location_id = ? AND o.status IN ($in) ORDER BY o.ordered_at", [$id, ...ORDER_ACTIVE])->fetchAll(), ['id', 'color_id', 'qty']);
        $transfers = typed(q('SELECT t.id, t.item_no, t.color_name, t.qty, t.created_at, lf.code AS from_code, lt.code AS to_code, u.display_name
            FROM {transfers} t LEFT JOIN {locations} lf ON lf.id = t.from_location_id
            LEFT JOIN {locations} lt ON lt.id = t.to_location_id LEFT JOIN {users} u ON u.id = t.user_id
            WHERE t.from_location_id = ? OR t.to_location_id = ? ORDER BY t.id DESC LIMIT 15', [$id, $id])->fetchAll(), ['id', 'qty']);
        $suggestions = array_values(array_filter(self::suggestions(),
            fn ($s) => $s['lot']['location_id'] === $id || $s['to_location_id'] === $id));
        // Natürliche Reihenfolge: N4 vor N150, Plate2 vor Plate10.
        $neighbors = q('SELECT code FROM {locations} WHERE kind = ?', [$loc['kind']])->fetchAll(PDO::FETCH_COLUMN);
        natcasesort($neighbors);
        $neighbors = array_values($neighbors);
        $pos = array_search($loc['code'], $neighbors, true);
        return $loc + [
            'lots'        => $lots,
            'stocktake'   => $st ? typed([$st], ['id'])[0] : null,
            'picks'       => $picks,
            'transfers'   => $transfers,
            'suggestions' => $suggestions,
            'prev'        => $pos > 0 ? $neighbors[$pos - 1] : null,
            'next'        => $pos !== false && $pos < count($neighbors) - 1 ? $neighbors[$pos + 1] : null,
        ];
    }

    /** Alles Wichtige zu einem Teil: Bestand je Farbe und Lagerort, Aufträge mit dem Teil, letzte Umlagerungen. */
    public static function part(string $itemNo): array
    {
        $lots = typed(q(self::LOT_SELECT . ' WHERE i.item_no = ? ORDER BY i.color_name, l.code', [$itemNo])->fetchAll(), LOT_INTS, ['price']);
        $in = implode(',', array_fill(0, count(ORDER_ACTIVE), '?'));
        $orders = typed(q("SELECT o.id, o.order_no, o.buyer, o.status, o.ordered_at, x.color_name, x.qty,
                CASE WHEN o.status IN ($in) THEN 1 ELSE 0 END AS active
            FROM {order_items} x JOIN {orders} o ON o.id = x.order_id
            WHERE x.item_no = ? ORDER BY o.ordered_at DESC LIMIT 30", [...ORDER_ACTIVE, $itemNo])->fetchAll(), ['id', 'qty', 'active']);
        $transfers = typed(q('SELECT t.id, t.color_name, t.qty, t.created_at, lf.code AS from_code, lt.code AS to_code, u.display_name
            FROM {transfers} t LEFT JOIN {locations} lf ON lf.id = t.from_location_id
            LEFT JOIN {locations} lt ON lt.id = t.to_location_id LEFT JOIN {users} u ON u.id = t.user_id
            WHERE t.item_no = ? ORDER BY t.id DESC LIMIT 10', [$itemNo])->fetchAll(), ['id', 'qty']);
        return [
            'item_no'   => $lots[0]['item_no'] ?? $itemNo,
            'name'      => $lots[0]['name'] ?? null,
            'category'  => $lots[0]['category'] ?? null,
            'item_type' => $lots[0]['item_type'] ?? 'PART',
            'lots'      => $lots,
            'orders'    => $orders,
            'transfers' => $transfers,
        ];
    }

    /** Alle Lose zu den angegebenen Teil-Nummern, gruppiert nach Nummer (für die Teileerkennung). */
    public static function lotsByItem(array $itemNos): array
    {
        $itemNos = array_values(array_unique(array_filter(array_map('strval', $itemNos))));
        if (!$itemNos) return [];
        $in = implode(',', array_fill(0, count($itemNos), '?'));
        $out = [];
        foreach (typed(q(self::LOT_SELECT . " WHERE i.item_no IN ($in) ORDER BY i.color_name, l.code", $itemNos)->fetchAll(), LOT_INTS, ['price']) as $lot) {
            $out[$lot['item_no']][] = $lot;
        }
        return $out;
    }

    public static function analysis(): array
    {
        $byCategory = typed(q('SELECT category AS label, COUNT(*) AS lots, SUM(qty) AS qty, SUM(qty * price) AS value
            FROM {inventory} GROUP BY category ORDER BY SUM(qty) DESC')->fetchAll(), ['lots', 'qty'], ['value']);
        $byLocation = typed(q('SELECT l.code AS label, l.kind, COUNT(i.id) AS lots, COALESCE(SUM(i.qty), 0) AS qty
            FROM {locations} l LEFT JOIN {inventory} i ON i.location_id = l.id
            GROUP BY l.id, l.code, l.kind ORDER BY COALESCE(SUM(i.qty), 0) DESC')->fetchAll(), ['lots', 'qty']);
        $byStatus = [];
        foreach (ORDER_STATUSES as $st) {
            $byStatus[] = ['label' => $st, 'count' => (int) q('SELECT COUNT(*) FROM {orders} WHERE status = ?', [$st])->fetchColumn()];
        }
        $lowStock = typed(q(self::LOT_SELECT . " WHERE l.kind = 'regal' AND i.qty < 20 ORDER BY i.qty ASC LIMIT 10")->fetchAll(), LOT_INTS, ['price']);
        $activity = typed(q("SELECT u.display_name AS label, COUNT(a.id) AS count FROM {users} u
            LEFT JOIN {activity_log} a ON a.user_id = u.id AND a.action NOT IN ('login', 'logout')
            WHERE u.role = 'teilnehmer' AND u.active = 1 GROUP BY u.id, u.display_name ORDER BY COUNT(a.id) DESC")->fetchAll(), ['count']);
        // Mischkisten (G-Kästchen mit zu vielen Losen), die vollsten zuerst
        $mixed = array_values(array_filter($byLocation, fn ($l) => preg_match(MIXED_AREA_PATTERN, $l['label']) && $l['lots'] > MIXED_MAX_LOTS));
        usort($mixed, fn ($a, $b) => $b['lots'] <=> $a['lots'] ?: strnatcasecmp($a['label'], $b['label']));
        $mixed = array_slice($mixed, 0, 10);
        return [
            'by_category' => $byCategory,
            'by_location' => array_slice(array_values(array_filter($byLocation, fn ($l) => $l['kind'] === 'regal')), 0, 10),
            'mixed_bins'  => $mixed,
            'orders_by_status' => $byStatus,
            'low_stock'   => $lowStock,
            'activity_by_participant' => $activity,
            'generated_at' => now(),
        ];
    }
}
