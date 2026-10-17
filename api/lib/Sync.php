<?php

/**
 * Daten von BrickLink und BrickOwl in die eigene Datenbank holen (Grundsatz: so wenig Abrufe wie möglich).
 *  - Nur auf Knopfdruck (Lehrer/Dev) oder per `php api/install.php --sync`, kein Zeitplan.
 *  - Mindestabstand zwischen zwei Abrufen derselben Art (sync.min_interval, Standard 10 Minuten).
 *  - Aufträge: die Liste mit einem Abruf; Details und Positionen nur einmal je neuem offenen Auftrag.
 * Gleiche Regeln wie im Original (bi-legoshop-dashboard: repositories/inventory.py und orders.py).
 */
final class Sync
{
    /** BrickLink-Status → Status der Auftragszentrale. null = storniert (nicht übernehmen). */
    private const BL_STATUS = [
        'PENDING' => 'Neu', 'UPDATED' => 'Neu', 'PROCESSING' => 'Bezahlt', 'READY' => 'Bezahlt', 'PAID' => 'Bezahlt',
        'PACKED' => 'Verpackt', 'SHIPPED' => 'Versandt', 'RECEIVED' => 'Abgeschlossen', 'COMPLETED' => 'Abgeschlossen',
        'OCR' => 'Abgeschlossen', 'NPB' => null, 'NPX' => null, 'NRS' => null, 'NSS' => null, 'CANCELLED' => null, 'PURGED' => null,
    ];
    /** BrickOwl: nur „bezahlt“ zählt wie im Original als offen für die Pickliste. */
    private const BO_STATUS = [
        'PAID' => 'Bezahlt', 'PAYMENT RECEIVED' => 'Bezahlt', 'PAYMENT_RECEIVED' => 'Bezahlt',
        'SHIPPED' => 'Versandt', 'RECEIVED' => 'Abgeschlossen', 'COMPLETED' => 'Abgeschlossen', 'CANCELLED' => null,
    ];

    public static function minInterval(): int
    {
        return (int) (config()['sync']['min_interval'] ?? 600);
    }

    /** Wann zuletzt abgerufen, wann wieder möglich. */
    public static function status(): array
    {
        $out = [];
        foreach (['inventory', 'orders'] as $kind) {
            $last = Dev::setting("sync_{$kind}_at");
            $out[$kind] = [
                'last_at' => $last, 'result' => json_decode(Dev::setting("sync_{$kind}_result") ?? 'null', true),
                'next_possible_at' => $last ? date('Y-m-d H:i:s', strtotime($last) + self::minInterval()) : null,
            ];
        }
        $out['bricklink_configured'] = BrickLinkClient::fromConfig()->isConfigured();
        $out['brickowl_configured'] = BrickOwlClient::fromConfig()->isConfigured();
        $out['min_interval'] = self::minInterval();
        $out['api_usage'] = ApiLog::usage24h();
        return $out;
    }

    private static function throttle(string $kind): void
    {
        $last = Dev::setting("sync_{$kind}_at");
        if ($last && strtotime($last) + self::minInterval() > time()) {
            $wait = (int) ceil((strtotime($last) + self::minInterval() - time()) / 60);
            throw new ApiException("Gerade erst aktualisiert. Nächster Abruf in $wait Min. möglich (spart API-Abrufe).", 429);
        }
    }

