<?php
/**
 * OPEN AN APP WITH THE SAME SIGN-IN (19 Nov 2026, ROVEXY "Connect first"). For apps that keep their own login
 * (ROVEXY is Laravel with its own session), Ametecs Global hands over who is signing in: a short-lived, signed note
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

const AG_OPEN = ['rovexy' => '../plugins/rovexy/public/ag/sso', 'qronex' => '../plugins/qronex/public/ag/sso', 'smartept' => '../plugins/smartept/public/ag/sso'];   // each Laravel app's hand-over address

session_name('AMETECSGLOBAL');
$pdo = Database::connect(AG_BASE . '/.env');
$session = $pdo === null ? null : new Session(new SqlStore($pdo), PasswordHasher::platformDefault());
$session?->start();
$user = $session?->user();
$app = (string) ($_GET['app'] ?? '');
// ONE MENU (20 Nov 2026): the page inside the app to land on (a console path, portal path or SmartEPT view)
$to = preg_match('#^[a-z0-9][a-z0-9/_:-]{0,79}$#', (string) ($_GET['to'] ?? '')) === 1 ? (string) $_GET['to'] : '';
if ($user === null && isset(AG_OPEN[$app]) && $session !== null) {   // ONE LOGIN: sign in here, then straight on into the app
    $_SESSION['ag_next'] = [$app, time(), $to];
    header('Location: ./');
    exit;
}
if ($user === null || !isset(AG_OPEN[$app])) {
    header('Location: ./');
    exit;
}
$tid = (string) $user['tenant_id'];
if (AmetecsAccess::state($tid, $app) !== 'on') {
    header('Location: ./?blocked=' . rawurlencode($app));
    exit;
}
if (!AmetecsLicences::installed(AmetecsLicences::row($tid, $app)) || AmetecsLicences::maintenance($tid, $app)) {   // enabled but not downloaded yet (owner, 19 Nov 2026)
    $_SESSION['flash_problem'] = (AmetecsAccess::plugins()[$app]['name'] ?? $app) . (AmetecsLicences::maintenance($tid, $app)
        ? ' is in maintenance mode while it is being installed or updated. Try again in a minute.'
        : ' is enabled for your company but not installed yet. Click Download on it in My applications.');
    header('Location: ./?v=apps');
    exit;
}
// ONE LOGIN, ONE ROLE MAP (owner, 19 Nov 2026): Admin = a login that is not an employee login; for employee logins the
// reporting line decides Manager / Team Lead / Employee (core/roles.php). Each app gives that role its own scope.
$emp = (string) ($user['employee_id'] ?? '');
$level = AmetecsRoles::level($pdo, $user);
$appName = AmetecsAccess::plugins()[$app]['name'] ?? $app;
$off = AmetecsAppAccess::hidden($pdo, $tid, $app);
if ($level !== 'admin' && $emp !== '' && in_array((int) $emp, array_map('intval', $off), true)) {
    $_SESSION['flash_problem'] = 'Your administrator has switched ' . $appName . ' off for you (App access).';
    header('Location: ./');
    exit;
}
if ($level === 'employee' && !in_array($app, AmetecsRoles::SELF_SERVICE, true)) {
    $_SESSION['flash_problem'] = $appName . ' is for administrators, managers and team leads. Employees use its mobile app.';
    header('Location: ./');
    exit;
}

// Not set up on this server yet (sync.ps1 installs its libraries, .env and database): say so instead of a PHP error.
$appDir = __DIR__ . '/../plugins/' . $app;
if (!is_file($appDir . '/vendor/autoload.php') || !str_contains((string) @file_get_contents($appDir . '/.env'), 'APP_KEY=base64:')) {
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
    'off' => $off,
    'role' => $level, 'emp' => $emp,   // the app reads the team under 'emp' from Ametecs Global's employees (reporting line)
    'sid' => hash('sha256', (string) session_id()),   // this Ametecs Global session: when it ends, the app's session ends too
    'to' => $to,   // the app lands here (each app's AgSsoController checks it is one of its own pages)
    'exp' => time() + 60, 'jti' => bin2hex(random_bytes(12)),
])), '+/', '-_'), '=');
$token = $body . '.' . hash_hmac('sha256', $body, trim((string) file_get_contents($keyFile)));
$to === '' && AmetecsData::log((string) ($user['email'] ?? ''), 'Opened ' . (AmetecsAccess::plugins()[$app]['name'] ?? $app), $tid);
// Straight into the app (owner, 19 Nov 2026: no "Opening…" page): a one-time 60-second hand-over in the address.
header('Location: ' . AG_OPEN[$app] . '?t=' . rawurlencode($token), true, 302);
exit;
