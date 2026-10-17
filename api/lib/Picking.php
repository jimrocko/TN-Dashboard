<?php

/**
 * Pickliste: Aufträge kommissionieren, Positionen vormerken, Fehler melden, Fortschritt und Statistik.
 * Nachbau von app/picking/service.py aus dem bestehenden Programm (gleiche Regeln und Reihenfolge).
 * Picker ist immer der angemeldete Benutzer. Die Seite fragt nie BrickLink/BrickOwl ab.
 * Gleiche Logik im Demo-Backend: frontend/src/demo/picking.js.
 */
final class Picking
{
    /** Offen für die Pickliste (entspricht PROCESSING/READY/PAID/PACKED bzw. bei BrickOwl „bezahlt“). */
    public const OPEN = ['Bezahlt', 'Verpackt'];
    public const ISSUES = ['missing', 'wrong_location', 'wrong_quantity', 'wrong_item', 'other'];

    /** BrickOwl-Farbnamen auf BrickLink-Namen abbilden (für die Zuordnung zum Bestand). */
    private const BO_COLORS = [
        'dark stone gray' => 'Dark Bluish Gray', 'medium stone gray' => 'Light Bluish Gray', 'light stone gray' => 'Very Light Bluish Gray',
        'transparent' => 'Trans-Clear', 'transparent clear' => 'Trans-Clear', 'transparent light blue' => 'Trans-Light Blue',
        'transparent dark blue' => 'Trans-Dark Blue', 'transparent red' => 'Trans-Red', 'transparent green' => 'Trans-Green',
        'transparent yellow' => 'Trans-Yellow', 'transparent orange' => 'Trans-Orange', 'transparent neon green' => 'Trans-Neon Green',
        'transparent neon orange' => 'Trans-Neon Orange', 'transparent bright green' => 'Trans-Bright Green',
        'transparent purple' => 'Trans-Purple', 'transparent pink' => 'Trans-Dark Pink',
    ];

    // ---------------------------------------------------------------- Hilfen