    private static function done(string $kind, array $result): array
    {
        Dev::setSetting("sync_{$kind}_at", now());
        Dev::setSetting("sync_{$kind}_result", json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    }

    // ---------------------------------------------------------------- Bestand

    /** BrickLink-Bestand (GET /inventories) übernehmen. */
    public static function inventory(bool $force = false): array
    {
        if (!$force) self::throttle('inventory');
        $bl = BrickLinkClient::fromConfig();
        $items = $bl->get('/inventories', [], 'inventory');
        $categories = self::categories($bl);
        return self::done('inventory', self::saveInventory(is_array($items) ? $items : [], $categories, 'BrickLink'));
    }

    /** Kategorie-Namen (ein Abruf, 30 Tage im Cache). */
    private static function categories(BrickLinkClient $bl): array
    {
        try {
            $r = Cache::remember('categories', 30 * 86400, fn () => $bl->get('/categories', [], 'catalog'));
            return array_column($r['data'], 'category_name', 'category_id');
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Lots ersetzen, nur nach Plausibilitätsprüfung (wie save_bricklink_inventory im Original).
     * Gleiche BrickLink-Lot-Nr. behält die eigene ID (Inventur, Aufträge bleiben verknüpft).
     * Der bisherige Stand wird in inventory_history archiviert. Lagerort = Bemerkung (remarks) des Lots.
     */
    public static function saveInventory(array $items, array $categories = [], string $source = 'BrickLink'): array
    {
        if (!$items) {
            throw new RuntimeException("Sicherheitsabbruch: $source hat 0 Lots geliefert. Bestand bleibt unverändert.");
        }
        $valid = array_values(array_filter($items, fn ($i) => is_array($i) && isset($i['inventory_id']) && is_array($i['item'] ?? [])));
        if (!$valid) {
            throw new RuntimeException('Sicherheitsabbruch: Keine gültigen Lots. Bestand bleibt unverändert.');
        }
        // Nur bereits übernommene Plattform-Lots zählen (Beispieldaten dürfen den ersten Abruf nicht blockieren)
        $old = q('SELECT COUNT(*) AS lots, COALESCE(SUM(qty), 0) AS parts FROM {inventory} WHERE bl_inventory_id IS NOT NULL')->fetch();
        $oldLots = (int) $old['lots'];
        $oldParts = (int) $old['parts'];
        $newLots = count($valid);
        $newParts = array_sum(array_map(fn ($i) => max(0, (int) ($i['quantity'] ?? 0)), $valid));
        if ($oldLots >= 100 && $newLots < max(1, (int) ($oldLots * 0.2))) {
            throw new RuntimeException("Sicherheitsabbruch: nur $newLots Lots statt bisher $oldLots.");
        }
        if ($oldParts >= 1000 && $newParts < max(1, (int) ($oldParts * 0.2))) {
            throw new RuntimeException("Sicherheitsabbruch: nur $newParts Teile statt bisher $oldParts.");
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $t = now();
            // Bisherigen Stand archivieren
            foreach (q('SELECT i.*, l.code FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.bl_inventory_id IS NOT NULL')->fetchAll() as $r) {
                self::archive((int) $r['bl_inventory_id'], $r['item_type'], $r['item_no'], $r['name'], $r['color_id'], $r['color_name'],
                    (int) $r['qty'], $r['new_or_used'], (float) $r['price'], $r['code'], $r['description'], $t);
            }
            $locIds = array_column(q('SELECT id, code FROM {locations}')->fetchAll(), 'id', 'code');
            $existing = array_column(q('SELECT id, bl_inventory_id FROM {inventory} WHERE bl_inventory_id IS NOT NULL')->fetchAll(), 'id', 'bl_inventory_id');
            $keep = [];
            $moved = 0;
            foreach ($valid as $i) {
                $item = $i['item'] ?? [];
                $color = $i['color'] ?? [];
                $blId = (int) $i['inventory_id'];
                $code = trim((string) ($i['remarks'] ?? ''));
                $locId = null;
                if ($code !== '') {
                    $code = substr($code, 0, 64);
                    if (!isset($locIds[$code])) {
                        q("INSERT INTO {locations} (code, name, kind) VALUES (?, ?, 'regal')", [$code, "Lagerort $code"]);
                        $locIds[$code] = (int) $pdo->lastInsertId();
                    }
                    $locId = (int) $locIds[$code];
                }
                $row = [
                    strtoupper((string) ($item['type'] ?? 'PART')), (string) ($item['no'] ?? ''),
                    (int) ($color['color_id'] ?? $i['color_id'] ?? 0), (string) ($color['color_name'] ?? $i['color_name'] ?? ''),
                    html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
                    (string) ($categories[$item['category_id'] ?? ''] ?? $item['category_name'] ?? ''),
                    $locId, max(0, (int) ($i['quantity'] ?? 0)), (float) ($i['unit_price'] ?? 0),
                    strtoupper(substr((string) ($i['new_or_used'] ?? ''), 0, 1)), substr((string) ($i['description'] ?? ''), 0, 255) ?: null,
                ];
                if (isset($existing[$blId])) {
                    $prev = q('SELECT location_id FROM {inventory} WHERE id = ?', [$existing[$blId]])->fetchColumn();
                    if ((int) $prev !== (int) $locId) $moved++;
                    q('UPDATE {inventory} SET item_type = ?, item_no = ?, color_id = ?, color_name = ?, name = ?, category = ?, location_id = ?,
                        qty = ?, price = ?, new_or_used = ?, description = ?, updated_at = ?, bl_lot_id = ?, bl_condition = ? WHERE id = ?', [...$row, $t, $blId, $row[9] === 'N' ? 'N' : 'U', $existing[$blId]]);
                    $keep[$existing[$blId]] = true;
                } else {
                    q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price, new_or_used,
                        description, bl_inventory_id, updated_at, bl_lot_id, bl_condition) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$row, $blId, $t, $blId, $row[9] === 'N' ? 'N' : 'U']);
                    $keep[(int) $pdo->lastInsertId()] = true;
                }
                self::archive($blId, $row[0], $row[1], $row[4], $row[2], $row[3], $row[7], $row[9], $row[8], $code ?: null, $row[10], $t);
            }
            // Lots, die es bei der Plattform nicht mehr gibt (auch alte Beispieldaten), entfernen – das Archiv behält sie.
            $removed = 0;
            foreach (q('SELECT id FROM {inventory}')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!isset($keep[(int) $id])) {
                    // Aufträge behalten die BrickLink-Lot-Nr. (ext_lot_id) und finden Lagerort/Zustand im Archiv
                    q('UPDATE {order_items} SET inventory_id = NULL WHERE inventory_id = ?', [$id]);
                    q('DELETE FROM {inventory} WHERE id = ?', [$id]);
                    $removed++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['lots' => $newLots, 'parts' => $newParts, 'removed' => $removed, 'moved' => $moved, 'source' => $source];
    }

    private static function archive(int $blId, $type, $no, $name, $colorId, $colorName, int $qty, $cond, float $price, $code, $desc, string $t): void
    {
        q('DELETE FROM {inventory_history} WHERE bl_inventory_id = ?', [$blId]);
        q('INSERT INTO {inventory_history} (bl_inventory_id, item_type, item_no, name, color_id, color_name, qty, new_or_used, price,
            location_code, description, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$blId, $type, $no, (string) $name, $colorId, (string) $colorName, $qty, (string) $cond, $price, $code, $desc, $t]);
    }

    // ---------------------------------------------------------------- Aufträge

    public static function orders(bool $force = false): array
    {
        if (!$force) self::throttle('orders');
        $result = ['bricklink' => null, 'brickowl' => null, 'errors' => []];
        $bl = BrickLinkClient::fromConfig();
        if ($bl->isConfigured()) {
            try { $result['bricklink'] = self::bricklinkOrders($bl); } catch (Throwable $e) { $result['errors'][] = 'BrickLink: ' . $e->getMessage(); }
        }
        $bo = BrickOwlClient::fromConfig();
        if ($bo->isConfigured()) {
            try { $result['brickowl'] = self::brickowlOrders($bo); } catch (Throwable $e) { $result['errors'][] = 'BrickOwl: ' . $e->getMessage(); }
        }
        if (!$bl->isConfigured() && !$bo->isConfigured()) {
            throw new ApiException('Weder BrickLink noch BrickOwl ist eingerichtet (.env).', 401);
        }
        self::bumpPickRevision();
        return self::done('orders', $result);
    }

    private static function bumpPickRevision(): void
    {
        Dev::setSetting('pick_revision', (string) ((int) Dev::setting('pick_revision') + 1));
    }

    private static function bricklinkOrders(BrickLinkClient $bl): array
    {
        $list = $bl->get('/orders', ['direction' => 'in'], 'orders');
        $stats = ['orders' => 0, 'new' => 0, 'details' => 0];
        foreach (is_array($list) ? $list : [] as $o) {
            $no = (string) ($o['order_id'] ?? '');
            if ($no === '') continue;
            $code = strtoupper((string) ($o['status'] ?? ''));
            $status = array_key_exists($code, self::BL_STATUS) ? self::BL_STATUS[$code] : 'Neu';
            $total = $o['disp_cost']['grand_total'] ?? $o['cost']['grand_total'] ?? $o['grand_total'] ?? 0;
            $id = self::upsertOrder('BrickLink', $no, $status, $code, self::date($o['date_ordered'] ?? null), (float) $total,
                $o['disp_cost']['currency_code'] ?? $o['cost']['currency_code'] ?? $o['currency_code'] ?? null, $stats);
            if ($id === null || !in_array($status, Picking::OPEN, true)) continue;
            // Details (Kundenname, Versand) und Positionen nur einmal je Auftrag
            if (q('SELECT details_at FROM {orders} WHERE id = ?', [$id])->fetchColumn()) continue;
            $details = $bl->get('/orders/' . rawurlencode($no), [], 'order_details');
            $items = $bl->get('/orders/' . rawurlencode($no) . '/items', [], 'order_items');
            self::saveDetails($id, $details);
            self::saveItems($id, 'BrickLink', self::normalizeBrickLinkItems(is_array($items) ? $items : []));
            $stats['details']++;
        }
        return $stats;
    }

    private static function brickowlOrders(BrickOwlClient $bo): array
    {
        $list = $bo->get('/order/list', [], 'orders');
        $stats = ['orders' => 0, 'new' => 0, 'details' => 0];
        foreach (is_array($list) ? $list : [] as $o) {
            if (!is_array($o)) continue;
            $no = (string) ($o['order_id'] ?? $o['id'] ?? '');
            if ($no === '') continue;
            $raw = (string) ($o['status'] ?? '');
            $code = strtoupper(trim($raw));
            $status = array_key_exists($code, self::BO_STATUS) ? self::BO_STATUS[$code] : 'Neu';
            $total = $o['total'] ?? $o['grand_total'] ?? $o['order_total'] ?? $o['base_order_total'] ?? 0;
            $id = self::upsertOrder('BrickOwl', $no, $status, $raw, self::date($o['order_date'] ?? $o['date'] ?? null), (float) $total,
                $o['currency_code'] ?? $o['currency'] ?? $o['base_currency'] ?? null, $stats);
            if ($id === null || !in_array($status, Picking::OPEN, true)) continue;
            if (q('SELECT details_at FROM {orders} WHERE id = ?', [$id])->fetchColumn()) continue;
            $details = $bo->get('/order/view', ['order_id' => $no], 'order_details');
            $items = $bo->get('/order/items', ['order_id' => $no], 'order_items');
            self::saveDetails($id, $details);
            self::saveItems($id, 'BrickOwl', self::normalizeBrickOwlItems(self::brickOwlItemList($items)));
            $stats['details']++;
        }
        return $stats;
    }

    /** Auftrag anlegen oder Status aktualisieren. Stornierte nicht übernehmen (vorhandene werden abgeschlossen). */
    private static function upsertOrder(string $platform, string $no, ?string $status, string $platformStatus, string $date, float $total, ?string $currency, array &$stats): ?int
    {
        $id = q('SELECT id FROM {orders} WHERE platform = ? AND order_no = ?', [$platform, $no])->fetchColumn();
        if ($status === null) {
            if ($id) q("UPDATE {orders} SET status = 'Abgeschlossen', platform_status = ? WHERE id = ?", [$platformStatus, $id]);
            return null;
        }
        $stats['orders']++;
        if ($id) {
            q('UPDATE {orders} SET status = ?, platform_status = ?, total = ?, currency = COALESCE(?, currency) WHERE id = ?',
                [$status, $platformStatus, round($total, 2), $currency, $id]);
            return (int) $id;
        }
        q('INSERT INTO {orders} (platform, order_no, buyer, status, platform_status, ordered_at, total, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$platform, $no, '', $status, $platformStatus, $date, round($total, 2), $currency]);
        $stats['new']++;
        return (int) db()->lastInsertId();
    }

    private static function date($v): string
    {
        if ($v === null || $v === '') return now();
        $ts = is_numeric($v) ? (int) $v : strtotime((string) $v);
        return $ts ? date('Y-m-d H:i:s', $ts) : now();
    }

    /** Erster nicht-leerer Wert über Punkt-Pfade (wie first_value im Original). */
    public static function fv(mixed $data, string ...$paths): mixed
    {
        foreach ($paths as $path) {
            $cur = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($cur) || !array_key_exists($part, $cur)) { $cur = null; break; }
                $cur = $cur[$part];
            }
            if ($cur !== null && $cur !== '') return $cur;
        }
        return null;
    }

    private static function minor($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_array($v)) $v = $v['value'] ?? $v['amount'] ?? $v['total'] ?? null;
        if (!is_numeric(str_replace(',', '.', (string) $v))) return null;
        return (int) round((float) str_replace(',', '.', (string) $v) * 100);
    }

