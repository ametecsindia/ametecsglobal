<?php
/**
 * AMETECSGLOBAL LICENCE SERVER (owner, 18 Nov 2026 - Phase 1): what an on-premise install of ANY Ametecs app calls.
 *
 *   POST core/licence-api.php   Content-Type: application/json
 *   {"action": "activate" | "check", "app": "smartdcm", "key": "AG-XXXX-XXXX-XXXX-XXXX",
 *    "fingerprint": "<the server's machine id>", "server_name": "...", "server_os": "...", "version": "1.4.2"}
 *
 *   -> {"ok": true|false, "status": "on|suspended|expired|off|wrong_server|unknown", "message": "...",
 *       "licence": {"app", "seats", "plan", "valid_until"}}
 *
 * The first "activate" binds the licence to that server; any other server is refused until Super Admin unbinds it.
 * Unknown keys get one neutral answer (no hint whether a key exists). Every call is in the licence history.
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only.']);
    exit;
}
$in = json_decode((string) file_get_contents('php://input', false, null, 0, 16384), true);
$in = is_array($in) ? $in : $_POST;
$action = (string) ($in['action'] ?? '');
if (!in_array($action, ['activate', 'check'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'action must be activate or check.']);
    exit;
}

// ponytail: crude per-IP brake (20 calls a minute) in the session-less temp dir; a real limiter when there is a load balancer.
$ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? 'x'));
$brake = sys_get_temp_dir() . '/ag-licapi-' . md5($ip) . '-' . date('YmdHi');
$n = (int) @file_get_contents($brake) + 1;
@file_put_contents($brake, (string) $n);
if ($n > 20) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'message' => 'Too many requests. Try again in a minute.']);
    exit;
}

$r = AmetecsLicences::serverCall($action, strtolower(trim((string) ($in['app'] ?? ''))), (string) ($in['key'] ?? ''),
    trim((string) ($in['fingerprint'] ?? '')), $in);
echo json_encode($r, JSON_UNESCAPED_SLASHES);