    /** Natürliche Reihenfolge wie _natural() im Original: Zahlenblöcke als Zahl, Text klein geschrieben. */
    public static function naturalCmp(string $a, string $b): int
    {
        $pa = preg_split('/(\d+)/', $a, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pb = preg_split('/(\d+)/', $b, -1, PREG_SPLIT_DELIM_CAPTURE);
        $n = min(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            if ($i % 2) {
                $x = ltrim($pa[$i], '0');
                $y = ltrim($pb[$i], '0');
                $c = strlen($x) <=> strlen($y) ?: strcmp($x, $y);
            } else {
                $c = strcmp(mb_strtolower($pa[$i]), mb_strtolower($pb[$i]));
            }
            if ($c) return $c < 0 ? -1 : 1;
        }
        return count($pa) <=> count($pb);
    }

    public static function conditionLabel(string $v): string
    {
        $u = strtoupper(trim($v));
        if (in_array($u, ['N', 'NEW', 'NEU'], true) || str_starts_with($u, 'NEW')) return 'Neu';
        if (in_array($u, ['U', 'USED', 'GEBRAUCHT'], true) || str_starts_with($u, 'USED')) return 'Gebraucht';
        return trim($v);
    }

    private static function conditionCode(string $v): string
    {
        $u = strtoupper(trim($v));
        if ($u === 'N' || str_starts_with($u, 'NEW') || $u === 'NEU') return 'N';
        if ($u === 'U' || str_starts_with($u, 'USED') || $u === 'GEBRAUCHT') return 'U';
        return '';
    }

    /** Nur plausible Lagerorte aus Auftrags-Bemerkungen (G386, FG03, Akte01, Plate-G01, „G562 A004“, Regal). */
    private static function looksLikeLocation(string $v): bool
    {
        $v = trim($v);
        if ($v === '') return false;
        if (mb_strtolower($v) === 'regal') return true;
        $simple = '[A-Za-z]+[0-9]+';
        $plate = '[A-Za-z]+-[A-Za-z]+[0-9]+';
        return (bool) preg_match("/^(?:$simple|$plate|(?:$simple|$plate)\\s+(?:$simple|$plate))$/", $v);
    }

    private static function revision(): int
    {
        return (int) Dev::setting('pick_revision');
    }

    private static function bump(int $orderId): int
    {
        if (!q('SELECT 1 FROM {pick_order_state} WHERE order_id = ?', [$orderId])->fetch()) {
            q('INSERT INTO {pick_order_state} (order_id, revision, completed) VALUES (?, 0, 0)', [$orderId]);
        }
        q('UPDATE {pick_order_state} SET revision = revision + 1 WHERE order_id = ?', [$orderId]);
        Dev::setSetting('pick_revision', (string) (self::revision() + 1));
        return (int) q('SELECT revision FROM {pick_order_state} WHERE order_id = ?', [$orderId])->fetchColumn();
    }

    private static function state(int $orderId): array
    {
        $s = q('SELECT revision, completed FROM {pick_order_state} WHERE order_id = ?', [$orderId])->fetch();
        return ['revision' => (int) ($s['revision'] ?? 0), 'completed' => (bool) ($s['completed'] ?? false)];
    }

    private static function itemOrder(int $itemId): array
    {
        $r = q('SELECT o.id, o.status FROM {order_items} x JOIN {orders} o ON o.id = x.order_id WHERE x.id = ?', [$itemId])->fetch();
        if (!$r) json_error('Position nicht gefunden', 400);
        return $r;
    }

    private static function userName(?int $id): ?string
    {
        return $id ? (q('SELECT display_name FROM {users} WHERE id = ?', [$id])->fetchColumn() ?: null) : null;
    }

    private static function tx(callable $fn): mixed
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- Übersicht

    public static function overviewRevision(): array
    {
        return ['revision' => self::revision()];
    }

    public static function orderRevision(int $id): array
    {
        return ['revision' => self::state($id)['revision']];
    }

    /** Offene Aufträge, älteste zuerst, mit Fortschritt und Pickern. */
    public static function openOrders(): array
    {
        $in = implode(',', array_fill(0, count(self::OPEN), '?'));
        $rows = q("SELECT o.id, o.platform, o.order_no, o.buyer, o.status, o.platform_status, o.ordered_at,
                COUNT(x.id) AS positions, COALESCE(SUM(x.qty), 0) AS parts,
                COALESCE(SUM(CASE WHEN pp.picked = 1 THEN 1 ELSE 0 END), 0) AS picked_positions
            FROM {orders} o LEFT JOIN {order_items} x ON x.order_id = o.id LEFT JOIN {pick_progress} pp ON pp.order_item_id = x.id
            WHERE o.status IN ($in) GROUP BY o.id, o.platform, o.order_no, o.buyer, o.status, o.platform_status, o.ordered_at
            ORDER BY o.ordered_at ASC, o.id ASC", self::OPEN)->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $pickers = q('SELECT DISTINCT u.id, u.display_name FROM {order_items} x JOIN {pick_progress} pp ON pp.order_item_id = x.id
                JOIN {users} u ON u.id = pp.user_id WHERE x.order_id = ? AND pp.picked = 1 ORDER BY u.display_name', [$id])->fetchAll();
            $st = self::state($id);
            $pos = (int) $r['positions'];
            $picked = (int) $r['picked_positions'];
            $out[] = [
                'id' => $id, 'platform' => $r['platform'], 'order_no' => $r['order_no'], 'buyer_name' => $r['buyer'] !== '' ? $r['buyer'] : null,
                'status' => $r['platform_status'] ?: $r['status'], 'date_ordered' => $r['ordered_at'],
                'positions' => $pos, 'parts' => (int) $r['parts'], 'picked_positions' => $picked,
                'percent' => $pos ? (int) round(100 * $picked / $pos) : 0,
                'picker_names' => array_column($pickers, 'display_name'), 'picker_ids' => array_map('intval', array_column($pickers, 'id')),
                'completed' => $st['completed'], 'revision' => $st['revision'],
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- Detail

    /** Lots aus dem aktuellen Bestand: gleiches Teil, gleiche Farbe (BrickOwl: Farbname), gleicher Zustand. */
    private static function candidates(array $row): array
    {
        $cond = self::conditionCode((string) $row['new_or_used']);
        if ($row['platform'] === 'BrickOwl') {
            $color = self::BO_COLORS[mb_strtolower(trim((string) $row['color_name']))] ?? trim((string) $row['color_name']);
            $extra = $cond !== '' ? ' AND UPPER(new_or_used) = ?' : '';
            $p = $cond !== '' ? [$row['item_no'], $color, $cond] : [$row['item_no'], $color];
            $current = q("SELECT i.id, i.bl_inventory_id, l.code AS remarks, i.qty AS quantity, i.new_or_used, i.price AS unit_price
                FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id
                WHERE i.item_no = ? AND LOWER(i.color_name) = LOWER(?)$extra ORDER BY i.bl_inventory_id, i.id", $p)->fetchAll();
            $history = q("SELECT NULL AS id, bl_inventory_id, location_code AS remarks, qty AS quantity, new_or_used, price AS unit_price
                FROM {inventory_history} WHERE item_no = ? AND LOWER(color_name) = LOWER(?)$extra ORDER BY bl_inventory_id", $p)->fetchAll();
            $byId = [];
            foreach ($history as $h) $byId['b' . $h['bl_inventory_id']] = $h + ['current' => false];
            foreach ($current as $c) $byId[$c['bl_inventory_id'] !== null ? 'b' . $c['bl_inventory_id'] : 'i' . $c['id']] = $c + ['current' => true];
            $cands = array_values($byId);
            $price = (float) $row['price'];
            if ($price > 0 && count($cands) > 1) {
                $exact = $near = [];
                foreach ($cands as $c) {
                    $diff = abs((float) $c['unit_price'] - $price);
                    if ($diff <= 0.0006) $exact[] = $c;
                    elseif ($diff <= max(0.003, $price * 0.05)) $near[] = $c;
                }
                if ($exact) $cands = $exact;
                elseif (count($near) === 1) $cands = $near;
            }
            return $cands;
        }
        $raw = strtoupper(trim((string) $row['new_or_used']));
        $extra = in_array($raw, ['N', 'U'], true) ? ' AND UPPER(i.new_or_used) = ?' : '';
        $p = [$row['item_no'], (int) $row['color_id']];
        if ($extra) $p[] = $raw;
        $colorSql = $row['color_id'] === null ? 'i.color_id IS NULL' : 'i.color_id = ?';
        if ($row['color_id'] === null) array_splice($p, 1, 1);
        return array_map(fn ($c) => $c + ['current' => true], q("SELECT i.id, i.bl_inventory_id, l.code AS remarks, i.qty AS quantity, i.new_or_used, i.price AS unit_price
            FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.item_no = ? AND $colorSql$extra ORDER BY i.id", $p)->fetchAll());
    }

    /** Lagerort, Zustand, Bild und Restbestand ergänzen (gleiche Rangfolge wie _enrich_item im Original). */
    private static function enrich(array $d): array
    {
        $inv = $hist = null;
        $isBo = $d['platform'] === 'BrickOwl';
        if (!$isBo && $d['inventory_id'] !== null) {
            $inv = q('SELECT i.id, l.code AS remarks, i.qty AS quantity, i.new_or_used FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.id = ?', [$d['inventory_id']])->fetch() ?: null;
        }
        if (!$isBo && !$inv && $d['ext_lot_id'] !== null) {
            $hist = q('SELECT location_code AS remarks, qty AS quantity, new_or_used FROM {inventory_history} WHERE bl_inventory_id = ?', [$d['ext_lot_id']])->fetch() ?: null;
        }
        if (!$isBo && !$inv && $d['inventory_id'] === null && $d['ext_lot_id'] !== null) {
            $inv = q('SELECT i.id, l.code AS remarks, i.qty AS quantity, i.new_or_used FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE i.bl_inventory_id = ?', [$d['ext_lot_id']])->fetch() ?: null;
            if ($inv) $hist = null;
        }
        $cands = $inv ? [$inv] : self::candidates($d);
        $distinct = fn (string $f, callable $map = null) => array_values(array_unique(array_filter(array_map(
            fn ($x) => $map ? $map(trim((string) $x[$f])) : trim((string) $x[$f]), $cands), fn ($v) => $v !== '')));

        $location = trim((string) $d['remarks']);
        if ($location === '' && $inv) $location = trim((string) $inv['remarks']);
        if ($location === '' && $hist) $location = trim((string) $hist['remarks']);
        if ($location === '' && $cands) {
            $locs = $distinct('remarks');
            if (count($locs) === 1) $location = $locs[0];
        }
        $cond = trim((string) $d['new_or_used']);
        if ($cond === '' && $inv) $cond = trim((string) $inv['new_or_used']);
        if ($cond === '' && $hist) $cond = trim((string) $hist['new_or_used']);
        if ($cond === '' && $cands) {
            $conds = $distinct('new_or_used', 'strtoupper');
            if (count($conds) === 1) $cond = $conds[0];
        }
        $img = trim((string) $d['image_url']);
        $type = strtoupper(trim((string) $d['item_type']));
        if ($img === '' && in_array($type, ['P', 'PART'], true) && $d['item_no'] !== '' && $d['color_id'] !== null) {
            $img = "https://img.bricklink.com/ItemImage/PN/{$d['color_id']}/" . rawurlencode($d['item_no']) . '.png';
        }
        // Ist das Lot nicht mehr im Bestand, ist es für die Pickliste ausverkauft: Rest 0
        $rest = 0;
        if ($inv) {
            $rest = (int) $inv['quantity'];
        } elseif ($cands) {
            $locs = $distinct('remarks');
            $conds = array_values(array_unique(array_filter(array_map(fn ($x) => self::conditionCode((string) $x['new_or_used']), $cands))));
            if (count($locs) <= 1 && count($conds) <= 1) {
                foreach ($cands as $c) {
                    if (!$isBo || $c['current']) $rest += (int) $c['quantity'];
                }
            }
        }
        $d['location'] = $location;
        $d['remaining_stock'] = $rest;
        $d['condition'] = self::conditionLabel($cond);
        $d['new_or_used'] = $cond;
        $d['image_url'] = $img;
        return $d;
    }

    /** Sortierung nach frei festgelegter Bereichsreihenfolge: längstes passendes Präfix gewinnt, dann natürlich. */
    private static function sortItems(array &$items): void
    {
        $groups = q('SELECT location_group FROM {pick_location_order} ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
        $map = [];
        foreach ($groups as $i => $g) {
            $k = mb_strtolower(trim((string) $g));
            if (!array_key_exists($k, $map)) $map[$k] = $i;
        }
        $rank = function (string $loc) use ($map) {
            $key = mb_strtolower(trim($loc));
            $best = null;
            foreach ($map as $area => $r) {
                if ($area !== '' && str_starts_with($key, (string) $area)) {
                    $len = mb_strlen((string) $area);
                    if ($best === null || $len > $best[0] || ($len === $best[0] && $r < $best[1])) $best = [$len, $r];
                }
            }
            return $best ? $best[1] : count($map) + 1000;
        };
        foreach ($items as &$it) $it['_rank'] = $rank((string) $it['location']);
        unset($it);
        usort($items, fn ($a, $b) => $a['_rank'] <=> $b['_rank'] ?: self::naturalCmp(trim((string) $a['location']), trim((string) $b['location'])));
        foreach ($items as &$it) unset($it['_rank']);
    }

    public static function detail(int $id, int $userId): ?array
    {
        $o = q('SELECT id, platform, order_no, buyer, status, platform_status, ordered_at, total, currency, shipping FROM {orders} WHERE id = ?', [$id])->fetch();
        if (!$o) return null;
        $st = self::state($id);
        $order = [
            'id' => (int) $o['id'], 'platform' => $o['platform'], 'order_no' => $o['order_no'], 'buyer_name' => $o['buyer'] !== '' ? $o['buyer'] : null,
            'status' => $o['platform_status'] ?: $o['status'], 'date_ordered' => $o['ordered_at'], 'grand_total' => round((float) $o['total'], 2),
            'currency_code' => $o['currency'] ?: 'EUR', 'completed' => $st['completed'], 'revision' => $st['revision'],
        ];
        $rows = q('SELECT x.id, x.inventory_id, x.item_type, x.item_no, x.color_id, x.color_name, x.name AS item_name, x.qty AS quantity,
                x.price, x.new_or_used, x.remarks, x.ext_lot_id, x.image_url, o.platform,
                pp.picked, pp.user_id AS picker_id, pp.picked_at, pu.display_name AS picker_name,
                c.user_id AS claim_picker_id, cu.display_name AS claim_picker_name
            FROM {order_items} x JOIN {orders} o ON o.id = x.order_id
            LEFT JOIN {pick_progress} pp ON pp.order_item_id = x.id LEFT JOIN {users} pu ON pu.id = pp.user_id
            LEFT JOIN {pick_claims} c ON c.order_item_id = x.id LEFT JOIN {users} cu ON cu.id = c.user_id
            WHERE x.order_id = ? ORDER BY x.id', [$id])->fetchAll();
        $reports = [];
        foreach (q("SELECT id, order_item_id, issue_type, details, missing_quantity, found_location, actual_quantity, created_at
            FROM {pick_reports} WHERE order_id = ? AND user_id = ? AND status = 'OPEN' ORDER BY created_at, id", [$id, $userId])->fetchAll() as $r) {
            $reports[(int) $r['order_item_id']][] = typed([$r], ['id', 'order_item_id', 'missing_quantity', 'actual_quantity'])[0];
        }
        $items = [];
        foreach ($rows as $r) {
            $r = typed([$r], ['id', 'inventory_id', 'color_id', 'quantity', 'ext_lot_id', 'picker_id', 'claim_picker_id'], ['price'])[0];
            $r['picked'] = (bool) $r['picked'];
            $it = self::enrich($r);
            unset($it['platform']);
            $it['reports'] = $reports[$it['id']] ?? [];
            $it['report_count'] = count($it['reports']);
            $items[] = $it;
        }
        self::sortItems($items);
        $names = [];
        foreach ($items as $it) {
            if ($it['picked'] && $it['picker_name'] && !in_array($it['picker_name'], $names, true)) $names[] = $it['picker_name'];
        }
        return ['order' => $order, 'items' => $items, 'picker_names' => $names, 'shipping' => json_decode((string) $o['shipping'], true) ?: (object) [],
            'sender' => config()['shop']['sender'] ?? []];
    }

    // ---------------------------------------------------------------- Aktionen

    public static function complete(int $id): array
    {
        return self::tx(function () use ($id) {
            if (!q('SELECT 1 FROM {orders} WHERE id = ?', [$id])->fetch()) json_error('Bestellung nicht gefunden', 404);
            $r = q('SELECT COUNT(*) AS positions, SUM(CASE WHEN pp.picked = 1 THEN 1 ELSE 0 END) AS picked
                FROM {order_items} x LEFT JOIN {pick_progress} pp ON pp.order_item_id = x.id WHERE x.order_id = ?', [$id])->fetch();
            if (!(int) $r['positions'] || (int) $r['positions'] !== (int) $r['picked']) {
                json_error('Zum Abschließen müssen Positionen vorhanden und vollständig gepickt sein.', 409);
            }
            self::bump($id);
            q('UPDATE {pick_order_state} SET completed = 1 WHERE order_id = ?', [$id]);
            return ['completed' => true, 'revision' => self::state($id)['revision']];
        });
    }

    public static function reopen(int $id): array
    {
        return self::tx(function () use ($id) {
            if (!self::state($id)['completed']) json_error('Bestellung ist nicht abgeschlossen.', 409);
            q('UPDATE {pick_order_state} SET completed = 0 WHERE order_id = ?', [$id]);
            return ['completed' => false, 'revision' => self::bump($id)];
        });
    }

    /** Position gepickt / zurücksetzen. Nur wer gepickt hat, darf zurücksetzen; Vormerkungen anderer sperren. */
    public static function toggle(int $itemId, array $user): array
    {
        return self::tx(function () use ($itemId, $user) {
            $uid = (int) $user['id'];
            $o = self::itemOrder($itemId);
            if (self::state((int) $o['id'])['completed']) json_error("Bestellung ist erledigt. Bitte zuerst 'Bestellung ändern' wählen.", 400);
            $claim = q('SELECT user_id FROM {pick_claims} WHERE order_item_id = ?', [$itemId])->fetchColumn();
            if ($claim && (int) $claim !== $uid) {
                json_error('Diese Position ist für ' . (self::userName((int) $claim) ?? 'einen anderen Picker') . ' vorgemerkt.', 403);
            }
            $p = q('SELECT picked, user_id FROM {pick_progress} WHERE order_item_id = ?', [$itemId])->fetch();
            if ($p && $p['picked']) {
                if ((int) $p['user_id'] !== $uid) {
                    json_error('Diese Position wurde von ' . (self::userName((int) $p['user_id']) ?? 'einem anderen Picker')
                        . ' gepickt und kann nur von diesem Picker zurückgesetzt werden.', 403);
                }
                $new = 0;
            } else {
                $new = 1;
            }
            $t = now();
            if ($new) q('DELETE FROM {pick_claims} WHERE order_item_id = ? AND user_id = ?', [$itemId, $uid]);
            q('DELETE FROM {pick_progress} WHERE order_item_id = ?', [$itemId]);
            q('INSERT INTO {pick_progress} (order_item_id, picked, user_id, picked_at) VALUES (?, ?, ?, ?)', [$itemId, $new, $new ? $uid : null, $new ? $t : null]);
            q('INSERT INTO {pick_events} (order_item_id, user_id, action, created_at) VALUES (?, ?, ?, ?)', [$itemId, $uid, $new ? 'picked' : 'reopened', $t]);
            self::bump((int) $o['id']);
            return ['item_id' => $itemId, 'picked' => (bool) $new, 'picker_id' => $new ? $uid : null,
                'picker_name' => $new ? $user['display_name'] : null, 'picked_at' => $new ? $t : null, 'order_id' => (int) $o['id']];
        });
    }

    /** Position für sich vormerken (Markieren-Modus) oder Vormerkung aufheben. */
    public static function claim(int $itemId, array $user): array
    {
        return self::tx(function () use ($itemId, $user) {
            $uid = (int) $user['id'];
            $o = self::itemOrder($itemId);
            if (self::state((int) $o['id'])['completed']) json_error("Bestellung ist erledigt. Bitte zuerst 'Bestellung ändern' wählen.", 400);
            if (q('SELECT picked FROM {pick_progress} WHERE order_item_id = ?', [$itemId])->fetchColumn()) json_error('Position ist bereits gepickt', 400);
            $owner = q('SELECT user_id FROM {pick_claims} WHERE order_item_id = ?', [$itemId])->fetchColumn();
            if ($owner) {
                if ((int) $owner !== $uid) {
                    json_error('Diese Position ist bereits für ' . (self::userName((int) $owner) ?? 'einen anderen Picker') . ' vorgemerkt.', 409);
                }
                q('DELETE FROM {pick_claims} WHERE order_item_id = ?', [$itemId]);
                self::bump((int) $o['id']);
                return ['item_id' => $itemId, 'claimed' => false, 'picker_id' => null, 'picker_name' => null];
            }
            $t = now();
            q('INSERT INTO {pick_claims} (order_item_id, order_id, user_id, claimed_at) VALUES (?, ?, ?, ?)', [$itemId, $o['id'], $uid, $t]);
            self::bump((int) $o['id']);
            return ['item_id' => $itemId, 'claimed' => true, 'picker_id' => $uid, 'picker_name' => $user['display_name'], 'claimed_at' => $t];
        });
    }

    public static function report(int $itemId, int $userId, array $in): array
    {
        $type = trim((string) ($in['issue_type'] ?? ''));
        if (!in_array($type, self::ISSUES, true)) json_error('Meldegrund ungültig');
        $details = trim((string) ($in['details'] ?? '')) ?: null;
        if ($details !== null && mb_strlen($details) > 500) json_error('Die Notiz darf höchstens 500 Zeichen enthalten.');
        $missing = max(1, (int) ($in['missing_quantity'] ?? 1) ?: 1);
        $found = trim((string) ($in['found_location'] ?? '')) ?: null;
        $actual = isset($in['actual_quantity']) && is_numeric($in['actual_quantity']) ? max(0, (int) $in['actual_quantity']) : null;
        return self::tx(function () use ($itemId, $userId, $type, $details, $missing, $found, $actual) {
            $o = self::itemOrder($itemId);
            $t = now();
            q('INSERT INTO {pick_reports} (order_id, order_item_id, user_id, issue_type, details, missing_quantity, found_location, actual_quantity, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$o['id'], $itemId, $userId, $type, $details, $missing, $found ? mb_substr($found, 0, 64) : null, $actual, 'OPEN', $t]);
            $rid = (int) db()->lastInsertId();
            self::bump((int) $o['id']);
            $count = (int) q("SELECT COUNT(*) FROM {pick_reports} WHERE order_item_id = ? AND user_id = ? AND status = 'OPEN'", [$itemId, $userId])->fetchColumn();
            return ['id' => $rid, 'item_id' => $itemId, 'issue_type' => $type, 'report_count' => $count, 'created_at' => $t, 'order_id' => (int) $o['id']];
        });
    }

    /** Eigene Meldung schließen. */
    public static function resolve(int $reportId, int $userId): array
    {
        return self::tx(function () use ($reportId, $userId) {
            $r = q("SELECT order_id FROM {pick_reports} WHERE id = ? AND user_id = ? AND status = 'OPEN'", [$reportId, $userId])->fetch();
            if (!$r) json_error('Meldung nicht gefunden', 400);
            q("UPDATE {pick_reports} SET status = 'RESOLVED' WHERE id = ?", [$reportId]);
            self::bump((int) $r['order_id']);
            return ['id' => $reportId, 'resolved' => true];
        });
    }

    // ---------------------------------------------------------------- Lagerbereiche (Reihenfolge)

    public static function locationOrder(): array
    {
        return typed(q('SELECT id, location_group, sort_order FROM {pick_location_order} ORDER BY sort_order, id')->fetchAll(), ['id', 'sort_order']);
    }

    public static function saveLocationOrder(array $groups): array
    {
        $clean = [];
        $seen = [];
        foreach ($groups as $g) {
            $v = trim((string) $g);
            $k = mb_strtolower($v);
            if ($v !== '' && !isset($seen[$k])) {
                $seen[$k] = true;
                $clean[] = mb_substr($v, 0, 64);
            }
        }
        self::tx(function () use ($clean) {
            q('DELETE FROM {pick_location_order}');
            foreach ($clean as $i => $v) q('INSERT INTO {pick_location_order} (location_group, sort_order) VALUES (?, ?)', [$v, ($i + 1) * 10]);
        });
        return self::locationOrder();
    }

    /** Alle bekannten Lagerorte (Bestand, Archiv, plausible Auftrags-Bemerkungen), natürlich sortiert. */
    public static function availableLocations(): array
    {
        $vals = [];
        foreach (q('SELECT DISTINCT l.code FROM {inventory} i JOIN {locations} l ON l.id = i.location_id')->fetchAll(PDO::FETCH_COLUMN) as $v) $vals[trim($v)] = true;
        foreach (q("SELECT DISTINCT location_code FROM {inventory_history} WHERE location_code IS NOT NULL AND location_code <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) $vals[trim($v)] = true;
        foreach (q("SELECT DISTINCT remarks FROM {order_items} WHERE remarks IS NOT NULL AND remarks <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) {
            if (self::looksLikeLocation($v)) $vals[trim($v)] = true;
        }
        unset($vals['']);
        $out = array_map('strval', array_keys($vals));
        usort($out, [self::class, 'naturalCmp']);
        return $out;
    }

    /** Nur Pick-Verlauf löschen (Aufträge, Bestand und Bereiche bleiben). */
    public static function resetTestData(): array
    {
        return self::tx(function () {
            $out = ['ok' => true];
            foreach (['events' => 'pick_events', 'progress' => 'pick_progress', 'reports' => 'pick_reports', 'claims' => 'pick_claims'] as $k => $tb) {
                $out["deleted_$k"] = (int) q("SELECT COUNT(*) FROM {{$tb}}")->fetchColumn();
                q("DELETE FROM {{$tb}}");
            }
            q('UPDATE {pick_order_state} SET completed = 0');
            Dev::setSetting('pick_revision', (string) (self::revision() + 1));
            return $out;
        });
    }

    // ---------------------------------------------------------------- Statistik

    /** Auswertung des Pick-Protokolls (wie picker_stats im Original). */
    public static function stats(?string $from, ?string $to, ?int $pickerId, ?string $platform): array
    {
        $today = date('Y-m-d');
        $dFrom = $from && strtotime($from) ? date('Y-m-d', strtotime($from)) : date('Y-m-01');
        $dTo = $to && strtotime($to) ? date('Y-m-d', strtotime($to)) : $today;
        if ($dTo < $dFrom) [$dFrom, $dTo] = [$dTo, $dFrom];
        $start = "$dFrom 00:00:00";
        $end = date('Y-m-d', strtotime("$dTo +1 day")) . ' 00:00:00';
        $platform = trim((string) $platform) ?: null;
        $pickerId = $pickerId ?: null;

        $where = ['e.created_at >= ?', 'e.created_at < ?'];
        $args = [$start, $end];
        if ($pickerId) { $where[] = 'e.user_id = ?'; $args[] = $pickerId; }
        if ($platform) { $where[] = 'o.platform = ?'; $args[] = $platform; }
        $events = q('SELECT e.id, e.order_item_id, e.user_id AS picker_id, e.action, e.created_at, u.display_name AS picker_name,
                x.order_id, o.platform, COALESCE(x.qty, 0) AS quantity
            FROM {pick_events} e JOIN {order_items} x ON x.id = e.order_item_id JOIN {orders} o ON o.id = x.order_id
            LEFT JOIN {users} u ON u.id = e.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY e.created_at, e.id', $args)->fetchAll();
        $picked = array_values(array_filter($events, fn ($e) => $e['action'] === 'picked'));
        $reopened = array_values(array_filter($events, fn ($e) => $e['action'] === 'reopened'));
        $touched = [];
        foreach ($events as $e) $touched[(int) $e['order_id']] = true;

        $completedRaw = q('SELECT x.order_id, COUNT(*) AS positions, SUM(CASE WHEN pp.picked = 1 THEN 1 ELSE 0 END) AS picked_positions,
                MAX(pp.picked_at) AS completed_at
            FROM {order_items} x JOIN {orders} o ON o.id = x.order_id LEFT JOIN {pick_progress} pp ON pp.order_item_id = x.id'
            . ($platform ? ' WHERE o.platform = ?' : '') . '
            GROUP BY x.order_id HAVING COUNT(*) > 0 AND SUM(CASE WHEN pp.picked = 1 THEN 1 ELSE 0 END) = COUNT(*)', $platform ? [$platform] : [])->fetchAll();
        $firstPick = fn (int $oid) => q("SELECT MIN(e.created_at) FROM {pick_events} e JOIN {order_items} x ON x.id = e.order_item_id
            WHERE x.order_id = ? AND e.action = 'picked'", [$oid])->fetchColumn() ?: null;
        $completed = [];
        foreach ($completedRaw as $c) {
            $at = $c['completed_at'];
            if (!$at || !($start <= $at && $at < $end)) continue;
            $oid = (int) $c['order_id'];
            if ($pickerId && !q("SELECT 1 FROM {pick_events} e JOIN {order_items} x ON x.id = e.order_item_id
                WHERE x.order_id = ? AND e.user_id = ? AND e.action = 'picked'", [$oid, $pickerId])->fetch()) continue;
            $first = $firstPick($oid);
            $completed[$oid] = ['order_id' => $oid, 'completed_at' => $at, 'first_pick_at' => $first,
                'duration_seconds' => $first ? max(0, strtotime($at) - strtotime($first)) : null];
        }
        $durations = array_values(array_filter(array_column($completed, 'duration_seconds'), fn ($v) => $v !== null));

        $pickers = [];
        $row = fn ($id, $name) => ['picker_id' => $id, 'picker_name' => $name, 'positions' => 0, 'parts' => 0, 'corrections' => 0, 'orders' => [], 'completed_orders' => 0];
        foreach ($events as $e) {
            $pid = (int) $e['picker_id'];
            if (!$pid) continue;
            $pickers[$pid] ??= $row($pid, $e['picker_name'] ?: "Picker $pid");
            $pickers[$pid]['orders'][(int) $e['order_id']] = true;
            if ($e['action'] === 'picked') { $pickers[$pid]['positions']++; $pickers[$pid]['parts'] += (int) $e['quantity']; }
            elseif ($e['action'] === 'reopened') $pickers[$pid]['corrections']++;
        }
        if (!$pickerId) {
            foreach (q("SELECT id, display_name FROM {users} WHERE active = 1 ORDER BY display_name")->fetchAll() as $u) {
                $pickers[(int) $u['id']] ??= $row((int) $u['id'], $u['display_name']);
            }
        }
        foreach (array_keys($completed) as $oid) {
            foreach (q("SELECT DISTINCT e.user_id FROM {pick_events} e JOIN {order_items} x ON x.id = e.order_item_id
                WHERE x.order_id = ? AND e.action = 'picked'", [$oid])->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                if (isset($pickers[(int) $pid])) $pickers[(int) $pid]['completed_orders']++;
            }
        }
        $pickers = array_map(fn ($p) => ['orders' => count($p['orders'])] + $p, array_values($pickers));
        usort($pickers, fn ($a, $b) => $b['positions'] <=> $a['positions'] ?: $b['parts'] <=> $a['parts']
            ?: strcmp(mb_strtolower($a['picker_name']), mb_strtolower($b['picker_name'])));

        $orders = [];
        foreach (array_keys($touched + $completed) as $oid) {
            $o = q('SELECT platform, order_no, buyer, status, platform_status, ordered_at FROM {orders} WHERE id = ?', [$oid])->fetch();
            $cnt = q('SELECT COUNT(*) AS positions, COALESCE(SUM(x.qty), 0) AS parts, SUM(CASE WHEN pp.picked = 1 THEN 1 ELSE 0 END) AS picked_positions
                FROM {order_items} x LEFT JOIN {pick_progress} pp ON pp.order_item_id = x.id WHERE x.order_id = ?', [$oid])->fetch();
            $names = q("SELECT DISTINCT u.display_name FROM {pick_events} e JOIN {order_items} x ON x.id = e.order_item_id
                LEFT JOIN {users} u ON u.id = e.user_id WHERE x.order_id = ? AND e.action = 'picked' AND u.display_name IS NOT NULL
                ORDER BY u.display_name", [$oid])->fetchAll(PDO::FETCH_COLUMN);
            $c = $completed[$oid] ?? null;
            $orders[] = [
                'id' => $oid, 'platform' => $o['platform'] ?? null, 'order_no' => $o['order_no'] ?? null,
                'buyer_name' => ($o['buyer'] ?? '') !== '' ? $o['buyer'] : null,
                'status' => $o ? ($o['platform_status'] ?: $o['status']) : null, 'date_ordered' => $o['ordered_at'] ?? null,
                'positions' => (int) $cnt['positions'], 'parts' => (int) $cnt['parts'], 'picked_positions' => (int) $cnt['picked_positions'],
                'picker_names' => $names, 'first_pick_at' => $firstPick($oid),
                'completed_at' => $c['completed_at'] ?? null, 'duration_seconds' => $c['duration_seconds'] ?? null, 'complete' => (bool) $c,
            ];
        }
        usort($orders, fn ($a, $b) => strcmp((string) $b['first_pick_at'], (string) $a['first_pick_at'])
            ?: strcmp((string) $b['platform'], (string) $a['platform']) ?: strcmp((string) $b['order_no'], (string) $a['order_no']));
        return [
            'period' => ['from' => $dFrom, 'to' => $dTo],
            'filters' => ['picker_id' => $pickerId, 'platform' => $platform],
            'summary' => [
                'orders' => count($touched), 'completed_orders' => count($completed), 'positions' => count($picked),
                'parts' => array_sum(array_map(fn ($e) => (int) $e['quantity'], $picked)), 'corrections' => count($reopened),
                'avg_duration_seconds' => $durations ? (int) round(array_sum($durations) / count($durations)) : null,
            ],
            'pickers' => $pickers,
            'orders' => $orders,
        ];
    }
}
