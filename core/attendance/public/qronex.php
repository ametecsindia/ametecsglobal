<?php
/**
 * QRONEX -> AMETECSGLOBAL ATTENDANCE: QRONEX calls this after each punch (AgBridge::pingPunches); it pulls that client's
 * new punches (core/attendance/qronex-pull.php). Takes nothing from the caller but the client number, so it needs no key:
 * at worst a stranger makes it look for new punches sooner.
 */
declare(strict_types=1);

require (dirname(__DIR__, 3) . '/plugins/smartprs3/public') . '/../surface/partials/endpoint-boot.php';

try {
    $out = (require dirname(__DIR__) . '/qronex-pull.php')((string) ($_GET['tenant'] ?? ''));
} catch (\Throwable $x) {
    json_out(500, ['ok' => false, 'error' => 'pull_failed']);
}
json_out(200, ['ok' => true] + $out);
