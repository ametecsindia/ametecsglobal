<?php
/**
 * Helper for INSTALL-Windows.bat / INSTALL-Linux.sh (ships only in the on-prem client package).
 *
 *   php _install.php verify          is this server ready, and did the zip extract completely?  (reads only)
 *   php _install.php db [fresh]      create the database and its two accounts from the MySQL root login; write .env
 *   php _install.php key             put a random APP_KEY into .env if it has none
 *   php _install.php iis [port]      register PHP in IIS, create the app pool and site, permissions, firewall, test it
 *
 * Every hurdle of the first real install (16 Nov 2026) is handled here so the engineer types only the MySQL root
 * password and a port: no SQL, no Notepad, no appcmd, no icacls, no php.ini edit, no guessing where PHP is.
 * Completeness is checked by file COUNT and BYTES against MANIFEST-SUMMARY.txt plus a list of essential files —
 * SmartPRS2's lesson: a path-by-path compare reports odd filenames as missing and blocks a good install.
 */
declare(strict_types=1);

$root = __DIR__;
$mode = $argv[1] ?? 'verify';
$envFile = $root . DIRECTORY_SEPARATOR . '.env';

/** @return array<string, string> */
$readEnv = static function () use ($envFile): array {
    $out = [];

    foreach (is_file($envFile) ? (array) file($envFile, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', (string) $line, $m) === 1) {
            $out[$m[1]] = trim($m[2], " \t\"'");
        }
    }

    return $out;
};
$setEnv = static function (array $values) use ($envFile): void {
    $text = is_file($envFile) ? (string) file_get_contents($envFile) : '';

    foreach ($values as $k => $v) {
        $line = $k . '=' . $v;
        $text = preg_match('/^' . preg_quote($k, '/') . '=.*$/m', $text) === 1
            ? (string) preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $text)
            : rtrim($text) . "\n" . $line . "\n";
    }

    file_put_contents($envFile, $text) !== false || exit(1);
};
$ask = static function (string $question, string $default = ''): string {
    echo '  ' . $question . ($default !== '' ? ' [' . $default . ']' : '') . ': ';
    $a = trim((string) fgets(STDIN));

    return $a === '' ? $default : $a;
};
$fail = static function (string $why): never {
    echo "\n  [X] {$why}\n";
    exit(1);
};

