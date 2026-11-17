<?php
/**
 * C:\SmartPRS3-Releases LAYOUT (owner, 16 Nov 2026) — shared by make-release.bat and make-client-package.bat.
 *
 *   Updates\          online update packages: smartprs3-<v>.sprs (+ .zip/.sig/.txt)  — Super Admin → Releases
 *   Client-Packages\  full on-prem install packages: SmartPRS3-Client-Package-<v>.zip (+ .txt)
 *                     older ones go to Client-Packages\_superseded\ (nothing is ever deleted)
 *   Mobile-Apps\      APKs (Android Studio copies them there)
 *   keystore\         Android signing key
 */
declare(strict_types=1);

/** Moves update files still lying loose in the top folder (the layout before 16 Nov 2026) into Updates\. */
function releasesTidy(string $base): void
{
    $updates = $base . DIRECTORY_SEPARATOR . 'Updates';
    $moved = 0;

    foreach (glob($base . DIRECTORY_SEPARATOR . 'smartprs3-*') ?: [] as $file) {
        if (!is_file($file) || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['zip', 'sig', 'sprs', 'txt'], true)) {
            continue;
        }

        is_dir($updates) || mkdir($updates, 0775, true);
        $to = $updates . DIRECTORY_SEPARATOR . basename($file);

        if (is_file($to)) {
            $to = $updates . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '-' . date('Ymd-His') . '.' . pathinfo($file, PATHINFO_EXTENSION);
        }

        rename($file, $to) ? $moved++ : fwrite(STDERR, "Could not move {$file} (open in another program?) - left where it is.\n");
    }

    $moved > 0 && print("Moved {$moved} earlier update file(s) into {$updates}\n\n");
}
