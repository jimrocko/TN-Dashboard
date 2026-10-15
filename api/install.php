<?php
/**
 * Einrichtung: legt die Tabellen in der Datenbank an und erstellt das erste Lehrer-Konto.
 * Im Browser aufrufen: <ordner>/api/install.php
 * Sobald ein Konto existiert, ist das Anlegen weiterer Konten hier gesperrt.
 *
 * Kommandozeile (lokale Entwicklung, mit Demo-Konten):  php api/install.php --demo
 */

declare(strict_types=1);

require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/db.php';

$cli = PHP_SAPI === 'cli';

function install_schema(): void
{
    $driver = config()['db']['driver'] === 'sqlite' ? 'sqlite' : 'mysql';
    $sql = str_replace('{p}', t(''), file_get_contents(__DIR__ . "/sql/schema.$driver.sql"));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
}

function user_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM ' . t('users'))->fetchColumn();
}

function create_user(string $username, string $name, string $password, string $role): void
{
    db()->prepare('INSERT INTO ' . t('users') . ' (username, display_name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
}

if ($cli) {
    install_schema();
    echo "Tabellen angelegt.\n";
    if (in_array('--demo', $argv, true) && user_count() === 0) {
        create_user('lehrer', 'Herr Lehrer', 'lehrer123', 'lehrer');
        create_user('max', 'Max Mustermann', 'max12345', 'teilnehmer');
        create_user('erika', 'Erika Musterfrau', 'erika123', 'teilnehmer');
        echo "Demo-Konten: lehrer/lehrer123, max/max12345, erika/erika123\n";
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$message = '';
$error = '';

try {
    install_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && user_count() === 0) {
        $u = strtolower(trim($_POST['username'] ?? ''));
        $n = trim($_POST['display_name'] ?? '');
        $p = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $u) || $n === '' || strlen($p) < 8) {
            $error = 'Bitte gültigen Benutzernamen, Namen und ein Passwort mit mind. 8 Zeichen angeben.';
        } else {
            create_user($u, $n, $p, 'lehrer');
            $message = 'Lehrer-Konto angelegt. Du kannst dich jetzt anmelden.';
        }
    }
    $count = user_count();
} catch (Throwable $e) {
    $error = 'Datenbankfehler: ' . $e->getMessage();
    $count = -1;
}
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>TN Dashboard – Einrichtung</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0b1018;color:#e6ebf2;max-width:520px;margin:48px auto;padding:0 16px}
  .card{background:#141b26;border:1px solid #263142;border-radius:14px;padding:24px}
  label{display:block;margin:12px 0 4px;color:#9aa6b8;font-size:14px}
  input{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid #2c3a4f;background:#0f151f;color:inherit}
  button,a.btn{display:inline-block;margin-top:18px;padding:10px 18px;border-radius:8px;border:0;background:#3b82f6;color:#fff;text-decoration:none;font-size:15px}
  .ok{color:#4ade80}.err{color:#f87171}
</style>
</head>
<body>
<h1>TN Dashboard – Einrichtung</h1>
<div class="card">
<?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="ok"><?= $h($message) ?></p><?php endif; ?>
<?php if ($count === 0): ?>
  <p>Die Tabellen sind angelegt. Lege jetzt das erste <strong>Lehrer-Konto</strong> an:</p>
  <form method="post">
    <label>Benutzername</label><input name="username" required pattern="[a-z0-9._\-]{3,32}">
    <label>Anzeigename</label><input name="display_name" required>
    <label>Passwort (mind. 8 Zeichen)</label><input name="password" type="password" required minlength="8">
    <button type="submit">Konto anlegen</button>
  </form>
<?php elseif ($count > 0): ?>
  <p class="ok">Die Einrichtung ist abgeschlossen (<?= $count ?> Konto/Konten vorhanden).</p>
  <p>Weitere Teilnehmer legt der Lehrer in der App unter <em>Lehrer-Ansicht</em> an.</p>
  <a class="btn" href="../">Zum Dashboard</a>
<?php endif; ?>
</div>
</body>
</html>
