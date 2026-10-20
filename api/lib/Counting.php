<?php
declare(strict_types=1);

/**
 * Zählen (Wunsch des Inhabers, Sitzung 7):
 * - Vormerkungen: Meldet jemand in der Pickliste ein Fehlteil, eine falsche Restmenge oder einen falschen Lagerort,
 *   wird das Kästchen zum Zählen vorgemerkt (tn_count_requests). Erledigt, sobald dort eine Inventur abgeschlossen wird.
 * - Zählliste des Tages: jeden Tag die gleichen N Kästchen für alle (tn_daily_counts), zuerst vorgemerkte,
 *   dann nie oder am längsten nicht gezählte, bei Gleichstand der höhere Lagerwert. N = tn_settings.count_daily (Standard 5).
 * Gleiche Regeln in frontend/src/demo/counting.js.
 */
final class Counting
{
    public const REASONS = ['missing' => 'Fehlteil gemeldet', 'wrong_quantity' => 'Restmenge stimmt nicht', 'wrong_location' => 'Lagerort stimmt nicht'];
    public const DEFAULT_SIZE = 5;

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $sqlite = Dev::isSqlite();
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $dt = $sqlite ? 'TEXT' : 'DATETIME';
        $end = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('count_requests') . ' (
            id ' . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY') . ',
            location_id ' . $int . ' NOT NULL,
            reason ' . ($sqlite ? 'TEXT' : 'VARCHAR(32)') . ' NOT NULL,
            report_id ' . $int . ' NULL,
            user_id ' . $int . ' NULL,
            created_at ' . $dt . ' NOT NULL,
            done_at ' . $dt . ' NULL
        )' . $end);
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('daily_counts') . ' (
            day ' . ($sqlite ? 'TEXT' : 'CHAR(10)') . ' NOT NULL,
            location_id ' . $int . ' NOT NULL,
            pos ' . $int . ' NOT NULL,
            PRIMARY KEY (day, location_id)
        )' . $end);
    }

    public static function size(): int
    {
        $v = Dev::setting('count_daily');
        return $v === null ? self::DEFAULT_SIZE : max(1, min(30, (int) $v));
    }

    public static function setSize(int $n): int
    {
        $n = max(1, min(30, $n));
        Dev::setSetting('count_daily', (string) $n);
        return $n;
    }

    /** Kästchen einer Auftragsposition: über das Lot, sonst der Lagerort-Code aus den Remarks. */
    public static function locationOfItem(int $itemId): ?int
    {
        $r = q('SELECT i.location_id, x.remarks FROM {order_items} x LEFT JOIN {inventory} i ON i.id = x.inventory_id WHERE x.id = ?', [$itemId])->fetch();
        if (!$r) return null;
        if ($r['location_id']) return (int) $r['location_id'];
        $code = trim((string) $r['remarks']);
        if ($code === '') return null;
        $id = q('SELECT id FROM {locations} WHERE UPPER(code) = UPPER(?)', [$code])->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Vormerkung aus einer Meldung (nur die drei Gründe, die auf einen falschen Bestand hindeuten). */
    public static function requestFromReport(int $itemId, string $type, int $reportId, int $userId): void
    {
        if (!isset(self::REASONS[$type])) return;
        $loc = self::locationOfItem($itemId);
        if (!$loc) return;
        q('INSERT INTO {count_requests} (location_id, reason, report_id, user_id, created_at) VALUES (?, ?, ?, ?, ?)', [$loc, $type, $reportId, $userId, now()]);
    }

    /** Inventur abgeschlossen → alle offenen Vormerkungen dieses Kästchens erledigt. */
    public static function markDone(int $locationId): void
    {
        q('UPDATE {count_requests} SET done_at = ? WHERE location_id = ? AND done_at IS NULL', [now(), $locationId]);
    }

    /** Offene Vormerkungen, je Kästchen gebündelt: [location_id => [{reason, label, by, at}]] */
    public static function openRequests(?int $locationId = null): array
    {
        $out = [];
        $rows = q('SELECT r.location_id, r.reason, r.created_at, u.display_name FROM {count_requests} r LEFT JOIN {users} u ON u.id = r.user_id
            WHERE r.done_at IS NULL' . ($locationId ? ' AND r.location_id = ?' : '') . ' ORDER BY r.created_at, r.id', $locationId ? [$locationId] : [])->fetchAll();
        foreach ($rows as $r) {
            $out[(int) $r['location_id']][] = ['reason' => $r['reason'], 'label' => self::REASONS[$r['reason']] ?? $r['reason'], 'by' => $r['display_name'], 'at' => $r['created_at']];
        }
        return $out;
    }

    /** Zählliste des Tages (beim ersten Abruf des Tages festgelegt) plus Vormerkungen, die erst danach dazukamen. */
    public static function today(): array
    {
        self::ensureSchema();
        $day = date('Y-m-d');
        $size = self::size();
        $ids = q('SELECT location_id FROM {daily_counts} WHERE day = ? ORDER BY pos', [$day])->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) $ids = self::pick($day, $size);
        $ids = array_map('intval', $ids);
        $requests = self::openRequests();
        $extra = array_values(array_diff(array_keys($requests), $ids));
        return ['day' => $day, 'size' => $size, 'items' => self::describe($ids, $requests, $day), 'extra' => self::describe($extra, $requests, $day)];
    }

    /** Auswahl: vorgemerkte (älteste Meldung zuerst), dann nie/lange nicht gezählte, dann höherer Lagerwert, dann Code natürlich. */
    private static function pick(string $day, int $size): array
    {
        $first = [];
        foreach (q('SELECT location_id, MIN(created_at) AS at, MIN(id) AS id FROM {count_requests} WHERE done_at IS NULL GROUP BY location_id')->fetchAll() as $r) {
            $first[] = ['id' => (int) $r['location_id'], 'at' => $r['at'], 'rid' => (int) $r['id']];
        }
        usort($first, fn ($a, $b) => strcmp($a['at'], $b['at']) ?: $a['rid'] <=> $b['rid']);
        $chosen = array_slice(array_column($first, 'id'), 0, $size);
        if (count($chosen) < $size) {
            $rows = q("SELECT l.id, l.code, SUM(i.qty * i.price) AS value,
                    (SELECT MAX(s.closed_at) FROM {stocktakes} s WHERE s.location_id = l.id AND s.status = 'abgeschlossen') AS last
                FROM {locations} l JOIN {inventory} i ON i.location_id = l.id WHERE i.qty > 0 GROUP BY l.id, l.code")->fetchAll();
            usort($rows, function ($a, $b) {
                if (($a['last'] === null) !== ($b['last'] === null)) return $a['last'] === null ? -1 : 1;
                return strcmp((string) $a['last'], (string) $b['last'])
                    ?: (round((float) $b['value'], 2) <=> round((float) $a['value'], 2))
                    ?: strnatcasecmp($a['code'], $b['code']);
            });
            foreach ($rows as $r) {
                if (count($chosen) >= $size) break;
                if (!in_array((int) $r['id'], $chosen, true)) $chosen[] = (int) $r['id'];
            }
        }
        foreach ($chosen as $i => $id) {
            // Gleichzeitiger erster Abruf: der zweite Eintrag scheitert am Primärschlüssel, die Liste des ersten gilt
            try { q('INSERT INTO {daily_counts} (day, location_id, pos) VALUES (?, ?, ?)', [$day, $id, $i]); } catch (PDOException) { /* schon da */ }
        }
        return q('SELECT location_id FROM {daily_counts} WHERE day = ? ORDER BY pos', [$day])->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function describe(array $ids, array $requests, string $day): array
    {
        $out = [];
        foreach ($ids as $id) {
            $l = q("SELECT l.id, l.code, COUNT(i.id) AS lots, COALESCE(SUM(i.qty), 0) AS parts, COALESCE(SUM(i.qty * i.price), 0) AS value,
                    (SELECT MAX(s.closed_at) FROM {stocktakes} s WHERE s.location_id = l.id AND s.status = 'abgeschlossen') AS last
                FROM {locations} l LEFT JOIN {inventory} i ON i.location_id = l.id WHERE l.id = ? GROUP BY l.id, l.code", [$id])->fetch();
            if (!$l) continue;
            $open = q("SELECT id FROM {stocktakes} WHERE location_id = ? AND status = 'offen' ORDER BY id DESC LIMIT 1", [$id])->fetchColumn();
            $reqs = $requests[$id] ?? [];
            $out[] = [
                'location_id' => (int) $l['id'], 'code' => $l['code'], 'lots' => (int) $l['lots'], 'parts' => (int) $l['parts'], 'value' => round((float) $l['value'], 2),
                'last_counted' => $l['last'], 'requests' => $reqs,
                'why' => $reqs ? 'vorgemerkt' : ($l['last'] === null ? 'nie gezählt' : 'lange nicht gezählt'),
                'open_stocktake' => $open === false ? null : (int) $open,
                'done' => $l['last'] !== null && substr((string) $l['last'], 0, 10) === $day && $open === false,
            ];
        }
        return $out;
    }
}
