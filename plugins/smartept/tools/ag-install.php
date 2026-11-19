<?php
/**
 * SmartEPT INSIDE AMETECSGLOBAL - first-time set-up on a PC (run by sync.ps1 once, from plugins/smartept):
 * its own MySQL database and account (smartept), and this PC's .env (key, address, database, file sessions).
 * Safe to run again: an existing database, account password and APP_KEY are kept.
 *   php tools/ag-install.php                         (MySQL root, no password - Laragon's default)
 *   php tools/ag-install.php --root-password=secret
 */
declare(strict_types=1);

ini_set('display_errors', '1');   // Laragon's PHP hides errors by default: sync.ps1 must be able to show why set-up stopped
set_exception_handler(static function (\Throwable $x): void {
    fwrite(STDOUT, 'SmartEPT set-up stopped: ' . $x->getMessage() . PHP_EOL);
    exit(1);
});

$dir = dirname(__DIR__);
$opt = getopt('', ['root-user::', 'root-password::', 'url::']) + ['root-user' => 'root', 'root-password' => ''];
foreach (['storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $d) {
    is_dir($dir . '/' . $d) || mkdir($dir . '/' . $d, 0775, true);   // storage/ is never on GitHub (AmetecsGlobal .gitignore)
}
$envFile = $dir . '/.env';
is_file($envFile) || copy($dir . '/.env.example', $envFile);
$env = (string) file_get_contents($envFile);
$get = static fn (string $k): string => preg_match('/^' . $k . '=("?)([^"#\r\n]*)\1/m', $env, $m) ? trim($m[2]) : '';
$set = static function (string $k, string $v) use (&$env): void {
    $line = $k . '=' . (preg_match('/[\s#"]/', $v) ? '"' . $v . '"' : $v);
    $env = preg_match('/^' . $k . '=.*$/m', $env) ? preg_replace('/^' . $k . '=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env) : rtrim($env) . "\n" . $line . "\n";
};

$db = 'smartept';
$user = 'smartept';
$pass = $get('DB_USERNAME') === $user && $get('DB_PASSWORD') !== '' ? $get('DB_PASSWORD') : bin2hex(random_bytes(12));
// MySQL's administrator: Laragon's default (root, no password); also tried: "root" as the password, then SmartPRS3's migrator.
$sp = (string) @file_get_contents($dir . '/../smartprs3/.env');
$spGet = static fn (string $k): string => preg_match('/^' . $k . '=("?)([^"#\r\n]*)\1/m', $sp, $m) ? trim($m[2]) : '';
$root = null;
$why = [];
// Already set up on this PC (its own account works): nothing needs MySQL's administrator - a later sync just confirms it.
$ready = false;
if ($get('DB_DATABASE') === $db && $get('DB_USERNAME') === $user && $get('DB_PASSWORD') !== '') {
    try {
        new PDO('mysql:host=127.0.0.1;port=' . ($get('DB_PORT') ?: '3306') . ';dbname=' . $db, $user, $get('DB_PASSWORD'));
        $ready = true;
    } catch (\PDOException) {
    }
}
foreach ($ready ? [] : array_unique([[(string) $opt['root-user'], (string) $opt['root-password']], ['root', 'root'], [$spGet('DB_MIGRATION_USERNAME'), $spGet('DB_MIGRATION_PASSWORD')]], SORT_REGULAR) as [$u, $pw]) {
    if ($u === '') { continue; }
    try {
        $root = new PDO('mysql:host=127.0.0.1;port=' . ($get('DB_PORT') ?: '3306'), $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");   // must be allowed to make it
        break;
    } catch (\PDOException $x) {
        $why[] = $u . ': ' . preg_replace('/^SQLSTATE\[\w+\]:? ?(\[\d+\] )?/', '', $x->getMessage());
        $root = null;
    }
}
if ($root === null && !$ready) {
    throw new RuntimeException('MySQL would not let it create the smartept database (' . implode(' | ', $why) . '). MySQL root has a password on this PC: open Laragon > Terminal and run  cd plugins\\smartept  then  php tools/ag-install.php --root-password=YOUR_ROOT_PASSWORD');
}
if (!$ready) {
    $root->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (['localhost', '127.0.0.1'] as $h) {
        $root->exec("CREATE USER IF NOT EXISTS '$user'@'$h' IDENTIFIED BY " . $root->quote($pass));
        $root->exec("ALTER USER '$user'@'$h' IDENTIFIED BY " . $root->quote($pass));
        $root->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '$user'@'$h'");
    }
}

$url = (string) ($opt['url'] ?? 'http://localhost/AmetecsGlobal/plugins/smartept/public');
$keep = static fn (string $k, int $bytes = 32): string => $get($k) !== '' && !str_contains($get($k), 'f1flY5G3rrhJbe') ? $get($k) : 'base64:' . base64_encode(random_bytes($bytes));
foreach ([
    'APP_NAME' => 'SmartEPT', 'APP_ENV' => 'staging', 'APP_DEBUG' => 'false', 'APP_URL' => $url, 'APP_TIMEZONE' => 'Asia/Kolkata',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => $db, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass,
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false',
    'SESSION_COOKIE' => 'smartept_session', 'SESSION_PATH' => rtrim((string) parse_url(dirname($url), PHP_URL_PATH), '/') . '/',
    'MAIL_MAILER' => 'log',
    // Inside AmetecsGlobal: each client is a cloud tenant licensed by AmetecsGlobal Super Admin (no SmartEPT Central)
    'SMARTEPT_ONPREM' => 'false', 'SMARTEPT_LICENCE_ENFORCE' => 'true',
    'SSO_SHARED_SECRET' => $keep('SSO_SHARED_SECRET'),       // signs the console's own ?sso= ticket (AgBridge::ticket)
    'RELAY_SECRET' => $keep('RELAY_SECRET'),                 // never the sample value shipped in .env.example
] as $k => $v) {
    $set($k, $v);
}
$get('APP_KEY') === '' && $set('APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
file_put_contents($envFile, $env);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/artisan') . ' migrate --force', $rc);   // its tables, now, in this database
$rc === 0 && passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/artisan') . ' db:seed --class=RolePermissionSeeder --force', $rc);   // roles and permissions
if ($rc !== 0) {
    throw new RuntimeException('the database is ready but creating its tables failed (see above).');
}
echo "SmartEPT: database '$db' and this PC's .env are ready.\n";
