<?php
/**
 * JSON-API des TN Dashboards.
 * Aufruf: api/?r=<route>, z. B. api/?r=auth/login
 * (Query-Parameter statt URL-Rewriting, damit es auf jedem Webserver ohne Zusatzkonfiguration läuft.)
 */

declare(strict_types=1);

require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/env.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ActivityLog.php';
require __DIR__ . '/lib/Cache.php';
require __DIR__ . '/lib/BrickLinkClient.php';

set_exception_handler(function (Throwable $e) {
    error_log('[tn-dashboard] ' . $e);
    json_error('Serverfehler', 500);
});

$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string) ($_GET['r'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

const MODULES = ['minifig', 'pickliste', 'inventar', 'umlagern', 'scanner', 'auftraege', 'inventur', 'analyse', 'suche'];

switch (true) {
    case $route === 'auth/login' && $method === 'POST':
        $in = request_json();
        $user = login(trim((string) ($in['username'] ?? '')), (string) ($in['password'] ?? ''));
        if (!$user) {
            json_error('Benutzername oder Passwort falsch', 401);
        }
        ActivityLog::log((int) $user['id'], 'login');
        json_response(['user' => public_user($user)]);

    case $route === 'auth/logout' && $method === 'POST':
        if ($user = current_user()) {
            ActivityLog::log((int) $user['id'], 'logout');
        }
        logout();
        json_response(['ok' => true]);

    case $route === 'auth/me' && $method === 'GET':
        $user = current_user();
        json_response(['user' => $user ? public_user($user) : null]);

    case $route === 'dashboard' && $method === 'GET':
        require_user();
        // Platzhalter-Kennzahlen – werden mit den Modulen durch echte Werte ersetzt.
        json_response([
            'pickliste' => ['open' => 0],
            'inventar'  => ['total_parts' => 0],
            'umlagern'  => ['suggestions' => 0],
            'auftraege' => ['active' => 0],
            'inventur'  => ['open' => 0],
            'analyse'   => ['last_run' => null],
            'bricklink' => ['configured' => (new BrickLinkClient(config()['bricklink']))->isConfigured()],
        ]);

    case $route === 'activity' && $method === 'POST':
        $user = require_user();
        $in = request_json();
        $action = substr(trim((string) ($in['action'] ?? '')), 0, 64);
        $module = $in['module'] ?? null;
        if ($action === '') {
            json_error('action fehlt');
        }
        if ($module !== null && !in_array($module, MODULES, true)) {
            json_error('Unbekanntes Modul');
        }
        $details = is_array($in['details'] ?? null) ? $in['details'] : [];
        ActivityLog::log((int) $user['id'], $action, $module, $details);
        json_response(['ok' => true], 201);

    case $route === 'activity' && $method === 'GET':
        $me = require_user();
        $isStaff = in_array($me['role'], ROLES_STAFF, true);
        // Teilnehmer sehen nur ihr eigenes Protokoll, Lehrer alle.
        $userId = isset($_GET['user']) && $_GET['user'] !== '' ? (int) $_GET['user'] : null;
        if (!$isStaff) {
            $userId = (int) $me['id'];
        }
        $rows = ActivityLog::list($userId, (int) ($_GET['limit'] ?? 200));
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="aktivitaeten.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel Umlaute korrekt anzeigt
            fputcsv($out, ['Zeit', 'Benutzer', 'Name', 'Aktion', 'Modul', 'Details'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['created_at'], $r['username'], $r['display_name'], $r['action'], $r['module'],
                    $r['details'] ? json_encode($r['details'], JSON_UNESCAPED_UNICODE) : '',
                ], ';');
            }
            exit;
        }
        json_response(['activity' => $rows]);

    case $route === 'users' && $method === 'GET':
        require_staff();
        $rows = db()->query(
            'SELECT u.id, u.username, u.display_name, u.role, u.last_seen,
                    (SELECT COUNT(*) FROM ' . t('activity_log') . ' a WHERE a.user_id = u.id) AS activity_count
             FROM ' . t('users') . ' u WHERE u.active = 1 ORDER BY u.role, u.display_name'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['activity_count'] = (int) $r['activity_count'];
        }
        json_response(['users' => $rows]);

    case $route === 'users' && $method === 'POST':
        $staff = require_staff();
        $in = request_json();
        $username = strtolower(trim((string) ($in['username'] ?? '')));
        $display = trim((string) ($in['display_name'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $role = $in['role'] ?? 'teilnehmer';
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
            json_error('Benutzername: 3–32 Zeichen, nur a–z, 0–9, . _ -');
        }
        if ($display === '' || strlen($password) < 6) {
            json_error('Name und Passwort (mind. 6 Zeichen) erforderlich');
        }
        if (!in_array($role, ['teilnehmer', 'lehrer'], true)) {
            json_error('Ungültige Rolle');
        }
        $exists = db()->prepare('SELECT 1 FROM ' . t('users') . ' WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetch()) {
            json_error('Benutzername bereits vergeben', 409);
        }
        db()->prepare('INSERT INTO ' . t('users') . ' (username, display_name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
        ActivityLog::log((int) $staff['id'], 'user_created', null, ['username' => $username, 'role' => $role]);
        json_response(['ok' => true], 201);

    case count($parts) === 4 && $parts[0] === 'bl' && $parts[1] === 'item' && $method === 'GET':
        // Beispiel für einen gecachten BrickLink-Aufruf: bl/item/{type}/{no}
        require_user();
        [$_, $_, $type, $no] = $parts;
        $type = strtoupper($type);
        if (!in_array($type, ['PART', 'MINIFIG', 'SET', 'BOOK', 'GEAR', 'CATALOG', 'INSTRUCTION', 'ORIGINAL_BOX'], true)
            || !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $no)) {
            json_error('Ungültiger Artikel');
        }
        $bl = new BrickLinkClient(config()['bricklink']);
        if (!$bl->isConfigured()) {
            json_error('BrickLink ist noch nicht eingerichtet (api/config.php)', 503);
        }
        try {
            $result = Cache::remember(
                "item:$type:$no",
                (int) (config()['bricklink']['cache_ttl'] ?? 86400),
                fn () => $bl->get('/items/' . rawurlencode($type) . '/' . rawurlencode($no))
            );
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        json_response($result);

    default:
        json_error('Nicht gefunden', 404);
}
