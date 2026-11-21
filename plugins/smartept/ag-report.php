<?php
/**
 * SMARTEPT REPORTS FOR THE AMETECSGLOBAL REPORTS PAGE (19 Nov 2026). Contract (same for every app):
 *   ['reports' => [key => title], 'run' => fn(string $tenant, string $key, string $from, string $to): ['cols' => [...], 'rows' => [[agEmployeeId|null, ...cells]]]]
 * Ametecs Global adds the employee columns, the filters, the team-lead scope and the download. Read-only; this client only.
 */
declare(strict_types=1);

return ['reports' => ['daily' => 'Productivity by day', 'summary' => 'Productivity summary by employee'],
'run' => static function (string $tenant, string $key, string $from, string $to): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4', (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $hm = static fn ($s) => sprintf('%d:%02d', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60));
    $base = "FROM employee_daily_summaries s JOIN employees e ON e.id = s.employee_id JOIN companies c ON c.id = e.company_id
        WHERE c.external_tenant_id = ? AND e.smartprs_employee_id LIKE 'ag-%' AND s.work_date BETWEEN ? AND ?";
    $ag = static fn ($r) => (int) substr((string) $r['ref'], 3);
    if ($key === 'summary') {
        $q = $pdo->prepare("SELECT e.smartprs_employee_id ref, COUNT(*) d, SUM(s.active_seconds) a, SUM(s.idle_seconds) i, AVG(s.productivity_score) p, SUM(s.violation_count) v, SUM(s.screenshot_count) sc $base GROUP BY e.smartprs_employee_id");
        $q->execute(['ag-' . $tenant, $from, $to]);
        return ['cols' => ['Days on PC', 'Active (h:mm)', 'Idle (h:mm)', 'Productivity %', 'Violations', 'Screenshots'],
            'rows' => array_map(static fn ($r) => [$ag($r), (int) $r['d'], $hm($r['a']), $hm($r['i']), $r['p'] === null ? '' : round((float) $r['p']), (int) $r['v'], (int) $r['sc']], $q->fetchAll())];
    }
    $q = $pdo->prepare("SELECT e.smartprs_employee_id ref, s.work_date, s.first_login_at, s.last_logout_at, s.active_seconds, s.idle_seconds, s.productivity_score, s.violation_count, s.screenshot_count $base ORDER BY s.work_date DESC");
    $q->execute(['ag-' . $tenant, $from, $to]);
    return ['cols' => ['Date', 'First login', 'Last logout', 'Active (h:mm)', 'Idle (h:mm)', 'Productivity %', 'Violations', 'Screenshots'],
        'rows' => array_map(static fn ($r) => [$ag($r), (string) $r['work_date'], substr((string) $r['first_login_at'], 11, 5), substr((string) $r['last_logout_at'], 11, 5), $hm($r['active_seconds']), $hm($r['idle_seconds']),
            $r['productivity_score'] === null ? '' : round((float) $r['productivity_score']), (int) $r['violation_count'], (int) $r['screenshot_count']], $q->fetchAll())];
}];
