<?php
/**
 * THE HUB'S DOOR FOR ON-PREMISE INSTALLS THAT SPEAK SMARTPRS' /update/* PROTOCOL (owner, 18 Nov 2026 - Phase 5).
 * SmartPRS3 (and other apps built on the SmartPRS 2 licence client) point at Ametecs Global with one .env line:
 *
 *   SMARTPRS_LICENCE_SERVER=https://<ametecsglobal host>/core/update.php
 *
 * and then call <that>/update/{activate|heartbeat|check|download|report} with form fields, exactly as before.
 * Each call is answered from the Ametecs Global licences and releases (Super Admin -> Licences / Updates & releases),
 * so binding, suspension, renewals, paid upgrades and install history all live in the one hub.
 * The licence key (AG-XXXX-...) says which app it is for; the machine fingerprint must match the bound server
 * for everything except the first activate. The JSON door (licence-api.php) stays for apps written fresh.
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

header('Cache-Control: no-store');
$out = static function (int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(405, ['ok' => false, 'error' => 'POST only.']);
}

// ponytail: same crude per-IP brake as licence-api.php (60 a minute here - an install makes several calls per update).
$ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? 'x'));
$brake = sys_get_temp_dir() . '/ag-upd-' . md5($ip) . '-' . date('YmdHi');
$n = (int) @file_get_contents($brake) + 1;
@file_put_contents($brake, (string) $n);
if ($n > 60) {
    $out(429, ['ok' => false, 'error' => 'Too many requests - try again in a minute.']);
}

$path = (string) ($_SERVER['PATH_INFO'] ?? parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
$op = ($p = strpos($path, '/update/')) !== false ? trim(substr($path, $p + 8), '/') : trim($path, '/');
$in = array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $_POST);
$plugin = trim($in['plugin'] ?? '') !== '' && AmetecsPlugins::ready() ? AmetecsPlugins::slug($in['plugin']) : '';
$key = $in['key'] ?? '';
$fp = mb_substr($in['fingerprint'] ?? '', 0, 190);
$lic = AmetecsLicences::byKey($key);
$neutral = 'Licence not recognised. Check the key or contact Ametecs India.';
if ($lic === null || (string) $lic['deployment'] !== 'onprem') {
    $out(422, ['ok' => false, 'error' => $neutral, 'reason' => 'unknown_key', 'update_available' => false]);
}
$app = (string) $lic['app'];
$info = ['server_name' => $in['server_name'] ?? '', 'version' => $in['current_version'] ?? ''];
$r = AmetecsLicences::serverCall($op === 'activate' ? 'activate' : 'check', $app, $key, $fp, $info);
$lic = AmetecsLicences::byKey($key) ?? $lic;   // re-read: activate may just have bound it
$expires = ($lic['valid_until'] ?? '') ?: null;
$seats = (int) $lic['seats'] > 0 ? (int) $lic['seats'] : null;

if ($op === 'activate') {
    $r['ok'] || $out(422, ['ok' => false, 'error' => $r['message']]);
    $cert = ['edition' => 'full', 'amc_expires_on' => $expires, 'expiry_mode' => 'renew', 'seats' => $seats, 'fingerprint' => $fp, 'issued' => date('Y-m-d H:i:s')];
    $cert['sig'] = hash_hmac('sha256', (string) json_encode($cert), strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $key)));
    $out(200, ['ok' => true, 'cert' => $cert, 'company' => AmetecsAccess::tenantName((string) $lic['tenant_id'])]);
}

if ($op === 'heartbeat') {
    // The SmartPRS client stops itself on suspended / revoked / server_mismatch; expiry it works out from amc_expires_on.
    $reason = ['wrong_server' => 'server_mismatch', 'suspended' => 'licence_suspended', 'off' => 'licence_revoked', 'unknown' => 'unknown_key'][$r['status']] ?? null;
    if ($reason !== null) {
        $out(200, ['ok' => false, 'reason' => $reason, 'status' => $r['status']]);
    }
    $stats = json_decode($in['stats'] ?? '', true);   // the dashboard figures, sent by installs that have them (Phase 6)
    is_array($stats) && AmetecsShell::saveStats($lic, $stats);
    if ($r['status'] === 'expired' && ($expires === null || $expires >= date('Y-m-d'))) {
        $expires = date('Y-m-d', strtotime('-1 day'));   // marked expired by hand: make the client see a past date
    }
    $out(200, ['ok' => true, 'status' => $r['status'] === 'on' ? 'active' : $r['status'], 'amc_expires_on' => $expires,
        'amc_active' => $expires === null || $expires >= date('Y-m-d'), 'expiry_mode' => 'renew', 'seats' => $seats]);
}

// check / download / report: an active licence on its own bound server only.
if (!$r['ok'] || !AmetecsReleases::ready()) {
    $msg = $r['ok'] ? 'Updates are not available yet on this server.' : $r['message'];
    $out(422, ['ok' => false, 'update_available' => false, 'reason' => 'licence_' . $r['status'], 'message' => $msg, 'error' => $msg]);
}
AmetecsLicences::$actor = 'server ' . ((string) ($lic['server_name'] ?? '') ?: 'on-premise');
$current = mb_substr($in['current_version'] ?? (string) ($lic['app_version'] ?? ''), 0, 39);

if ($op === 'check') {
    $out(200, AmetecsReleases::apiCheck($lic, $current) + ['amc_expires_on' => $expires, 'expiry_mode' => 'renew', 'amc_active' => true]);
}
if ($op === 'report') {
    // 'download' is SmartPRS' "I fetched it" ping - nothing to record until it says applied or failed.
    ($in['action'] ?? '') === 'download' || AmetecsReleases::apiReport($lic, mb_substr($in['version'] ?? '', 0, 39), $in['action'] ?? '', $in['detail'] ?? '', $current, $plugin);
    $out(200, ['ok' => true]);
}
if ($op === 'download') {
    $d = AmetecsReleases::apiDownload($lic, $in['version'] ?? '', $current, $plugin);
    $d['ok'] || $out(403, ['ok' => false, 'error' => $d['why']]);
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($d['path']));
    header('Content-Disposition: attachment; filename="' . basename($d['path']) . '"');
    header('X-Package-SHA256: ' . $d['release']['sha256']);
    readfile($d['path']);
    exit;
}
$out(404, ['ok' => false, 'error' => 'No such licence operation.']);
