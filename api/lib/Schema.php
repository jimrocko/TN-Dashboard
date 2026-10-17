<?php

/**
 * Tabellen anlegen und bestehende Installationen nachziehen.
 * install.php legt alles neu an; ensure() ergänzt beim ersten API-Aufruf nach einem Update
 * fehlende Tabellen und Spalten, damit install.php nicht erneut laufen muss.
 */
final class Schema
{
    /** Erhöhen, wenn sich das Schema ändert (neue Spalten unten in COLUMNS eintragen). */
    public const VERSION = 4;

    /** Spalten, die nach Version 1 dazugekommen sind: Tabelle => [Spalte => [MySQL-Typ, SQLite-Typ]] */
    private const COLUMNS = [
        'inventory' => [
            'new_or_used'     => ["CHAR(1) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
            'bl_inventory_id' => ['BIGINT NULL', 'INTEGER NULL'],
            'description'     => ['VARCHAR(255) NULL', 'TEXT NULL'],
        ],
        'orders' => [
            'platform'        => ["VARCHAR(16) NOT NULL DEFAULT 'TN'", "TEXT NOT NULL DEFAULT 'TN'"],
            'platform_status' => ['VARCHAR(32) NULL', 'TEXT NULL'],
            'currency'        => ['VARCHAR(3) NULL', 'TEXT NULL'],
            'shipping'        => ['TEXT NULL', 'TEXT NULL'],
            'details_at'      => ['DATETIME NULL', 'TEXT NULL'],
        ],
        'order_items' => [
            'item_type'   => ["VARCHAR(16) NOT NULL DEFAULT 'PART'", "TEXT NOT NULL DEFAULT 'PART'"],
            'color_id'    => ['INT NULL', 'INTEGER NULL'],
            'new_or_used' => ["CHAR(1) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
            'remarks'     => ['VARCHAR(255) NULL', 'TEXT NULL'],
            'ext_lot_id'  => ['BIGINT NULL', 'INTEGER NULL'],
            'image_url'   => ['VARCHAR(255) NULL', 'TEXT NULL'],
        ],
    ];

    public static function sqlite(): bool
    {
        return (config()['db']['driver'] ?? 'mysql') === 'sqlite';
    }

    /** Alle CREATE-Anweisungen aus sql/schema.<treiber>.sql ausführen (IF NOT EXISTS). */
    public static function install(): void
    {
        $driver = self::sqlite() ? 'sqlite' : 'mysql';
        $sql = str_replace('{p}', t(''), file_get_contents(__DIR__ . "/../sql/schema.$driver.sql"));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            // Alte Installation: Spalte fehlt noch, Index darauf schlägt fehl – ensure() legt beides an.
            try {
                db()->exec($stmt);
            } catch (PDOException $e) {
                if (!preg_match('/^\s*(--[^\n]*\n\s*)*CREATE (UNIQUE )?INDEX/i', $stmt)) throw $e;
            }
        }
        self::addColumns();
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (preg_match('/^\s*(--[^\n]*\n\s*)*CREATE (UNIQUE )?INDEX/i', $stmt)) {
                try { db()->exec($stmt); } catch (PDOException $e) { /* schon vorhanden */ }
            }
        }
        Dev::ensureSchema();
        BrickLinkSync::ensureSchema();
        Dev::setSetting('schema_version', (string) self::VERSION);
    }

    /** Bei jedem API-Aufruf: nur eine kurze Abfrage, Arbeit nur nach einem Update. */
    public static function ensure(): void
    {
        try {
            if ((int) Dev::setting('schema_version') >= self::VERSION) return;
        } catch (Throwable $e) { /* Einstellungen fehlen: unten anlegen */ }
        self::install();
    }

    private static function columns(string $table): array
    {
        if (self::sqlite()) {
            return array_column(db()->query('PRAGMA table_info(' . t($table) . ')')->fetchAll(), 'name');
        }
        return q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [t($table)])
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function addColumns(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            $have = self::columns($table);
            foreach ($cols as $col => [$mysql, $sqlite]) {
                if (!in_array($col, $have, true)) {
                    db()->exec('ALTER TABLE ' . t($table) . " ADD COLUMN $col " . (self::sqlite() ? $sqlite : $mysql));
                }
            }
        }
        // Lagerort-Codes von BrickLink können länger sein („G562 A004“)
        if (!self::sqlite()) db()->exec('ALTER TABLE ' . t('locations') . ' MODIFY code VARCHAR(64) NOT NULL');
    }
}
