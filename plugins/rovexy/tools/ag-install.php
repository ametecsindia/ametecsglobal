<?php
/**
 * ROVEXY INSIDE AMETECSGLOBAL - first-time set-up on a PC (run by sync.ps1 once, from plugins/rovexy):
 * its own MySQL database and account (rovexy), and this PC's .env (key, address, database, file sessions).
 * Safe to run again: an existing database, account password and APP_KEY are kept.
 *   php tools/ag-install.php                         (MySQL root, no password - Laragon's default)
 *   php tools/ag-install.php --root-password=secret
 */
declare(strict_types=1);

ini_set('display_errors', '1');   // Laragon's PHP hides errors by default: sync.ps1 must be able to show why set-up stopped
set_exception_handler(static function (\Throwable $x): void {
    fwrite(STDOUT, 'ROVEXY set-up stopped: ' . $x->getMessage() . PHP_EOL);
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

$db = 'rovexy';
$user = 'rovexy';
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
    throw new RuntimeException('MySQL would not let it create the rovexy database (' . implode(' | ', $why) . '). MySQL root has a password on this PC: open Laragon > Terminal and run  cd plugins\\rovexy  then  php tools/ag-install.php --root-password=YOUR_ROOT_PASSWORD');
}
if (!$ready) {
    $root->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (['localhost', '127.0.0.1'] as $h) {
        $root->exec("CREATE USER IF NOT EXISTS '$user'@'$h' IDENTIFIED BY " . $root->quote($pass));
        $root->exec("ALTER USER '$user'@'$h' IDENTIFIED BY " . $root->quote($pass));
        $root->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '$user'@'$h'");
    }
}

$url = (string) ($opt['url'] ?? 'http://localhost/AmetecsGlobal/plugins/rovexy/public');
foreach ([
    'APP_ENV' => 'staging',   // not "production": that one insists on https and a live phone/push set-up
    'APP_DEBUG' => 'false', 'APP_URL' => $url,
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => $db, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass,
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost,127.0.0.1,' . parse_url($url, PHP_URL_HOST),
    'BROADCAST_CONNECTION' => 'null', 'TELEPHONY_DRIVER' => 'click2call', 'MANAGEMENT_DRIVER' => 'app_only',
    'RECORDINGS_LOCAL_ROOT' => str_replace('\\', '/', $dir) . '/storage/app/recordings',
] as $k => $v) {
    $set($k, $v);
}
$get('APP_KEY') === '' && $set('APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
file_put_contents($envFile, $env);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/artisan') . ' migrate --force', $rc);   // its tables, now, in this database
if ($rc !== 0) {
    throw new RuntimeException('the database is ready but creating its tables failed (see above).');
}
echo "ROVEXY: database '$db' and this PC's .env are ready.\n";
