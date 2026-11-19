<?php
/**
 * 04-Nov-2026 (Ejaz: update 2.7 failed "Class Laravel\Pail\PailServiceProvider not found").
 *
 * Writes bootstrap/cache/packages.php for the STAGED, production-only vendor/ — used for the
 * UPDATE zip only. The updater merges files and the client's OLD app rebuilds its own package
 * list (developer packages included) during `artisan down`, a moment before the new files land.
 * Shipping the right list makes the copy overwrite that stale one, so the new version boots.
 *
 * Usage: php make-package-manifest.php <staged app folder>
 */
$stage = rtrim($argv[1] ?? '', '\\/');
if ($stage === '' || ! is_file($stage . '/vendor/autoload.php')) {
    fwrite(STDERR, "make-package-manifest: no vendor/autoload.php in '{$stage}'\n");
    exit(1);
}
require $stage . '/vendor/autoload.php';

$out = $stage . '/bootstrap/cache/packages.php';
@unlink($out);
(new Illuminate\Foundation\PackageManifest(new Illuminate\Filesystem\Filesystem, $stage, $out))->build();

$manifest = is_file($out) ? require $out : null;
if (! is_array($manifest)) {
    fwrite(STDERR, "make-package-manifest: packages.php was not written\n");
    exit(1);
}
foreach (['laravel/pail', 'laravel/sail', 'nunomaduro/collision'] as $dev) {
    if (isset($manifest[$dev])) {
        fwrite(STDERR, "make-package-manifest: developer package {$dev} is in the list - vendor is not production-only\n");
        exit(1);
    }
}
echo 'packages.php written for ' . count($manifest) . " packages (no developer packages).\n";
