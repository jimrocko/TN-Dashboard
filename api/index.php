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
require __DIR__ . '/lib/Brickognize.php';
require __DIR__ . '/lib/Dev.php';
require __DIR__ . '/lib/Shop.php';
require __DIR__ . '/lib/BrickLinkSync.php';
require __DIR__ . '/lib/DemoData.php';

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
        $me = require_user();
        $isStaff = in_array($me['role'], ROLES_STAFF, true);
        json_response(Shop::dashboard() + [
            'bricklink' => ['configured' => (new BrickLinkClient(config()['bricklink']))->isConfigured()],
            'recent'    => ActivityLog::list($isStaff ? null : (int) $me['id'], 8),
            'demo_seedable' => $isStaff && DemoData::isEmpty(),
        ]);

    case $route === 'demo/seed' && $method === 'POST':
        $staff = require_staff();
        if (!DemoData::isEmpty()) {
            json_error('Es sind bereits Lagerdaten vorhanden', 409);
        }
        $r = DemoData::seed();
        ActivityLog::log((int) $staff['id'], 'demo_seed', null, $r);
        json_response($r, 201);

    case $route === 'inventory' && $method === 'GET':
        require_user();
        json_response(['items' => Shop::inventory($_GET)]);

    case $route === 'inventory/meta' && $method === 'GET':
        require_user();
        json_response(Shop::meta());

    case $route === 'search' && $method === 'GET':
        $me = require_user();
        $term = trim((string) ($_GET['q'] ?? ''));
        if ($term === '') {
            json_response(['parts' => [], 'locations' => [], 'orders' => []]);
        }
        json_response(Shop::search($term));

    case count($parts) === 2 && $parts[0] === 'location' && $method === 'GET':
        require_user();
        $l = Shop::location(rawurldecode($parts[1]));
        $l ? json_response(['location' => $l]) : json_error('Lagerort nicht gefunden', 404);

    case count($parts) === 2 && $parts[0] === 'part' && $method === 'GET':
        require_user();
        $no = rawurldecode($parts[1]);
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $no)) {
            json_error('Ungültige Teil-Nr.');
        }
        json_response(['part' => Shop::part($no)]);

    case $route === 'recognize' && $method === 'POST':
        // Teileerkennung per Foto: Bild an Brickognize weiterreichen, Treffer mit dem eigenen Lager verknüpfen.
        $me = require_user();
        $file = $_FILES['image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_error('Kein Foto empfangen');
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            json_error('Foto ist zu groß (max. 8 MB)');
        }
        $mime = @getimagesize($file['tmp_name'])['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            json_error('Nur JPEG, PNG oder WebP');
        }
        try {
            $items = Brickognize::predict($file['tmp_name'], $mime, config()['brickognize'] ?? []);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        $lots = Shop::lotsByItem(array_column(array_filter($items, fn ($i) => $i['type'] === 'part'), 'id'));
        foreach ($items as &$it) {
            $it['lots'] = $it['type'] === 'part' ? ($lots[$it['id']] ?? []) : [];
        }
        unset($it);
        ActivityLog::log((int) $me['id'], 'recognize', 'scanner', $items
            ? ['teil' => $items[0]['id'], 'name' => $items[0]['name'], 'sicherheit' => round($items[0]['score'] * 100) . ' %']
            : ['ergebnis' => 'nichts erkannt']);
        json_response(['items' => $items]);

    case $route === 'orders' && $method === 'GET':
        require_user();
        json_response(['orders' => Shop::orders($_GET['status'] ?? null, $_GET['q'] ?? null)]);

    case count($parts) === 2 && $parts[0] === 'orders' && ctype_digit($parts[1]) && $method === 'GET':
        require_user();
        $o = Shop::order((int) $parts[1]);
        $o ? json_response(['order' => $o]) : json_error('Auftrag nicht gefunden', 404);

    case count($parts) === 3 && $parts[0] === 'orders' && ctype_digit($parts[1]) && $parts[2] === 'status' && $method === 'POST':
        $me = require_user();
        $status = (string) (request_json()['status'] ?? '');
        Shop::setOrderStatus((int) $parts[1], $status);
        $o = Shop::order((int) $parts[1]);
        ActivityLog::log((int) $me['id'], 'order_status', 'auftraege', ['auftrag' => $o['order_no'], 'status' => $status]);
        json_response(['order' => $o]);

    case $route === 'transfers/suggestions' && $method === 'GET':
        require_user();
        json_response(['suggestions' => Shop::suggestions()]);

    case $route === 'transfers' && $method === 'GET':
        require_user();
        json_response(['transfers' => Shop::transfers((int) ($_GET['limit'] ?? 30))]);

    case $route === 'transfers' && $method === 'POST':
        $me = require_user();
        $in = request_json();
        $r = Shop::transfer((int) ($in['lot_id'] ?? 0), (int) ($in['to_location_id'] ?? 0), (int) ($in['qty'] ?? 0), (int) $me['id']);
        ActivityLog::log((int) $me['id'], 'transfer', 'umlagern', ['teil' => "{$r['item_no']} {$r['color']}", 'menge' => $r['qty'], 'von' => $r['from'], 'nach' => $r['to']]);
        json_response(['transfer' => $r], 201);

    case $route === 'bricklink/pending' && $method === 'GET':
        // Eigene Umlagerungen, die noch nicht per Mass Update/Upload bei BrickLink eingetragen sind
        $me = require_user();
        json_response(['transfers' => BrickLinkSync::pending((int) $me['id'])]);

    case $route === 'bricklink/stock' && $method === 'GET':
        // Ganzer Bestand für die BrickLink-XML (alle Lots mit Menge nach dem Abziehen)
        require_user();
        json_response(BrickLinkSync::stock());

    case $route === 'bricklink/done' && $method === 'POST':
        $me = require_user();
        $n = BrickLinkSync::done((array) (request_json()['ids'] ?? []));
        if ($n) ActivityLog::log((int) $me['id'], 'bricklink_export', 'umlagern', ['umlagerungen' => $n]);
        json_response(['done' => $n]);

    case $route === 'bricklink/import' && $method === 'POST':
        // Lager komplett aus dem BrickLink-Shop übernehmen (nur Lehrer)
        $staff = require_staff();
        $bl = new BrickLinkClient(config()['bricklink']);
        if (!$bl->isConfigured()) {
            json_error('BrickLink ist noch nicht eingerichtet (api/config.php)', 503);
        }
        try {
            $r = BrickLinkSync::import($bl);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        ActivityLog::log((int) $staff['id'], 'bricklink_import', null, ['lose' => $r['lots'], 'neu' => $r['new'], 'entfernt' => $r['removed']]);
        json_response($r);

    case $route === 'stocktakes' && $method === 'GET':
        require_user();
        json_response(['stocktakes' => Shop::stocktakes()]);

    case $route === 'stocktakes' && $method === 'POST':
        $me = require_user();
        $id = Shop::createStocktake((int) (request_json()['location_id'] ?? 0));
        $s = Shop::stocktake($id);
        ActivityLog::log((int) $me['id'], 'stocktake_created', 'inventur', ['lagerort' => $s['location_code']]);
        json_response(['stocktake' => $s], 201);

    case count($parts) === 2 && $parts[0] === 'stocktakes' && ctype_digit($parts[1]) && $method === 'GET':
        require_user();
        $s = Shop::stocktake((int) $parts[1]);
        $s ? json_response(['stocktake' => $s]) : json_error('Prüfung nicht gefunden', 404);

    case count($parts) === 3 && $parts[0] === 'stocktakes' && ctype_digit($parts[1]) && $parts[2] === 'count' && $method === 'POST':
        require_user();
        $in = request_json();
        $counted = ($in['counted'] ?? null) === null || $in['counted'] === '' ? null : (int) $in['counted'];
        Shop::count((int) $parts[1], (int) ($in['item_id'] ?? 0), $counted);
        json_response(['ok' => true]);

    case count($parts) === 3 && $parts[0] === 'stocktakes' && ctype_digit($parts[1]) && $parts[2] === 'close' && $method === 'POST':
        $me = require_user();
        $r = Shop::closeStocktake((int) $parts[1], (int) $me['id']);
        ActivityLog::log((int) $me['id'], 'stocktake_closed', 'inventur', ['lagerort' => $r['location'], 'korrekturen' => count($r['corrections'])]);
        json_response($r);

    case $route === 'analysis' && $method === 'GET':
        require_user();
        json_response(Shop::analysis());

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

    // ---- Dev-Bereich ----
    case $route === 'dev/status' && $method === 'GET':
        json_response(Dev::status(require_user()));

    case $route === 'dev/system' && $method === 'GET':
        Dev::require();
        json_response(Dev::system());

    case $route === 'dev/tables' && $method === 'GET':
        Dev::require();
        json_response(['tables' => Dev::tables()]);

    case $route === 'dev/table' && $method === 'GET':
        Dev::require();
        json_response(Dev::table((string) ($_GET['name'] ?? ''), (int) ($_GET['page'] ?? 1), (int) ($_GET['per'] ?? 50)));

    case $route === 'dev/test' && $method === 'POST':
        $me = Dev::require();
        $r = Dev::test((string) (request_json()['name'] ?? ''));
        ActivityLog::log((int) $me['id'], 'dev_test', null, ['test' => $r['name'], 'ok' => $r['ok']]);
        json_response($r);

    case count($parts) === 3 && $parts[0] === 'users' && ctype_digit($parts[1]) && $parts[2] === 'dev' && $method === 'POST':
        // Dev-Rolle an Teilnehmer geben oder wegnehmen (nur Lehrer).
        $staff = require_staff();
        $on = !empty(request_json()['enabled']);
        $target = q('SELECT id, username FROM {users} WHERE id = ? AND active = 1', [(int) $parts[1]])->fetch();
        if (!$target) json_error('Benutzer nicht gefunden', 404);
        Dev::grant((int) $target['id'], $on, (int) $staff['id']);
        ActivityLog::log((int) $staff['id'], $on ? 'dev_granted' : 'dev_revoked', null, ['username' => $target['username']]);
        json_response(['ok' => true]);

    case count($parts) === 3 && $parts[0] === 'users' && ctype_digit($parts[1]) && $parts[2] === 'delete' && $method === 'POST':
        // Konto löschen (nur Lehrer, nur Teilnehmer). Deaktivieren statt entfernen: Das Protokoll bleibt erhalten,
        // der Benutzername wird frei, eine offene Sitzung endet beim nächsten Aufruf.
        $staff = require_staff();
        $target = q('SELECT id, username, role FROM {users} WHERE id = ? AND active = 1', [(int) $parts[1]])->fetch();
        if (!$target) json_error('Benutzer nicht gefunden', 404);
        if ((int) $target['id'] === (int) $staff['id']) json_error('Das eigene Konto kann nicht gelöscht werden', 400);
        if ($target['role'] !== 'teilnehmer') json_error('Nur Teilnehmer-Konten können gelöscht werden', 403);
        q('UPDATE {users} SET active = 0, username = ? WHERE id = ?', [$target['username'] . '~' . $target['id'], (int) $target['id']]);
        Dev::grant((int) $target['id'], false, (int) $staff['id']);
        ActivityLog::log((int) $staff['id'], 'user_deleted', null, ['username' => $target['username']]);
        json_response(['ok' => true]);

    case $route === 'users' && $method === 'GET':
        require_staff();
        $rows = db()->query(
            'SELECT u.id, u.username, u.display_name, u.role, u.last_seen,
                    (SELECT COUNT(*) FROM ' . t('activity_log') . ' a WHERE a.user_id = u.id) AS activity_count
             FROM ' . t('users') . ' u WHERE u.active = 1 ORDER BY u.role, u.display_name'
        )->fetchAll();
        $dev = Dev::accessIds();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['activity_count'] = (int) $r['activity_count'];
            $r['dev_access'] = in_array($r['role'], ROLES_STAFF, true) || in_array($r['id'], $dev, true);
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
