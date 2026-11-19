<?php
/** ONE EMPLOYEE IN QRONEX, FOR THE AMETECSGLOBAL EMPLOYEE 360 PAGE (19 Nov 2026). Same contract as plugins/smartept/ag-person.php; hrms_ref "ag-<id>". */
declare(strict_types=1);

return static function (string $tenant, int $id, string $from, string $to): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4', (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $q = $pdo->prepare('SELECT em.id, em.status, o.name office FROM employees em JOIN tenants t ON t.id = em.tenant_id LEFT JOIN offices o ON o.id = em.office_id WHERE t.ag_tenant = ? AND em.hrms_ref = ?');
    $q->execute([$tenant, 'ag-' . $id]);
    $em = $q->fetch();
    if ($em === false) {
        return ['tiles' => [], 'rows' => [], 'note' => 'Not in QRONEX yet - copied the next time an administrator opens QRONEX.'];
    }
    $utc = static fn (string $d): string => (new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $range = [$utc($from), $utc(date('Y-m-d', strtotime($to . ' +1 day')))];
    $s = $pdo->prepare("SELECT COUNT(*), COUNT(DISTINCT DATE(recorded_at)) FROM attendance_events WHERE employee_id = ? AND recorded_at >= ? AND recorded_at < ?");
    $s->execute([$em['id'], ...$range]);
    [$n, $days] = $s->fetch(\PDO::FETCH_NUM);
    $d = $pdo->prepare("SELECT status, model FROM devices WHERE employee_id = ? ORDER BY id DESC LIMIT 1");
    $d->execute([$em['id']]);
    $dev = $d->fetch() ?: null;
    $l = $pdo->prepare('SELECT a.recorded_at, a.type, g.name gate, o.name office FROM attendance_events a LEFT JOIN gates g ON g.id = a.gate_id LEFT JOIN offices o ON o.id = a.office_id WHERE a.employee_id = ? AND a.recorded_at >= ? AND a.recorded_at < ? ORDER BY a.recorded_at DESC LIMIT 12');
    $l->execute([$em['id'], ...$range]);
    $rows = array_map(static fn ($x) => [(new \DateTimeImmutable((string) $x['recorded_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i'), 'QR ' . $x['type'], trim(($x['office'] ?? '') . ' · ' . ($x['gate'] ?? ''), ' ·')], $l->fetchAll());
    return ['tiles' => [
        ['QR punches', (string) $n, $days . ' days with a punch'],
        ['Office', (string) ($em['office'] ?: 'Not set'), ucfirst((string) $em['status'])],
        ['Phone', $dev ? ucfirst((string) $dev['status']) : 'None', $dev ? (string) ($dev['model'] ?? '') : 'not activated'],
    ], 'rows' => $rows];
};
