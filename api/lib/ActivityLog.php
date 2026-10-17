<?php

final class ActivityLog
{
    public static function log(int $userId, string $action, ?string $module = null, array $details = []): void
    {
        db()->prepare('INSERT INTO ' . t('activity_log') . ' (user_id, action, module, details, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $action, $module, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, now()]);
    }

    /**
     * Zahlen je Nutzer für Abzeichen und Bestenliste (Wunsch des Inhabers, Sitzung 6), aus dem Protokoll berechnet.
     * Gleiche Regeln in frontend/src/demo/backend.js (userStats). $since = 'YYYY-MM-DD' oder null (alles).
     * picks = Aufträge auf „Verpackt“ gesetzt, orders = alle Status-Schritte, early = Aktionen vor 8 Uhr.
     */
    public static function stats(?string $since = null): array
    {
        $sql = 'SELECT a.user_id, a.action, a.details, a.created_at FROM ' . t('activity_log') . ' a
                WHERE a.action NOT IN (\'login\', \'logout\')' . ($since ? ' AND a.created_at >= ?' : '');
        $stmt = db()->prepare($sql);
        $stmt->execute($since ? [$since] : []);
        $map = ['transfer' => 'transfers', 'stocktake_closed' => 'stocktakes', 'recognize' => 'scans',
                'bricklink_export' => 'exports', 'search' => 'searches', 'order_status' => 'orders'];
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $s = &$out[(int) $r['user_id']];
            $s ??= ['total' => 0, 'picks' => 0, 'orders' => 0, 'transfers' => 0, 'stocktakes' => 0, 'scans' => 0,
                    'exports' => 0, 'searches' => 0, 'early' => 0, 'days' => []];
            $s['total']++;
            if (isset($map[$r['action']])) $s[$map[$r['action']]]++;
            if ($r['action'] === 'order_status' && (json_decode((string) $r['details'], true)['status'] ?? '') === 'Verpackt') $s['picks']++;
            if (substr((string) $r['created_at'], 11, 2) < '08') $s['early']++;
            $s['days'][substr((string) $r['created_at'], 0, 10)] = true;
            unset($s);
        }
        foreach ($out as &$s) $s['days'] = count($s['days']);
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function list(?int $userId, int $limit = 200): array
    {
        $sql = 'SELECT a.id, a.user_id, u.display_name, u.username, a.action, a.module, a.details, a.created_at
                FROM ' . t('activity_log') . ' a JOIN ' . t('users') . ' u ON u.id = a.user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . max(1, min($limit, 5000));
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['details'] = $row['details'] !== null ? json_decode($row['details'], true) : null;
        }
        return $rows;
    }
}
