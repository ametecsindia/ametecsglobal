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
    // THE FULL DAY, NOT FOUR NUMBERS (owner, 21 Nov 2026: "date filter, break, idle, lunch, all history"): time split,
    // breaks by type, one row per day and every session / break / idle period in the range (newest first).
    $s2 = $pdo->prepare('SELECT SUM(present_seconds) pr, SUM(away_seconds) aw, SUM(break_seconds) br, SUM(productive_app_seconds) pa, SUM(non_productive_seconds) np FROM employee_daily_summaries WHERE employee_id = ? AND work_date BETWEEN ? AND ?');
    $s2->execute([$eid, $from, $to]);
    $r2 = $s2->fetch() ?: [];
    $end = $to . ' 23:59:59';
    $names = ['LUNCH' => 'Lunch', 'TEA' => 'Tea', 'BIO' => 'Bio break', 'MEETING' => 'Meeting', 'TRAINING' => 'Training', 'PRAYER' => 'Prayer', 'CUSTOM' => 'Other'];
    $bt = $pdo->prepare('SELECT break_type t, COUNT(*) n, SUM(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, start_at, COALESCE(end_at, NOW())))) s FROM employee_break_logs WHERE employee_id = ? AND start_at BETWEEN ? AND ? GROUP BY break_type');
    $bt->execute([$eid, $from, $end]);
    $breaks = array_map(static fn (array $x): array => [$names[$x['t']] ?? ucfirst(strtolower((string) $x['t'])), (int) $x['s'], (int) $x['n']], $bt->fetchAll());
    $days = [];
    $dq = $pdo->prepare('SELECT work_date, first_login_at, last_logout_at, present_seconds, active_seconds, idle_seconds, away_seconds, break_seconds, productivity_score, late_minutes, early_logout_minutes FROM employee_daily_summaries WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date DESC LIMIT 62');
    $dq->execute([$eid, $from, $to]);
    foreach ($dq->fetchAll() as $x) {
        $days[] = ['date' => $x['work_date'], 'in' => $x['first_login_at'] ? substr((string) $x['first_login_at'], 11, 5) : null, 'out' => $x['last_logout_at'] ? substr((string) $x['last_logout_at'], 11, 5) : null,
            'present' => (int) $x['present_seconds'], 'active' => (int) $x['active_seconds'], 'idle' => (int) $x['idle_seconds'], 'away' => (int) $x['away_seconds'], 'break' => (int) $x['break_seconds'],
            'score' => round((float) $x['productivity_score']), 'late' => (int) $x['late_minutes'], 'early' => (int) $x['early_logout_minutes']];
    }
    $ev = [];
    $q1 = $pdo->prepare('SELECT login_at a, logout_at b, duration_seconds s, logout_reason r FROM employee_login_sessions WHERE employee_id = ? AND login_at BETWEEN ? AND ? ORDER BY login_at DESC LIMIT 150');
    $q1->execute([$eid, $from, $end]);
    foreach ($q1->fetchAll() as $x) {
        $ev[] = ['kind' => 'session', 'label' => 'PC session', 'from' => $x['a'], 'to' => $x['b'], 'sec' => (int) ($x['s'] ?? 0), 'note' => $x['r'] ? 'ended: ' . strtolower((string) $x['r']) : ($x['b'] ? '' : 'still on')];
    }
    $q2 = $pdo->prepare('SELECT break_type t, source src, start_at a, end_at b, duration_seconds s, approval_status ap FROM employee_break_logs WHERE employee_id = ? AND start_at BETWEEN ? AND ? ORDER BY start_at DESC LIMIT 150');
    $q2->execute([$eid, $from, $end]);
    foreach ($q2->fetchAll() as $x) {
        $ev[] = ['kind' => $x['t'] === 'LUNCH' ? 'lunch' : 'break', 'label' => $x['t'] === 'LUNCH' ? 'Lunch' : ($names[$x['t']] ?? 'Other') . ' break', 'from' => $x['a'], 'to' => $x['b'], 'sec' => (int) ($x['s'] ?? 0),
            'note' => trim(($x['src'] === 'AUTO_IDLE' ? 'auto from idle' : '') . (in_array($x['ap'], ['PENDING', 'REJECTED'], true) ? ' · ' . strtolower((string) $x['ap']) : ''), ' ·')];
    }
    $q3 = $pdo->prepare('SELECT idle_start a, idle_end b, duration_seconds s, reason r FROM employee_idle_logs WHERE employee_id = ? AND idle_start BETWEEN ? AND ? ORDER BY idle_start DESC LIMIT 150');
    $q3->execute([$eid, $from, $end]);
    foreach ($q3->fetchAll() as $x) {
        $ev[] = ['kind' => 'idle', 'label' => ['NO_INPUT' => 'Idle', 'LOCKED' => 'PC locked', 'AWAY' => 'Away'][$x['r']] ?? 'Idle', 'from' => $x['a'], 'to' => $x['b'], 'sec' => (int) $x['s'], 'note' => ''];
    }
    usort($ev, static fn (array $p, array $q): int => strcmp((string) $q['from'], (string) $p['from']));
    // ponytail: newest 150 of each kind; page it if one person's range ever needs more
    $extra = ['split' => [['Active', (int) ($r['a'] ?? 0)], ['Idle', (int) ($r['i'] ?? 0)], ['Break', (int) ($r2['br'] ?? 0)], ['Away', (int) ($r2['aw'] ?? 0)]],
        'present' => (int) ($r2['pr'] ?? 0), 'productive' => (int) ($r2['pa'] ?? 0), 'unproductive' => (int) ($r2['np'] ?? 0),
        'breaks' => $breaks, 'days' => $days, 'events' => $ev];
    return $extra + ['tiles' => [
        ['Productivity', $r['p'] !== null ? round((float) $r['p']) . '%' : '—', 'average'],
        ['Active time', $hm($r['a'] ?? 0), 'idle ' . $hm($r['i'] ?? 0)],
        ['Violations', (string) (int) ($r['v'] ?? 0), (int) ($r['sc'] ?? 0) . ' screenshots'],
        ['PCs', (string) count($pcs), $pcs !== [] ? $pcs[0]['computer_name'] . ' · ' . strtolower((string) $pcs[0]['current_status']) : 'agent not installed'],
    ], 'rows' => $rows];
};
