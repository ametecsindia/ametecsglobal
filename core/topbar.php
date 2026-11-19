<?php
/**
 * ONE TOP BAR (19 Nov 2026, owner: "show everywhere same"): who is signed in to AmetecsGlobal, for the AmetecsGlobal top bar
 * that assets/ag-topbar.js draws inside ROVEXY, QRONEX and SmartEPT. Same-origin JSON; nothing is changed here.
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

header('Content-Type: application/json');
header('Cache-Control: no-store');
session_name('AMETECSGLOBAL');
$pdo = Database::connect(AG_BASE . '/.env');
$platform = $pdo === null ? null : new SqlStore($pdo);
$session = $platform === null ? null : new Session($platform, PasswordHasher::platformDefault());
$session?->start();
$user = $session?->user();
if ($user === null) {
    echo json_encode(['ok' => false]);
    exit;
}
$tenant = (string) $user['tenant_id'];
$who = trim((string) ($user['name'] ?? '')) ?: ucfirst((string) strtok((string) ($user['email'] ?? ''), '@'));
$isAdmin = strtolower((string) ($user['role'] ?? '')) !== 'employee';
echo json_encode([
    'ok' => true,
    'company' => (string) ($platform->find('tenants', $tenant)['name'] ?? ''),
    'name' => $who,
    'email' => (string) ($user['email'] ?? ''),
    'role' => ucfirst((string) ($user['role'] ?? '')),
    'initials' => strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/[\s._-]+/', $who) ?: ['U'], 0, 2)))),
    'notes' => count(AmetecsShell::notifications($tenant, $isAdmin)),
    'admin' => $isAdmin,
    'csrf' => $session->token(),
]);
