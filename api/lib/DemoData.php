<?php

/** Lädt die erfundenen Beispieldaten aus sql/demo-data.json (nur in eine leere Datenbank). */
final class DemoData
{
    public const DEMO_LOT_BASE = 300000001;

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
        BrickLinkSync::ensureSchema();
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
                // Erfundene BrickLink-Lot-IDs (gleiche Rechnung wie seedData() in frontend/src/demo/shop.js).
                // bl_lot_id/bl_condition (Export) und bl_inventory_id/new_or_used (Pickliste, Minifig) bekommen dieselben Werte.
                $cond = $p['new_or_used'] ?? BrickLinkSync::conditionFor($p['location']);
                q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price, updated_at,
                   bl_lot_id, bl_condition, bl_inventory_id, new_or_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$p['item_type'], $p['item_no'], $p['color_id'],
                   $p['color_name'], $p['name'], $p['category'], $loc[$p['location']], $p['qty'], $p['price'], $ago($p['updated_days_ago']),
                   self::DEMO_LOT_BASE + $i, $cond, self::DEMO_LOT_BASE + $i, $cond]);
                $lot[$i] = (int) $pdo->lastInsertId();
            }
            foreach ($data['orders'] as $o) {
                $total = array_sum(array_map(fn ($it) => $it['qty'] * $it['price'], $o['items']));
                q('INSERT INTO {orders} (platform, order_no, buyer, status, ordered_at, total, currency, shipping, details_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$o['platform'] ?? 'TN', $o['order_no'], $o['customer'] ?? $o['buyer'], $o['status'], $ago($o['days_ago']), round($total, 2), 'EUR',
                     json_encode(self::shipping($o, $total), JSON_UNESCAPED_UNICODE), now()]);
                $oid = (int) $pdo->lastInsertId();
                foreach ($o['items'] as $it) {
                    $p = $data['inventory'][$it['lot']];
                    q('INSERT INTO {order_items} (order_id, inventory_id, item_type, item_no, color_id, color_name, name, qty, price, new_or_used, remarks, image_url)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$oid, $lot[$it['lot']], $p['item_type'], $p['item_no'], $p['color_id'], $p['color_name'],
                       $p['name'], $it['qty'], $it['price'], $p['new_or_used'] ?? '', $p['location'], Sync::imageUrl($p['item_type'], $p['item_no'], $p['color_id'])]);
                }
            }
            self::seedMinifigs($data);
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

    /** Versanddaten im Format der Pickliste (gleich wie seedData() in frontend/src/demo/shop.js). */
    public static function shipping(array $o, float $itemsTotal): array
    {
        $s = $o['shipping'] ?? null;
        if (!$s) return [];
        return ['shipping_method' => $s['method'], 'shipping_cost_minor' => (int) round($s['cost'] * 100),
            'grand_total_minor' => (int) round(($itemsTotal + $s['cost']) * 100), 'recipient_address' => $s['address']];
    }

    /**
     * Supersets einer Teile-Variante aus dem erfundenen Katalog (Antwort wie BrickLink /items/part/{no}/supersets).
     * Gleiche Regel wie supersetsOf() in frontend/src/demo/minifig.js.
     */
    public static function supersetsOf(array $figs, string $no, int $color): array
    {
        $entries = [];
        foreach ($figs as $f) {
            foreach ($f['subset'] as $g) {
                foreach ($g['entries'] as $e) {
                    if ($e['item']['no'] === $no && (int) $e['color_id'] === $color) {
                        $entries[] = ['item' => ['no' => $f['minifig_no'], 'name' => $f['name'], 'type' => 'MINIFIG', 'category_id' => $f['category_id']],
                            'quantity' => 1, 'appears_as' => 'A'];
                        continue 3;
                    }
                }
            }
        }
        return $entries ? [['color_id' => $color, 'entries' => $entries]] : [];
    }

    /** Minifig-Finder: geprüfte Varianten, bekannte Figuren, geladene Stücklisten und Preise (alles erfunden). */
    private static function seedMinifigs(array $data): void
    {
        $figs = $data['minifigs'] ?? [];
        if (!$figs) return;
        $t = now();
        $byNo = array_column($figs, null, 'minifig_no');
        $loaded = array_flip($data['minifig_state']['subsets_loaded']);
        foreach ($data['minifig_state']['scanned'] as [$no, $color]) {
            $resp = self::supersetsOf($figs, $no, (int) $color);
            q('INSERT INTO {minifig_superset_cache} (item_no, color_id, fetched_at, response_json) VALUES (?, ?, ?, ?)', [$no, $color, $t, json_encode($resp, JSON_UNESCAPED_UNICODE)]);
            foreach ($resp[0]['entries'] ?? [] as $e) {
                $fno = $e['item']['no'];
                if (q('SELECT 1 FROM {minifig_catalog} WHERE minifig_no = ?', [$fno])->fetch()) continue;
                q('INSERT INTO {minifig_catalog} (minifig_no, name, category_id, first_seen_at, last_seen_at, subset_fetched_at, subset_json) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$fno, $e['item']['name'], $e['item']['category_id'], $t, $t, isset($loaded[$fno]) ? $t : null,
                     isset($loaded[$fno]) ? json_encode($byNo[$fno]['subset'], JSON_UNESCAPED_UNICODE) : null]);
            }
        }
        foreach ($data['price_guide'] ?? [] as $p) {
            q('INSERT INTO {price_guide} (item_type, item_no, color_id, new_or_used, currency, avg_price, source, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$p['item_type'], $p['item_no'], $p['color_id'], 'U', 'EUR', $p['avg_price'], 'brickstore_import', $t]);
        }
    }
}
