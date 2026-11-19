<?php
/**
 * OPEN AN APP WITH THE SAME SIGN-IN (19 Nov 2026, ROVEXY "Connect first"). For apps that keep their own login
 * (ROVEXY is Laravel with its own session), AmetecsGlobal hands over who is signing in: a short-lived, signed note
 * (HMAC-SHA256 with core/data/ag-sso.key - made here on first use, never leaves this server) posted to the app.
 * The note carries the client, the person, the licence (seats, expiry) and who App access has switched off.
 * ROVEXY's side: plugins/rovexy/app/Services/AgBridge.php.
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

const AG_OPEN = ['rovexy' => '../plugins/rovexy/public/ag/sso'];   // ponytail: one app so far; the next one adds a line

session_name('AMETECSGLOBAL');
$pdo = Database::connect(AG_BASE . '/.env');
$session = $pdo === null ? null : new Session(new SqlStore($pdo), PasswordHasher::platformDefault());
$session?->start();
$user = $session?->user();
$app = (string) ($_GET['app'] ?? '');
if ($user === null || !isset(AG_OPEN[$app])) {
    header('Location: ./');
    exit;
}
$tid = (string) $user['tenant_id'];
if (AmetecsAccess::state($tid, $app) !== 'on') {
    header('Location: ./?blocked=' . rawurlencode($app));
    exit;
}
if (strtolower((string) ($user['role'] ?? '')) === 'employee') {
    $_SESSION['flash_problem'] = 'The ROVEXY console is for administrators. Employees use the ROVEXY mobile app.';
    header('Location: ./');
    exit;
}

// Not set up on this server yet (sync.ps1 installs its libraries, .env and database): say so instead of a PHP error.
$appDir = __DIR__ . '/../plugins/' . $app;
if (!is_file($appDir . '/vendor/autoload.php') || !is_file($appDir . '/.env')) {
    $_SESSION['flash_problem'] = (AmetecsAccess::plugins()[$app]['name'] ?? $app) . ' is not installed on this server yet. Start Laragon and run SYNC.bat once, then open it again.';
    header('Location: ./');
    exit;
}

$keyFile = __DIR__ . '/data/ag-sso.key';
if (!is_file($keyFile)) {
    file_put_contents($keyFile, bin2hex(random_bytes(32)), LOCK_EX);
}
$t = $pdo->prepare('SELECT name, subdomain FROM tenants WHERE id = ?');
$t->execute([$tid]);
$tenant = $t->fetch(\PDO::FETCH_ASSOC) ?: [];
$lic = AmetecsAccess::get($tid, $app);
$body = rtrim(strtr(base64_encode((string) json_encode([
    'tid' => $tid, 'tname' => (string) ($tenant['name'] ?? 'Company ' . $tid), 'slug' => (string) ($tenant['subdomain'] ?? ''),
    'email' => strtolower((string) $user['email']), 'name' => (string) ($user['name'] ?? ''),
    'seats' => (int) ($lic['seats'] ?? 0), 'until' => (string) ($lic['until'] ?? ''), 'state' => 'on',
    'off' => AmetecsAppAccess::hidden($pdo, $tid, $app),
    'exp' => time() + 60, 'jti' => bin2hex(random_bytes(12)),
])), '+/', '-_'), '=');
$token = $body . '.' . hash_hmac('sha256', $body, trim((string) file_get_contents($keyFile)));
AmetecsData::log((string) ($user['email'] ?? ''), 'Opened ' . (AmetecsAccess::plugins()[$app]['name'] ?? $app), $tid);
?><!doctype html>
<html><head><meta charset="utf-8"><title>Opening…</title></head>
<body style="background:#0b0b0d;color:#c9a24c;font:15px Inter,system-ui,sans-serif;display:grid;place-items:center;height:100vh;margin:0">
<form method="post" action="<?= htmlspecialchars(AG_OPEN[$app]) ?>"><input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
<noscript><button>Continue</button></noscript></form>
<p>Opening <?= htmlspecialchars((string) (AmetecsAccess::plugins()[$app]['name'] ?? $app)) ?>…</p>
<script>document.forms[0].submit()</script>
</body></html>
