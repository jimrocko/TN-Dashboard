<?php

/** Lädt die erfundenen Beispieldaten aus sql/demo-data.json (nur in eine leere Datenbank). */
final class DemoData
{
    public static function isEmpty(): bool
    {
        return (int) q('SELECT COUNT(*) FROM {inventory}')->fetchColumn() === 0
            && (int) q('SELECT COUNT(*) FROM {locations}')->fetchColumn() === 0;
    }

    public static function seed(): array
    {
        if (!self::isEmpty()) {
            throw new RuntimeException('Es sind bereits Lagerdaten vorhanden');
        }
        $data = json_decode(file_get_contents(__DIR__ . '/../sql/demo-data.json'), true);
        $ago = fn (int $days) => date('Y-m-d H:i:s', time() - $days * 86400 - random_int(0, 6 * 3600));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $loc = [];
            foreach ($data['locations'] as $l) {
                q('INSERT INTO {locations} (code, name, kind) VALUES (?, ?, ?)', [$l['code'], $l['name'], $l['kind']]);
                $loc[$l['code']] = (int) $pdo->lastInsertId();
            }
            $lot = [];
            foreach ($data['inventory'] as $i => $p) {
                q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$p['item_type'], $p['item_no'], $p['color_id'], $p['color_name'], $p['name'],
                   $p['category'], $loc[$p['location']], $p['qty'], $p['price'], $ago($p['updated_days_ago'])]);
                $lot[$i] = (int) $pdo->lastInsertId();
            }
            foreach ($data['orders'] as $o) {
                $total = array_sum(array_map(fn ($it) => $it['qty'] * $it['price'], $o['items']));
                q('INSERT INTO {orders} (order_no, buyer, status, ordered_at, total) VALUES (?, ?, ?, ?, ?)',
                    [$o['order_no'], $o['buyer'], $o['status'], $ago($o['days_ago']), round($total, 2)]);
                $oid = (int) $pdo->lastInsertId();
                foreach ($o['items'] as $it) {
                    $p = $data['inventory'][$it['lot']];
                    q('INSERT INTO {order_items} (order_id, inventory_id, item_no, color_name, name, qty, price) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$oid, $lot[$it['lot']], $p['item_no'], $p['color_name'], $p['name'], $it['qty'], $it['price']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        foreach ($data['stocktakes'] as $s) {
            Shop::createStocktake($loc[$s['location']]);
        }
        return ['locations' => count($loc), 'lots' => count($lot), 'orders' => count($data['orders'])];
    }
}
