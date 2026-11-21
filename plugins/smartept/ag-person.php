<?php
/**
 * ONE EMPLOYEE IN SMARTEPT, FOR THE AMETECSGLOBAL EMPLOYEE 360 PAGE (19 Nov 2026). Contract: return a closure
 * fn(string $tenant, int $employeeId, string $from, string $to): ['tiles' => [[label, value, sub]], 'rows' => [[when, text, sub]]]
 * The employee is found by employees.smartprs_employee_id "ag-<Ametecs Global employee id>".
 */
declare(strict_types=1);

return static function (string $tenant, int $id, string $from, string $to): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4', (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $q = $pdo->prepare("SELECT e.id FROM employees e JOIN companies c ON c.id = e.company_id WHERE c.external_tenant_id = ? AND e.smartprs_employee_id = ?");
    $q->execute(['ag-' . $tenant, 'ag-' . $id]);
    $eid = $q->fetchColumn();
    if ($eid === false) {
        return ['tiles' => [], 'rows' => [], 'note' => 'Not in SmartEPT yet - it is copied the next time an administrator opens SmartEPT.'];
    }
    $s = $pdo->prepare('SELECT COUNT(*) d, SUM(active_seconds) a, SUM(idle_seconds) i, AVG(productivity_score) p, SUM(violation_count) v, SUM(screenshot_count) sc FROM employee_daily_summaries WHERE employee_id = ? AND work_date BETWEEN ? AND ?');
    $s->execute([$eid, $from, $to]);
    $r = $s->fetch() ?: [];
    $hm = static fn ($sec) => sprintf('%dh %02dm', intdiv((int) $sec, 3600), intdiv((int) $sec % 3600, 60));
    $d = $pdo->prepare("SELECT computer_name, current_status, last_heartbeat_at FROM employee_devices WHERE employee_id = ? AND unbound_at IS NULL ORDER BY last_heartbeat_at DESC");
    $d->execute([$eid]);
    $pcs = $d->fetchAll();
    $rows = [];
    $l = $pdo->prepare('SELECT work_date, first_login_at, last_logout_at, active_seconds, productivity_score FROM employee_daily_summaries WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date DESC LIMIT 14');
    $l->execute([$eid, $from, $to]);
    foreach ($l->fetchAll() as $x) {
        $rows[] = [$x['work_date'], 'PC ' . ($x['first_login_at'] ? substr((string) $x['first_login_at'], 11, 5) : '—') . ' – ' . ($x['last_logout_at'] ? substr((string) $x['last_logout_at'], 11, 5) : '—'), 'active ' . $hm($x['active_seconds']) . ' · productivity ' . round((float) $x['productivity_score']) . '%'];
    }
    return ['tiles' => [
        ['Productivity', $r['p'] !== null ? round((float) $r['p']) . '%' : '—', 'average'],
        ['Active time', $hm($r['a'] ?? 0), 'idle ' . $hm($r['i'] ?? 0)],
        ['Violations', (string) (int) ($r['v'] ?? 0), (int) ($r['sc'] ?? 0) . ' screenshots'],
        ['PCs', (string) count($pcs), $pcs !== [] ? $pcs[0]['computer_name'] . ' · ' . strtolower((string) $pcs[0]['current_status']) : 'agent not installed'],
    ], 'rows' => $rows];
};