    /** Kundenname und Versanddaten aus den Auftragsdetails (BrickLink oder BrickOwl). */
    public static function shippingFromDetails(array $d): array
    {
        if (isset($d['data']) && is_array($d['data'])) $d = $d['data'];
        if (isset($d['order']) && is_array($d['order'])) $d = $d['order'];
        $shipping = self::fv($d, 'shipping', 'shipping_details');
        $shipping = is_array($shipping) ? $shipping : [];
        $method = self::fv($d, 'shipping.method', 'shipping_method', 'shipping_method_name', 'ship_method', 'ship_method_name',
            'delivery_method', 'delivery_method_name', 'shipping_service', 'shipping_service_name', 'method')
            ?? self::fv($shipping, 'method', 'name', 'service', 'service_name', 'description');
        $cost = self::fv($d, 'cost.shipping', 'shipping_cost', 'shipping_total', 'shipping_amount', 'disp_cost.shipping')
            ?? self::fv($shipping, 'cost', 'amount', 'total');
        $grand = self::fv($d, 'cost.grand_total', 'cost.grandtotal', 'grand_total', 'total', 'disp_cost.grand_total', 'disp_cost.grandtotal');
        $address = self::fv($d, 'shipping.address', 'shipping_address', 'shipping_details.address') ?? $shipping;
        $address = is_array($address) ? $address : [];
        $first = self::fv($address, 'first_name', 'name.first', 'name.first_name') ?? self::fv($d, 'ship_first_name', 'buyer_first_name');
        $last = self::fv($address, 'last_name', 'name.last', 'name.last_name') ?? self::fv($d, 'ship_last_name', 'buyer_last_name');
        $name = trim(implode(' ', array_filter([trim((string) $first), trim((string) $last)])));
        if ($name === '') {
            $n = self::fv($address, 'name.full', 'full_name', 'recipient', 'name') ?? self::fv($d, 'buyer_name', 'customer_name', 'ship_name', 'shipping_name');
            $name = is_string($n) ? trim($n) : '';
        }
        $full = trim(str_replace("\r", "\n", (string) self::fv($address, 'full', 'formatted', 'address')));
        $lines = array_values(array_filter(array_map(fn ($l) => preg_replace('/\s+/', ' ', trim($l)), explode("\n", $full))));
        if ($name === '' && $lines) $name = $lines[0];
        if (!$lines) {
            $street = self::fv($address, 'address1', 'address_line1', 'street', 'line1', 'street1') ?? self::fv($d, 'ship_street_1', 'ship_street', 'ship_address');
            $line2 = self::fv($address, 'address2', 'address_line2', 'line2', 'street2') ?? self::fv($d, 'ship_street_2');
            $postal = self::fv($address, 'postal_code', 'postcode', 'zip_code', 'zip') ?? self::fv($d, 'ship_post_code', 'ship_postcode');
            $city = self::fv($address, 'city', 'city_name') ?? self::fv($d, 'ship_city');
            $country = self::fv($address, 'country_name', 'country', 'country_code') ?? self::fv($d, 'ship_country');
            if ($street && $postal && $city) $lines = array_values(array_filter([(string) $street, (string) $line2, "$postal $city", (string) $country]));
        }
        $company = self::fv($address, 'company', 'organization') ?? self::fv($d, 'ship_company');
        if ($lines && $name !== '' && $lines[0] !== $name) array_unshift($lines, $name);
        if ($company && (!$lines || $lines[0] !== $company)) array_unshift($lines, (string) $company);
        $out = [];
        if ($method !== null && $method !== '') $out['shipping_method'] = trim((string) $method);
        if (($m = self::minor($cost)) !== null) $out['shipping_cost_minor'] = $m;
        if (($m = self::minor($grand)) !== null) $out['grand_total_minor'] = $m;
        if (count($lines) >= 2) $out['recipient_address'] = $lines;
        return ['customer' => $company ? (string) $company : $name, 'shipping' => $out];
    }

