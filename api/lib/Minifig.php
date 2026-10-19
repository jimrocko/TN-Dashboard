<?php

/**
 * Minifig-Finder: welche Minifiguren lassen sich aus losen Teilen im Bestand bauen (oder fehlt nur 1 Teil)?
 * Nachbau von app/minifigs/ aus dem bestehenden Programm (gleiche Rechenregeln, Reihenfolgen und Rundung).
 * Abrufe bei BrickLink nur auf Knopfdruck und mit Tagesbudget; Ergebnisse bleiben dauerhaft in der Datenbank.
 * Die Auswertung (analyze) rechnet nur mit eigenen Daten. Gleiche Logik im Demo-Backend: frontend/src/demo/minifig.js.
 */
final class Minifig
{
    private const CORE_PREFIXES = ['torso ', 'hips ', 'hips and ', 'legs ', 'minifigure, head', 'minifigure head',
        'minifigure, hair', 'minifigure hair', 'minifigure, headgear', 'minifigure headgear', 'minifigure, neckwear', 'minifigure neckwear'];
    public const TRANSFER_VERSION = 1;
    private const TRANSFER_TABLES = ['minifig_superset_cache', 'minifig_catalog', 'minifig_subset_state', 'minifig_decisions'];

    // ---------------------------------------------------------------- Hilfen

    /** Runden wie Python round(): auf Basis des exakten Dezimalwerts, exakte Hälften zur geraden Zahl. */
    public static function pyRound(float $x, int $nd = 0): float
    {
        if (!is_finite($x)) return $x;
        $neg = $x < 0;
        [$int, $frac] = explode('.', sprintf('%.53F', abs($x)));
        $keep = $int . substr($frac, 0, $nd);
        $rest = substr($frac, $nd);
        $n = (int) $keep;
        $first = (int) ($rest[0] ?? '0');
        $tail = rtrim(substr($rest, 1), '0');
        if ($first > 5 || ($first === 5 && $tail !== '') || ($first === 5 && $tail === '' && $n % 2 === 1)) $n++;
        $r = $n / (10 ** $nd);
        return $neg ? -$r : $r;
    }

    private static function money(?float $v): ?float
    {
        return $v === null ? null : self::pyRound($v, 2);
    }

    private static function key(string $no, int $color): string
    {
        return $no . '|' . $color;
    }

    private static function isCore(string $name): bool
    {
        $v = mb_strtolower(trim($name));
        foreach (self::CORE_PREFIXES as $p) if (str_starts_with($v, $p)) return true;
        return false;
    }

    private static function cfg(): array
    {
        return (config()['minifig'] ?? []) + ['daily_budget' => 5000, 'reserve' => 500];
    }

    /** Python-Vergleich von Tupeln (für Prioritäten). */
    private static function cmpTuple(array $a, array $b): int
    {
        foreach ($a as $i => $v) {
            $c = $v <=> $b[$i];
            if ($c) return $c;
        }
        return 0;
    }

    // ---------------------------------------------------------------- Daten aus dem eigenen Bestand

    /** Teile-Varianten im Bestand (nur Figurenteile, wenn $scope = core). */
    // ---------------------------------------------------------------- Teilebestand und Quelle

    /** Woher die Teile kommen: nur Lager, Lager + eigene BSX-Dateien oder nur BSX-Dateien (gemeinsame Einstellung). */
    public const SOURCES = ['bestand', 'beides', 'bsx'];

    public static function stockSource(): string
    {
        $s = (string) (Dev::setting('minifig_source') ?? 'bestand');
        return in_array($s, self::SOURCES, true) ? $s : 'bestand';
    }

    public static function setStockSource(string $s): string
    {
        if (!in_array($s, self::SOURCES, true)) throw new InvalidArgumentException('Unbekannte Quelle');
        Dev::setSetting('minifig_source', $s);
        return $s;
    }

    public const BSX_BASE = 800000000;

    /**
     * Teile-Lots aus den hochgeladenen BSX-Dateien (gleiche Form wie lots()). Lot-Nr. = 800000000 + Datei-ID * 100000 + Zeile.
     * Remarks und Bemerkung (BrickStore „Comments“) bleiben getrennt, der Dateiname steht in „file“ (Wunsch des Inhabers, Sitzung 7).
     */
    private static function bsxRows(): array
    {
        $out = [];
        foreach (q('SELECT id, name, items_json, sort_order FROM {minifig_bsx} ORDER BY sort_order, id')->fetchAll() as $rank => $f) {
            foreach (json_decode((string) $f['items_json'], true) ?: [] as $n => $r) {
                if ((int) $r['quantity'] <= 0) continue;
                $out[] = [
                    'inventory_id' => self::BSX_BASE + (int) $f['id'] * 100000 + $n, 'item_no' => (string) $r['item_no'], 'item_name' => (string) $r['item_name'],
                    'color_id' => (int) $r['color_id'], 'color_name' => (string) $r['color_name'], 'quantity' => (int) $r['quantity'],
                    'new_or_used' => (string) $r['condition'], 'remarks' => (string) ($r['remarks'] ?? ''),
                    'description' => (string) ($r['comments'] ?? ''), 'stock_room_id' => '', 'file' => (string) $f['name'], '_rank' => $rank + 1,
                ];
            }
        }
        return $out;
    }

    /** Welche Quelle zuerst genommen wird, wenn ein Teil im Lager und in BSX-Dateien liegt: 'lager' (wie im Original) oder 'bsx'. */
    public static function priority(): string
    {
        return Dev::setting('minifig_priority') === 'bsx' ? 'bsx' : 'lager';
    }

    /** Prio und Reihenfolge der BSX-Dateien speichern (gemeinsame Einstellung). */
    public static function setPriority(string $prio, array $bsxOrder): array
    {
        self::ensureSchema();
        if (!in_array($prio, ['lager', 'bsx'], true)) throw new InvalidArgumentException('Unbekannte Reihenfolge');
        Dev::setSetting('minifig_priority', $prio);
        foreach (array_values(array_map('intval', $bsxOrder)) as $i => $id) q('UPDATE {minifig_bsx} SET sort_order = ? WHERE id = ?', [$i + 1, $id]);
        return ['priority' => $prio, 'bsx_files' => self::bsxFiles()];
    }

