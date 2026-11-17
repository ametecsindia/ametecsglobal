<?php
/**
 * BUILD THE ON-PREMISES CLIENT PACKAGE (run by make-client-package.bat on the build PC).
 * SmartPRS2's _ClientPackages\BUILD-CLIENT-PACKAGE.bat, step for step, for SmartPRS3:
 *
 *   1. take the CURRENT code of this folder (nothing to update by hand; the folder is never modified)
 *   2. leave out secrets, development files and build junk; add an empty storage skeleton
 *   3. lay the client installer set (deploy/onprem) over the top
 *   4. refuse to ship a broken package (essential files missing, .env / key / licence file leaked)
 *   5. stamp VERSION.txt, write MANIFEST.txt + MANIFEST-SUMMARY.txt (the installer checks the extraction against them)
 *   6. zip it
 *   7. move the previous package to _superseded\, put the new one in place, print its SHA-256
 *
 *   php tools/release/build-client-package.php [releases folder]      (default C:\SmartPRS3-Releases)
 *
 * Output: <releases>\Client-Packages\SmartPRS3-Client-Package-<version>.zip and .txt. The version is the one in
 * config/version.json (make-release.bat sets it) — a client package is a full install of a released version.
 */
declare(strict_types=1);

require __DIR__ . '/releases-folder.php';

$root = dirname(__DIR__, 2);
$base = rtrim((string) ($argv[1] ?? 'C:\\AmetecsGlobal-Releases'), '\\/');
$out = $base . DIRECTORY_SEPARATOR . 'Client-Packages';
$kit = $root . '/deploy/onprem';
$inner = 'SmartPRS3-OnPrem';
$stop = static function (string $why): never {
    fwrite(STDERR, "\n[X] {$why}\nBuild stopped - nothing was written.\n");
    exit(1);
};

class_exists(\ZipArchive::class) || $stop("PHP extension 'zip' is not enabled (php.ini).");
releasesTidy($base);

$ver = json_decode((string) @file_get_contents($root . '/config/version.json'), true) ?: [];
$version = (string) ($ver['version'] ?? '');
$version !== '' || $stop('config/version.json has no version.');
echo "SmartPRS3 on-premises client package\n  Source  : {$root}\n  Version : {$version}\n\n";

// ---- 1-2. WHAT GOES IN --------------------------------------------------------------------------------------------
// Left out: secrets (.env, storage/ which holds the licence private key), development and test files, the Android
// sources, Ametecs' own build tools. tests/lib stays: tools/db/migrate.php needs it. vendor/ stays, as in SmartPRS2:
// a client server has no composer (it is only used for the old .xls import, and the app runs without it).
$skipDirs = ['.git', 'storage', 'mobile', 'node_modules', 'Claude outputs', '.phpunit.cache', '.idea', '.vscode', '.fleet',
    'public/uploads', 'docs', 'tools/release', 'deploy/onprem'];
$skipFiles = ['.env', '.env.bak', '.env.example', 'env-local.txt', 'CLAUDE.md', 'make-release.bat', 'make-client-package.bat',
    'laragon-setup.bat', 'public/diag.php'];
$skipPatterns = ['#^surface/(screens|actions)/dev-[^/]*\.php$#', '#^public/dev-fieldtest[^/]*\.js$#', '#\.upd-#', '#(^|/)desktop\.ini$#i',
    '#\.(log|bak)$#i'];

echo "[1/7] Collecting files ...\n";
$files = [];
$it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    static function (\SplFileInfo $f) use ($root, $skipDirs, $skipFiles, $skipPatterns): bool {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));

        if ($f->isDir()) {
            // tests/: only tests/lib goes in.
            return !in_array($rel, $skipDirs, true) && ($rel === 'tests' || !str_starts_with($rel, 'tests/') || str_starts_with($rel, 'tests/lib'));
        }

        if (str_starts_with($rel, 'tests/') && !str_starts_with($rel, 'tests/lib/')) {
            return false;
        }

        foreach ($skipPatterns as $p) {
            if (preg_match($p, $rel) === 1) {
                return false;
            }
        }

        return !in_array($rel, $skipFiles, true);
    }
));

