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
 *
 * UPDATES (Phase 2) - same key + fingerprint, from the bound server only, licence active:
 *   {"action": "updates", "current_version": "2.1.0"}   -> {"update_available", "version", "notes", "size_bytes",
 *        "package_hash", "signature", "mandatory", "payment", "downloadable", "plugins", "rollback"}  (SmartPRS3's field names)
 *   {"action": "download", "version": "2.2.0"}          -> the zip (application/zip), or JSON {"ok": false, "error"}
 *   {"action": "report", "version": "2.2.0", "result": "applied|failed|rolled_back", "detail": "...", "from_version": "2.1.0"}
 *   download / report take "plugin": "<code>" for a plugin package (Phase 6); "updates" lists each plugin with its state.
 *   {"action": "stats", "stats": {"tiles": [{"label", "value", "sub", "tone"}], "list": {...}}}  -> the dashboard figures (Phase 6)
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
$in = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
$in = is_array($in) ? $in : $_POST;
$action = (string) ($in['action'] ?? '');
if (!in_array($action, ['activate', 'check', 'updates', 'download', 'report', 'stats'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'action must be activate, check, updates, download, report or stats.']);
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

$app = strtolower(trim((string) ($in['app'] ?? '')));
$r = AmetecsLicences::serverCall($action === 'activate' ? 'activate' : 'check', $app, (string) ($in['key'] ?? ''), trim((string) ($in['fingerprint'] ?? '')), $in);
if ($action === 'activate' || $action === 'check') {
    echo json_encode($r, JSON_UNESCAPED_SLASHES);
    exit;
}
// Updates are only for an active licence on its own bound server.
if (!$r['ok'] || !AmetecsReleases::ready() || ($lic = AmetecsLicences::byKey((string) ($in['key'] ?? ''))) === null) {
    http_response_code($r['ok'] ? 503 : 403);
    echo json_encode(['ok' => false, 'update_available' => false, 'error' => $r['ok'] ? 'Updates are not available yet on this server.' : $r['message'], 'message' => $r['ok'] ? 'Updates are not available yet.' : $r['message']]);
    exit;
}
AmetecsLicences::$actor = 'server ' . ((string) ($lic['server_name'] ?? '') ?: 'on-premise');
$current = mb_substr(trim((string) ($in['current_version'] ?? $in['from_version'] ?? $lic['app_version'] ?? '')), 0, 39);
if ($action === 'updates') {
    echo json_encode(AmetecsReleases::apiCheck($lic, $current), JSON_UNESCAPED_SLASHES);
    exit;
}
$plugin = AmetecsPlugins::ready() && trim((string) ($in['plugin'] ?? '')) !== '' ? AmetecsPlugins::slug((string) $in['plugin']) : '';
if ($action === 'stats') {
    AmetecsShell::saveStats($lic, is_array($in['stats'] ?? null) ? $in['stats'] : []);
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'report') {
    AmetecsReleases::apiReport($lic, mb_substr(trim((string) ($in['version'] ?? '')), 0, 39), (string) ($in['result'] ?? $in['status'] ?? ''), (string) ($in['detail'] ?? ''), $current, $plugin);
    echo json_encode(['ok' => true]);
    exit;
}
$d = AmetecsReleases::apiDownload($lic, trim((string) ($in['version'] ?? '')), $current, $plugin);
if (!$d['ok']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $d['why']]);
    exit;
}
header_remove('Content-Type');
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($d['path']));
header('Content-Disposition: attachment; filename="' . basename($d['path']) . '"');
header('X-Package-SHA256: ' . $d['release']['sha256']);
readfile($d['path']);