    /**
     * Alle Teile-Lots der gewählten Quelle, sortiert nach Teil, Farbe, Quelle (Prio), Lot-Nr.
     * Bei Prio „Lager zuerst“ und ohne gesammelte Figuren genau wie im Original. Gesammelte, noch nicht hochgeladene Figuren
     * („Fertige Figuren“) sind schon abgezogen, damit ihre Teile nicht ein zweites Mal verplant werden.
     */
    private static function partRows(): array
    {
        self::ensureSchema();
        $src = self::stockSource();
        $rows = [];
        if ($src !== 'bsx') {
            foreach (q("SELECT COALESCE(i.bl_inventory_id, i.id) AS inventory_id, i.item_no, i.name AS item_name, i.color_id, i.color_name, i.qty AS quantity,
                    i.new_or_used, l.code AS remarks, i.description FROM {inventory} i LEFT JOIN {locations} l ON l.id = i.location_id
                WHERE i.item_type = 'PART' AND i.qty > 0")->fetchAll() as $r) {
                $r = typed([$r], ['inventory_id', 'color_id', 'quantity'])[0];
                $r['stock_room_id'] = '';
                $r['_rank'] = 0;
                $rows[] = $r;
            }
        }
        if ($src !== 'bestand') $rows = array_merge($rows, self::bsxRows());
        $used = self::reservedByBuilds();
        if ($used) {
            $rows = array_values(array_filter(array_map(function ($r) use ($used) {
                $r['quantity'] -= $used[$r['inventory_id']] ?? 0;
                return $r;
            }, $rows), fn ($r) => $r['quantity'] > 0));
        }
        // Rang der Quelle: Lager = 0, BSX-Dateien 1, 2, … in ihrer Reihenfolge; bei „BSX zuerst“ kommt das Lager ans Ende
        $bsxFirst = self::priority() === 'bsx';
        $rank = fn ($r) => $bsxFirst && $r['_rank'] === 0 ? PHP_INT_MAX : $r['_rank'];
        usort($rows, fn ($a, $b) => [strtolower($a['item_no']), $a['color_id'], $rank($a), $a['inventory_id']] <=> [strtolower($b['item_no']), $b['color_id'], $rank($b), $b['inventory_id']]);
        foreach ($rows as &$r) unset($r['_rank']);
        unset($r);
        return $rows;
    }

    public static function variants(string $scope = 'core'): array
    {
        $m = [];
        foreach (self::partRows() as $r) {
            $k = self::key($r['item_no'], $r['color_id']);
            $m[$k] ??= ['item_no' => $r['item_no'], 'item_name' => $r['item_name'], 'color_id' => $r['color_id'], 'quantity' => 0];
            if (strcmp((string) $r['item_name'], (string) $m[$k]['item_name']) > 0) $m[$k]['item_name'] = $r['item_name'];
            $m[$k]['quantity'] += $r['quantity'];
        }
        $rows = array_values($m);
        return $scope === 'all' ? $rows : array_values(array_filter($rows, fn ($r) => self::isCore((string) $r['item_name'])));
    }

    public static function stock(): array
    {
        $out = [];
        foreach (self::partRows() as $r) {
            $k = self::key($r['item_no'], $r['color_id']);
            $out[$k] = ($out[$k] ?? 0) + $r['quantity'];
        }
        return $out;
    }

    /** Lots je Teil+Farbe in fester Reihenfolge (zum Zusammensuchen). Lagerort = Code des Kästchens bzw. BSX-Datei. */
    public static function lots(): array
    {
        $out = [];
        foreach (self::partRows() as $r) $out[self::key($r['item_no'], $r['color_id'])][] = $r;
        return $out;
    }

    /** Hochgeladene BSX-Dateien (ohne Inhalt). */
    public static function bsxFiles(): array
    {
        self::ensureSchema();
        return typed(q('SELECT f.id, f.name, f.lots, f.parts, f.created_at, u.display_name AS uploaded_by FROM {minifig_bsx} f
            LEFT JOIN {users} u ON u.id = f.uploaded_by ORDER BY f.sort_order, f.id')->fetchAll(), ['id', 'lots', 'parts']);
    }

    /** BSX-Datei als Teilebestand speichern (nur Teile, Status X und Menge 0 fallen weg wie beim Einlesen). */
    public static function addBsx(string $content, string $name, int $userId): array
    {
        [$rows] = Bsx::parse($content);
        $items = [];
        foreach ($rows as $r) {
            if (strtoupper($r['item_type']) !== 'P') continue;
            $items[] = ['item_no' => $r['item_no'], 'item_name' => $r['item_name'], 'color_id' => max(0, $r['color_id']), 'color_name' => $r['color_name'],
                'quantity' => $r['quantity'], 'condition' => $r['condition'], 'remarks' => $r['remarks'], 'comments' => $r['comments'], 'price' => $r['price']];
        }
        if (!$items) throw new RuntimeException('In der Datei sind keine Teile (nur Teile zählen für Minifiguren)');
        $name = trim(basename($name)) ?: 'datei.bsx';
        $parts = array_sum(array_column($items, 'quantity'));
        self::ensureSchema();
        q('INSERT INTO {minifig_bsx} (name, items_json, lots, parts, uploaded_by, created_at, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, json_encode($items, JSON_UNESCAPED_UNICODE), count($items), $parts, $userId, now(),
             (int) q('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {minifig_bsx}')->fetchColumn()]);
        return ['id' => (int) db()->lastInsertId(), 'name' => $name, 'lots' => count($items), 'parts' => $parts];
    }

    public static function deleteBsx(int $id): ?string
    {
        $name = q('SELECT name FROM {minifig_bsx} WHERE id = ?', [$id])->fetchColumn();
        if ($name === false) return null;
        q('DELETE FROM {minifig_bsx} WHERE id = ?', [$id]);
        return (string) $name;
    }

    /** Preise ohne API: Preisimport (BrickStore) vor aktuellem Shoppreis vor Verkaufspreisen. */
    public static function prices(): array
    {
        // Mengengewichteter Durchschnitt, in PHP gerechnet (gleiche Reihenfolge wie die Datenbank, volle Genauigkeit)
        $avg = function (string $sql, bool $withColor) {
            // Summe nach Kahan-Babuška-Neumaier wie SUM() in SQLite (genauer als einfaches Addieren)
            $sum = $err = $qty = [];
            foreach (q($sql)->fetchAll() as $r) {
                $k = $withColor ? self::key((string) $r['item_no'], (int) $r['color_id']) : (string) $r['item_no'];
                $x = (int) $r['qty'] * (float) $r['price'];
                $s = $sum[$k] ?? 0.0;
                $t = $s + $x;
                $err[$k] = ($err[$k] ?? 0.0) + (abs($s) > abs($x) ? ($s - $t) + $x : ($x - $t) + $s);
                $sum[$k] = $t;
                $qty[$k] = ($qty[$k] ?? 0) + (int) $r['qty'];
            }
            $out = [];
            foreach ($sum as $k => $s) if ($qty[$k] > 0) $out[$k] = ($s + $err[$k]) / $qty[$k];
            return $out;
        };
        $partCur = $avg("SELECT item_no, color_id, qty, price FROM {inventory} WHERE UPPER(item_type) = 'PART' AND qty > 0 AND price > 0 ORDER BY id", true);
        $partHist = $avg("SELECT item_no, color_id, qty, price FROM {order_items} WHERE UPPER(item_type) = 'PART' AND color_id IS NOT NULL AND price > 0 ORDER BY id", true);
        $figCur = $avg("SELECT item_no, qty, price FROM {inventory} WHERE UPPER(item_type) = 'MINIFIG' AND qty > 0 AND price > 0 ORDER BY id", false);
        $figHist = $avg("SELECT item_no, qty, price FROM {order_items} WHERE UPPER(item_type) IN ('MINIFIG', 'MINIFIGURE') AND price > 0 ORDER BY id", false);
        $guideParts = $guideFigs = [];
        foreach (q("SELECT item_type, item_no, color_id, avg_price FROM {price_guide} WHERE currency = 'EUR' ORDER BY fetched_at ASC")->fetchAll() as $r) {
            $p = (float) $r['avg_price'];
            if ($p <= 0) continue;
            $t = strtoupper((string) $r['item_type']);
            if (in_array($t, ['PART', 'P'], true)) $guideParts[self::key((string) $r['item_no'], (int) $r['color_id'])] = $p;
            elseif (in_array($t, ['MINIFIG', 'MINIFIGURE', 'M'], true)) $guideFigs[(string) $r['item_no']] = $p;
        }
        $merge = function ($guide, $cur, $hist) {
            $out = [];
            foreach (array_keys($hist + $cur + $guide) as $k) {
                $out[$k] = isset($guide[$k]) ? ['price' => $guide[$k], 'source' => 'Price Guide Cache']
                    : (isset($cur[$k]) ? ['price' => $cur[$k], 'source' => 'aktueller Shoppreis'] : ['price' => $hist[$k], 'source' => 'Verkaufshistorie']);
            }
            return $out;
        };
        return ['parts' => $merge($guideParts, $partCur, $partHist), 'minifigs' => $merge($guideFigs, $figCur, $figHist)];
    }

    // ---------------------------------------------------------------- Stücklisten auswerten

    /** BrickLink-Stückliste → Gruppen von Alternativen (nur Teile). */
    public static function groups($raw): array
    {
        $groups = [];
        if (!is_array($raw) || !array_is_list($raw)) return $groups;
        $outer = $raw;
        if ($outer && count(array_filter($outer, fn ($x) => is_array($x) && !array_is_list($x))) === count($outer)) {
            $outer = array_map(fn ($x) => [$x], $outer);
        }
        foreach ($outer as $matching) {
            if (is_array($matching) && !array_is_list($matching)) $matching = [$matching];
            if (!is_array($matching)) continue;
            $alts = [];
            foreach ($matching as $entry) {
                if (!is_array($entry)) continue;
                foreach ($entry['entries'] ?? [] as $e) {
                    if (!is_array($e)) continue;
                    $item = $e['item'] ?? [];
                    if (strtoupper((string) ($item['type'] ?? '')) !== 'PART') continue;
                    $no = trim((string) ($item['no'] ?? ''));
                    $qty = (int) ($e['quantity'] ?? 0);
                    if ($no !== '' && isset($e['color_id']) && $qty > 0) {
                        $alts[] = ['item_no' => $no, 'item_name' => (string) ($item['name'] ?? ''), 'color_id' => (int) $e['color_id'], 'quantity' => $qty];
                    }
                }
            }
            if ($alts) $groups[] = $alts;
        }
        return $groups;
    }

    private static function chooseRequirements(array $groups, array $stock): array
    {
        $req = $missing = [];
        foreach ($groups as $alts) {
            $best = null;
            foreach ($alts as $r) {
                $have = (int) ($stock[self::key($r['item_no'], $r['color_id'])] ?? 0);
                $builds = $r['quantity'] ? intdiv($have, $r['quantity']) : 0;
                if ($best === null || [$builds, $have] > [$best[0], $best[1]]) $best = [$builds, $have, $r];
            }
            $chosen = $best[2] + ['have' => $best[1]];
            $req[] = $chosen;
            if ($best[0] <= 0) $missing[] = $chosen;
        }
        return [$req, $missing];
    }

    /** Lässt sich die Figur aus dem Restbestand bauen? Alternativen per Rückverfolgung, kein Teil doppelt. */
    private static function canAllocate(array $groups, array $remaining): array
    {
        $ordered = $groups;
        $maxq = fn ($alts) => max(array_map(fn ($r) => (int) $r['quantity'], $alts));
        usort($ordered, fn ($a, $b) => count($a) <=> count($b) ?: $maxq($b) <=> $maxq($a));
        $best = null;
        $bestSlack = -1;
        $search = function (int $i, array $temp, array $chosen, array $slacks) use (&$search, &$best, &$bestSlack, $ordered) {
            if ($i >= count($ordered)) {
                $score = $slacks ? min($slacks) : 0;
                if ($best === null || $score > $bestSlack) { $best = $chosen; $bestSlack = $score; }
                return;
            }
            $cands = [];
            foreach ($ordered[$i] as $r) {
                $have = (int) ($temp[self::key($r['item_no'], $r['color_id'])] ?? 0);
                $qty = (int) $r['quantity'];
                if ($qty <= 0 || $have < $qty) continue;
                $cands[] = [$have - $qty, $have, $r];
            }
            usort($cands, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
            foreach ($cands as [$slack, $have, $r]) {
                $next = $temp;
                $next[self::key($r['item_no'], $r['color_id'])] = $have - (int) $r['quantity'];
                $search($i + 1, $next, [...$chosen, $r + ['have_before_reservation' => $have]], [...$slacks, $slack]);
            }
        };
        $search(0, $remaining, [], []);
        return [$best !== null, $best ?? [], $bestSlack];
    }

    /**
     * Wie oft lässt sich die Figur bauen? Jede Kopie wird vom Restbestand abgezogen (wie _max_buildable_copies
     * im MinifigFinder Standalone 0.1.7), damit kein Teil doppelt zählt, auch bei Alternativteilen.
     */
    private static function maxBuildableCopies(array $groups, array $stock, int $hardCap = 1000): int
    {
        // Schneller Weg ohne Alternativen
        if ($groups && !array_filter($groups, fn ($alts) => count($alts) !== 1)) {
            $totals = [];
            foreach ($groups as $alts) {
                $k = self::key($alts[0]['item_no'], $alts[0]['color_id']);
                $totals[$k] = ($totals[$k] ?? 0) + (int) $alts[0]['quantity'];
            }
            $limits = [];
            foreach ($totals as $k => $q) if ($q > 0) $limits[] = intdiv((int) ($stock[$k] ?? 0), $q);
            return $limits ? min($limits) : 0;
        }
        $remaining = $stock;
        $count = 0;
        while ($count < max(1, $hardCap)) {
            [$ok, $req] = self::canAllocate($groups, $remaining);
            if (!$ok) break;
            foreach ($req as $r) {
                $k = self::key($r['item_no'], $r['color_id']);
                $remaining[$k] = (int) ($remaining[$k] ?? 0) - (int) $r['quantity'];
            }
            $count++;
        }
        return $count;
    }

    private static function missingAfterReservations(array $groups, array $remaining): array
    {
        $temp = $remaining;
        $missing = [];
        $sorted = $groups;
        usort($sorted, fn ($a, $b) => count($a) <=> count($b));
        foreach ($sorted as $alts) {
            $cands = [];
            foreach ($alts as $r) {
                $have = (int) ($temp[self::key($r['item_no'], $r['color_id'])] ?? 0);
                $qty = (int) $r['quantity'];
                $cands[] = [$have >= $qty && $qty > 0 ? 1 : 0, $have - $qty, $have, $r];
            }
            usort($cands, fn ($a, $b) => [$b[0], $b[1], $b[2]] <=> [$a[0], $a[1], $a[2]]);
            [$ok, , $have, $sel] = $cands[0];
            if ($ok) $temp[self::key($sel['item_no'], $sel['color_id'])] = $have - (int) $sel['quantity'];
            else $missing[] = $sel + ['have' => $have];
        }
        return $missing;
    }

    /**
     * Gemeinsamer Bauplan: Bestand virtuell reservieren, wirtschaftlich beste Figur zuerst.
     * Runde 1: jede Figur höchstens einmal. Runde 2: weitere Kopien aus dem echten Restbestand (Standalone 0.1.7).
     */
    private static function jointPlan(array $cands, array $stock): array
    {
        $remaining = $stock;
        $pending = $cands;
        $selected = [];
        $sourceByNo = [];
        $prio = fn ($row, $slack) => [
            $row['economic_advantage'] !== null ? 1 : 0, $row['economic_advantage'] !== null ? (float) $row['economic_advantage'] : 0.0,
            $row['figure_value'] !== null ? 1 : 0, $row['figure_value'] !== null ? (float) $row['figure_value'] : 0.0,
            (int) $slack, -(int) $row['required_groups'],
        ];
        while ($pending) {
            $best = null;
            foreach ($pending as $row) {
                [$ok, $req, $slack] = self::canAllocate($row['groups'], $remaining);
                if (!$ok) continue;
                $p = $prio($row, $slack);
                if ($best === null || (self::cmpTuple($p, $best[0]) ?: strcmp((string) $row['minifig_no'], (string) $best[1]['minifig_no'])) > 0) {
                    $best = [$p, $row, $req, $slack];
                }
            }
            if (!$best) break;
            [, $row, $req, $slack] = $best;
            foreach ($req as $r) {
                $k = self::key($r['item_no'], $r['color_id']);
                $remaining[$k] = (int) ($remaining[$k] ?? 0) - (int) $r['quantity'];
            }
            $sourceByNo[(string) $row['minifig_no']] = $row;
            $selected[] = [
                'minifig_no' => $row['minifig_no'], 'name' => $row['name'], 'required_groups' => $row['required_groups'], 'completeness' => 100,
                'buildable_qty' => 1, 'individual_buildable_qty' => (int) ($row['buildable_qty'] ?: 1),
                'figure_value' => $row['figure_value'], 'parts_value' => $row['parts_value'], 'economic_advantage' => $row['economic_advantage'],
                'price_coverage_pct' => $row['price_coverage_pct'], 'allocation_slack' => $slack, 'reserved' => $req,
            ];
            $pending = array_values(array_filter($pending, fn ($r) => $r['minifig_no'] !== $row['minifig_no']));
        }
        // Runde 2: zusätzliche Kopien erst, wenn jede ausgewählte Figur ihre erste hat
        $idx = [];
        foreach ($selected as $i => $r) $idx[(string) $r['minifig_no']] = $i;
        while ($selected) {
            $best = null;
            foreach ($idx as $no => $i) {
                $src = $sourceByNo[$no];
                [$ok, $req, $slack] = self::canAllocate($src['groups'], $remaining);
                if (!$ok) continue;
                $p = $prio($src, $slack);
                if ($best === null || (self::cmpTuple($p, $best[0]) ?: strcmp((string) $no, $best[1])) > 0) $best = [$p, (string) $no, $req, $slack];
            }
            if (!$best) break;
            [, $no, $req, $slack] = $best;
            foreach ($req as $r) {
                $k = self::key($r['item_no'], $r['color_id']);
                $remaining[$k] = (int) ($remaining[$k] ?? 0) - (int) $r['quantity'];
            }
            $i = $idx[$no];
            $selected[$i]['buildable_qty'] = (int) ($selected[$i]['buildable_qty'] ?: 1) + 1;
            // Python: min(int(x or slack), slack)
            $selected[$i]['allocation_slack'] = min((int) ($selected[$i]['allocation_slack'] ?: $slack), (int) $slack);
            array_push($selected[$i]['reserved'], ...$req);
        }
        $sel = array_flip(array_column($selected, 'minifig_no'));
        $conflicts = [];
        foreach ($cands as $row) {
            if (isset($sel[$row['minifig_no']])) continue;
            $conflicts[] = [
                'minifig_no' => $row['minifig_no'], 'name' => $row['name'], 'required_groups' => $row['required_groups'],
                'figure_value' => $row['figure_value'], 'parts_value' => $row['parts_value'], 'economic_advantage' => $row['economic_advantage'],
                'price_coverage_pct' => $row['price_coverage_pct'],
                'missing_after_reservations' => array_slice(self::missingAfterReservations($row['groups'], $remaining), 0, 5),
            ];
        }
        return [$selected, $conflicts];
    }

    private static function remainingAfterJoint(array $stock, array $joint): array
    {
        foreach ($joint as $f) {
            foreach ($f['reserved'] as $r) {
                $k = self::key((string) $r['item_no'], (int) $r['color_id']);
                $stock[$k] = max(0, (int) ($stock[$k] ?? 0) - (int) $r['quantity']);
            }
        }
        return $stock;
    }

    /** Zweite Runde: Restbestand auf Figuren verteilen, denen genau 1 Teil fehlt (das Fehlteil wird gekauft). */
    private static function almostJointPlan(array $cands, array $remainingStock): array
    {
        $remaining = $remainingStock;
        $pending = $cands;
        $selected = [];
        $alloc = function ($row) use (&$remaining) {
            $missing = $row['missing'] ?? [];
            if (count($missing) !== 1) return null;
            $miss = $missing[0];
            $mk = self::key((string) $miss['item_no'], (int) $miss['color_id']);
            $mq = (int) $miss['quantity'];
            $totals = $details = [];
            $skipped = false;
            foreach ($row['requirements'] as $r) {
                $k = self::key((string) $r['item_no'], (int) $r['color_id']);
                $q = (int) $r['quantity'];
                if (!$skipped && $k === $mk && $q === $mq) { $skipped = true; continue; }
                $totals[$k] = ($totals[$k] ?? 0) + $q;
                $details[$k] = $r;
            }
            if (!$skipped) return null;
            $slacks = $reserved = [];
            foreach ($totals as $k => $q) {
                $have = (int) ($remaining[$k] ?? 0);
                if ($q <= 0 || $have < $q) return null;
                $slacks[] = $have - $q;
                $reserved[] = ['quantity' => $q, 'have_before_reservation' => $have] + $details[$k];
            }
            return [$reserved, $slacks ? min($slacks) : 0, $miss];
        };
        $prio = fn ($row, $slack) => [
            $row['economic_advantage'] !== null ? 1 : 0, $row['economic_advantage'] !== null ? (float) $row['economic_advantage'] : 0.0,
            $row['figure_value'] !== null ? 1 : 0, $row['figure_value'] !== null ? (float) $row['figure_value'] : 0.0,
            $row['completion_cost'] !== null ? -(float) $row['completion_cost'] : -999999.0, (int) $slack,
        ];
        while ($pending) {
            $best = null;
            foreach ($pending as $row) {
                $a = $alloc($row);
                if ($a === null) continue;
                $p = $prio($row, $a[1]);
                if ($best === null || (self::cmpTuple($p, $best[0]) ?: strcmp((string) $row['minifig_no'], (string) $best[1]['minifig_no'])) > 0) {
                    $best = [$p, $row, ...$a];
                }
            }
            if (!$best) break;
            [, $row, $reserved, $slack, $miss] = $best;
            foreach ($reserved as $r) {
                $k = self::key((string) $r['item_no'], (int) $r['color_id']);
                $remaining[$k] = (int) ($remaining[$k] ?? 0) - (int) $r['quantity'];
            }
            $selected[] = [
                'minifig_no' => $row['minifig_no'], 'name' => $row['name'], 'required_groups' => $row['required_groups'],
                'completeness' => $row['completeness'], 'figure_value' => $row['figure_value'], 'parts_value' => $row['parts_value'],
                'completion_cost' => $row['completion_cost'], 'economic_advantage' => $row['economic_advantage'],
                'price_coverage_pct' => $row['price_coverage_pct'], 'missing' => [$miss], 'allocation_slack' => $slack, 'reserved' => $reserved,
            ];
            $pending = array_values(array_filter($pending, fn ($r) => $r['minifig_no'] !== $row['minifig_no']));
        }
        $sel = array_flip(array_column($selected, 'minifig_no'));
        return [$selected, array_values(array_filter($cands, fn ($r) => !isset($sel[$r['minifig_no']])))];
    }

    private static function copyLots(array $lotsByKey): array
    {
        $out = [];
        foreach ($lotsByKey as $k => $lots) foreach ($lots as $l) $out[$k][] = $l + ['remaining_quantity' => (int) $l['quantity']];
        return $out;
    }

    private static function colorName(array $lots): string
    {
        foreach ($lots as $l) if (($l['color_name'] ?? '') !== '') return (string) $l['color_name'];
        return '';
    }

    private static function source(array $lot, int $take, bool $withLotQty): array
    {
        $s = ['inventory_id' => $lot['inventory_id'], 'quantity' => $take];
        if ($withLotQty) $s['lot_quantity'] = (int) $lot['quantity'];
        $s += ['remarks' => $lot['remarks'] ?? '', 'description' => $lot['description'] ?? '', 'stock_room_id' => '', 'new_or_used' => $lot['new_or_used'] ?? ''];
        // Nur bei BSX-Teilen: aus welcher Datei (für die getrennte Anzeige von Remarks und Bemerkung)
        if (isset($lot['file'])) $s['file'] = $lot['file'];
        return $s;
    }

    /** Teileliste je Lot für den gemeinsamen Bauplan (Lots werden nacheinander verbraucht). */
    private static function attachPickLists(array $selected, array $lotsByKey): array
    {
        $rem = self::copyLots($lotsByKey);
        $out = [];
        foreach ($selected as $fig) {
            $list = [];
            foreach ($fig['reserved'] as $r) {
                $k = self::key((string) $r['item_no'], (int) $r['color_id']);
                $need = (int) $r['quantity'];
                $still = $need;
                $sources = [];
                $before = array_sum(array_map(fn ($l) => max(0, $l['remaining_quantity']), $rem[$k] ?? []));
                foreach ($rem[$k] ?? [] as $i => $l) {
                    $av = max(0, $l['remaining_quantity']);
                    if ($av <= 0 || $still <= 0) continue;
                    $take = min($av, $still);
                    $rem[$k][$i]['remaining_quantity'] = $av - $take;
                    $still -= $take;
                    $sources[] = self::source($l, $take, false);
                }
                $list[] = ['item_no' => $r['item_no'], 'item_name' => ($r['item_name'] ?? '') ?: $r['item_no'], 'color_id' => (int) $r['color_id'],
                    'color_name' => self::colorName($rem[$k] ?? []), 'quantity' => $need, 'stock_before_figure' => $before, 'sources' => $sources];
            }
            $out[] = $fig + ['pick_list' => $list];
        }
        return $out;
    }

    private static function urls(string $no): array
    {
        return ['catalog_url' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?M=' . rawurlencode($no),
            'image_url' => 'https://img.bricklink.com/ItemImage/MN/0/' . rawurlencode($no) . '.png'];
    }

    private static function attachAlmostJointPickLists(array $primary, array $selected, array $lotsByKey): array
    {
        $rem = self::copyLots($lotsByKey);
        $consume = function (array $r, bool $collect) use (&$rem) {
            $k = self::key((string) $r['item_no'], (int) $r['color_id']);
            $need = max(0, (int) $r['quantity']);
            $still = $need;
            $sources = [];
            $before = array_sum(array_map(fn ($l) => max(0, $l['remaining_quantity']), $rem[$k] ?? []));
            foreach ($rem[$k] ?? [] as $i => $l) {
                $av = max(0, $l['remaining_quantity']);
                if ($av <= 0 || $still <= 0) continue;
                $take = min($av, $still);
                $rem[$k][$i]['remaining_quantity'] = $av - $take;
                $still -= $take;
                if ($collect) $sources[] = self::source($l, $take, true);
            }
            if (!$collect) return null;
            return ['item_no' => (string) $r['item_no'], 'item_name' => ($r['item_name'] ?? '') ?: (string) $r['item_no'], 'color_id' => (int) $r['color_id'],
                'color_name' => self::colorName($rem[$k] ?? []), 'quantity' => $need, 'available' => max(0, $need - $still), 'shortfall' => $still,
                'stock_before_figure' => $before, 'sources' => $sources];
        };
        foreach ($primary as $f) foreach ($f['reserved'] as $r) $consume($r, false);
        $out = [];
        foreach ($selected as $fig) {
            $list = [];
            foreach ($fig['reserved'] as $r) $list[] = $consume($r, true);
            foreach ($fig['missing'] as $m) {
                $k = self::key((string) $m['item_no'], (int) $m['color_id']);
                $need = max(0, (int) $m['quantity']);
                $have = max(0, (int) ($m['have'] ?? 0));
                $list[] = ['item_no' => (string) $m['item_no'], 'item_name' => ($m['item_name'] ?? '') ?: (string) $m['item_no'], 'color_id' => (int) $m['color_id'],
                    'color_name' => self::colorName($rem[$k] ?? []), 'quantity' => $need, 'available' => $have, 'shortfall' => max(1, $need - $have), 'sources' => []];
            }
            $out[] = $fig + ['pick_list' => $list] + self::urls((string) $fig['minifig_no']);
        }
        return $out;
    }

    /** Wo liegen die Teile einer Figur? Ohne Reservierung (jede Figur für sich). */
    private static function individualPick(array $requirements, array $lotsByKey): array
    {
        $out = [];
        foreach ($requirements as $r) {
            $k = self::key((string) $r['item_no'], (int) $r['color_id']);
            $need = max(0, (int) $r['quantity']);
            $still = $need;
            $sources = [];
            foreach ($lotsByKey[$k] ?? [] as $l) {
                $av = max(0, (int) $l['quantity']);
                if ($av <= 0 || $still <= 0) continue;
                $take = min($av, $still);
                $still -= $take;
                $sources[] = self::source($l, $take, true);
            }
            $out[] = ['item_no' => (string) $r['item_no'], 'item_name' => ($r['item_name'] ?? '') ?: (string) $r['item_no'], 'color_id' => (int) $r['color_id'],
                'color_name' => self::colorName($lotsByKey[$k] ?? []), 'quantity' => $need, 'available' => max(0, $need - $still), 'shortfall' => $still, 'sources' => $sources];
        }
        return $out;
    }

    /** Werte: Teilewert aus dem Lager, Fehlteilkosten, Figurenwert, wirtschaftlicher Vorteil. */
    private static function priceEnrich(array $row, array $req, array $prices): array
    {
        $pp = $prices['parts'];
        $fp = $prices['minifigs'];
        $known = 0.0;
        $priced = $total = 0;
        $missCost = 0.0;
        $missComplete = true;
        $missDetails = [];
        $missKeys = [];
        foreach ($row['missing'] as $m) $missKeys[self::key($m['item_no'], (int) $m['color_id'])] = $m;
        foreach ($req as $r) {
            $k = self::key($r['item_no'], (int) $r['color_id']);
            $q = (int) $r['quantity'];
            $total += $q;
            $ref = $pp[$k] ?? null;
            if ($ref && $ref['price'] > 0) {
                $haveQ = max(0, (int) ($r['have'] ?? $q));
                $known += min($q, $haveQ) * $ref['price'];
                $priced += $q;
            }
            if (isset($missKeys[$k])) {
                $m = $missKeys[$k];
                $short = max(0, (int) $m['quantity'] - (int) ($m['have'] ?? 0));
                $est = $src = null;
                if ($ref && $ref['price'] > 0) {
                    $est = $short * $ref['price'];
                    $missCost += $est;
                    $src = $ref['source'];
                } else {
                    $missComplete = false;
                }
                $missDetails[] = $m + ['shortfall' => $short, 'estimated_unit_price' => $ref ? self::money($ref['price']) : null,
                    'estimated_missing_cost' => self::money($est), 'price_source' => $src];
            }
        }
        $figRef = $fp[(string) $row['minifig_no']] ?? null;
        $figVal = $figRef ? (float) $figRef['price'] : null;
        $complete = $priced === $total && (!$row['missing_count'] || $missComplete);
        $input = $known + ($row['missing_count'] ? $missCost : 0);
        $adv = $figVal !== null && $complete ? $figVal - $input : null;
        return array_merge($row, [
            'missing' => $row['missing_count'] ? $missDetails : $row['missing'],
            'parts_value' => self::money($known),
            'price_coverage_pct' => $total ? (int) self::pyRound(100 * $priced / $total) : 0,
            'completion_cost' => $row['missing_count'] && $missComplete ? self::money($missCost) : (!$row['missing_count'] ? 0.0 : null),
            'figure_value' => self::money($figVal),
            'figure_price_source' => $figRef['source'] ?? null,
            'economic_advantage' => self::money($adv),
            'economic_advantage_before_fees' => self::money($adv),
        ]);
    }

    private static function missingFrequency(array $almost): array
    {
        $g = [];
        foreach ($almost as $row) {
            foreach ($row['missing'] as $m) {
                $k = self::key((string) $m['item_no'], (int) $m['color_id']);
                $g[$k] ??= ['item_no' => $m['item_no'], 'item_name' => ($m['item_name'] ?? '') ?: $m['item_no'], 'color_id' => (int) $m['color_id'],
                    'figures_affected' => 0, 'total_shortfall' => 0, 'estimated_unit_price' => $m['estimated_unit_price'] ?? null, 'price_source' => $m['price_source'] ?? null];
                $g[$k]['figures_affected']++;
                $g[$k]['total_shortfall'] += (int) ($m['shortfall'] ?? 0);
                if ($g[$k]['estimated_unit_price'] === null && ($m['estimated_unit_price'] ?? null) !== null) {
                    $g[$k]['estimated_unit_price'] = $m['estimated_unit_price'];
                    $g[$k]['price_source'] = $m['price_source'];
                }
            }
        }
        $rows = array_values($g);
        foreach ($rows as &$r) $r['estimated_total_cost'] = $r['estimated_unit_price'] !== null ? self::money($r['estimated_unit_price'] * $r['total_shortfall']) : null;
        unset($r);
        usort($rows, fn ($a, $b) => $b['figures_affected'] <=> $a['figures_affected'] ?: $b['total_shortfall'] <=> $a['total_shortfall'] ?: strcmp((string) $a['item_no'], (string) $b['item_no']));
        return $rows;
    }

    private static function bomPartCount(array $groups): int
    {
        $t = 0;
        foreach ($groups as $alts) {
            $qs = array_filter(array_map(fn ($r) => (int) $r['quantity'], $alts), fn ($q) => $q > 0);
            if ($qs) $t += min($qs);
        }
        return $t;
    }

    /** Ausschlüsse für „Gemeinsam ergänzbar“ (Nicht bauen). */
    private static function exclusions(): array
    {
        $out = [];
        foreach (q("SELECT minifig_no, reason, decided_at FROM {minifig_decisions} WHERE decision = 'exclude'")->fetchAll() as $r) $out[(string) $r['minifig_no']] = $r;
        return $out;
    }

    /** Komplette lokale Auswertung (keine API). */
    public static function analyze(int $limit = 500): array
    {
        self::ensureBundledCatalog();
        $stock = self::stock();
        $lots = self::lots();
        $prices = self::prices();
        $rows = $buildableInternal = [];
        $underMin = 0;
        foreach (q('SELECT minifig_no, name, subset_json FROM {minifig_catalog} WHERE subset_json IS NOT NULL ORDER BY seq, minifig_no')->fetchAll() as $fig) {
            $raw = json_decode((string) $fig['subset_json'], true);
            if (!is_array($raw)) continue;
            $groups = self::groups($raw);
            if (!$groups) continue;
            // Eine echte Minifigur hat mindestens 4 Teile
            $bom = self::bomPartCount($groups);
            if ($bom < 4) { $underMin++; continue; }
            // Wie Standalone 0.1.7: erst prüfen, ob eine Kopie wirklich aufgeht (Alternativen per Rückverfolgung)
            [$oneOk, $allocated] = self::canAllocate($groups, $stock);
            if ($oneOk) {
                $req = array_map(fn ($r) => array_merge($r, ['have' => (int) ($stock[self::key((string) $r['item_no'], (int) $r['color_id'])] ?? 0)]), $allocated);
                $missing = [];
                $present = count($groups);
                $buildQty = self::maxBuildableCopies($groups, $stock);
            } else {
                [$req, $missing] = self::chooseRequirements($groups, $stock);
                $present = count($groups) - count($missing);
                $buildQty = 0;
            }
            $row = [
                'minifig_no' => (string) $fig['minifig_no'], 'name' => $fig['name'] ?: (string) $fig['minifig_no'],
                'required_groups' => count($groups), 'present_groups' => $present, 'bom_part_count' => $bom,
                'missing_count' => count($missing), 'missing' => array_slice($missing, 0, 5),
                'buildable_qty' => $buildQty, 'completeness' => (int) self::pyRound(100 * $present / count($groups)),
            ];
            $row = self::priceEnrich($row, $req, $prices);
            $row['_requirements'] = $req;
            $rows[] = $row;
            if ($row['buildable_qty'] > 0) $buildableInternal[] = $row + ['groups' => $groups];
        }
        usort($rows, fn ($a, $b) => [$b['buildable_qty'] > 0, -$b['missing_count'], $b['completeness']] <=> [$a['buildable_qty'] > 0, -$a['missing_count'], $a['completeness']]);
        $buildable = array_values(array_filter($rows, fn ($r) => $r['buildable_qty'] > 0));
        $almostAll = array_values(array_filter($rows, fn ($r) => $r['buildable_qty'] === 0 && $r['missing_count'] === 1));
        $almost = array_values(array_filter($almostAll, fn ($r) => $r['completion_cost'] !== null));
        $almostInternal = array_map(fn ($r) => $r + ['requirements' => $r['_requirements']], $almost);
        $finish = function (array $r) use ($lots) {
            $req = $r['_requirements'];
            unset($r['_requirements']);
            return array_merge($r, ['pick_list' => self::individualPick($req, $lots)], self::urls((string) $r['minifig_no']));
        };
        $buildable = array_map($finish, $buildable);
        $almost = array_map($finish, $almost);
        [$jointRaw, $conflicts] = self::jointPlan($buildableInternal, $stock);
        $residual = self::remainingAfterJoint($stock, $jointRaw);
        $excl = self::exclusions();
        $ajCands = array_values(array_filter($almostInternal, fn ($r) => !isset($excl[(string) $r['minifig_no']])));
        [$ajRaw, $ajBlocked] = self::almostJointPlan($ajCands, $residual);
        $almostByNo = array_column($almost, null, 'minifig_no');
        $excluded = [];
        foreach ($excl as $no => $d) {
            if (isset($almostByNo[$no])) $excluded[] = ['minifig_no' => (string) $no, 'name' => $almostByNo[$no]['name'], 'reason' => $d['reason'] ?: 'not_build', 'decided_at' => $d['decided_at']];
        }
        $joint = self::attachPickLists($jointRaw, $lots);
        $almostJoint = self::attachAlmostJointPickLists($jointRaw, $ajRaw, $lots);
        $freq = self::missingFrequency($almost);
        // Python-Wahrheitswerte nachbilden: 0 bzw. 0.0 zählt wie „nicht vorhanden“ (x or 0)
        $or = fn ($v, $d) => $v ? $v : $d;
        $byValue = $buildable;
        usort($byValue, fn ($a, $b) => [$b['figure_value'] !== null, $or($b['figure_value'], 0), $or($b['parts_value'], 0)]
            <=> [$a['figure_value'] !== null, $or($a['figure_value'], 0), $or($a['parts_value'], 0)]);
        $byAdv = $buildable;
        usort($byAdv, fn ($a, $b) => [$b['economic_advantage'] !== null, $or($b['economic_advantage'], -999999)]
            <=> [$a['economic_advantage'] !== null, $or($a['economic_advantage'], -999999)]);
        $byPot = $almost;
        usort($byPot, fn ($a, $b) => [$b['economic_advantage'] !== null, $or($b['economic_advantage'], -999999), -$or($b['completion_cost'], 999999)]
            <=> [$a['economic_advantage'] !== null, $or($a['economic_advantage'], -999999), -$or($a['completion_cost'], 999999)]);
        return [
            'buildable' => array_slice($buildable, 0, $limit), 'buildable_by_value' => array_slice($byValue, 0, $limit),
            'buildable_by_advantage' => array_slice($byAdv, 0, $limit), 'almost_by_potential' => array_slice($byPot, 0, $limit),
            'missing_frequency' => array_slice($freq, 0, $limit), 'joint_buildable' => array_slice($joint, 0, $limit),
            'joint_conflicts' => array_slice($conflicts, 0, $limit), 'joint_buildable_count' => array_sum(array_map(fn ($r) => (int) ($r['buildable_qty'] ?: 1), $joint)),
            'joint_buildable_distinct_count' => count($joint), 'joint_conflict_count' => count($conflicts),
            'almost_joint_buildable' => array_slice($almostJoint, 0, $limit), 'almost_joint_buildable_count' => count($almostJoint),
            'almost_joint_blocked_count' => count($ajBlocked), 'almost_joint_excluded' => $excluded, 'almost_joint_excluded_count' => count($excluded),
            'individual_buildable_count' => count($buildable), 'almost_count' => count($almost),
            'almost_without_cost_count' => count($almostAll) - count($almost), 'under_min_parts_count' => $underMin,
            'almost' => array_slice($almost, 0, $limit), 'all_count' => count($rows),
        ] + self::statusCounts();
    }

    // ---------------------------------------------------------------- Stand und Budget

    public static function statusCounts(): array
    {
        $now = now();
        return [
            'scanned_variants' => (int) q('SELECT COUNT(*) FROM {minifig_superset_cache}')->fetchColumn(),
            'candidate_minifigs' => (int) q('SELECT COUNT(*) FROM {minifig_catalog}')->fetchColumn(),
            'cached_subsets' => (int) q('SELECT COUNT(*) FROM {minifig_catalog} WHERE subset_json IS NOT NULL')->fetchColumn(),
            'subset_permanent_failed' => (int) q("SELECT COUNT(*) FROM {minifig_subset_state} WHERE status = 'permanent'")->fetchColumn(),
            'subset_retry_waiting' => (int) q("SELECT COUNT(*) FROM {minifig_subset_state} WHERE status = 'retry' AND next_retry_at > ?", [$now])->fetchColumn(),
            'subset_retry_ready' => (int) q("SELECT COUNT(*) FROM {minifig_subset_state} WHERE status = 'retry' AND (next_retry_at IS NULL OR next_retry_at <= ?)", [$now])->fetchColumn(),
        ];
    }

    public static function budget(): array
    {
        $c = self::cfg();
        $u = ApiLog::usage24h('bricklink');
        return ['api_usage_24h' => $u['total'], 'api_usage_24h_by_type' => (object) $u['by_type'],
            'safe_available_calls' => max(0, $c['daily_budget'] - $c['reserve'] - $u['total']),
            'api_daily_budget' => $c['daily_budget'], 'api_reserved_calls' => $c['reserve']];
    }

    private static function refreshStatus(): array
    {
        $keys = array_flip(array_map(fn ($v) => self::key($v['item_no'], $v['color_id']), self::variants('core')));
        $stamps = [];
        foreach (q('SELECT item_no, color_id, fetched_at FROM {minifig_superset_cache}')->fetchAll() as $r) {
            if (isset($keys[self::key((string) $r['item_no'], (int) $r['color_id'])])) $stamps[] = $r['fetched_at'];
        }
        return ['refreshable_variants' => count($stamps), 'oldest_checked_at' => $stamps ? min($stamps) : null, 'latest_checked_at' => $stamps ? max($stamps) : null];
    }

    public static function priceStatus(): array
    {
        $parts = $figs = 0;
        foreach (q("SELECT UPPER(item_type) AS t, COUNT(*) AS n FROM {price_guide} WHERE currency = 'EUR' GROUP BY UPPER(item_type)")->fetchAll() as $r) {
            if (in_array($r['t'], ['PART', 'P'], true)) $parts += (int) $r['n'];
            elseif (in_array($r['t'], ['MINIFIG', 'MINIFIGURE', 'M'], true)) $figs += (int) $r['n'];
        }
        $meta = json_decode(Dev::setting('price_import_meta') ?? 'null', true) ?: [];
        return ['price_guide_part_count' => $parts, 'price_guide_minifig_count' => $figs,
            'price_guide_import_at' => $meta['imported_at'] ?? null, 'price_guide_import_filename' => $meta['filename'] ?? null,
            'price_guide_import_rows' => (int) ($meta['row_count'] ?? 0)];
    }

    public static function status(): array
    {
        self::ensureBundledCatalog();
        $variants = self::variants('core');
        $r = self::statusCounts();
        $inv = q('SELECT COALESCE(SUM(qty), 0) AS parts, COUNT(*) AS lots, COALESCE(SUM(qty * price), 0) AS value FROM {inventory}')->fetch();
        $sync = Dev::setting('sync_inventory_at');
        $r += [
            'eligible_core_variants' => count($variants),
            'remaining_core_variants' => max(0, count($variants) - $r['scanned_variants']),
            'remaining_subsets' => max(0, $r['candidate_minifigs'] - $r['cached_subsets']),
            'inventory_parts' => (int) $inv['parts'], 'inventory_lots' => (int) $inv['lots'], 'inventory_value' => round((float) $inv['value'], 2),
            'inventory_import_at' => $sync, 'bricklink_configured' => BrickLinkClient::fromConfig()->isConfigured(),
            'source' => self::stockSource(), 'priority' => self::priority(), 'bsx_files' => self::bsxFiles(),
            'katalog' => json_decode((string) Dev::setting('minifig_bundle_meta'), true) ?: null,
        ];
        return $r + self::refreshStatus() + self::budget() + self::priceStatus();
    }

    // ---------------------------------------------------------------- Abrufe bei BrickLink (nur auf Knopfdruck)

    private static function supersetCandidates($data): array
    {
        $c = [];
        foreach (is_array($data) ? $data : [] as $g) {
            foreach ((is_array($g) ? ($g['entries'] ?? []) : []) as $e) {
                $item = is_array($e) ? ($e['item'] ?? []) : [];
                if (strtoupper((string) ($item['type'] ?? '')) !== 'MINIFIG') continue;
                $no = trim((string) ($item['no'] ?? ''));
                if ($no === '') continue;
                $c[$no] = [trim((string) ($item['name'] ?? '')), $item['category_id'] ?? $item['categoryID'] ?? null];
            }
        }
        return $c;
    }

    /** Superset-Antwort speichern, neue Figuren in den Katalog aufnehmen (Stücklisten bleiben erhalten). */
    public static function saveSuperset(string $no, int $color, $data): array
    {
        $t = now();
        $cands = self::supersetCandidates($data);
        $known = [];
        foreach (array_keys($cands) as $fno) if (q('SELECT 1 FROM {minifig_catalog} WHERE minifig_no = ?', [$fno])->fetch()) $known[$fno] = true;
        q('DELETE FROM {minifig_superset_cache} WHERE item_no = ? AND color_id = ?', [$no, $color]);
        q('INSERT INTO {minifig_superset_cache} (item_no, color_id, fetched_at, response_json) VALUES (?, ?, ?, ?)', [$no, $color, $t, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        foreach ($cands as $fno => [$name, $cat]) {
            $fno = (string) $fno;
            if (isset($known[$fno])) {
                q("UPDATE {minifig_catalog} SET name = CASE WHEN ? <> '' THEN ? ELSE name END, category_id = COALESCE(?, category_id), last_seen_at = ? WHERE minifig_no = ?",
                    [$name, html_entity_decode($name, ENT_QUOTES | ENT_HTML5), $cat, $t, $fno]);
            } else {
                q('INSERT INTO {minifig_catalog} (minifig_no, name, category_id, first_seen_at, last_seen_at, seq) VALUES (?, ?, ?, ?, ?, ?)',
                    [$fno, html_entity_decode($name, ENT_QUOTES | ENT_HTML5), $cat, $t, $t, self::nextSeq()]);
            }
        }
        $new = array_values(array_diff(array_map('strval', array_keys($cands)), array_keys($known)));
        sort($new, SORT_STRING);
        return ['candidate_hits' => count($cands), 'new_minifigs' => array_map(fn ($n) => ['minifig_no' => $n, 'name' => $cands[$n][0] ?: $n], $new)];
    }

    public static function saveSubset(string $no, $data): void
    {
        q('UPDATE {minifig_catalog} SET subset_json = ?, subset_fetched_at = ? WHERE minifig_no = ?', [json_encode($data, JSON_UNESCAPED_UNICODE), now(), $no]);
        q('DELETE FROM {minifig_subset_state} WHERE minifig_no = ?', [$no]);
    }

    /** Neue Teile-Varianten prüfen: Welche Minifiguren enthalten dieses Teil? (1 Abruf je Variante) */
    public static function scan(int $batch, string $scope = 'core'): array
    {
        $batch = max(1, min($batch, 1000));
        $budget = self::budget();
        $batch = min($batch, $budget['safe_available_calls']);
        $variants = self::variants($scope);
        $cached = [];
        foreach (q('SELECT item_no, color_id FROM {minifig_superset_cache}')->fetchAll() as $r) $cached[self::key((string) $r['item_no'], (int) $r['color_id'])] = true;
        $todo = array_slice(array_values(array_filter($variants, fn ($v) => !isset($cached[self::key($v['item_no'], $v['color_id'])]))), 0, $batch);
        $bl = BrickLinkClient::fromConfig();
        $calls = $errors = $found = 0;
        foreach ($todo as $v) {
            $calls++;
            try {
                $data = $bl->get('/items/part/' . rawurlencode($v['item_no']) . '/supersets', ['color_id' => $v['color_id']], 'catalog_supersets');
            } catch (ApiException $e) {
                $errors++;
                if (in_array($e->status, [401, 403, 429], true)) break;
                continue;
            }
            $found += self::saveSuperset($v['item_no'], $v['color_id'], $data)['candidate_hits'];
        }
        $c = self::statusCounts();
        return $c + ['api_calls' => $calls, 'errors' => $errors, 'candidate_hits' => $found, 'scope' => $scope, 'eligible_variants' => count($variants),
            'remaining_variants' => max(0, count($variants) - $c['scanned_variants'])] + self::budget();
    }

    /** Bereits geprüfte Varianten erneut abfragen (älteste zuerst), um neue Figuren zu entdecken. */
    public static function refreshCatalog(int $batch): array
    {
        $batch = max(1, min($batch, 1000));
        $batch = min($batch, self::budget()['safe_available_calls']);
        $byKey = [];
        foreach (self::variants('core') as $v) $byKey[self::key($v['item_no'], $v['color_id'])] = $v;
        $todo = [];
        foreach (q('SELECT item_no, color_id, fetched_at FROM {minifig_superset_cache} ORDER BY fetched_at ASC, LOWER(item_no), color_id')->fetchAll() as $r) {
            $v = $byKey[self::key((string) $r['item_no'], (int) $r['color_id'])] ?? null;
            if ($v === null) continue;
            $todo[] = $v + ['last_checked_at' => $r['fetched_at']];
            if (count($todo) >= $batch) break;
        }
        $bl = BrickLinkClient::fromConfig();
        $calls = $errors = $hits = 0;
        $new = [];
        foreach ($todo as $v) {
            $calls++;
            try {
                $data = $bl->get('/items/part/' . rawurlencode($v['item_no']) . '/supersets', ['color_id' => $v['color_id']], 'catalog_supersets');
            } catch (ApiException $e) {
                $errors++;
                if (in_array($e->status, [401, 403, 429], true)) break;
                continue;
            }
            $s = self::saveSuperset($v['item_no'], $v['color_id'], $data);
            $hits += $s['candidate_hits'];
            foreach ($s['new_minifigs'] as $f) $new[$f['minifig_no']] = $f;
        }
        ksort($new, SORT_STRING);
        $c = self::statusCounts();
        return $c + ['api_calls' => $calls, 'errors' => $errors, 'candidate_hits' => $hits, 'new_minifig_count' => count($new),
            'new_minifigs' => array_values($new), 'remaining_subsets' => max(0, $c['candidate_minifigs'] - $c['cached_subsets'])]
            + self::refreshStatus() + self::budget();
    }

    /** Fehlerregeln für Stücklisten-Abrufe: [dauerhaft, erneut nach Stunden, Lauf abbrechen]. */
    private static function failurePolicy(int $code): array
    {
        if (in_array($code, [400, 404, 410], true)) return [true, 0, false];
        if (in_array($code, [401, 403], true)) return [false, 24, true];
        if ($code === 429) return [false, 6, true];
        if ($code === 0 || $code >= 500) return [false, 6, false];
        if ($code >= 400) return [false, 24, false];
        return [false, 6, false];
    }

    /** Stücklisten (Subsets) noch unbekannter Figuren laden. Nur ein Lauf gleichzeitig. */
    public static function fetchSubsets(int $batch): array
    {
        $req = max(1, min($batch, 1000));
        $lock = Dev::setting('minifig_lock');
        if ($lock && strtotime($lock) > time()) {
            return self::statusCounts() + ['api_calls' => 0, 'errors' => 0, 'loaded_subsets' => 0, 'permanent_failed_this_run' => 0,
                'deferred_this_run' => 0, 'stopped_early' => true, 'already_running' => true, 'requested_batch_size' => $req, 'effective_call_limit' => 0];
        }
        Dev::setSetting('minifig_lock', date('Y-m-d H:i:s', time() + 3600));
        try {
            $budget = self::budget();
            $limit = min($req, $budget['safe_available_calls']);
            $todo = q("SELECT c.minifig_no, c.name FROM {minifig_catalog} c LEFT JOIN {minifig_subset_state} f ON f.minifig_no = c.minifig_no
                WHERE c.subset_json IS NULL AND (f.minifig_no IS NULL OR (f.status = 'retry' AND (f.next_retry_at IS NULL OR f.next_retry_at <= ?)))
                ORDER BY c.last_seen_at DESC, c.minifig_no LIMIT " . (int) $limit, [now()])->fetchAll();
            $bl = BrickLinkClient::fromConfig();
            $calls = $errors = $loaded = $perm = $deferred = 0;
            $stopped = false;
            foreach ($todo as $fig) {
                if ($calls >= $limit) { $stopped = true; break; }
                $calls++;
                try {
                    $data = $bl->get('/items/minifig/' . rawurlencode($fig['minifig_no']) . '/subsets', [], 'catalog_subsets');
                } catch (ApiException $e) {
                    $errors++;
                    [$permanent, $hours, $stop] = self::failurePolicy($e->status);
                    $prev = q('SELECT attempts FROM {minifig_subset_state} WHERE minifig_no = ?', [$fig['minifig_no']])->fetchColumn();
                    q('DELETE FROM {minifig_subset_state} WHERE minifig_no = ?', [$fig['minifig_no']]);
                    q('INSERT INTO {minifig_subset_state} (minifig_no, status, attempts, last_status_code, last_error, last_attempt_at, next_retry_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)', [$fig['minifig_no'], $permanent ? 'permanent' : 'retry', ((int) $prev) + 1, $e->status,
                        mb_substr($e->getMessage(), 0, 1000), now(), $permanent ? null : date('Y-m-d H:i:s', time() + max(1, $hours ?: 6) * 3600)]);
                    $permanent ? $perm++ : $deferred++;
                    if ($stop) { $stopped = true; break; }
                    continue;
                }
                self::saveSubset((string) $fig['minifig_no'], $data);
                $loaded++;
            }
            $c = self::statusCounts();
            return $c + ['api_calls' => $calls, 'errors' => $errors, 'loaded_subsets' => $loaded, 'permanent_failed_this_run' => $perm,
                'deferred_this_run' => $deferred, 'stopped_early' => $stopped, 'already_running' => false, 'requested_batch_size' => $req,
                'effective_call_limit' => $limit, 'remaining_subsets' => max(0, $c['candidate_minifigs'] - $c['cached_subsets'])] + $budget;
        } finally {
            q('DELETE FROM {settings} WHERE setting_key = ?', ['minifig_lock']);
        }
    }

    // ---------------------------------------------------------------- Entscheidungen

    public static function decide(string $no, string $action, string $reason): array
    {
        $no = trim($no);
        $action = strtolower(trim($action));
        if ($no === '') json_error('minifig_no fehlt');
        if (!in_array($action, ['exclude', 'allow'], true)) json_error('action muss exclude oder allow sein');
        $reason = strtolower(trim($reason ?: 'not_build'));
        if (!in_array($reason, ['parts_value', 'missing_cost', 'not_build'], true)) $reason = 'not_build';
        q('DELETE FROM {minifig_decisions} WHERE minifig_no = ?', [$no]);
        if ($action === 'exclude') q('INSERT INTO {minifig_decisions} (minifig_no, decision, reason, decided_at) VALUES (?, ?, ?, ?)', [$no, 'exclude', $reason, now()]);
        $r = self::analyze(10000);
        return ['ok' => true, 'minifig_no' => $no, 'action' => $action, 'reason' => $action === 'exclude' ? $reason : '',
            'almost_joint_buildable_count' => $r['almost_joint_buildable_count'], 'almost_joint_blocked_count' => $r['almost_joint_blocked_count'],
            'almost_joint_excluded_count' => $r['almost_joint_excluded_count']];
    }

    // ---------------------------------------------------------------- Exporte

    public const LIST_FILES = [
        'all' => '01-baubar-und-1-teil-fehlt', 'buildable' => '02-einzeln-baubar', 'joint' => '03-gemeinsam-baubar',
        'almost_joint' => '04-gemeinsam-ergaenzbar-1-teil-fehlt', 'almost' => '05-nur-1-teil-fehlt',
    ];

    private static function exportRows(string $kind, ?array $result = null): array
    {
        $r = $result ?? self::analyze(10000);
        if (!isset(self::LIST_FILES[$kind])) $kind = 'all';
        $jointIds = array_flip(array_column($r['joint_buildable'], 'minifig_no'));
        $ajIds = array_flip(array_column($r['almost_joint_buildable'], 'minifig_no'));
        $rows = match ($kind) {
            'joint' => $r['joint_buildable'], 'almost_joint' => $r['almost_joint_buildable'], 'buildable' => $r['buildable'],
            'almost' => $r['almost'], default => array_merge($r['buildable'], $r['almost']),
        };
        $out = [];
        foreach ($rows as $row) {
            $no = (string) $row['minifig_no'];
            $status = isset($ajIds[$no]) ? 'Gemeinsam ergänzbar · 1 Teil fehlt' : (!empty($row['missing']) ? 'Nur 1 Teil fehlt'
                : (isset($jointIds[$no]) ? 'Gemeinsam baubar' : 'Einzeln baubar'));
            $miss = count($row['missing'] ?? []) === 1 ? $row['missing'][0] : [];
            $locs = [];
            foreach ($row['pick_list'] ?? [] as $p) {
                if ((int) ($p['shortfall'] ?? 0) > 0) continue;
                foreach ($p['sources'] ?? [] as $s) {
                    // Teile aus BSX-Dateien: Dateiname · Remarks
                    $l = implode(' · ', array_filter([trim((string) ($s['file'] ?? '')), trim((string) (($s['remarks'] ?? '') ?: ($s['stock_room_id'] ?? '')))], fn ($x) => $x !== ''));
                    if ($l !== '') $locs["{$p['item_no']}: $l"] = true;
                }
            }
            $out[] = ['minifig_no' => $no, 'name' => (string) $row['name'], 'status' => $status, 'completeness' => $row['completeness'] ?? null,
                'bom_part_count' => $row['bom_part_count'] ?? null, 'figure_value' => $row['figure_value'] ?? null, 'parts_value' => $row['parts_value'] ?? null,
                'completion_cost' => $row['completion_cost'] ?? null, 'economic_advantage' => $row['economic_advantage'] ?? null,
                'missing_item_no' => (string) ($miss['item_no'] ?? ''), 'missing_item_name' => (string) ($miss['item_name'] ?? ''),
                'missing_color_id' => $miss['color_id'] ?? null,
                'missing_quantity' => $miss ? max(0, (int) $miss['quantity'] - (int) ($miss['have'] ?? 0)) : '',
                'locations' => implode(' | ', array_keys($locs))];
        }
        return $out;
    }

    /** CSV wie Pythons csv-Modul: Semikolon, Anführungszeichen nur bei Bedarf, Zeilenende CRLF, BOM für Excel. */
    private static function csv(array $header, array $rows): string
    {
        $cell = function ($v) {
            $s = (string) ($v ?? '');
            return preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $out = "\xEF\xBB\xBF";
        foreach ([$header, ...$rows] as $r) $out .= implode(';', array_map($cell, $r)) . "\r\n";
        return $out;
    }

    private static function eur($v): string
    {
        return $v === null ? '' : str_replace('.', ',', number_format((float) $v, 2, '.', ''));
    }

    public static function listCsv(string $kind, ?array $result = null): string
    {
        return self::csv(['Minifigur', 'Name', 'Status', 'Vollständigkeit %', 'Teileanzahl', 'Figurenwert', 'Lagerteilewert', 'Fehlteilkosten',
            'Wirtschaftlicher Vorteil', 'Fehlteil', 'Fehlteilname', 'Farbe', 'Fehlmenge', 'Lagerorte'], array_map(fn ($r) => [
            $r['minifig_no'], $r['name'], $r['status'], $r['completeness'] ?? '', $r['bom_part_count'] ?? '',
            self::eur($r['figure_value']), self::eur($r['parts_value']), self::eur($r['completion_cost']), self::eur($r['economic_advantage']),
            $r['missing_item_no'], $r['missing_item_name'], $r['missing_color_id'] ?? '', $r['missing_quantity'], $r['locations'],
        ], self::exportRows($kind, $result)));
    }

    public static function listBsx(string $kind, ?array $result = null): string
    {
        return Bsx::build(array_map(fn ($r) => ['ItemTypeID' => 'M', 'ItemID' => $r['minifig_no'], 'ItemName' => $r['name'], 'ColorID' => '0',
            'Qty' => '1', 'Price' => '0', 'Condition' => 'U', 'Status' => 'I',
            'Remarks' => $r['status'] . ($r['missing_item_no'] ? " | Fehlteil: {$r['missing_item_no']} Farbe {$r['missing_color_id']}" : '')],
            self::exportRows($kind, $result)));
    }

    public static function allListsZip(string $format): string
    {
        $r = self::analyze(10000);
        $files = [];
        foreach (self::LIST_FILES as $kind => $name) $files["$name.$format"] = $format === 'bsx' ? self::listBsx($kind, $r) : self::listCsv($kind, $r);
        return Bsx::zip($files);
    }

    private static function almostJointMissingRows(): array
    {
        $rows = [];
        foreach (self::analyze(10000)['almost_joint_buildable'] as $f) {
            foreach ($f['missing'] as $m) {
                $rows[] = ['minifig_no' => (string) $f['minifig_no'], 'minifig_name' => (string) $f['name'], 'item_no' => (string) $m['item_no'],
                    'item_name' => (string) (($m['item_name'] ?? '') ?: $m['item_no']), 'color_id' => (int) $m['color_id'],
                    'quantity' => max(1, max(0, (int) $m['quantity']) - max(0, (int) ($m['have'] ?? 0))),
                    'estimated_total' => $f['completion_cost'], 'figure_value' => $f['figure_value'], 'economic_advantage' => $f['economic_advantage']];
            }
        }
        return $rows;
    }

    public static function almostJointCsv(): string
    {
        return self::csv(['Minifigur', 'Name', 'Fehlteil', 'Teilname', 'Farbe', 'Menge', 'geschätzte Fehlteilkosten', 'Figurenwert', 'Vorteil nach Zukauf'],
            array_map(fn ($r) => [$r['minifig_no'], $r['minifig_name'], $r['item_no'], $r['item_name'], $r['color_id'], $r['quantity'],
                self::eur($r['estimated_total']), self::eur($r['figure_value']), self::eur($r['economic_advantage'])], self::almostJointMissingRows()));
    }

    public static function almostJointBsx(): string
    {
        $agg = [];
        foreach (self::almostJointMissingRows() as $r) {
            $k = $r['item_no'] . "\0" . str_pad((string) $r['color_id'], 10, '0', STR_PAD_LEFT);
            $agg[$k] ??= $r + ['figures' => []];
            if (count($agg[$k]['figures'])) $agg[$k]['quantity'] += $r['quantity'];
            $agg[$k]['figures'][] = $r['minifig_no'];
        }
        ksort($agg, SORT_STRING);
        return Bsx::build(array_map(fn ($r) => ['ItemTypeID' => 'P', 'ItemID' => $r['item_no'], 'ItemName' => $r['item_name'], 'ColorID' => (string) $r['color_id'],
            'Qty' => (string) $r['quantity'], 'Price' => '0', 'Condition' => 'U', 'Status' => 'I', 'Remarks' => 'Minifig Finder: ' . implode(', ', $r['figures'])], array_values($agg)));
    }

    /** BrickStore-Preispaket: alle bekannten Figuren und die relevanten Teile, ohne API-Abruf. */
    public static function priceExport(): string
    {
        $r = self::analyze(10000);
        $parts = [];
        foreach (array_merge($r['buildable'], $r['almost']) as $row) {
            foreach ($row['pick_list'] as $p) {
                $no = trim((string) $p['item_no']);
                if ($no !== '') $parts[mb_strtolower($no) . "\0" . str_pad((string) $p['color_id'], 10, '0', STR_PAD_LEFT)] = $p;
            }
        }
        ksort($parts, SORT_STRING);
        $items = [];
        foreach (q('SELECT minifig_no, name FROM {minifig_catalog} ORDER BY LOWER(minifig_no)')->fetchAll() as $f) {
            $items[] = ['ItemTypeID' => 'M', 'ItemID' => $f['minifig_no'], 'ItemName' => $f['name'] ?: $f['minifig_no'], 'ColorID' => '0', 'ColorName' => '',
                'Qty' => '1', 'Price' => '0', 'Condition' => 'U', 'Status' => 'I'];
        }
        foreach ($parts as $p) {
            $items[] = ['ItemTypeID' => 'P', 'ItemID' => $p['item_no'], 'ItemName' => $p['item_name'], 'ColorID' => (string) $p['color_id'],
                'ColorName' => $p['color_name'], 'Qty' => '1', 'Price' => '0', 'Condition' => 'U', 'Status' => 'I'];
        }
        return Bsx::build($items);
    }

    /** Preise aus BrickStore übernehmen (6-Monats-Durchschnitt). Ohne $apply nur Vorschau. */
    public static function priceImport(string $content, bool $apply, string $filename = ''): array
    {
        [$rows, $currency] = Bsx::parse($content);
        if ($currency !== 'EUR') throw new RuntimeException("Die BrickStore-Datei hat Währung $currency; erwartet wird EUR");
        $grouped = [];
        foreach ($rows as $r) {
            if ($r['price'] <= 0) continue;
            $grouped[implode("\0", [$r['item_type'], $r['item_no'], $r['color_id'], $r['condition']])][] = $r['price'];
        }
        $conflicts = $usable = 0;
        foreach ($grouped as $p) (max($p) - min($p) > 0.001) ? $conflicts++ : $usable++;
        if (!$apply) return ['kind' => 'guide', 'currency' => 'EUR', 'rows' => count($rows), 'combinations' => count($grouped), 'usable_combinations' => $usable, 'conflicts' => $conflicts];
        $t = now();
        $imported = 0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($grouped as $k => $p) {
                if (max($p) - min($p) > 0.001) continue;
                [$type, $no, $color, $cond] = explode("\0", $k);
                q('DELETE FROM {price_guide} WHERE item_type = ? AND item_no = ? AND color_id = ? AND new_or_used = ? AND currency = ?', [$type, $no, (int) $color, $cond, 'EUR']);
                q('INSERT INTO {price_guide} (item_type, item_no, color_id, new_or_used, currency, avg_price, source, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$type, $no, (int) $color, $cond, 'EUR', array_sum($p) / count($p), 'brickstore_import', $t]);
                $imported++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Dev::setSetting('price_import_meta', json_encode(['imported_at' => $t, 'filename' => mb_substr($filename, 0, 120), 'row_count' => $imported]));
        return ['kind' => 'guide', 'currency' => 'EUR', 'rows' => count($rows), 'combinations' => count($grouped), 'imported_combinations' => $imported,
            'conflicts' => $conflicts, 'applied' => true, 'imported_at' => $t];
    }

    public static function offlinePriceStatus(): array
    {
        $r = self::analyze(10000);
        $rank = array_merge($r['buildable'], $r['almost']);
        return self::priceStatus() + [
            'rank_rows' => count($rank), 'rank_figures_priced' => count(array_filter($rank, fn ($x) => $x['figure_value'] !== null)),
            'almost_rows' => count($r['almost']), 'almost_missing_priced' => count(array_filter($r['almost'], fn ($x) => $x['completion_cost'] !== null)),
            'full_part_coverage' => count(array_filter($rank, fn ($x) => (int) $x['price_coverage_pct'] === 100)),
            'candidate_minifigs' => $r['candidate_minifigs'],
        ];
    }

    // ---------------------------------------------------------------- Übertragen (Katalog, Stücklisten, Preise)

    public static function transferExport(): string
    {
        $tables = [];
        foreach (self::TRANSFER_TABLES as $tb) $tables[$tb] = q("SELECT * FROM {{$tb}}")->fetchAll();
        $payload = ['format' => 'tn-dashboard-minifig-transfer', 'version' => self::TRANSFER_VERSION, 'exported_at' => now(),
            'tables' => $tables, 'price_guide' => q("SELECT * FROM {price_guide} WHERE currency = 'EUR'")->fetchAll()];
        return Bsx::zip(['minifig-transfer.json' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    public static function transferImport(string $zip): array
    {
        if ($zip === '') throw new RuntimeException('Leere Transferdatei.');
        $raw = Bsx::unzip($zip, 'minifig-transfer.json');
        if ($raw === null) throw new RuntimeException('Keine gültige Minifig-Transferdatei.');
        $p = json_decode($raw, true);
        if (!is_array($p) || ($p['format'] ?? '') !== 'tn-dashboard-minifig-transfer') throw new RuntimeException('Falsches Transferformat.');
        if ((int) ($p['version'] ?? 0) !== self::TRANSFER_VERSION) throw new RuntimeException('Nicht unterstützte Transferversion.');
        $keys = ['minifig_superset_cache' => ['item_no', 'color_id'], 'minifig_catalog' => ['minifig_no'], 'minifig_subset_state' => ['minifig_no'],
            'minifig_decisions' => ['minifig_no'], 'price_guide' => ['item_type', 'item_no', 'color_id', 'new_or_used', 'currency']];
        $imported = [];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($keys as $tb => $pk) {
                $rows = $tb === 'price_guide' ? ($p['price_guide'] ?? []) : ($p['tables'][$tb] ?? []);
                if (!is_array($rows)) throw new RuntimeException("Ungültige Daten für $tb.");
                $cols = self::tableColumns($tb);
                $n = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $use = array_values(array_filter($cols, fn ($c) => array_key_exists($c, $row)));
                    if (array_diff($pk, $use)) continue;
                    q("DELETE FROM {{$tb}} WHERE " . implode(' AND ', array_map(fn ($c) => "$c = ?", $pk)), array_map(fn ($c) => $row[$c], $pk));
                    q("INSERT INTO {{$tb}} (" . implode(', ', $use) . ') VALUES (' . implode(', ', array_fill(0, count($use), '?')) . ')', array_map(fn ($c) => $row[$c], $use));
                    $n++;
                }
                $imported[$tb] = $n;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'version' => self::TRANSFER_VERSION, 'exported_at' => $p['exported_at'] ?? null, 'imported' => $imported];
    }

    // ---------------------------------------------------------------- Fertige Figuren (gemeinsame Sammlung, Wunsch des Inhabers, Sitzung 7)
    // Ablauf: „Fertig gebaut“ im gemeinsamen Bauplan legt eine Figur mit ihren Lots in die Sammlung (status open). Die Teile sind ab dann
    // in der Rechnung abgezogen. „Sammlung abschließen“ (status export, nur eine Person zugleich) erzeugt eine BrickLink-Datei mit
    // Minus-Mengen je Lot und geänderte BSX-Dateien. Erst „Ja, ist hochgeladen“ zieht die Teile im Dashboard-Bestand ab (status done).

    /** Von gesammelten Figuren belegte Mengen je Lot (Lot-Nr. wie in partRows). */
    private static function reservedByBuilds(): array
    {
        $out = [];
        foreach (q("SELECT lots_json FROM {minifig_builds} WHERE status IN ('open', 'export')")->fetchAll(PDO::FETCH_COLUMN) as $j) {
            foreach (json_decode((string) $j, true) ?: [] as $l) $out[(int) $l['inventory_id']] = ($out[(int) $l['inventory_id']] ?? 0) + (int) $l['quantity'];
        }
        return $out;
    }

    /** Läuft gerade ein Abschluss der Sammlung? Dann darf keine Bestands-Übertragung (Umlagern) starten und umgekehrt. */
    public static function buildExportActive(): bool
    {
        self::ensureSchema();
        return (bool) q("SELECT 1 FROM {minifig_builds} WHERE status = 'export' LIMIT 1")->fetchColumn();
    }

    public static function builds(): array
    {
        self::ensureSchema();
        $rows = q("SELECT b.id, b.minifig_no, b.name, b.qty, b.lots_json, b.status, b.created_at, b.export_at, u.display_name AS user_name, e.display_name AS export_name
            FROM {minifig_builds} b LEFT JOIN {users} u ON u.id = b.user_id LEFT JOIN {users} e ON e.id = b.export_by
            WHERE b.status IN ('open', 'export') ORDER BY b.id")->fetchAll();
        $out = [];
        $export = null;
        foreach ($rows as $r) {
            $lots = json_decode((string) $r['lots_json'], true) ?: [];
            $out[] = ['id' => (int) $r['id'], 'minifig_no' => $r['minifig_no'], 'name' => $r['name'], 'qty' => (int) $r['qty'], 'status' => $r['status'],
                'created_at' => $r['created_at'], 'user_name' => $r['user_name'], 'lots' => $lots, 'parts' => array_sum(array_column($lots, 'quantity'))];
            if ($r['status'] === 'export') $export ??= ['by' => $r['export_name'], 'at' => $r['export_at']];
        }
        $bl = BrickLinkSync::openExport();
        return ['builds' => $out, 'export' => $export, 'transfer_open' => $bl !== null];
    }

    /**
     * Figur in die Sammlung legen. $lots = [{inventory_id, quantity}] aus der Teileliste des Bauplans; der Server prüft, ob die
     * Lots (nach Abzug der schon gesammelten Figuren) noch reichen, und merkt sich alles, was für die Dateien nötig ist.
     */
    public static function addBuild(string $no, int $qty, array $lots, int $userId): array
    {
        self::ensureSchema();
        $no = trim($no);
        if ($no === '' || $qty < 1) throw new InvalidArgumentException('Figur oder Anzahl fehlt');
        $want = [];
        foreach ($lots as $l) {
            $id = (int) ($l['inventory_id'] ?? 0);
            $n = (int) ($l['quantity'] ?? 0);
            if ($id > 0 && $n > 0) $want[$id] = ($want[$id] ?? 0) + $n;
        }
        if (!$want) throw new InvalidArgumentException('Keine Teile angegeben');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $avail = [];
            foreach (self::partRows() as $r) $avail[(int) $r['inventory_id']] = $r;
            $stored = [];
            foreach ($want as $id => $n) {
                $r = $avail[$id] ?? null;
                if (!$r || $r['quantity'] < $n) throw new RuntimeException('Ein Teil ist nicht mehr in dieser Menge da (vielleicht hat jemand anderes es gerade gesammelt). Die Liste wird neu berechnet.');
                $l = ['inventory_id' => $id, 'quantity' => $n, 'item_no' => $r['item_no'], 'item_name' => $r['item_name'], 'color_id' => $r['color_id'],
                    'color_name' => $r['color_name'], 'remarks' => (string) ($r['remarks'] ?? ''), 'condition' => (string) ($r['new_or_used'] ?? '')];
                if ($id >= self::BSX_BASE) {
                    $l += ['kind' => 'bsx', 'bsx_id' => intdiv($id - self::BSX_BASE, 100000), 'line' => ($id - self::BSX_BASE) % 100000, 'file' => $r['file'] ?? ''];
                } else {
                    $inv = q('SELECT id, bl_inventory_id FROM {inventory} WHERE bl_inventory_id = ?', [$id])->fetch()
                        ?: q('SELECT id, bl_inventory_id FROM {inventory} WHERE id = ? AND bl_inventory_id IS NULL', [$id])->fetch();
                    $l += ['kind' => 'bestand', 'local_id' => $inv ? (int) $inv['id'] : null, 'bl_lot_id' => $inv && $inv['bl_inventory_id'] !== null ? (int) $inv['bl_inventory_id'] : null];
                }
                $stored[] = $l;
            }
            $name = (string) (q('SELECT name FROM {minifig_catalog} WHERE minifig_no = ?', [$no])->fetchColumn() ?: $no);
            q("INSERT INTO {minifig_builds} (minifig_no, name, qty, lots_json, status, user_id, created_at) VALUES (?, ?, ?, ?, 'open', ?, ?)",
                [$no, $name, $qty, json_encode($stored, JSON_UNESCAPED_UNICODE), $userId, now()]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'minifig_no' => $no, 'name' => $name, 'qty' => $qty, 'parts' => array_sum($want)];
    }

    /** Figur wieder aus der Sammlung nehmen (nicht, solange der Abschluss läuft). */
    public static function deleteBuild(int $id): ?array
    {
        self::ensureSchema();
        $b = q("SELECT minifig_no, qty FROM {minifig_builds} WHERE id = ? AND status = 'open'", [$id])->fetch();
        if (!$b) return null;
        q('DELETE FROM {minifig_builds} WHERE id = ?', [$id]);
        return $b;
    }

    /** Lots der gesammelten Figuren im Abschluss, zusammengefasst je Lot. */
    private static function exportLots(): array
    {
        $agg = [];
        foreach (q("SELECT lots_json FROM {minifig_builds} WHERE status = 'export' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $j) {
            foreach (json_decode((string) $j, true) ?: [] as $l) {
                $k = (int) $l['inventory_id'];
                if (isset($agg[$k])) $agg[$k]['quantity'] += (int) $l['quantity'];
                else $agg[$k] = $l;
            }
        }
        return array_values($agg);
    }

    /** Abschluss: alle offenen Figuren übernehmen und die Dateien liefern. Läuft er schon, bekommt man denselben Stand. */
    public static function startBuildExport(int $userId): array
    {
        self::ensureSchema();
        if (!self::buildExportActive()) {
            if (BrickLinkSync::openExport()) json_error('Gerade läuft eine BrickLink-Übertragung vom Umlagern. Bitte die erst abschließen („Ja, ist eingefügt“ oder „Verwerfen“).', 409);
            $n = q("UPDATE {minifig_builds} SET status = 'export', export_by = ?, export_at = ? WHERE status = 'open'", [$userId, now()])->rowCount();
            if (!$n) json_error('In der Sammlung sind keine Figuren.', 400);
        }
        return self::buildExportFiles();
    }

    private static function buildExportFiles(): array
    {
        $lots = self::exportLots();
        $xml = '';
        $noLot = [];
        $files = [];
        foreach ($lots as $l) {
            if (($l['kind'] ?? '') === 'bsx') {
                $f = $files[$l['bsx_id']] ??= ['id' => (int) $l['bsx_id'], 'name' => (string) ($l['file'] ?? ''), 'lots' => 0, 'parts' => 0];
                $files[$l['bsx_id']]['lots']++;
                $files[$l['bsx_id']]['parts'] += (int) $l['quantity'];
            } elseif (!empty($l['bl_lot_id'])) {
                $xml .= "<ITEM><LOTID>{$l['bl_lot_id']}</LOTID><QTY>-" . (int) $l['quantity'] . "</QTY></ITEM>\n";
            } else {
                $noLot[] = $l;
            }
        }
        $exp = q("SELECT b.export_at, u.display_name FROM {minifig_builds} b LEFT JOIN {users} u ON u.id = b.export_by WHERE b.status = 'export' LIMIT 1")->fetch() ?: [];
        return ['xml' => $xml === '' ? '' : "<INVENTORY>\n$xml</INVENTORY>\n", 'bricklink_lots' => substr_count($xml, '<ITEM>'), 'without_lot' => $noLot,
            'bsx_files' => array_values($files), 'export_by' => $exp['display_name'] ?? null, 'export_at' => $exp['export_at'] ?? null] + self::builds();
    }

    /** Neue Fassung einer BSX-Datei (ohne die Teile der Sammlung im Abschluss); Teile mit Menge 0 fallen weg. */
    public static function bsxAfterExport(int $fileId): array
    {
        self::ensureSchema();
        $f = q('SELECT id, name, items_json FROM {minifig_bsx} WHERE id = ?', [$fileId])->fetch();
        if (!$f) throw new RuntimeException('BSX-Datei nicht gefunden');
        $items = self::bsxMinus(json_decode((string) $f['items_json'], true) ?: [], $fileId);
        $out = [];
        foreach ($items as $r) {
            if ((int) $r['quantity'] <= 0) continue;
            $out[] = ['ItemTypeID' => 'P', 'ItemID' => $r['item_no'], 'ItemName' => $r['item_name'], 'ColorID' => (string) $r['color_id'], 'ColorName' => $r['color_name'],
                'Qty' => (string) $r['quantity'], 'Price' => (string) ($r['price'] ?? 0), 'Condition' => $r['condition'] ?: 'U', 'Status' => 'I',
                'Remarks' => (string) ($r['remarks'] ?? ''), 'Comments' => (string) ($r['comments'] ?? '')];
        }
        return [preg_replace('/\.bsx$/i', '', (string) $f['name']) . '-nach-figuren.bsx', Bsx::build($out)];
    }

    /** Mengen der Sammlung im Abschluss von den Zeilen einer BSX-Datei abziehen (Zeilen bleiben stehen, damit die Lot-Nr. gleich bleiben). */
    private static function bsxMinus(array $items, int $fileId): array
    {
        foreach (self::exportLots() as $l) {
            if (($l['kind'] ?? '') !== 'bsx' || (int) $l['bsx_id'] !== $fileId || !isset($items[(int) $l['line']])) continue;
            $items[(int) $l['line']]['quantity'] = max(0, (int) $items[(int) $l['line']]['quantity'] - (int) $l['quantity']);
        }
        return $items;
    }

    /**
     * Abschluss beenden. $done = bei BrickLink hochgeladen: Bestand im Dashboard abziehen, BSX-Dateien aus $applyBsx im Finder
     * ersetzen, Figuren gelten als erledigt. Sonst gehen die Figuren zurück in die Sammlung.
     */
    public static function finishBuildExport(bool $done, array $applyBsx): array
    {
        self::ensureSchema();
        if (!self::buildExportActive()) json_error('Dieser Abschluss wurde schon beendet.', 409);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $figs = (int) q("SELECT COALESCE(SUM(qty), 0) FROM {minifig_builds} WHERE status = 'export'")->fetchColumn();
            $parts = $lotsChanged = 0;
            $files = [];
            if ($done) {
                foreach (self::exportLots() as $l) {
                    if (($l['kind'] ?? '') !== 'bestand') continue;
                    $inv = (!empty($l['local_id']) ? q('SELECT id, qty FROM {inventory} WHERE id = ?', [$l['local_id']])->fetch() : null)
                        ?: (!empty($l['bl_lot_id']) ? q('SELECT id, qty FROM {inventory} WHERE bl_inventory_id = ?', [$l['bl_lot_id']])->fetch() : null);
                    if (!$inv) continue;
                    $left = (int) $inv['qty'] - (int) $l['quantity'];
                    if ($left > 0) q('UPDATE {inventory} SET qty = ?, updated_at = ? WHERE id = ?', [$left, now(), $inv['id']]);
                    else q('DELETE FROM {inventory} WHERE id = ?', [$inv['id']]);
                    $parts += min((int) $l['quantity'], (int) $inv['qty']);
                    $lotsChanged++;
                }
                foreach (array_unique(array_map('intval', $applyBsx)) as $fid) {
                    $f = q('SELECT items_json FROM {minifig_bsx} WHERE id = ?', [$fid])->fetch();
                    if (!$f) continue;
                    $items = self::bsxMinus(json_decode((string) $f['items_json'], true) ?: [], $fid);
                    $alive = array_filter($items, fn ($r) => (int) $r['quantity'] > 0);
                    q('UPDATE {minifig_bsx} SET items_json = ?, lots = ?, parts = ? WHERE id = ?',
                        [json_encode($items, JSON_UNESCAPED_UNICODE), count($alive), array_sum(array_column($alive, 'quantity')), $fid]);
                    $files[] = $fid;
                }
                q("UPDATE {minifig_builds} SET status = 'done', done_at = ? WHERE status = 'export'", [now()]);
            } else {
                q("UPDATE {minifig_builds} SET status = 'open', export_by = NULL, export_at = NULL WHERE status = 'export'");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Ollies Dashboard kennt den Abzug erst nach seinem nächsten Laden (wie nach „Ja, ist eingefügt“ beim Umlagern)
        if ($done && $lotsChanged) OllieSync::markExported();
        return ['done' => $done, 'figures' => $figs, 'parts' => $parts, 'lots' => $lotsChanged, 'bsx_files' => $files];
    }

    // ---------------------------------------------------------------- Figurenkatalog ohne API (wie MinifigFinder Standalone)

    /**
     * Reihenfolge, in der Figuren in den Katalog kamen (Spalte seq). Das Original geht den Katalog in dieser Reihenfolge durch
     * (SQLite-rowid); bei Gleichstand bestimmt sie die Reihenfolge der Listen. Alte Installationen bekommen die Spalte hier.
     */
    private static function ensureSeq(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        self::ensureSchema();
        // Ohne seq eingefügte Figuren (alte Stände, Transferpakete) hinten anhängen
        $open = q('SELECT minifig_no FROM {minifig_catalog} WHERE seq IS NULL ORDER BY ' . (Schema::sqlite() ? 'rowid' : 'minifig_no'))->fetchAll(PDO::FETCH_COLUMN);
        if (!$open) return;
        $next = self::nextSeq();
        foreach ($open as $no) q('UPDATE {minifig_catalog} SET seq = ? WHERE minifig_no = ?', [$next++, $no]);
    }

    /** Neue Spalten und Tabellen für bestehende Installationen (Katalog-Reihenfolge, BSX-Reihenfolge, „Fertige Figuren“). */
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (Dev::setting('minifig_schema') === '2') return;
        $sqlite = Schema::sqlite();
        if (!in_array('seq', self::tableColumns('minifig_catalog'), true)) db()->exec('ALTER TABLE ' . t('minifig_catalog') . ' ADD COLUMN seq ' . ($sqlite ? 'INTEGER' : 'INT') . ' NULL');
        if (!in_array('sort_order', self::tableColumns('minifig_bsx'), true)) {
            db()->exec('ALTER TABLE ' . t('minifig_bsx') . ' ADD COLUMN sort_order ' . ($sqlite ? 'INTEGER' : 'INT') . ' NULL');
            q('UPDATE {minifig_bsx} SET sort_order = id');
        }
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('minifig_builds') . ' (
            id ' . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY') . ',
            minifig_no ' . ($sqlite ? 'TEXT' : 'VARCHAR(40)') . ' NOT NULL,
            name ' . ($sqlite ? 'TEXT' : 'VARCHAR(255)') . " NOT NULL DEFAULT '',
            qty INTEGER NOT NULL DEFAULT 1,
            lots_json " . ($sqlite ? 'TEXT' : 'MEDIUMTEXT') . ' NOT NULL,
            status ' . ($sqlite ? 'TEXT' : 'VARCHAR(16)') . " NOT NULL DEFAULT 'open',
            user_id " . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' NULL,
            created_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NOT NULL,
            export_by ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' NULL,
            export_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NULL,
            done_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NULL
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
        Dev::setSetting('minifig_seq', '1');
        Dev::setSetting('minifig_schema', '2');
    }

    public static function nextSeq(): int
    {
        return (int) q('SELECT COALESCE(MAX(seq), 0) + 1 FROM {minifig_catalog}')->fetchColumn();
    }

    public const BUNDLE_FILE = __DIR__ . '/../sql/minifig-katalog.json.gz';

    /**
     * Mitgelieferten Katalog (api/sql/minifig-katalog.json.gz, erzeugt mit tools/make-minifig-katalog.py) einmal übernehmen.
     * Ergänzt nur Fehlendes: per API geholte oder hochgeladene Stücklisten bleiben unverändert. Läuft erneut, wenn sich die Datei ändert.
     */
    public static function ensureBundledCatalog(): void
    {
        self::ensureSeq();
        if (!is_file(self::BUNDLE_FILE)) return;
        $sig = filesize(self::BUNDLE_FILE) . ':' . md5_file(self::BUNDLE_FILE);
        if (Dev::setting('minifig_bundle') === $sig) return;
        $raw = gzdecode((string) file_get_contents(self::BUNDLE_FILE));
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'tn-minifig-katalog') return;
        self::mergeCatalog($data, false);
        Dev::setSetting('minifig_bundle', $sig);
        Dev::setSetting('minifig_bundle_meta', json_encode(['quelle' => $data['quelle'] ?? '', 'stand' => $data['stand'] ?? '', 'figuren' => count($data['catalog'] ?? [])], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Katalog, Supersets und Preise übernehmen. $overwrite = false ergänzt nur (mitgelieferte Datei),
     * true ersetzt gleiche Einträge (Datei, die jemand bewusst hochlädt).
     */
    private static function mergeCatalog(array $data, bool $overwrite): array
    {
        $t = now();
        $out = ['figuren' => 0, 'supersets' => 0, 'preise' => 0];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $have = [];
            $seq = self::nextSeq();
            foreach (q('SELECT minifig_no, subset_json IS NOT NULL AS has FROM {minifig_catalog}')->fetchAll() as $r) $have[(string) $r['minifig_no']] = (bool) $r['has'];
            foreach ($data['catalog'] ?? [] as $c) {
                $no = trim((string) ($c['minifig_no'] ?? ''));
                $sub = $c['subset_json'] ?? null;
                if ($no === '' || !is_string($sub) || $sub === '') continue;
                if (isset($have[$no]) && $have[$no] && !$overwrite) continue;
                if (isset($have[$no])) {
                    q('UPDATE {minifig_catalog} SET name = ?, category_id = ?, last_seen_at = ?, subset_fetched_at = ?, subset_json = ? WHERE minifig_no = ?',
                        [(string) ($c['name'] ?? ''), $c['category_id'] ?? null, $c['last_seen_at'] ?? $t, $c['subset_fetched_at'] ?? $t, $sub, $no]);
                } else {
                    q('INSERT INTO {minifig_catalog} (minifig_no, name, category_id, first_seen_at, last_seen_at, subset_fetched_at, subset_json, seq) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$no, (string) ($c['name'] ?? ''), $c['category_id'] ?? null, $c['first_seen_at'] ?? $t, $c['last_seen_at'] ?? $t, $c['subset_fetched_at'] ?? $t, $sub, $seq++]);
                }
                $have[$no] = true;
                $out['figuren']++;
            }
            $sets = [];
            foreach (q('SELECT item_no, color_id FROM {minifig_superset_cache}')->fetchAll() as $r) $sets[self::key((string) $r['item_no'], (int) $r['color_id'])] = true;
            foreach ($data['superset'] ?? [] as $r) {
                $k = self::key((string) ($r['item_no'] ?? ''), (int) ($r['color_id'] ?? 0));
                if (($r['item_no'] ?? '') === '' || !is_string($r['response_json'] ?? null) || (isset($sets[$k]) && !$overwrite)) continue;
                q('DELETE FROM {minifig_superset_cache} WHERE item_no = ? AND color_id = ?', [(string) $r['item_no'], (int) $r['color_id']]);
                q('INSERT INTO {minifig_superset_cache} (item_no, color_id, fetched_at, response_json) VALUES (?, ?, ?, ?)',
                    [(string) $r['item_no'], (int) $r['color_id'], $r['fetched_at'] ?? $t, $r['response_json']]);
                $sets[$k] = true;
                $out['supersets']++;
            }
            $prices = [];
            foreach (q("SELECT item_type, item_no, color_id, new_or_used FROM {price_guide} WHERE currency = 'EUR'")->fetchAll() as $r) {
                $prices[implode("\0", [$r['item_type'], $r['item_no'], (int) $r['color_id'], $r['new_or_used']])] = true;
            }
            foreach ($data['price_guide'] ?? [] as $r) {
                if (($r['currency'] ?? 'EUR') !== 'EUR' || (float) ($r['avg_price'] ?? 0) <= 0 || ($r['item_no'] ?? '') === '') continue;
                $pk = [(string) $r['item_type'], (string) $r['item_no'], (int) ($r['color_id'] ?? -1), (string) ($r['new_or_used'] ?? 'U')];
                if (isset($prices[implode("\0", $pk)]) && !$overwrite) continue;
                q("DELETE FROM {price_guide} WHERE item_type = ? AND item_no = ? AND color_id = ? AND new_or_used = ? AND currency = 'EUR'", $pk);
                q("INSERT INTO {price_guide} (item_type, item_no, color_id, new_or_used, currency, avg_price, source, fetched_at) VALUES (?, ?, ?, ?, 'EUR', ?, ?, ?)",
                    [...$pk, (float) $r['avg_price'], (string) ($r['source'] ?? 'katalog'), (string) ($r['fetched_at'] ?? $t)]);
                $prices[implode("\0", $pk)] = true;
                $out['preise']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $out;
    }

    /** Katalog aus einer SQLite-Datei lesen (MinifigFinder Standalone oder Ollies Dashboard, gleiche Tabellen), nur lesend. */
    private static function catalogFromSqlite(string $bytes): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Die PHP-Erweiterung pdo_sqlite fehlt auf dem Server.');
        $tmp = tempnam(sys_get_temp_dir(), 'tnkat');
        file_put_contents($tmp, $bytes);
        try {
            $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
            if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            $src = new PDO('sqlite:' . $tmp, null, null, $opts);
            $tables = $src->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('minifig_catalog', $tables, true)) throw new RuntimeException('In der Datenbank gibt es keinen Minifig-Katalog.');
            $data = ['catalog' => $src->query("SELECT minifig_no, name, category_id, first_seen_at, last_seen_at, subset_fetched_at, subset_json
                FROM minifig_catalog WHERE subset_json IS NOT NULL AND subset_json <> '' ORDER BY rowid")->fetchAll()];
            $data['superset'] = in_array('minifig_superset_cache', $tables, true)
                ? $src->query('SELECT item_no, color_id, fetched_at, response_json FROM minifig_superset_cache')->fetchAll() : [];
            $data['price_guide'] = in_array('assortment_price_guide_cache', $tables, true)
                ? self::pricesFromCache($src->query("SELECT item_type, item_no, color_id, condition, currency_code, payload_json, fetched_at FROM assortment_price_guide_cache ORDER BY rowid")->fetchAll()) : [];
            $src = null;
            return $data;
        } finally {
            @unlink($tmp);
        }
    }

    /** Preise im Format von Ollies assortment_price_guide_cache (payload_json mit avg_price) in unser Format umrechnen. */
    private static function pricesFromCache(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (($r['currency_code'] ?? '') !== 'EUR' || !in_array(strtoupper((string) $r['item_type']), ['M', 'MINIFIG', 'MINIFIGURE', 'P', 'PART'], true)) continue;
            $p = json_decode((string) ($r['payload_json'] ?? ''), true);
            // wie local_reference_prices im Original: avg_price, sonst qty_avg_price
            $avg = is_array($p) ? (float) (($p['avg_price'] ?? 0) ?: ($p['qty_avg_price'] ?? 0)) : 0.0;
            if ($avg <= 0) continue;
            $out[] = ['item_type' => $r['item_type'], 'item_no' => $r['item_no'], 'color_id' => (int) ($r['color_id'] ?? -1), 'new_or_used' => $r['condition'] ?: 'U',
                'currency' => 'EUR', 'avg_price' => $avg, 'source' => (string) ($p['source'] ?? 'brickstore_import'), 'fetched_at' => $r['fetched_at']];
        }
        return $out;
    }

    /**
     * „Figurenkatalog einlesen“ (Technik): nimmt die Datenbank des MinifigFinder Standalone (.db), dessen Installer-ZIP,
     * Ollies Minifig-Transfer-ZIP, unser Transferpaket oder eine Katalogdatei (.json.gz). Gleiche Einträge werden ersetzt.
     */
    public static function catalogImport(string $bytes, string $name = ''): array
    {
        if ($bytes === '') throw new RuntimeException('Leere Datei.');
        if (str_starts_with($bytes, "SQLite format 3\0")) return ['quelle' => 'Datenbank', 'imported' => self::mergeCatalog(self::catalogFromSqlite($bytes), true)];
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $data = json_decode((string) gzdecode($bytes), true);
            if (!is_array($data) || ($data['format'] ?? '') !== 'tn-minifig-katalog') throw new RuntimeException('Keine gültige Katalogdatei.');
            return ['quelle' => 'Katalogdatei', 'imported' => self::mergeCatalog($data, true)];
        }
        if (!str_starts_with($bytes, 'PK')) throw new RuntimeException('Unbekanntes Format. Erwartet wird eine .db-, .zip- oder .json.gz-Datei.');
        $json = Bsx::unzip($bytes, 'minifig-transfer.json');
        if ($json !== null) {
            $p = json_decode($json, true);
            if (($p['format'] ?? '') === 'tn-dashboard-minifig-transfer') return ['quelle' => 'Transferpaket'] + self::transferImport($bytes);
            if (($p['format'] ?? '') !== 'bi-legoshop-minifig-transfer') throw new RuntimeException('Falsches Transferformat.');
            // Transfer-ZIP aus Ollies Dashboard
            $tb = $p['tables'] ?? [];
            $data = ['catalog' => $tb['minifig_catalog'] ?? [], 'superset' => $tb['minifig_superset_cache'] ?? [], 'price_guide' => self::pricesFromCache($p['price_guide_cache'] ?? [])];
            return ['quelle' => 'Ollies Transfer-ZIP', 'imported' => self::mergeCatalog($data, true)];
        }
        // Installer-ZIP des MinifigFinder Standalone: enthält data/minifigfinder.db
        $db = Bsx::unzipFind($bytes, fn ($n) => (bool) preg_match('~(^|/)(minifigfinder|legoshop)\.db$~i', $n));
        if ($db === null) throw new RuntimeException('In der ZIP-Datei wurde kein Minifig-Katalog gefunden.');
        return ['quelle' => 'Installer-ZIP', 'imported' => self::mergeCatalog(self::catalogFromSqlite($db), true)];
    }

    private static function tableColumns(string $tb): array
    {
        if (Schema::sqlite()) return array_column(db()->query('PRAGMA table_info(' . t($tb) . ')')->fetchAll(), 'name');
        return q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [t($tb)])->fetchAll(PDO::FETCH_COLUMN);
    }
}
