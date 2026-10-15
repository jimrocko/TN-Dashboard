<?php

const ROLES_STAFF = ['lehrer', 'admin'];

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = config()['session_lifetime'] ?? 28800;
    session_name('tn_session');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function public_user(array $u): array
{
    return [
        'id'           => (int) $u['id'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'role'         => $u['role'],
        'last_seen'    => $u['last_seen'] ?? null,
    ];
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM ' . t('users') . ' WHERE id = ? AND active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    db()->prepare('UPDATE ' . t('users') . ' SET last_seen = ? WHERE id = ?')->execute([now(), $user['id']]);
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        json_error('Nicht angemeldet', 401);
    }
    return $user;
}

function require_staff(): array
{
    $user = require_user();
    if (!in_array($user['role'], ROLES_STAFF, true)) {
        json_error('Nur für Lehrer', 403);
    }
    return $user;
}

function login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM ' . t('users') . ' WHERE username = ? AND active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return $user;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}
