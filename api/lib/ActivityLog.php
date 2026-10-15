<?php

final class ActivityLog
{
    public static function log(int $userId, string $action, ?string $module = null, array $details = []): void
    {
        db()->prepare('INSERT INTO ' . t('activity_log') . ' (user_id, action, module, details, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $action, $module, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, now()]);
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
