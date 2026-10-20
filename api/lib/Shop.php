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
// Mischkiste: Kästchen in Bereich G mit mehr als 3 verschiedenen Lots (Regel des Inhabers)
const MIXED_AREA_PATTERN = '/^G\\d+$/i';
const MIXED_MAX_LOTS = 3;
// Beim Zusammenlegen von Farben bekommt ein Kästchen höchstens so viele Lots
const SLOT_MAX_LOTS = 3;
// Fast leeres Kästchen: nur ein Lot mit höchstens so vielen Teilen (Vorschlag „Kästchen leeren“)
const REST_MAX_QTY = 3;
const ORDER_ACTIVE = ['Neu', 'Bezahlt', 'Verpackt'];

final class Shop
{
    private const LOT_SELECT = 'SELECT i.id, i.item_type, i.item_no, i.color_id, i.color_name, i.name, i.category,
            i.location_id, i.qty, i.price, i.updated_at, i.new_or_used, l.code AS location_code, l.kind AS location_kind
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
        if (!empty($f['id'])) { // ein bestimmtes Lot (z. B. Umlagern aus dem Inventar)
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
        // Zahlen je Kategorie für die Kategorien-Ansicht im Inventar
        $cats = q('SELECT category, COUNT(*) AS lots, SUM(qty) AS qty, ROUND(SUM(qty * price), 2) AS value
            FROM {inventory} GROUP BY category ORDER BY category')->fetchAll();
        return [
            'categories' => array_column($cats, 'category'),
            'category_stats' => typed($cats, ['lots', 'qty'], ['value']),
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
     * Umlagerungsvorschläge (immer kleine Handgriffe am bestehenden Lager, Wunsch des Inhabers):
     *  1. 'falsch'     – gleiches Teil in gleicher Farbe liegt in mehreren Kästchen: alles in das Kästchen mit der größten Menge.
     *  2. 'farben'     – gleiches Teil in anderen Farben verstreut: in ein Kästchen, das nur dieses Teil enthält,
     *                    höchstens SLOT_MAX_LOTS Lots; Quelle muss auch andere Teile enthalten.
     *  3. 'art'        – gleiche Teile-Art (Kategorie) verstreut: in ein Kästchen, das nur diese Art enthält (gleiche Grenzen).
     *  4. 'bereich'    – neues Lot im Gebraucht-Bereich (G/FG) oder gebrauchtes im Neu-Bereich (N/FN): dorthin, wo das Teil im
     *                    richtigen Bereich liegt (gleiche Farbe, sonst gleiches Teil).
     *  5. 'mischkiste' – G-Kästchen mit mehr als MIXED_MAX_LOTS Lots: die größten bleiben, der Rest zum gleichen Teil,
     *                    zur gleichen Art oder ins leere Kästchen mit der nächsten Nummer.
     *  6. 'rest'       – Kästchen mit nur einem kleinen Rest (≤ REST_MAX_QTY Teile): zum gleichen Teil oder zur gleichen Art.
     *  7. 'trennen'    – leicht Verwechselbares im selben Kästchen (confusable()): das kleinere Lot woandershin.
     * Nie in ein Kästchen, in dem etwas Verwechselbares liegt. Ziel wie bisher: wo schon am meisten davon liegt.
     * Schritte 1–3 zuerst, 4–7 rechnen auf dem dann geplanten Stand. Immer innerhalb eines Bereichs (außer 4).
     * Gleiche Regeln und Reihenfolge wie suggestions() in frontend/src/demo/shop.js.
     */
    public static function suggestions(): array
    {
        $all = typed(q(self::LOT_SELECT . ' WHERE l.code IS NOT NULL ORDER BY i.id')->fetchAll(), LOT_INTS, ['price']);
        $out = [];
        $moved = [];
        // Geplanter Inhalt je Kästchen (nach den bisherigen Vorschlägen): location_id => [Lot-ID => Lot]
        $in = [];
        foreach ($all as $l) $in[$l['location_id']][$l['id']] = $l;
        $add = function (array $lot, array $target, string $reason, array $extra = [], bool $merge = false) use (&$out, &$moved, &$in) {
            $out[] = ['lot' => $lot, 'to_location_id' => $target['location_id'], 'to_location_code' => $target['location_code'],
                'target_qty' => $target['qty'], 'reason' => $reason] + $extra;
            $moved[$lot['id']] = true;
            foreach ($in as $loc => $lots) if (isset($lots[$lot['id']])) unset($in[$loc][$lot['id']]);
            // Gleiches Teil + Farbe am Ziel wird zusammengeführt, sonst liegt das Lot danach dort
            if (!$merge) $in[$target['location_id']][$lot['id']] = $lot;
        };
        // Liegt im Kästchen schon etwas, das man mit diesem Lot leicht verwechselt?
        $clash = function (array $lot, int $loc) use (&$in) {
            foreach ($in[$loc] ?? [] as $x) if ($x['id'] !== $lot['id'] && self::confusable($lot, $x)) return true;
            return false;
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
            // Lots im selben Kästchen wie das Ziel bleiben liegen (echter Bestand: gleiches Teil mit anderem Preis/Zustand)
            foreach (array_slice($lots, 1) as $l) if ($l['location_id'] !== $home['location_id']) $add($l, $home, 'falsch', [], true);
        }

        // 2. Farben zusammenlegen
        $planned = []; // Lot-ID => Ziel-Kästchen der Farben-Vorschläge (für Schritt 3)
        $boxLots = [];   // location_id => Anzahl Lots
        $boxItems = [];  // location_id => [item_no => Anzahl Lots]
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
                    if ($clash($src, $tid)) continue; // z. B. Light Gray zu Light Bluish Gray
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
        $catLots = [];  // location_id => Anzahl Lots (nach den Vorschlägen aus 1 und 2)
        $catCount = []; // location_id => [Kategorie => Anzahl Lots]
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
                    if ($clash($src, $tid)) continue; // z. B. Plate 1 x 4 zu Plate 1 x 5
                    $add($src, $t, 'art', ['target_parts' => array_values(array_unique($namesIn[$tid] ?? []))]);
                    $catLots[$tid]++; $catCount[$tid][$cat] = ($catCount[$tid][$cat] ?? 0) + 1; $namesIn[$tid][] = $src['name'];
                    $catLots[$sid]--; $catCount[$sid][$cat]--;
                }
            }
        }

        // Ab hier rechnen 4–7 auf dem geplanten Inhalt ($in)
        $codes = [];
        foreach (q('SELECT id, code FROM {locations} ORDER BY id')->fetchAll() as $r) $codes[(int) $r['id']] = (string) $r['code'];
        $byArea = [];
        foreach ($codes as $id => $c) $byArea[self::areaOf($c)][] = $id;
        foreach ($byArea as &$ids) usort($ids, fn ($a, $b) => strnatcasecmp($codes[$a], $codes[$b]) ?: $a <=> $b);
        unset($ids);
        $num = fn (int $loc) => preg_match('/(\d+)$/', $codes[$loc] ?? '', $m) ? (int) $m[1] : 0;
        $box = fn (int $loc, int $qty = 0) => ['location_id' => $loc, 'location_code' => $codes[$loc] ?? '', 'qty' => $qty];
        // Ziel für ein Lot im Bereich $area (nicht $from): a) gleiches Teil + Farbe (zusammenführen), b) gleiches Teil mit Platz,
        // c) Kästchen nur mit dieser Art und Platz, d) leeres Kästchen mit der nächsten Nummer. Je Stufe: wo am meisten davon liegt.
        $targetFor = function (array $lot, string $area, int $from, string $steps) use (&$in, $byArea, $codes, $clash, $num, $box) {
            $boxes = $byArea[$area] ?? [];
            if (str_contains($steps, 'a')) {
                $best = null;
                foreach ($boxes as $b) {
                    if ($b === $from) continue;
                    foreach ($in[$b] ?? [] as $x) {
                        if ($x['item_no'] !== $lot['item_no'] || $x['color_id'] !== $lot['color_id']) continue;
                        if ($best === null || $x['qty'] > $best['qty']) $best = ['loc' => $b, 'qty' => $x['qty']];
                    }
                }
                if ($best) return [$box($best['loc'], $best['qty']), true];
            }
            $pickBy = function (callable $score) use ($boxes, $from, &$in, $lot, $clash) {
                $best = null;
                foreach ($boxes as $b) {
                    if ($b === $from || count($in[$b] ?? []) >= SLOT_MAX_LOTS || empty($in[$b])) continue;
                    $v = $score($in[$b]);
                    if ($v <= 0 || $clash($lot, $b)) continue;
                    if ($best === null || $v > $best[1]) $best = [$b, $v];
                }
                return $best[0] ?? null;
            };
            if (str_contains($steps, 'b')) {
                $b = $pickBy(function ($lots) use ($lot) { $q = 0; foreach ($lots as $x) if ($x['item_no'] === $lot['item_no']) $q += $x['qty']; return $q; });
                if ($b !== null) return [$box($b), false];
            }
            if (str_contains($steps, 'c')) {
                $b = $pickBy(function ($lots) use ($lot) { foreach ($lots as $x) if ($x['category'] !== $lot['category']) return 0; return count($lots); });
                if ($b !== null) return [$box($b), false];
            }
            if (str_contains($steps, 'd')) {
                $best = null;
                foreach ($boxes as $b) {
                    if ($b === $from || !empty($in[$b])) continue;
                    $d = abs($num($b) - $num($from));
                    if ($best === null || $d < $best[1]) $best = [$b, $d];
                }
                if ($best) return [$box($best[0]), false];
            }
            return null;
        };
        $place = function (array $lot, ?array $hit, string $reason, array $extra = []) use ($add) {
            if (!$hit) return;
            [$t, $merge] = $hit;
            $add($lot, $t, $reason, $extra, $merge);
        };

        // 4. Falscher Bereich: neu in G/FG, gebraucht in N/FN
        $other = ['N' => ['G' => 'N', 'FG' => 'FN'], 'U' => ['N' => 'G', 'FN' => 'FG']];
        foreach ($all as $l) {
            if (isset($moved[$l['id']])) continue;
            $area = self::areaOf((string) $l['location_code']);
            $to = $other[strtoupper((string) ($l['new_or_used'] ?? ''))][$area] ?? null;
            if ($to === null) continue;
            $place($l, $targetFor($l, $to, (int) $l['location_id'], 'ab'), 'bereich', ['condition' => strtoupper((string) $l['new_or_used'])]);
        }

        // 5. Mischkisten aufteilen: die MIXED_MAX_LOTS größten Lots bleiben
        foreach ($byArea as $area => $boxes) {
            foreach ($boxes as $b) {
                if (!preg_match(MIXED_AREA_PATTERN, $codes[$b]) || count($in[$b] ?? []) <= MIXED_MAX_LOTS) continue;
                $lots = array_values($in[$b]);
                usort($lots, fn ($x, $y) => $y['qty'] <=> $x['qty'] ?: $x['id'] <=> $y['id']);
                foreach (array_slice($lots, MIXED_MAX_LOTS) as $l) {
                    if (isset($moved[$l['id']])) continue;
                    $place($l, $targetFor($l, $area, $b, 'abcd'), 'mischkiste', ['from_mixed' => $codes[$b]]);
                }
            }
        }

        // 6. Fast leere Kästchen leeren: nur ein Lot mit wenigen Teilen
        foreach ($byArea as $area => $boxes) {
            foreach ($boxes as $b) {
                if (count($in[$b] ?? []) !== 1) continue;
                $l = reset($in[$b]);
                if (isset($moved[$l['id']]) || strtoupper((string) $l['item_type']) !== 'PART' || $l['qty'] > REST_MAX_QTY) continue;
                $place($l, $targetFor($l, $area, $b, 'abc'), 'rest');
            }
        }

        // 7. Verwechselbares trennen: das kleinere Lot eines verwechselbaren Paares woandershin
        foreach ($byArea as $area => $boxes) {
            foreach ($boxes as $b) {
                for ($guard = 0; $guard < 50; $guard++) {
                    $lots = array_values($in[$b] ?? []);
                    usort($lots, fn ($x, $y) => $x['id'] <=> $y['id']);
                    $done = true;
                    foreach ($lots as $i => $x) {
                        foreach (array_slice($lots, $i + 1) as $y) {
                            if (!self::confusable($x, $y)) continue;
                            $mover = $y['qty'] < $x['qty'] || ($y['qty'] === $x['qty']) ? $y : $x;
                            if (isset($moved[$mover['id']])) $mover = $mover === $y ? $x : $y;
                            if (isset($moved[$mover['id']])) continue;
                            $keep = $mover === $y ? $x : $y;
                            $hit = $targetFor($mover, $area, $b, 'abcd');
                            if (!$hit) continue;
                            $place($mover, $hit, 'trennen', ['confused_with' => trim($keep['item_no'] . ' ' . $keep['color_name'])]);
                            $done = false;
                            break 2;
                        }
                    }
                    if ($done) break;
                }
            }
        }
        return $out;
    }