// =====================================================================================================================
if ($mode === 'key') {
    $env = (string) @file_get_contents($envFile);

    if (preg_match('/^APP_KEY=\S+/m', $env) === 1 && preg_match('/^APP_KEY=base64:CHANGE_ME/m', $env) !== 1) {
        echo "  APP_KEY already set - kept.\n";
        exit(0);
    }

    $setEnv(['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))]);
    echo "  APP_KEY written to .env - back up .env, it cannot be re-created.\n";
    exit(0);
}

// =====================================================================================================================
// THE DATABASE. Hurdles it removes: hand-written SQL, a .env from an earlier install holding passwords MySQL does not
// know, root refused as the application account, and a client install landing in a database that already holds
// someone's data (our own test database, 16 Nov 2026).
if ($mode === 'db') {
    $env = $readEnv();
    $host = ($env['DB_HOST'] ?? '') ?: '127.0.0.1';
    $port = ($env['DB_PORT'] ?? '') ?: '3306';
    $dsn = static fn (string $db = ''): string => "mysql:host={$host};port={$port};charset=utf8mb4" . ($db !== '' ? ";dbname={$db}" : '');
    $try = static function (string $user, string $pass, string $db = '') use ($dsn): ?PDO {
        try {
            return new PDO($dsn($db), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (Throwable) {
            return null;
        }
    };

    // Re-run: this install's own accounts already work -> nothing to do.
    $db = ($env['DB_DATABASE'] ?? '') ?: 'smartprs3';

    if (($env['DB_PASSWORD'] ?? '') !== '' && ($env['DB_MIGRATION_PASSWORD'] ?? '') !== '' && ($env['DB_USERNAME'] ?? '') !== 'root'
        && $try((string) $env['DB_USERNAME'], (string) $env['DB_PASSWORD']) !== null // its table rights come later (grant step)
        && $try((string) ($env['DB_MIGRATION_USERNAME'] ?? ''), (string) $env['DB_MIGRATION_PASSWORD'], $db) !== null) {
        echo "  Database `{$db}` and its two accounts already work - kept.\n";
        exit(0);
    }

    echo "  SmartPRS3 needs the MySQL administrator login ONCE, to create its database and two accounts.\n"
        . "  It is not saved anywhere.\n\n";
    $admin = null;

    for ($i = 0; $i < 3 && $admin === null; $i++) {
        $u = $ask('MySQL administrator user', 'root');
        $p = $ask('MySQL ' . $u . ' password');

        try {
            $admin = new PDO($dsn(), $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (Throwable $e) {
            echo '  [!] MySQL refused it: ' . $e->getMessage() . "\n";

            if (stripos($e->getMessage(), 'refused') !== false || stripos($e->getMessage(), '2002') !== false) {
                $fail("MySQL is not running on {$host}:{$port}. Start the MySQL service (services.msc -> MySQL80) and run this installer again.");
            }
        }
    }

    $admin ?? $fail('Three wrong MySQL passwords. Check the root password (MySQL Workbench uses the same) and run this installer again.');

    // A database that already holds tables is NEVER used without the engineer saying so: pick the first free name.
    $used = static function (string $name) use ($admin): int {
        $q = $admin->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $q->execute([$name]);

        return (int) $q->fetchColumn();
    };
    $base = preg_replace('/_\d+$/', '', $db) ?: 'smartprs3';
    $free = $db;

    for ($n = 2; $used($free) > 0; $n++) {
        $free = $base . '_' . $n;
    }

    if ($free !== $db) {
        echo "\n  [!] MySQL already has a database `{$db}` with " . $used($db) . " tables in it (another install or test data).\n"
            . "      SmartPRS3 will use a NEW database `{$free}` instead, so that data is not touched.\n";
    }

    for (;;) {
        $pick = $ask('Database name for this install', $free);

        if (preg_match('/^[A-Za-z0-9_]{1,48}$/', $pick) !== 1) {
            echo "  [!] Letters, digits and _ only.\n";
            continue;
        }

        if ($used($pick) > 0 && strtoupper($ask("`{$pick}` already has " . $used($pick) . " tables. Type YES to install into it anyway (only for a restore)")) !== 'YES') {
            continue;
        }

        $db = $pick;
        break;
    }

    // Two accounts named after the database, so two installs on one MySQL never share (or reset) each other's.
    $appUser = substr($db, 0, 27) . '_app';
    $migUser = substr($db, 0, 27) . '_mig';
    $appPass = bin2hex(random_bytes(12));
    $migPass = bin2hex(random_bytes(12));
    require $root . '/tools/db/auth-plugin.php';

    try {
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . $db . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        foreach ([[$migUser, $migPass], [$appUser, $appPass]] as [$u, $p]) {
            $clause = smartprs_identified_clause($admin, $p)['clause'];

            foreach (['localhost', '127.0.0.1'] as $h) {
                $admin->exec("CREATE USER IF NOT EXISTS '{$u}'@'{$h}' {$clause}");
                $admin->exec("ALTER USER '{$u}'@'{$h}' {$clause}");
            }
        }

        foreach (['localhost', '127.0.0.1'] as $h) {
            $admin->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO '{$migUser}'@'{$h}' WITH GRANT OPTION");
        }

        $admin->exec('FLUSH PRIVILEGES');
    } catch (Throwable $e) {
        $fail('MySQL refused to create the database or accounts: ' . $e->getMessage()
            . "\n      The login typed above must be an administrator (root).");
    }

    $setEnv(['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $db, 'DB_USERNAME' => $appUser, 'DB_PASSWORD' => $appPass,
        'DB_MIGRATION_USERNAME' => $migUser, 'DB_MIGRATION_PASSWORD' => $migPass]);
    echo "\n  [ok] database `{$db}`, accounts `{$migUser}` (setup) and `{$appUser}` (application) - passwords written to .env.\n";
    exit(0);
}

// =====================================================================================================================
// IIS. Hurdles it removes: PHP registered at the wrong path (500 "unknown FastCGI error" 0x8007010b), the app pool
// without rights on the folder, the port, the firewall, and not knowing whether it worked.
if ($mode === 'iis') {
    $sys = (getenv('windir') ?: 'C:\\Windows') . '\\system32\\inetsrv';
    $appcmd = $sys . '\\appcmd.exe';

    if (PHP_OS_FAMILY !== 'Windows' || !is_file($appcmd)) {
        echo "  IIS is not installed on this server - skipped. (To use IIS, install it - see the guide, section 2 - and run this\n"
            . "  installer again. To use Apache instead, point it at the public folder.)\n";
        exit(0);
    }

    $run = static function (string $cmd): array {
        exec($cmd . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    };
    $q = static fn (string $s): string => '"' . str_replace('"', '\\"', $s) . '"';
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $public = $root . DIRECTORY_SEPARATOR . 'public';
    $problems = [];

    is_file($cgi) || $fail('php-cgi.exe is not next to ' . PHP_BINARY . " ({$cgi}). Use the PHP zip from windows.php.net (NTS x64), which has it.");

    if (!is_file($sys . '\\rewrite.dll')) {
        $problems[] = 'URL Rewrite is NOT installed: /activate, /api and /iclock will give 404. Install "IIS URL Rewrite 2.1" (iis.net), then run this installer again.';
    }

    // 1. PHP as a FastCGI application (server-wide list), at THIS PHP's real path.
    [, $fcgi] = $run($appcmd . ' list config -section:system.webServer/fastCgi');

    if (stripos($fcgi, 'fullPath="' . $cgi . '"') === false) {
        [$c, $o] = $run($appcmd . ' set config -section:system.webServer/fastCgi /+"[fullPath=\'' . $cgi . '\',activityTimeout=\'600\',requestTimeout=\'600\',instanceMaxRequests=\'10000\']" /commit:apphost');
        $c === 0 || $fail("Could not register PHP in IIS: {$o}");
    }

    // 2. The site and its app pool: reuse the one already serving this folder, else a free name.
    [, $sites] = $run($appcmd . ' list site');
    [, $vdirs] = $run($appcmd . ' list vdir');
    $site = '';

    foreach (explode("\n", $vdirs) as $line) {
        if (preg_match('~^VDIR "([^/"]+)/" \(physicalPath:(.*)\)$~', trim($line), $m) === 1 && strcasecmp(rtrim(trim($m[2]), '\\'), $public) === 0) {
            $site = $m[1];
        }
    }

    $port = (int) ($argv[2] ?? 8085) ?: 8085;

    if ($site === '') {
        $site = 'SmartPRS3';

        for ($n = 2; stripos($sites, 'SITE "' . $site . '"') !== false; $n++) {
            $site = 'SmartPRS3-' . $n;
        }

        // A port another site binds, or that anything listens on, is skipped.
        [, $listening] = $run('netstat -an');

        while (preg_match('~:' . $port . '[:,)]~', $sites) === 1 || preg_match('~[:.]' . $port . '\s+\S+\s+LISTENING~i', $listening) === 1) {
            $port++;
        }

        stripos((string) $run($appcmd . ' list apppool')[1], 'APPPOOL "' . $site . '"') !== false
            || $run($appcmd . ' add apppool /name:' . $q($site) . ' /managedRuntimeVersion:"" /managedPipelineMode:Integrated');
        $run($appcmd . ' set apppool ' . $q($site) . ' /processModel.idleTimeout:00:00:00 /recycling.periodicRestart.time:00:00:00');
        [$c, $o] = $run($appcmd . ' add site /name:' . $q($site) . ' /physicalPath:' . $q($public) . ' /bindings:http/*:' . $port . ':');
        $c === 0 || $fail("Could not create the IIS site: {$o}");
        $run($appcmd . ' set app ' . $q($site . '/') . ' /applicationPool:' . $q($site));
    } elseif (preg_match('~SITE "' . preg_quote($site, '~') . '" \(id:\d+,bindings:[a-z]+/[^:]*:(\d+):~i', $sites, $m) === 1) {
        $port = (int) $m[1];
    }

    // 3. THIS site runs THIS PHP, whatever older PHP mapping the server has (the 0x8007010b of 16 Nov 2026).
    [, $handlers] = $run($appcmd . ' list config ' . $q($site) . ' -section:system.webServer/handlers');

    preg_match_all('~<add name="([^"]+)" path="\*\.php"[^>]*scriptProcessor="([^"]*)"~i', $handlers, $hm, PREG_SET_ORDER);

    foreach ($hm as $h) {
        if (strcasecmp($h[2], $cgi) !== 0) {
            $run($appcmd . ' set config ' . $q($site) . ' -section:system.webServer/handlers /-"[name=\'' . $h[1] . '\']" /commit:apphost');
        }
    }

    if (stripos($handlers, 'scriptProcessor="' . $cgi . '"') === false) {
        [$c, $o] = $run($appcmd . ' set config ' . $q($site) . ' -section:system.webServer/handlers /+"[name=\'SmartPRS3-PHP\',path=\'*.php\',verb=\'GET,HEAD,POST,PUT,DELETE,PATCH,OPTIONS\',modules=\'FastCgiModule\',scriptProcessor=\'' . $cgi . '\',resourceType=\'Either\']" /commit:apphost');
        $c === 0 || $fail("Could not map .php to PHP for the site: {$o}");
    }

    // 4. Rights (online updates rewrite the application files), firewall, restart the pool.
    $run('icacls ' . $q($root) . ' /grant ' . $q('IIS AppPool\\' . $site) . ':(OI)(CI)M /T /C /Q');
    $run('icacls ' . $q(dirname(PHP_BINARY)) . ' /grant ' . $q('IIS AppPool\\' . $site) . ':(OI)(CI)RX /T /C /Q');
    $run('netsh advfirewall firewall delete rule name=' . $q('SmartPRS3 port ' . $port));
    $run('netsh advfirewall firewall add rule name=' . $q('SmartPRS3 port ' . $port) . ' dir=in action=allow protocol=TCP localport=' . $port);
    $run($appcmd . ' start site ' . $q($site));
    $run($appcmd . ' recycle apppool /apppool.name:' . $q($site));

    $ip = gethostbyname((string) gethostname());
    $url = 'http://' . ($ip !== '' && $ip !== gethostname() ? $ip : 'localhost') . ':' . $port;
    $setEnv(['APP_URL' => $url]);

    // 5. Does it answer? (activate.php needs PHP, the database and URL Rewrite-free routing)
    sleep(2);
    $code = 0;
    $body = '';

    if (function_exists('curl_init')) {
        $ch = curl_init('http://127.0.0.1:' . $port . '/activate.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    echo "  IIS site `{$site}` on port {$port} -> {$public}\n  PHP: {$cgi}\n";

    if ($code === 200 && str_contains($body, 'Activate SmartPRS')) {
        echo "  [ok] the site answers: {$url}/activate\n";
    } else {
        $problems[] = "The site did not answer as expected (HTTP {$code}). Open http://localhost:{$port}/activate.php on this server to see the error.";
    }

    foreach ($problems as $p) {
        echo "  [!] {$p}\n";
    }

    file_put_contents($root . '/storage/install-url.txt', $url . "\n");
    exit(0);
}

// =====================================================================================================================
// verify: the server and the extraction (reads only)
$bad = 0;

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    echo '  [X] PHP ' . PHP_VERSION . " - SmartPRS3 needs PHP 8.3 or newer.\n";
    $bad++;
}

$missing = array_values(array_filter(['pdo_mysql', 'openssl', 'mbstring', 'curl', 'zip', 'gd', 'fileinfo'],
    static fn (string $e): bool => !extension_loaded($e)));

if ($missing !== []) {
    echo '  [X] PHP extensions not enabled in ' . (php_ini_loaded_file() ?: 'php.ini (none loaded - copy php.ini-production to php.ini)') . ': ' . implode(', ', $missing) . "\n"
        . "      Remove the ; in front of extension=" . implode(', extension=', $missing) . " and run this again.\n";
    $bad++;
}

$essential = ['public/app.php', 'public/activate.php', 'surface/Licence.php', 'tools/db/migrate.php',
    'tools/db/grant-application-account.php', 'tools/db/auth-plugin.php', 'tests/lib/bootstrap.php', 'config/version.json', 'config/cacert.pem'];
$gone = array_values(array_filter($essential, static fn (string $e): bool => !is_file("{$root}/{$e}")));

if ($gone !== []) {
    echo '  [X] Essential files missing: ' . implode(', ', $gone) . "\n";
    $bad++;
}

$summary = (string) @file_get_contents("{$root}/MANIFEST-SUMMARY.txt");
preg_match('/files=(\d+)/', $summary, $f);
preg_match('/bytes=(\d+)/', $summary, $b);
$wantFiles = (int) ($f[1] ?? 0);
$wantBytes = (int) ($b[1] ?? 0);
$gotFiles = 0;
$gotBytes = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    // What the install itself creates or changes is not part of the package.
    if (!$file->isFile() || in_array($rel, ['.env', 'MANIFEST.txt', 'MANIFEST-SUMMARY.txt'], true)
        || str_starts_with($rel, 'storage/') || str_starts_with($rel, 'public/uploads/')) {
        continue;
    }

    $gotFiles++;
    $gotBytes += (int) $file->getSize();
}

if ($wantFiles > 0) {
    printf("  files %d of %d   bytes %s of %s\n", $gotFiles, $wantFiles, number_format($gotBytes), number_format($wantBytes));

    if ($gotFiles < $wantFiles || $gotBytes < $wantBytes) {
        echo "  [X] The package did not extract completely.\n"
            . "      Windows Explorer's unzip skips files on long paths or when antivirus holds them.\n"
            . "      Extract again with tar into a SHORT, EMPTY folder, e.g.:\n"
            . "        mkdir C:\\SmartPRS3  &&  cd C:\\SmartPRS3\n"
            . "        tar -xf \"C:\\path\\to\\SmartPRS3-Client-Package-<version>.zip\" --strip-components=1\n";
        $bad++;
    }
}

echo $bad === 0 ? "  [OK] server and files are ready.\n" : '';
exit($bad === 0 ? 0 : 1);
