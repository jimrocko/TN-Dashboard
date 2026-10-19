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
require __DIR__ . '/lib/OllieSync.php';
require __DIR__ . '/lib/DemoData.php';
require __DIR__ . '/lib/Schema.php';
require __DIR__ . '/lib/Bsx.php';
require __DIR__ . '/lib/Sync.php';
require __DIR__ . '/lib/Picking.php';
require __DIR__ . '/lib/Minifig.php';

set_exception_handler(function (Throwable $e) {
    error_log('[tn-dashboard] ' . $e);
    json_error('Serverfehler', 500);
});

$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string) ($_GET['r'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

Schema::ensure();

/** Datei zum Herunterladen senden (CSV, BrickStore, ZIP). */
function send_file(string $content, string $name, string $type): never
{
    header("Content-Type: $type");
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

/** Hochgeladene Datei: multipart-Feld „file“ oder der rohe Anfragetext. */
function upload_body(int $max = 25 * 1024 * 1024): array
{
    $f = $_FILES['file'] ?? null;
    if ($f && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        json_error('Die Datei ist größer, als der Server erlaubt (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Bitte in der PHP-Einstellung erhöhen oder eine kleinere Datei wählen.', 413);
    }
    if (!$f && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/')) {
        // post_max_size überschritten: PHP verwirft dann die ganze Anfrage
        json_error('Die Datei ist größer, als der Server erlaubt (post_max_size = ' . ini_get('post_max_size') . ').', 413);
    }
    if ($f && ($f['error'] ?? 1) === UPLOAD_ERR_OK) {
        if ($f['size'] > $max) json_error('Datei ist zu groß');
        return [file_get_contents($f['tmp_name']), (string) $f['name']];
    }
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) json_error('Keine Datei übertragen');
    if (strlen($raw) > $max) json_error('Datei ist zu groß');
    return [$raw, (string) ($_SERVER['HTTP_X_FILENAME'] ?? '')];
}

/** Fehler der Plattform-APIs oder der Prüfungen als verständliche Meldung zurückgeben. */
function api_try(callable $fn): mixed
{
    try {
        return $fn();
    } catch (ApiException $e) {
        json_error($e->getMessage(), $e->status === 429 ? 429 : 502);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 400);
    }
}

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
        OllieSync::autoSync((int) $me['id']); // hat Ollies Dashboard neu geladen? Dann gleichen Stand übernehmen
        $isStaff = in_array($me['role'], ROLES_STAFF, true);
        json_response(Shop::dashboard() + [
            'pickliste' => ['open' => (int) q('SELECT COUNT(*) FROM {orders} WHERE status IN (' . implode(',', array_fill(0, count(Picking::OPEN), '?')) . ')', Picking::OPEN)->fetchColumn()],
            'minifig'   => json_decode(Dev::setting('minifig_counts') ?? 'null', true),
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
        OllieSync::autoSync((int) require_user()['id']);
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
        $r = Shop::transfer((int) ($in['lot_id'] ?? 0), (int) ($in['to_location_id'] ?? 0), (int) ($in['qty'] ?? 0), (int) $me['id'],
            isset($in['expect_location_id']) ? (int) $in['expect_location_id'] : null, isset($in['expect_qty']) ? (int) $in['expect_qty'] : null);
        ActivityLog::log((int) $me['id'], 'transfer', 'umlagern', ['teil' => "{$r['item_no']} {$r['color']}", 'menge' => $r['qty'], 'von' => $r['from'], 'nach' => $r['to']]);
        json_response(['transfer' => $r], 201);

    case $route === 'bricklink/pending' && $method === 'GET':
        // Offene Umlagerungen aller Nutzer und die laufende Übertragung (gemeinsam für alle, nicht persönlich)
        require_user();
        json_response(['transfers' => BrickLinkSync::pending(), 'stocktakes' => BrickLinkSync::pendingStocktakes(),
            'export' => BrickLinkSync::openExport()]);

    case $route === 'bricklink/stock' && $method === 'GET':
        // Ganzer Bestand für die BrickLink-XML (alle Lots mit Menge nach dem Abziehen)
        require_user();
        json_response(BrickLinkSync::stock());

    case $route === 'bricklink/start' && $method === 'POST':
        // Übertragung starten (Bestand wurde kopiert). Es darf immer nur eine offen sein.
        $me = require_user();
        $in = request_json();
        $e = BrickLinkSync::start((array) ($in['ids'] ?? []), (int) $me['id'], (array) ($in['stocktake_ids'] ?? []));
        ActivityLog::log((int) $me['id'], 'bricklink_export_start', 'umlagern', ['umlagerungen' => count($e['transfer_ids']), 'inventuren' => count($e['stocktake_ids'])]);
        json_response(['export' => $e], 201);

    case $route === 'bricklink/finish' && $method === 'POST':
        // Offene Übertragung abschließen: eingefügt (done = true) oder verworfen. Darf jeder.
        $me = require_user();
        $in = request_json();
        $n = BrickLinkSync::finish((int) ($in['id'] ?? 0), !empty($in['done']));
        if (!empty($in['done'])) OllieSync::markExported(); // Ollies alter Stand kennt die Änderungen noch nicht
        ActivityLog::log((int) $me['id'], !empty($in['done']) ? 'bricklink_export' : 'bricklink_export_verworfen', 'umlagern', ['aenderungen' => $n]);
        json_response(['done' => $n, 'ollie' => OllieSync::path() !== null]);

    case $route === 'bricklink/import' && $method === 'POST':
        // Lager komplett aus dem BrickLink-Shop übernehmen (nur Lehrer)
        $staff = require_staff();
        if (OllieSync::path() !== null) {
            // Mit Ollies Dashboard: über seinen Import laden, damit beide denselben neuen Stand haben
            try {
                $records = OllieSync::triggerRefresh();
                $r = OllieSync::import();
            } catch (RuntimeException $e) {
                json_error($e->getMessage(), 502);
            }
            ActivityLog::log((int) $staff['id'], 'ollie_refresh', null, ['ollie_lose' => $records, 'lose' => $r['lots'], 'neu' => $r['new'], 'entfernt' => $r['removed']]);
            json_response($r + ['via_ollie' => true]);
        }
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

    // ---- Abgleich mit Ollies Dashboard (gleicher Rechner, nur lesend) ----
    case $route === 'ollie/status' && $method === 'GET':
        Dev::require();
        json_response(OllieSync::status());

    case $route === 'ollie/import' && $method === 'POST':
        // Ganzen Bestand aus Ollies Datenbank übernehmen (nur Lehrer, ersetzt das Lager wie der BrickLink-Import)
        $staff = require_staff();
        try {
            $r = OllieSync::import();
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        ActivityLog::log((int) $staff['id'], 'ollie_import', null, ['lose' => $r['lots'], 'neu' => $r['new'], 'entfernt' => $r['removed']]);
        json_response($r);

    case $route === 'ollie/refresh' && $method === 'POST':
        // Wie Ollies Knopf „BrickLink-Inventar“: sein Dashboard lädt neu von BrickLink, danach übernehmen wir den Stand
        $staff = require_staff();
        try {
            $records = OllieSync::triggerRefresh();
            $r = OllieSync::import();
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        ActivityLog::log((int) $staff['id'], 'ollie_refresh', null, ['ollie_lose' => $records, 'lose' => $r['lots'], 'neu' => $r['new'], 'entfernt' => $r['removed']]);
        json_response($r + ['ollie_records' => $records]);

    case $route === 'ollie/after-export' && $method === 'POST':
        // Nach „Ja, ist eingefügt“ (darf jeder): Ollies Dashboard lädt neu, damit es die Änderungen auch hat.
        // Läuft weiter, auch wenn die Seite gewechselt wird.
        $me = require_user();
        if (OllieSync::path() === null) json_response(['skipped' => true]);
        ignore_user_abort(true);
        try {
            $records = OllieSync::triggerRefresh();
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 502);
        }
        json_response(['ollie_records' => $records, 'imported' => OllieSync::autoSync((int) $me['id'])]);

    case $route === 'achievements' && $method === 'GET':
        // Abzeichen (eigene Zahlen) und Bestenliste aller aktiven Teilnehmer. period: all | month | week
        $me = require_user();
        $since = ['month' => date('Y-m-01'), 'week' => date('Y-m-d', strtotime('monday this week'))][$_GET['period'] ?? 'all'] ?? null;
        $total = ActivityLog::stats();
        $all = $since ? ActivityLog::stats($since) : $total;
        $mine = $total[(int) $me['id']] ?? null;
        $board = [];
        foreach (q("SELECT id, display_name FROM {users} WHERE role = 'teilnehmer' AND active = 1 ORDER BY display_name")->fetchAll() as $u) {
            $board[] = ['id' => (int) $u['id'], 'name' => $u['display_name'], 'stats' => $all[(int) $u['id']] ?? null, 'all' => $total[(int) $u['id']] ?? null];
        }
        json_response(['me' => $mine, 'board' => $board]);

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

    // ---- Daten von BrickLink/BrickOwl holen (nur Lehrer und Dev-Rolle, kostet API-Abrufe) ----
    case $route === 'sync/status' && $method === 'GET':
        require_user();
        json_response(Sync::status());

    case $route === 'sync/inventory' && $method === 'POST':
        $me = Dev::require();
        $r = api_try(fn () => Sync::inventory(!empty(request_json()['force'])));
        ActivityLog::log((int) $me['id'], 'sync_inventory', 'inventar', ['lose' => $r['lots'], 'teile' => $r['parts']]);
        json_response($r);

    case $route === 'sync/orders' && $method === 'POST':
        $me = Dev::require();
        $r = api_try(fn () => Sync::orders(!empty(request_json()['force'])));
        ActivityLog::log((int) $me['id'], 'sync_orders', 'auftraege', ['fehler' => count($r['errors'])]);
        json_response($r);

    // ---- Pickliste ----
    case $route === 'picking/revision' && $method === 'GET':
        require_user();
        json_response(Picking::overviewRevision());

    case $route === 'picking/orders' && $method === 'GET':
        require_user();
        json_response(['orders' => Picking::openOrders(), 'revision' => Picking::overviewRevision()['revision']]);

    case $route === 'picking/pickers' && $method === 'GET':
        require_user();
        json_response(['pickers' => typed(q("SELECT id, display_name AS name FROM {users} WHERE active = 1 ORDER BY role DESC, display_name")->fetchAll(), ['id'])]);

    case count($parts) === 3 && $parts[0] === 'picking' && $parts[1] === 'orders' && ctype_digit($parts[2]) && $method === 'GET':
        require_user();
        $d = Picking::detail((int) $parts[2]);
        $d ? json_response($d) : json_error('Bestellung nicht gefunden', 404);

    case count($parts) === 4 && $parts[0] === 'picking' && $parts[1] === 'orders' && ctype_digit($parts[2]) && $parts[3] === 'revision' && $method === 'GET':
        require_user();
        json_response(Picking::orderRevision((int) $parts[2]));

    case count($parts) === 4 && $parts[0] === 'picking' && $parts[1] === 'orders' && ctype_digit($parts[2]) && in_array($parts[3], ['complete', 'reopen'], true) && $method === 'POST':
        $me = require_user();
        $id = (int) $parts[2];
        $r = $parts[3] === 'complete' ? Picking::complete($id) : Picking::reopen($id);
        $no = q('SELECT order_no FROM {orders} WHERE id = ?', [$id])->fetchColumn();
        ActivityLog::log((int) $me['id'], $parts[3] === 'complete' ? 'pick_order_completed' : 'pick_order_reopened', 'pickliste', ['auftrag' => $no]);
        json_response($r);

    case count($parts) === 4 && $parts[0] === 'picking' && $parts[1] === 'items' && ctype_digit($parts[2]) && in_array($parts[3], ['toggle', 'claim', 'reports'], true) && $method === 'POST':
        $me = require_user();
        $item = (int) $parts[2];
        $info = q('SELECT x.item_no, x.color_name, x.qty, o.order_no FROM {order_items} x JOIN {orders} o ON o.id = x.order_id WHERE x.id = ?', [$item])->fetch() ?: [];
        $details = ['auftrag' => $info['order_no'] ?? null, 'teil' => trim(($info['item_no'] ?? '') . ' ' . ($info['color_name'] ?? '')), 'menge' => (int) ($info['qty'] ?? 0)];
        if ($parts[3] === 'toggle') {
            $r = Picking::toggle($item, $me);
            ActivityLog::log((int) $me['id'], $r['picked'] ? 'pick_item' : 'pick_item_reset', 'pickliste', $details);
        } elseif ($parts[3] === 'claim') {
            $r = Picking::claim($item, $me);
            ActivityLog::log((int) $me['id'], $r['claimed'] ? 'pick_claim' : 'pick_unclaim', 'pickliste', $details);
        } else {
            $in = request_json();
            $r = Picking::report($item, (int) $me['id'], $in);
            ActivityLog::log((int) $me['id'], 'pick_report', 'pickliste', $details + ['grund' => $r['issue_type']]);
            json_response($r, 201);
        }
        json_response($r);

    case count($parts) === 4 && $parts[0] === 'picking' && $parts[1] === 'reports' && ctype_digit($parts[2]) && $parts[3] === 'resolve' && $method === 'POST':
        require_user();
        json_response(Picking::resolve((int) $parts[2]));

    case $route === 'picking/location-order' && $method === 'GET':
        require_user();
        json_response(['groups' => Picking::locationOrder()]);

    case $route === 'picking/location-order' && $method === 'POST':
        $staff = require_staff();
        $groups = request_json()['groups'] ?? [];
        $r = Picking::saveLocationOrder(is_array($groups) ? $groups : []);
        ActivityLog::log((int) $staff['id'], 'pick_location_order', 'pickliste', ['bereiche' => implode(', ', array_column($r, 'location_group'))]);
        json_response(['groups' => $r]);

    case $route === 'picking/location-order/available' && $method === 'GET':
        require_user();
        json_response(['locations' => Picking::availableLocations()]);

    case $route === 'picking/reset' && $method === 'POST':
        $staff = require_staff();
        if ((request_json()['confirmation'] ?? '') !== 'RESET') json_error('Bitte mit RESET bestätigen');
        $r = Picking::resetTestData();
        ActivityLog::log((int) $staff['id'], 'pick_reset', 'pickliste', $r);
        json_response($r);

    case $route === 'picking/stats' && $method === 'GET':
        Dev::require(); // nur Lehrer und Dev-Rolle (Wunsch des Inhabers)
        json_response(Picking::stats($_GET['date_from'] ?? null, $_GET['date_to'] ?? null,
            isset($_GET['picker_id']) && $_GET['picker_id'] !== '' ? (int) $_GET['picker_id'] : null, $_GET['platform'] ?? null));

    // ---- Minifig-Finder ----
    case $route === 'minifig/status' && $method === 'GET':
        require_user();
        json_response(Minifig::status());

    case $route === 'minifig/source' && $method === 'POST':
        // Woher die Teile kommen: bestand | beides | bsx (gilt für alle, wie der Bestand selbst)
        $me = require_user();
        $src = Minifig::setStockSource((string) (request_json()['source'] ?? ''));
        ActivityLog::log((int) $me['id'], 'minifig_source', 'minifig', ['quelle' => $src]);
        json_response(['source' => $src]);

    case $route === 'minifig/bsx' && $method === 'GET':
        require_user();
        json_response(['files' => Minifig::bsxFiles()]);

    case $route === 'minifig/bsx' && $method === 'POST':
        $me = require_user();
        [$content, $name] = upload_body();
        try { $r = Minifig::addBsx($content, $name, (int) $me['id']); } catch (RuntimeException $e) { json_error($e->getMessage(), 422); }
        ActivityLog::log((int) $me['id'], 'minifig_bsx_add', 'minifig', ['datei' => $r['name'], 'lots' => $r['lots']]);
        json_response($r);

    case preg_match('#^minifig/bsx/(\d+)/delete$#', $route, $m) === 1 && $method === 'POST':
        $me = require_user();
        $name = Minifig::deleteBsx((int) $m[1]);
        if ($name === null) json_error('Datei nicht gefunden', 404);
        ActivityLog::log((int) $me['id'], 'minifig_bsx_delete', 'minifig', ['datei' => $name]);
        json_response(['ok' => true]);

    case $route === 'minifig/results' && $method === 'GET':
        require_user();
        $r = Minifig::analyze(max(1, min(10000, (int) ($_GET['limit'] ?? 500))));
        // Für die Kachel auf der Startseite merken (ohne jedes Mal neu zu rechnen)
        Dev::setSetting('minifig_counts', json_encode(['buildable' => $r['individual_buildable_count'], 'almost' => $r['almost_count']]));
        json_response($r);

    case in_array($route, ['minifig/scan', 'minifig/catalog-refresh', 'minifig/subsets'], true) && $method === 'POST':
        // Kostet BrickLink-Abrufe: nur Lehrer und Dev-Rolle
        $me = Dev::require();
        if (!BrickLinkClient::fromConfig()->isConfigured()) json_error('BrickLink ist noch nicht eingerichtet (.env).', 503);
        $batch = (int) (request_json()['batch_size'] ?? 25);
        $r = match ($route) {
            'minifig/scan' => Minifig::scan($batch),
            'minifig/catalog-refresh' => Minifig::refreshCatalog($batch),
            default => Minifig::fetchSubsets($batch),
        };
        ActivityLog::log((int) $me['id'], str_replace(['minifig/', '-'], ['minifig_', '_'], $route), 'minifig', ['abrufe' => $r['api_calls'] ?? 0]);
        json_response($r);

    case $route === 'minifig/decision' && $method === 'POST':
        $me = require_user();
        $in = request_json();
        $r = Minifig::decide((string) ($in['minifig_no'] ?? ''), (string) ($in['action'] ?? ''), (string) ($in['reason'] ?? ''));
        ActivityLog::log((int) $me['id'], 'minifig_decision', 'minifig', ['figur' => $r['minifig_no'], 'aktion' => $r['action'], 'grund' => $r['reason']]);
        json_response($r);

    case $route === 'minifig/export' && $method === 'GET':
        require_user();
        $kind = isset(Minifig::LIST_FILES[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'all';
        $name = 'minifig-' . Minifig::LIST_FILES[$kind];
        ($_GET['format'] ?? 'csv') === 'bsx'
            ? send_file(Minifig::listBsx($kind), "$name.bsx", 'application/xml')
            : send_file(Minifig::listCsv($kind), "$name.csv", 'text/csv; charset=utf-8');

    case $route === 'minifig/export-all' && $method === 'GET':
        require_user();
        $fmt = ($_GET['format'] ?? 'csv') === 'bsx' ? 'bsx' : 'csv';
        send_file(Minifig::allListsZip($fmt), "minifig-listen-$fmt.zip", 'application/zip');

    case $route === 'minifig/almost-joint-export' && $method === 'GET':
        require_user();
        ($_GET['format'] ?? 'csv') === 'bsx'
            ? send_file(Minifig::almostJointBsx(), 'minifig-fehlteile.bsx', 'application/xml')
            : send_file(Minifig::almostJointCsv(), 'minifig-fehlteile.csv', 'text/csv; charset=utf-8');

    case $route === 'minifig/prices/status' && $method === 'GET':
        require_user();
        json_response(Minifig::offlinePriceStatus());

    case $route === 'minifig/prices/export' && $method === 'GET':
        require_user();
        send_file(Minifig::priceExport(), 'minifig-preispaket.bsx', 'application/xml');

    case $route === 'minifig/prices/import' && $method === 'POST':
        $me = Dev::require();
        [$content, $name] = upload_body();
        $apply = !empty($_GET['apply']);
        $r = api_try(fn () => Minifig::priceImport($content, $apply, $name));
        if ($apply) ActivityLog::log((int) $me['id'], 'minifig_price_import', 'minifig', ['preise' => $r['imported_combinations']]);
        json_response($r);

    case $route === 'minifig/transfer/export' && $method === 'GET':
        Dev::require();
        send_file(Minifig::transferExport(), 'minifig-transfer.zip', 'application/zip');

    case $route === 'minifig/catalog/import' && $method === 'POST':
        $me = Dev::require();
        // Die Datenbank des Standalone ist über 20 MB groß; Einlesen braucht mehr Speicher als üblich
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);
        [$content, $name] = upload_body(60 * 1024 * 1024);
        $r = api_try(fn () => Minifig::catalogImport($content, $name));
        ActivityLog::log((int) $me['id'], 'minifig_catalog_import', 'minifig', ['quelle' => $r['quelle']] + $r['imported']);
        json_response($r);

    case $route === 'minifig/transfer/import' && $method === 'POST':
        $me = Dev::require();
        [$content] = upload_body(60 * 1024 * 1024);
        $r = api_try(fn () => Minifig::transferImport($content));
        ActivityLog::log((int) $me['id'], 'minifig_transfer_import', 'minifig', $r['imported']);
        json_response($r);

    default:
        json_error('Nicht gefunden', 404);
}
