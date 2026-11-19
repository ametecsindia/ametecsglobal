<?php
/** QRONEX REPORTS FOR THE AMETECSGLOBAL REPORTS PAGE (19 Nov 2026). Same contract as plugins/smartept/ag-report.php; hrms_ref "ag-<id>". Times in India time. */
declare(strict_types=1);

return ['reports' => ['punches' => 'QR punches (every scan)', 'summary' => 'QR attendance summary by employee'],
'run' => static function (string $tenant, string $key, string $from, string $to): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4', (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $tz = static fn (string $d, string $a, string $b) => (new \DateTimeImmutable($d, new \DateTimeZone($a)))->setTimezone(new \DateTimeZone($b))->format('Y-m-d H:i:s');
    $range = [$tz($from . ' 00:00:00', 'Asia/Kolkata', 'UTC'), $tz(date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00', 'Asia/Kolkata', 'UTC')];
    $ag = static fn ($r) => str_starts_with((string) $r['ref'], 'ag-') ? (int) substr((string) $r['ref'], 3) : null;
    $base = "FROM attendance_events a JOIN employees em ON em.id = a.employee_id JOIN tenants t ON t.id = em.tenant_id LEFT JOIN offices o ON o.id = a.office_id LEFT JOIN gates g ON g.id = a.gate_id
        WHERE t.ag_tenant = ? AND a.recorded_at >= ? AND a.recorded_at < ?";
    if ($key === 'summary') {
        $q = $pdo->prepare("SELECT em.hrms_ref ref, COUNT(*) n, MIN(a.recorded_at) f, MAX(a.recorded_at) l, COUNT(DISTINCT DATE(CONVERT_TZ(a.recorded_at, '+00:00', '+05:30'))) d $base GROUP BY em.hrms_ref");
        $q->execute([$tenant, ...$range]);
        return ['cols' => ['Days with a scan', 'Scans', 'First scan', 'Last scan'],
            'rows' => array_map(static fn ($r) => [$ag($r), (int) $r['d'], (int) $r['n'], substr($tz((string) $r['f'], 'UTC', 'Asia/Kolkata'), 0, 16), substr($tz((string) $r['l'], 'UTC', 'Asia/Kolkata'), 0, 16)], $q->fetchAll())];
    }
    $q = $pdo->prepare("SELECT em.hrms_ref ref, a.recorded_at, a.type, o.name office, g.name gate $base ORDER BY a.recorded_at DESC LIMIT 20000");
    $q->execute([$tenant, ...$range]);
    return ['cols' => ['When', 'Type', 'Office', 'Gate'],
        'rows' => array_map(static fn ($r) => [$ag($r), substr($tz((string) $r['recorded_at'], 'UTC', 'Asia/Kolkata'), 0, 16), ucfirst((string) $r['type']), (string) $r['office'], (string) $r['gate']], $q->fetchAll())];
}];