    /** Verwechselbare Farben (BrickLink-Namen, kleingeschrieben). Gleiche Liste in frontend/src/demo/shop.js. */
    private const CONFUSABLE_COLORS = [['light gray', 'light bluish gray'], ['dark gray', 'dark bluish gray'], ['tan', 'dark tan'],
        ['white', 'very light gray'], ['black', 'dark bluish gray']];

    /**
     * Leicht zu verwechseln (nur Teile, keine Figuren): gleiches Teil in ähnlicher Farbe; gleiche Grundnummer in anderer Variante
     * (3626b/3626c); gleicher Name bis auf einen Zusatz („Tile 1 x 2“ / „… with Groove“); gleiche Form in ähnlicher Größe
     * (Name gleich bis auf Zahlen, jede höchstens 2 auseinander: Plate 1 x 4 / 1 x 5, Axle 5 / 6).
     */
    public static function confusable(array $a, array $b): bool
    {
        if (strtoupper((string) ($a['item_type'] ?? 'PART')) !== 'PART' || strtoupper((string) ($b['item_type'] ?? 'PART')) !== 'PART') return false;
        if ($a['item_no'] === $b['item_no']) {
            $x = mb_strtolower(trim((string) $a['color_name']));
            $y = mb_strtolower(trim((string) $b['color_name']));
            foreach (self::CONFUSABLE_COLORS as [$p, $q]) if (($x === $p && $y === $q) || ($x === $q && $y === $p)) return true;
            return false;
        }
        if (preg_match('/^(\d+)[a-z]?$/i', (string) $a['item_no'], $m1) && preg_match('/^(\d+)[a-z]?$/i', (string) $b['item_no'], $m2) && $m1[1] === $m2[1]) return true;
        $na = trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $a['name'])));
        $nb = trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $b['name'])));
        if ($na === '' || $nb === '') return false;
        if (str_starts_with($na, $nb . ' ') || str_starts_with($nb, $na . ' ')) return true;
        if (preg_replace('/\d+/', '#', $na) !== preg_replace('/\d+/', '#', $nb)) return false;
        preg_match_all('/\d+/', $na, $xa);
        preg_match_all('/\d+/', $nb, $xb);
        if (!$xa[0]) return $na === $nb;
        foreach ($xa[0] as $i => $v) if (abs((int) $v - (int) $xb[0][$i]) > 2) return false;
        return true;
    }

    /** Menge eines Lots an einen anderen Lagerort buchen. Gleiches Teil am Ziel wird zusammengeführt. */
    public static function transfer(int $lotId, int $toId, int $qty, int $userId, ?int $expectLoc = null, ?int $expectQty = null): array
    {
        BrickLinkSync::ensureSchema(); // vor der Transaktion: ALTER TABLE beendet sie in MySQL
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lot sperren, damit zwei gleichzeitige Buchungen nacheinander laufen (SQLite sperrt beim Schreiben ohnehin alles)
            $lot = q('SELECT * FROM {inventory} WHERE id = ?' . (Dev::isSqlite() ? '' : ' FOR UPDATE'), [$lotId])->fetch();
            if (!$lot) json_error('Dieses Lot wurde gerade von jemand anderem umgelagert. Die Liste wird neu geladen.', 409);
            // Stand wie auf dem Bildschirm? Sonst hat inzwischen jemand anders gebucht.
            if (($expectLoc !== null && (int) $lot['location_id'] !== $expectLoc) || ($expectQty !== null && (int) $lot['qty'] !== $expectQty)) {
                json_error('Dieses Lot wurde gerade von jemand anderem umgelagert. Die Liste wird neu geladen.', 409);
            }
            $to = q('SELECT * FROM {locations} WHERE id = ?', [$toId])->fetch();
            if (!$to) json_error('Ziel-Lagerort nicht gefunden', 404);
            if ((int) $lot['location_id'] === $toId) json_error('Quelle und Ziel sind gleich');
            if ($qty < 1 || $qty > (int) $lot['qty']) json_error('Menge muss zwischen 1 und ' . $lot['qty'] . ' liegen');

            $t = now();
            $target = q('SELECT id, item_no, color_name, bl_lot_id FROM {inventory} WHERE location_id = ? AND item_no = ? AND color_id = ?',
                [$toId, $lot['item_no'], $lot['color_id']])->fetch() ?: null;
            $existing = $target ? (int) $target['id'] : false;
            $blChanges = BrickLinkSync::changes($lot, $target, $qty, (string) $to['code']);
            if ($existing) {
                q('UPDATE {inventory} SET qty = qty + ?, updated_at = ? WHERE id = ?', [$qty, $t, $existing]);
            } elseif ($qty === (int) $lot['qty']) {
                q('UPDATE {inventory} SET location_id = ?, updated_at = ? WHERE id = ?', [$toId, $t, $lotId]);
            } else {
                // Neues Lot bekommt seine BrickLink-Lot-ID erst beim nächsten Import (BrickLink vergibt sie beim Mass Upload)
                q('INSERT INTO {inventory} (item_type, item_no, color_id, color_name, name, category, location_id, qty, price, bl_condition, new_or_used, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$lot['item_type'], $lot['item_no'], $lot['color_id'], $lot['color_name'],
                   $lot['name'], $lot['category'], $toId, $qty, $lot['price'], $lot['bl_condition'], $lot['new_or_used'] ?? $lot['bl_condition'], $t]);
            }
            if ($existing || $qty < (int) $lot['qty']) {
                if ($qty === (int) $lot['qty']) {
                    q('DELETE FROM {inventory} WHERE id = ?', [$lotId]);
                } else {
                    q('UPDATE {inventory} SET qty = qty - ?, updated_at = ? WHERE id = ?', [$qty, $t, $lotId]);
                }
            }
            q('INSERT INTO {transfers} (item_no, color_name, name, from_location_id, to_location_id, qty, user_id, created_at, bl_changes)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$lot['item_no'], $lot['color_name'], $lot['name'], $lot['location_id'], $toId, $qty,
               $userId, $t, json_encode($blChanges, JSON_UNESCAPED_UNICODE)]);
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
        BrickLinkSync::ensureSchema(); // Spalten vorher anlegen: ALTER TABLE beendet in MySQL jede Transaktion
        Counting::ensureSchema();
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
            // Korrekturen müssen noch zu BrickLink (gleiches Pop-up wie nach dem Umlagern, siehe BrickLinkSync)
            q("UPDATE {stocktakes} SET status = 'abgeschlossen', closed_at = ?, closed_by = ?, bl_changes = ? WHERE id = ?",
                [$t, $userId, $corrections ? json_encode($corrections, JSON_UNESCAPED_UNICODE) : null, $id]);
            // Vormerkungen zum Zählen für dieses Kästchen sind damit erledigt
            Counting::markDone((int) q('SELECT location_id FROM {stocktakes} WHERE id = ?', [$id])->fetchColumn());
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
            'count_requests' => (function () use ($id) { Counting::ensureSchema(); return Counting::openRequests($id)[$id] ?? []; })(),
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

    /** Alle Lots zu den angegebenen Teil-Nummern, gruppiert nach Nummer (für die Teileerkennung). */
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
        // Mischkisten (G-Kästchen mit zu vielen Lots), die vollsten zuerst
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