    private static function saveDetails(int $orderId, $details): void
    {
        $s = is_array($details) ? self::shippingFromDetails($details) : ['customer' => '', 'shipping' => []];
        q('UPDATE {orders} SET buyer = ?, shipping = ?, details_at = ? WHERE id = ?',
            [mb_substr($s['customer'], 0, 100), json_encode($s['shipping'], JSON_UNESCAPED_UNICODE), now(), $orderId]);
    }

    // ---------------------------------------------------------------- Positionen

    /** Bild ohne API-Abruf: direkt vom BrickLink-Bildserver. */
    public static function imageUrl(string $type, string $no, $colorId): ?string
    {
        if ($no === '') return null;
        $t = strtoupper($type);
        if (in_array($t, ['P', 'PART'], true) && $colorId !== null) return "https://img.bricklink.com/ItemImage/PN/$colorId/" . rawurlencode($no) . '.png';
        $letter = ['MINIFIG' => 'M', 'M' => 'M', 'SET' => 'S', 'S' => 'S', 'GEAR' => 'G', 'BOOK' => 'B'][$t] ?? null;
        return $letter ? "https://img.bricklink.com/ItemImage/{$letter}N/0/" . rawurlencode($no) . '.png' : null;
    }

    private static function normalizeBrickLinkItems(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $list = is_array($group) && array_is_list($group) ? $group : [$group];
            foreach ($list as $it) {
                if (!is_array($it)) continue;
                $item = $it['item'] ?? [];
                $type = strtoupper((string) ($item['type'] ?? 'PART'));
                $no = (string) ($item['no'] ?? '');
                $color = isset($it['color_id']) ? (int) $it['color_id'] : null;
                $out[] = [
                    'item_type' => $type, 'item_no' => $no, 'name' => html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
                    'color_id' => $color, 'color_name' => (string) ($it['color_name'] ?? ''), 'qty' => (int) ($it['quantity'] ?? 0),
                    'price' => (float) ($it['unit_price'] ?? 0), 'ext_lot_id' => isset($it['inventory_id']) ? (int) $it['inventory_id'] : null,
                    'remarks' => trim((string) ($it['remarks'] ?? '')) ?: null,
                    'new_or_used' => strtoupper(substr((string) ($it['new_or_used'] ?? ''), 0, 1)),
                    'image_url' => self::imageUrl($type, $no, $color),
                ];
            }
        }
        return $out;
    }

    /** BrickOwl liefert Positionen in mehreren Formen (Liste, unter items/data, nach ID indiziert). */
    private static function brickOwlItemList($v): array
    {
        if (!is_array($v)) return [];
        if (array_is_list($v)) {
            $out = [];
            foreach ($v as $c) $out = array_merge($out, self::brickOwlItemList($c));
            return $out;
        }
        foreach (['items', 'order_items', 'data', 'results'] as $k) {
            if (isset($v[$k]) && is_array($v[$k]) && ($n = self::brickOwlItemList($v[$k]))) return $n;
        }
        $markers = ['ordered_quantity', 'quantity', 'qty', 'base_price', 'unit_price', 'price', 'ids', 'item_no', 'boid', 'color_id', 'colour_id', 'image_small', 'image_url'];
        if (array_intersect($markers, array_keys($v))) return [$v];
        $out = [];
        foreach ($v as $c) if (is_array($c)) $out = array_merge($out, self::brickOwlItemList($c));
        return $out;
    }

    /** Wie save_brickowl_order_items im Original: BrickLink-Nummer bevorzugt, public_note = Lagerort. */
    private static function normalizeBrickOwlItems(array $entries): array
    {
        $first = function ($src, string ...$keys) {
            if (!is_array($src)) return null;
            foreach ($keys as $k) if (isset($src[$k]) && $src[$k] !== '') return $src[$k];
            return null;
        };
        $out = [];
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $d = is_array($e['item'] ?? null) ? $e['item'] : $e;
            $qty = (int) ($first($e, 'ordered_quantity', 'quantity', 'qty') ?? $first($d, 'ordered_quantity', 'quantity', 'qty') ?? 0);
            if ($qty <= 0) continue;
            // Bevorzugte Nummer: BrickLink-ID vor Design-ID; sonst Nummer in Klammern im Namen; zuletzt BO:<boid>
            $prio = ['bl_item_no' => 0, 'bricklink' => 0, 'bricklink_id' => 0, 'blid' => 0, 'item_no' => 1, 'part_no' => 1, 'number' => 1, 'design_id' => 2, 'ldraw' => 3];
            $found = [];
            $boid = null;
            foreach ([$e, $d] as $src) {
                $ids = $src['ids'] ?? [];
                if (is_array($ids) && !array_is_list($ids)) $ids = [$ids];
                foreach (is_array($ids) ? $ids : [] as $idx => $id) {
                    if (!is_array($id) || ($id['id'] ?? '') === '') continue;
                    $t = str_replace(['-', ' '], '_', strtolower(trim((string) ($id['type'] ?? ''))));
                    if (isset($prio[$t])) $found[] = [$prio[$t], count($found), (string) $id['id']];
                    elseif ($t === 'boid' && $boid === null) $boid = 'BO:' . $id['id'];
                }
            }
            usort($found, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
            $no = $found[0][2] ?? null;
            $name = (string) ($first($d, 'name', 'item_name', 'description') ?? $first($e, 'name', 'item_name', 'description') ?? '');
            if ($no === null) {
                $direct = $first($d, 'item_no', 'number', 'design_id', 'part_no', 'bricklink_id') ?? $first($e, 'item_no', 'number', 'design_id', 'part_no', 'bricklink_id');
                if ($direct !== null) $no = (string) $direct;
            }
            if ($no === null && preg_match_all('/\(([^()]*)\)/', $name, $m) && $m[1]) {
                preg_match_all('/[A-Za-z0-9_-]+/', end($m[1]), $cands);
                foreach ($cands[0] as $c) if (preg_match('/\d/', $c)) { $no = $c; break; }
            }
            if ($no === null) {
                $b = $first($d, 'boid', 'id', 'item_id') ?? $first($e, 'boid', 'id', 'item_id');
                $no = $b !== null ? "BO:$b" : $boid;
            }
            $type = strtoupper((string) ($first($d, 'type', 'item_type') ?? $first($e, 'type', 'item_type') ?? 'PART'));
            $type = ['P' => 'PART', 'M' => 'MINIFIG', 'MINIFIGURE' => 'MINIFIG', 'S' => 'SET'][$type] ?? $type;
            $color = $first($e, 'color_id', 'colour_id') ?? $first($d, 'color_id', 'colour_id');
            $cond = strtoupper((string) ($first($e, 'condition', 'new_or_used') ?? $first($d, 'condition', 'new_or_used') ?? ''));
            $out[] = [
                'item_type' => $type, 'item_no' => (string) $no, 'name' => $name,
                'color_id' => $color !== null ? (int) $color : null,
                'color_name' => (string) ($first($e, 'color_name', 'colour_name') ?? $first($d, 'color_name', 'colour_name') ?? ''),
                'qty' => $qty, 'price' => (float) ($first($e, 'base_price', 'unit_price', 'price') ?? $first($d, 'base_price', 'unit_price', 'price') ?? 0),
                'ext_lot_id' => ($l = $first($e, 'lot_id', 'lotid', 'lotId', 'inventory_id') ?? $first($d, 'lot_id', 'lotid', 'lotId', 'inventory_id')) !== null ? (int) $l : null,
                'remarks' => trim((string) ($first($e, 'public_note', 'remarks', 'personal_note', 'private_note', 'personal_notes', 'note')
                    ?? $first($d, 'public_note', 'remarks', 'personal_note', 'private_note', 'personal_notes', 'note') ?? '')) ?: null,
                'new_or_used' => str_starts_with($cond, 'N') ? 'N' : (str_starts_with($cond, 'U') ? 'U' : ''),
                'image_url' => (string) ($first($e, 'image_url', 'image_small', 'image_large') ?? $first($d, 'image_url', 'image_small', 'image_large') ?? '') ?: null,
            ];
        }
        return $out;
    }

    /**
     * Positionen speichern. IDs bleiben erhalten (Pick-Fortschritt hängt daran); fehlende Lagerorte/Zustände
     * werden aus dem eigenen Bestand bzw. dem Archiv ergänzt (BrickLink-Lot-Nr.).
     */
    public static function saveItems(int $orderId, string $platform, array $items): void
    {
        foreach ($items as &$it) {
            $it['inventory_id'] = null;
            if ($platform !== 'BrickLink' || $it['ext_lot_id'] === null) continue;
            $inv = q('SELECT i.id, i.new_or_used, l.code FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.bl_inventory_id = ?', [$it['ext_lot_id']])->fetch();
            if ($inv) $it['inventory_id'] = (int) $inv['id'];
            $meta = $inv ? ['code' => $inv['code'], 'cond' => $inv['new_or_used']]
                : (($h = q('SELECT location_code AS code, new_or_used AS cond FROM {inventory_history} WHERE bl_inventory_id = ?', [$it['ext_lot_id']])->fetch()) ?: null);
            if ($meta) {
                if (!$it['remarks']) $it['remarks'] = $meta['code'] ?: null;
                if ($it['new_or_used'] === '') $it['new_or_used'] = (string) $meta['cond'];
            }
        }
        unset($it);
        $existing = q('SELECT * FROM {order_items} WHERE order_id = ? ORDER BY id', [$orderId])->fetchAll();
        $same = fn ($r, $it, $strict) => (string) $r['item_no'] === (string) $it['item_no']
            && ((string) $r['color_id'] === (string) $it['color_id'] || mb_strtolower($r['color_name']) === mb_strtolower($it['color_name']))
            && (!$strict || (int) $r['qty'] === (int) $it['qty']);
        $unused = $existing;
        foreach ($items as $it) {
            $match = null;
            foreach ([true, false] as $strict) {
                foreach ($unused as $k => $r) {
                    if ($same($r, $it, $strict)) { $match = $k; break 2; }
                }
            }
            $vals = [$it['inventory_id'], $it['item_type'], $it['item_no'], $it['color_id'], $it['color_name'], $it['name'] ?: $it['item_no'],
                $it['qty'], $it['price'], $it['new_or_used'], $it['remarks'], $it['ext_lot_id'], $it['image_url']];
            if ($match !== null) {
                $r = $unused[$match];
                unset($unused[$match]);
                // Bekannte Lagerorte nie durch leere API-Werte verlieren
                if (!$it['remarks']) $vals[9] = $r['remarks'];
                if ($it['new_or_used'] === '') $vals[8] = $r['new_or_used'];
                q('UPDATE {order_items} SET inventory_id = ?, item_type = ?, item_no = ?, color_id = ?, color_name = ?, name = ?, qty = ?, price = ?,
                    new_or_used = ?, remarks = ?, ext_lot_id = ?, image_url = ? WHERE id = ?', [...$vals, $r['id']]);
            } else {
                q('INSERT INTO {order_items} (inventory_id, item_type, item_no, color_id, color_name, name, qty, price, new_or_used, remarks,
                    ext_lot_id, image_url, order_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $orderId]);
            }
        }
        foreach ($unused as $r) {
            if (!q('SELECT 1 FROM {pick_progress} WHERE order_item_id = ?', [$r['id']])->fetch()) {
                q('DELETE FROM {order_items} WHERE id = ?', [$r['id']]);
            }
        }
    }
}
