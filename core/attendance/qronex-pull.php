<?php
/**
 * QRONEX PUNCHES INTO AMETECSGLOBAL ATTENDANCE (owner, 19 Nov 2026: "yes, into SmartPRS 3").
 * Reads new verified punches from QRONEX's database (plugins/qronex/.env) for one client and writes them through
 * PunchIngest (source "qronex", external_id "qx:<ref>"), so payroll, late marks and reports treat them like a device punch.
 * Never twice: PunchIngest claims each external_id; the cursor below only saves work. Times: QRONEX keeps UTC,
 * attendance is in the office's own time zone (Asia/Kolkata by default).
 * Returns a closure: fn(string $agTenant): array{accepted:int, ...}. Called by QRONEX after each punch
 * (core/attendance/public/qronex.php) and by the dashboard (plugins/qronex/ag-stats.php).
 */
declare(strict_types=1);

return static function (string $agTenant): array {
    $ag = dirname(__DIR__, 2);
    $agTenant = preg_replace('/[^0-9]/', '', $agTenant);
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents($ag . '/plugins/qronex/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    if ($agTenant === '' || ($e['DB_DATABASE'] ?? '') === '') {
        return ['received' => 0];
    }
    $qx = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . $e['DB_DATABASE'] . ';charset=utf8mb4',
        (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $q = $qx->prepare('SELECT id FROM tenants WHERE ag_tenant = ?');
    $q->execute([$agTenant]);
    $qt = $q->fetchColumn();
    if ($qt === false) {
        return ['received' => 0];
    }
    $curFile = __DIR__ . '/../data/qronex-cursor.json';
    $cur = json_decode((string) @file_get_contents($curFile), true) ?: [];
    // ponytail: 500 a call from the last id seen; the next ping or dashboard visit takes the rest
    $q = $qx->prepare("SELECT a.id, a.ref, a.type, a.recorded_at, a.mode, em.hrms_ref, em.employee_code, o.timezone, g.code AS gate
        FROM attendance_events a JOIN employees em ON em.id = a.employee_id LEFT JOIN offices o ON o.id = a.office_id LEFT JOIN gates g ON g.id = a.gate_id
        WHERE a.tenant_id = ? AND a.id > ? ORDER BY a.id LIMIT 500");
    $q->execute([$qt, (int) ($cur[$agTenant] ?? 0)]);
    $rows = $q->fetchAll();
    if ($rows === []) {
        return ['received' => 0];
    }
    $pdo = \SmartPRS\Surface\Database::connect($ag . '/plugins/smartprs3/.env');
    if ($pdo === null) {
        return ['received' => 0, 'error' => 'database'];
    }
    // the employee's current AmetecsGlobal code, found by id (hrms_ref "ag-<id>"); QRONEX's own employees are skipped
    $ids = array_values(array_unique(array_filter(array_map(static fn ($r) => str_starts_with((string) $r['hrms_ref'], 'ag-') ? (int) substr((string) $r['hrms_ref'], 3) : 0, $rows))));
    $codes = [];
    if ($ids !== []) {
        $s = $pdo->prepare('SELECT id, emp_code FROM employees WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $s->execute([$agTenant, ...$ids]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $codes['ag-' . $r['id']] = (string) $r['emp_code'];
        }
    }
    $punches = [];
    foreach ($rows as $r) {
        $code = $codes[(string) $r['hrms_ref']] ?? '';
        if ($code === '') {
            continue;
        }
        $tz = in_array((string) $r['timezone'], timezone_identifiers_list(), true) ? (string) $r['timezone'] : 'Asia/Kolkata';
        $punches[] = ['punch_at' => (new \DateTimeImmutable((string) $r['recorded_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i:s'),
            'direction' => strtolower((string) $r['type']), 'employee_code' => $code, 'device_user_id' => $code, 'device_sn' => 'QRONEX' . ($r['gate'] ? '-' . $r['gate'] : ''),
            'external_id' => 'qx:' . $r['ref'], 'verify_mode' => 'QR'];
    }
    $res = (new \SmartPRS\Surface\PunchIngest((new \SmartPRS\Surface\SqlStore($pdo))->forTenant($agTenant), $pdo, $agTenant))->ingest($punches, 'qronex');
    $cur[$agTenant] = (int) end($rows)['id'];
    @file_put_contents($curFile, json_encode($cur), LOCK_EX);
    return $res['batch'];
};
