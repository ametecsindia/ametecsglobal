<?php
/** ROVEXY REPORTS FOR THE AMETECSGLOBAL REPORTS PAGE (19 Nov 2026). Same contract as plugins/smartept/ag-report.php; login code "ag-<id>". Numbers are masked. */
declare(strict_types=1);

return ['reports' => ['calls' => 'Calls (every call)', 'summary' => 'Calls summary by employee', 'followups' => 'Open follow-ups'],
'run' => static function (string $tenant, string $key, string $from, string $to): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4', (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $co = "JOIN companies c ON c.id = u.company_id WHERE JSON_UNQUOTE(JSON_EXTRACT(c.settings, '$.ag_tenant')) = ?";
    $ag = static fn ($r) => str_starts_with((string) $r['code'], 'ag-') ? (int) substr((string) $r['code'], 3) : null;
    $hm = static fn ($s) => sprintf('%d:%02d:%02d', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60), (int) $s % 60);
    $mask = static fn ($n) => substr((string) $n, 0, 4) . '••••' . substr((string) $n, -2);
    if ($key === 'followups') {
        $q = $pdo->prepare("SELECT u.employee_code code, f.* FROM follow_ups f JOIN users u ON u.id = f.user_id $co AND f.status = 'open' ORDER BY f.id DESC");
        $q->execute([$tenant]);
        return ['cols' => ['Due', 'Status', 'Note'], 'rows' => array_map(static fn ($r) => [$ag($r), (string) ($r['due_at'] ?? $r['follow_up_at'] ?? ''), (string) $r['status'], mb_strimwidth((string) ($r['note'] ?? $r['notes'] ?? ''), 0, 120, '…')], $q->fetchAll())];
    }
    $w = "AND k.is_test = 0 AND k.initiated_at >= ? AND k.initiated_at < ? + INTERVAL 1 DAY";
    if ($key === 'summary') {
        $q = $pdo->prepare("SELECT u.employee_code code, COUNT(*) n, COUNT(k.answered_at) c, COALESCE(SUM(k.talk_seconds), 0) t FROM calls k JOIN users u ON u.id = k.user_id $co $w GROUP BY u.employee_code");
        $q->execute([$tenant, $from, $to]);
        return ['cols' => ['Calls', 'Connected', 'Connect %', 'Talk time', 'Avg talk'],
            'rows' => array_map(static fn ($r) => [$ag($r), (int) $r['n'], (int) $r['c'], $r['n'] ? round(100 * $r['c'] / $r['n']) : 0, $hm($r['t']), $hm($r['c'] ? $r['t'] / $r['c'] : 0)], $q->fetchAll())];
    }
    $q = $pdo->prepare("SELECT u.employee_code code, k.initiated_at, k.destination, k.answered_at, k.talk_seconds, k.disposition_status FROM calls k JOIN users u ON u.id = k.user_id $co $w ORDER BY k.initiated_at DESC LIMIT 20000");
    $q->execute([$tenant, $from, $to]);
    return ['cols' => ['When', 'Number', 'Connected', 'Talk time', 'Outcome'],
        'rows' => array_map(static fn ($r) => [$ag($r), substr((string) $r['initiated_at'], 0, 16), $mask($r['destination']), $r['answered_at'] ? 'Yes' : 'No', $hm($r['talk_seconds']), (string) $r['disposition_status']], $q->fetchAll())];
}];
