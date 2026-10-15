<?php

/** Datenbank-Cache für BrickLink-Antworten, damit das Tageslimit geschont wird. */
final class Cache
{
    public static function get(string $key): mixed
    {
        $stmt = db()->prepare('SELECT payload, fetched_at, ttl_seconds FROM ' . t('bl_cache') . ' WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (strtotime($row['fetched_at']) + (int) $row['ttl_seconds'] < time()) {
            return null;
        }
        return json_decode($row['payload'], true);
    }

    public static function set(string $key, mixed $payload, int $ttl): void
    {
        $pdo = db();
        $pdo->prepare('DELETE FROM ' . t('bl_cache') . ' WHERE cache_key = ?')->execute([$key]);
        $pdo->prepare('INSERT INTO ' . t('bl_cache') . ' (cache_key, payload, fetched_at, ttl_seconds) VALUES (?, ?, ?, ?)')
            ->execute([$key, json_encode($payload, JSON_UNESCAPED_UNICODE), now(), $ttl]);
    }

    /** Liefert den gecachten Wert oder ruft $fetch auf und speichert das Ergebnis. */
    public static function remember(string $key, int $ttl, callable $fetch): array
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return ['data' => $cached, 'cached' => true];
        }
        $data = $fetch();
        self::set($key, $data, $ttl);
        return ['data' => $data, 'cached' => false];
    }
}
