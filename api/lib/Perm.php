<?php

/**
 * Berechtigungen je Teilnehmer (Wunsch des Inhabers, Sitzung 8). Lehrer haben immer alles.
 * Gespeichert in tn_permissions (user_id, perms_json); ohne eigenen Eintrag gelten die Standardrechte (tn_settings.perm_defaults).
 * Der Dev-Bereich bleibt bei tn_dev_access (Dev-Rolle) und wird im Panel nur mit angezeigt.
 */
final class Perm
{
    /** key => [Gruppe, Bezeichnung]. Reihenfolge = Reihenfolge im Panel. */
    public const KEYS = [
        'see_inventar'          => ['Bereiche sehen', 'Inventar'],
        'see_umlagern'          => ['Bereiche sehen', 'Umlagern'],
        'see_inventur'          => ['Bereiche sehen', 'Inventur'],
        'see_analyse'           => ['Bereiche sehen', 'Analyse'],
        'see_pickliste'         => ['Bereiche sehen', 'Pickliste'],
        'see_minifig'           => ['Bereiche sehen', 'Minifig-Finder'],
        'umlagern_buchen'       => ['Umlagern', 'Umlagern (Vorschläge übernehmen, umbuchen)'],
        'inventur_zaehlen'      => ['Inventur', 'Inventur starten und zählen'],
        'inventur_abschliessen' => ['Inventur', 'Inventur abschließen'],
        'pick_picken'           => ['Pickliste', 'Picken und Markieren'],
        'pick_melden'           => ['Pickliste', 'Probleme melden und schließen'],
        'pick_abschliessen'     => ['Pickliste', 'Bestellung abschließen / Status ändern'],
        'minifig_bauen'         => ['Minifig-Finder', '„Fertig gebaut“, Teile ändern, „Nicht bauen“'],
        'minifig_fehlt'         => ['Minifig-Finder', '„Fehlt“ (Bestand abziehen)'],
        'minifig_abschluss'     => ['Minifig-Finder', 'Sammlung abschließen'],
        'minifig_dateien'       => ['Minifig-Finder', 'BSX-Dateien hochladen und löschen'],
        'minifig_einstellungen' => ['Minifig-Finder', 'Quelle und Reihenfolge ändern'],
        'bricklink_uebertragen' => ['BrickLink', 'Übertragung zu BrickLink starten und bestätigen'],
    ];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $sqlite = Dev::isSqlite();
        db()->exec('CREATE TABLE IF NOT EXISTS ' . t('permissions') . ' (
            user_id ' . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' NOT NULL PRIMARY KEY,
            perms_json ' . ($sqlite ? 'TEXT' : 'MEDIUMTEXT') . ' NOT NULL,
            updated_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NOT NULL
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    }

    /** Nur bekannte Schlüssel, Werte als bool; fehlende Schlüssel = erlaubt. */
    public static function clean(array $p): array
    {
        $out = [];
        foreach (array_keys(self::KEYS) as $k) $out[$k] = array_key_exists($k, $p) ? (bool) $p[$k] : true;
        return $out;
    }

    public static function defaults(): array
    {
        return self::clean(json_decode((string) (Dev::setting('perm_defaults') ?? ''), true) ?: []);
    }

    public static function setDefaults(array $p): array
    {
        $p = self::clean($p);
        Dev::setSetting('perm_defaults', json_encode($p));
        return $p;
    }

    /** Wirksame Rechte eines Kontos (Lehrer: alles). */
    public static function of(array $user): array
    {
        if (in_array($user['role'] ?? '', ROLES_STAFF, true)) return array_fill_keys(array_keys(self::KEYS), true);
        self::ensureSchema();
        $j = q('SELECT perms_json FROM {permissions} WHERE user_id = ?', [(int) $user['id']])->fetchColumn();
        return $j === false ? self::defaults() : self::clean(json_decode((string) $j, true) ?: []);
    }

    public static function custom(int $userId): bool
    {
        self::ensureSchema();
        return q('SELECT 1 FROM {permissions} WHERE user_id = ?', [$userId])->fetchColumn() !== false;
    }

    public static function setFor(int $userId, array $p): array
    {
        self::ensureSchema();
        $p = self::clean($p);
        q('DELETE FROM {permissions} WHERE user_id = ?', [$userId]);
        q('INSERT INTO {permissions} (user_id, perms_json, updated_at) VALUES (?, ?, ?)', [$userId, json_encode($p), now()]);
        return $p;
    }

    public static function can(array $user, string $key): bool
    {
        return self::of($user)[$key] ?? true;
    }

    /** Kurzer Text für das Protokoll: was wurde an- bzw. ausgeschaltet. */
    public static function diffText(array $old, array $new): string
    {
        $on = $off = [];
        foreach (self::KEYS as $k => [, $label]) {
            if (($old[$k] ?? true) === ($new[$k] ?? true)) continue;
            if ($new[$k]) $on[] = $label; else $off[] = $label;
        }
        return trim(($on ? 'erlaubt: ' . implode(', ', $on) : '') . ($on && $off ? '; ' : '') . ($off ? 'gesperrt: ' . implode(', ', $off) : ''));
    }
}