foreach ($it as $f) {
    $f->isFile() && $files[str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1))] = $f->getPathname();
}

// ---- 3. THE CLIENT INSTALLER SET (exists only in the package) -----------------------------------------------------
echo "[2/7] Applying the client installer set ...\n";
$overlay = ['INSTALL-Windows.bat', 'INSTALL-Linux.sh', 'VERIFY-INSTALL.bat', 'README-INSTALL.txt', '_install.php', '.env.example' => 'env.onprem.example',
    'SmartPRS3-OnPrem-IIS-Installation-Guide.pdf'];

foreach ($overlay as $to => $from) {
    $to = is_int($to) ? $from : $to;
    is_file("{$kit}/{$from}") || $stop("deploy/onprem/{$from} is missing - the installer set lives there.");
    $files[$to] = "{$kit}/{$from}";
}

// IIS: the URL rewriting that public/.htaccess does for Apache.
is_file($root . '/deploy/web.config') && $files['public/web.config'] = $root . '/deploy/web.config';

// ---- 4. COMPLETENESS GUARDS ---------------------------------------------------------------------------------------
echo "[3/7] Checking the build ...\n";
$essential = ['public/app.php', 'public/activate.php', 'public/.htaccess', 'surface/Licence.php', 'surface/Database.php',
    'surface/Updater.php', 'config/version.json', 'tools/db/migrate.php', 'tools/db/grant-application-account.php',
    'tools/db/verify-connection.php', 'tools/db/auth-plugin.php', 'tools/cron.php', 'tests/lib/bootstrap.php', 'tests/lib/laravel_schema_stub.php',
    'config/cacert.pem'];
$bad = array_values(array_filter($essential, static fn (string $e): bool => !isset($files[$e])));
$migrations = count(array_filter(array_keys($files), static fn (string $r): bool => str_starts_with($r, 'database/migrations/')));
$migrations > 0 || $bad[] = 'database/migrations/*';

foreach (array_keys($files) as $rel) {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $name = strtolower(basename($rel));

    // Licence private key, issued .lic files, Android signing keys, a real .env: never in a package.
    // cacert*.pem is the PUBLIC CA bundle (SmartPRS2 made the same exception).
    if ($name === '.env' || (in_array($ext, ['pem', 'key', 'lic', 'jks', 'keystore', 'p12', 'pfx'], true) && !str_contains($name, 'cacert'))) {
        $bad[] = "{$rel}  <- secret file leaked into the package";
    }
}

$bad === [] || $stop("The package would be broken or unsafe:\n    " . implode("\n    ", $bad));
echo '      ' . count($files) . " files, {$migrations} migrations" . (isset($files['vendor/autoload.php']) ? '' : ' (no vendor\ - old .xls import will not work on the client)') . "\n";

// ---- 5. VERSION.txt + MANIFEST ------------------------------------------------------------------------------------
echo "[4/7] Stamping VERSION.txt and MANIFEST.txt ...\n";
$commit = '';
$head = trim((string) @file_get_contents($root . '/.git/HEAD'));

if (str_starts_with($head, 'ref: ')) {
    $commit = trim((string) @file_get_contents($root . '/.git/' . substr($head, 5)));
}

$extra = [
    'VERSION.txt' => "SmartPRS3 - on-premises client package\r\n======================================\r\n"
        . "Version     : {$version} (released " . (string) ($ver['released_on'] ?? '') . ")\r\n"
        . 'Built       : ' . date('d-m-Y H:i') . "\r\nBuilt from  : {$root}" . ($commit !== '' ? "\r\nGit commit  : " . substr($commit, 0, 12) : '') . "\r\n"
        . 'Files       : ' . count($files) . "\r\nMigrations  : {$migrations}\r\nRequires    : PHP 8.3+, MySQL 8 / MariaDB 10.6+\r\n\r\n"
        . "Install: see README-INSTALL.txt. Later versions arrive online: Administration -> Updates & Licence.\r\n"
        . "Support: ejaz@ametecsindia.com  .  WhatsApp 9000098877\r\n",
];
$manifest = [];
$bytes = 0;

