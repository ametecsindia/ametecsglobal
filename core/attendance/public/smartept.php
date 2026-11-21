<?php
/**
 * SMARTEPT -> AMETECSGLOBAL ATTENDANCE (owner, 19 Nov 2026). SmartEPT inside Ametecs Global (AgBridge::punch) posts every
 * agent log-in / log-out here, signed with the shared key core/data/ag-sso.key. Same mapping as SmartEPT's webhook
 * (SmartEpt::punchesFor) and the same one door (PunchIngest, source "smartept"): never twice, unknown codes held.
 */
declare(strict_types=1);

require (dirname(__DIR__, 3) . '/plugins/smartprs3/public') . '/../surface/partials/endpoint-boot.php';

$raw = (string) file_get_contents('php://input', false, null, 0, 65536);
$key = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/core/data/ag-sso.key'));
if (strlen($key) < 32 || !hash_equals(hash_hmac('sha256', $raw, $key), (string) ($_SERVER['HTTP_X_AG_SIGNATURE'] ?? ''))) {
    json_out(401, ['ok' => false, 'error' => 'signature']);
}
$in = json_decode($raw, true);
$tid = preg_replace('/[^0-9]/', '', (string) ($in['tenant'] ?? ''));
if (!is_array($in) || $tid === '' || !in_array($in['event'] ?? '', SmartPRS\Surface\SmartEpt::EVENTS, true)) {
    json_out(422, ['ok' => false, 'error' => 'payload']);
}
$t = SmartPRS\Surface\SmartEpt::punchesFor((string) $in['event'], (array) ($in['payload'] ?? []));
$out = $t['punches'] === [] ? ['batch' => ['received' => 0]]
    : (new SmartPRS\Surface\PunchIngest($platform->forTenant($tid), $pdo, $tid))->ingest($t['punches'], SmartPRS\Surface\SmartEpt::SOURCE);
json_out(200, ['ok' => true] + $out['batch']);
