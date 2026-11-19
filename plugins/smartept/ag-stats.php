<?php
/**
 * SMARTEPT'S FIGURES FOR THE AMETECSGLOBAL DASHBOARD (19 Nov 2026). Contract: plugins/smartprs3/ag-stats.php.
 * Reads SmartEPT's own database (plugins/smartept/.env); the client's company is companies.external_tenant_id "ag-<client>".
 * Employee filters map through employees.smartprs_employee_id "ag-<employee id>".
 */
declare(strict_types=1);

return static function (\PDO $agPdo, string $tenant, array $f = []): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    if (($e['DB_DATABASE'] ?? '') === '' || ($e['DB_CONNECTION'] ?? '') !== 'mysql') {
        return ['tiles' => []];
    }
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . $e['DB_DATABASE'] . ';charset=utf8mb4',
        (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $q = $pdo->prepare('SELECT id FROM companies WHERE external_tenant_id = ?');
    $q->execute(['ag-' . $tenant]);
    $cid = $q->fetchColumn();
    if ($cid === false) {
        return ['tiles' => [], 'list' => ['title' => 'SmartEPT', 'rows' => [['text' => 'Not opened yet', 'sub' => 'Open SmartEPT once from My applications to set it up.']]]];
    }
    $today = date('Y-m-d');   // SmartEPT keeps Indian time (APP_TIMEZONE Asia/Kolkata)
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) === 1 ? min((string) $f['to'], $today) : $today;
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) === 1 ? min((string) $f['from'], $to) : $to;
    $who = '';
    $args = [$cid];
    if (is_array($f['ids'] ?? null)) {   // the dashboard's employee filters
        $refs = array_map(static fn ($id): string => 'ag-' . (int) $id, $f['ids']) ?: ['-'];
        $who = ' AND employee_id IN (SELECT id FROM employees WHERE company_id = ? AND smartprs_employee_id IN (' . implode(',', array_fill(0, count($refs), '?')) . '))';
        $args = [$cid, $cid, ...$refs];
    }
    $one = static function (string $sql, array $more = []) use ($pdo, $args): array {
        $s = $pdo->prepare($sql);
        $s->execute([...$args, ...$more]);
        return $s->fetch(\PDO::FETCH_NUM) ?: [0, 0];
    };
    [$staff] = $one("SELECT COUNT(*) FROM employees WHERE company_id = ? AND employment_status = 'ACTIVE' AND deleted_at IS NULL" . str_replace('employee_id', 'id', $who));
    [$pcs, $online] = $one("SELECT COUNT(*), SUM(current_status = 'ONLINE' AND last_heartbeat_at > NOW() - INTERVAL 10 MINUTE) FROM employee_devices WHERE company_id = ?{$who} AND unbound_at IS NULL");
    [$present] = $one("SELECT COUNT(DISTINCT employee_id) FROM employee_attendance_logs WHERE company_id = ?{$who} AND check_in_at IS NOT NULL AND work_date BETWEEN ? AND ?", [$from, $to]);
    [$viol] = $one("SELECT COUNT(*) FROM employee_compliance_events WHERE company_id = ?{$who} AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY", [$from, $to]);
    return ['tiles' => [
        ['key' => 'ept-users', 'label' => 'SmartEPT employees', 'value' => (string) $staff, 'sub' => (int) $pcs . ' PCs with the agent', 'tone' => 'blue'],
        ['key' => 'ept-online', 'label' => 'PCs online now', 'value' => (string) (int) $online, 'sub' => 'agent heartbeat in 10 min', 'tone' => (int) $online > 0 ? 'green' : ''],
        ['key' => 'ept-present', 'label' => 'Logged in (PC)', 'value' => (string) $present, 'sub' => 'first log-in recorded', 'tone' => 'blue'],
        ['key' => 'ept-viol', 'label' => 'Policy violations', 'value' => (string) $viol, 'sub' => 'websites, apps, USB…', 'tone' => $viol > 0 ? 'amber' : 'green'],
    ]];
};