foreach ($files as $rel => $path) {
    $size = (int) filesize($path);
    $manifest[] = "{$rel}|{$size}";
    $bytes += $size;
}

foreach ($extra as $rel => $body) {
    $manifest[] = "{$rel}|" . strlen($body);
    $bytes += strlen($body);
}

$extra['MANIFEST.txt'] = implode("\n", $manifest) . "\n";
$extra['MANIFEST-SUMMARY.txt'] = 'files=' . count($manifest) . "\nbytes={$bytes}\n";

// ---- 6. ZIP --------------------------------------------------------------------------------------------------------
echo "[5/7] Creating the zip (vendor makes this take a minute) ...\n";
is_dir($out) || mkdir($out, 0775, true);
$name = "SmartPRS3-Client-Package-{$version}";
$tmp = $out . DIRECTORY_SEPARATOR . ".{$name}.building.zip";
@unlink($tmp);
$zip = new \ZipArchive();
$zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true || $stop("Cannot write {$tmp}.");

foreach ($files as $rel => $path) {
    $zip->addFile($path, "{$inner}/{$rel}");
}

foreach ($extra as $rel => $body) {
    $zip->addFromString("{$inner}/{$rel}", $body);
}

// The writable skeleton (an install writes logs, sessions, updates and uploads here).
foreach (['storage', 'storage/keys', 'storage/updates', 'public/uploads'] as $d) {
    $zip->addEmptyDir("{$inner}/{$d}");
}

$zip->close() || $stop('The zip could not be finished.');

// ---- 7. PUBLISH: previous package -> _superseded\ -----------------------------------------------------------------
echo "[6/7] Publishing ...\n";
$old = $out . DIRECTORY_SEPARATOR . '_superseded';

foreach (glob($out . DIRECTORY_SEPARATOR . 'SmartPRS3-Client-Package-*') ?: [] as $prev) {
    if (!is_file($prev)) {
        continue;
    }

    is_dir($old) || mkdir($old, 0775, true);
    $to = $old . DIRECTORY_SEPARATOR . basename($prev);
    is_file($to) && $to = $old . DIRECTORY_SEPARATOR . pathinfo($prev, PATHINFO_FILENAME) . '-' . date('Ymd-His', (int) filemtime($prev)) . '.' . pathinfo($prev, PATHINFO_EXTENSION);
    rename($prev, $to) && print('      previous ' . basename($prev) . " -> _superseded\\\n");
}

$final = $out . DIRECTORY_SEPARATOR . "{$name}.zip";
rename($tmp, $final) || $stop("Could not rename {$tmp}.");

echo "[7/7] Checking the zip ...\n";
$check = new \ZipArchive();
($check->open($final) === true && $check->numFiles >= count($manifest) + 2) || $stop("{$final} did not come out complete - build again.");
$check->close();
$sha = (string) hash_file('sha256', $final);
$size = (int) filesize($final);
file_put_contents($out . DIRECTORY_SEPARATOR . "{$name}.txt",
    "SmartPRS3 on-premises client package {$version}\r\nBuilt: " . date('d-m-Y H:i') . "\r\nFile: {$name}.zip (" . number_format($size)
    . " bytes, " . count($manifest) . " files)\r\nSHA-256: {$sha}\r\n");

echo "\n===============================================================\n  PACKAGE READY\n    {$final}\n    "
    . round($size / 1048576, 1) . " MB   " . count($manifest) . " files\n    SHA-256 {$sha}\n\n"
    . "  ON THE CLIENT SERVER (README-INSTALL.txt inside has the details)\n"
    . "    1. Unzip into an EMPTY folder.\n"
    . "    2. Double-click INSTALL-Windows.bat - it asks for the MySQL root password and a port, and sets up\n"
    . "       the database, IIS, permissions, firewall and background jobs.  (Linux: sudo bash INSTALL-Linux.sh)\n"
    . "    3. Browser -> the address it prints /activate (License Code), then the sign-in page creates the administrator.\n"
    . "===============================================================\n";
