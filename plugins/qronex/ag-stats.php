<?php
/**
 * QRONEX'S FIGURES FOR THE AMETECSGLOBAL DASHBOARD (19 Nov 2026). Contract: plugins/smartprs3/ag-stats.php.
 * Reads QRONEX's own database (plugins/qronex/.env); the client's company is the one linked to it (tenants.ag_tenant).
 * Employee filters map through hrms_ref "ag-<employee id>". Also pulls new punches into Ametecs Global attendance.
 */
declare(strict_types=1);

return static function (\PDO $agPdo, string $tenant, array $f = []): array {
    preg_match_all('/^([A-Z_]+)=("?)([^"#\r\n]*)\2/m', (string) @file_get_contents(__DIR__ . '/.env'), $m);
    $e = array_combine($m[1], array_map('trim', $m[3]));
    if (($e['DB_DATABASE'] ?? '') === '') {
        return ['tiles' => []];
    }
    $pdo = new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . $e['DB_DATABASE'] . ';charset=utf8mb4',
        (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $q = $pdo->prepare('SELECT id FROM tenants WHERE ag_tenant = ?');
    $q->execute([$tenant]);
    $tid = $q->fetchColumn();
    if ($tid === false) {
        return ['tiles' => [], 'list' => ['title' => 'QRONEX', 'rows' => [['text' => 'Not opened yet', 'sub' => 'Open QRONEX once from My applications to set it up.']]]];
    }
    // QRONEX keeps times in UTC; the dashboard's days are Indian days
    $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) === 1 ? min((string) $f['to'], $today) : $today;
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) === 1 ? min((string) $f['from'], $to) : $to;
    $utc = static fn (string $d): string => (new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $who = '';
    $args = [$tid];
    if (is_array($f['ids'] ?? null)) {   // the dashboard's employee filters
        $refs = array_map(static fn ($id): string => 'ag-' . (int) $id, $f['ids']) ?: ['-'];
        $who = ' AND employee_id IN (SELECT id FROM employees WHERE tenant_id = ? AND hrms_ref IN (' . implode(',', array_fill(0, count($refs), '?')) . '))';
        $args = [$tid, $tid, ...$refs];
    }
    $one = static function (string $sql, array $more = []) use ($pdo, $args): array {
        $s = $pdo->prepare($sql);
        $s->execute([...$args, ...$more]);
        return $s->fetch(\PDO::FETCH_NUM) ?: [0, 0];
    };
    $range = [$utc($from), $utc(date('Y-m-d', strtotime($to . ' +1 day')))];
    [$punches, $present] = $one("SELECT COUNT(*), COUNT(DISTINCT CASE WHEN type = 'IN' THEN employee_id END) FROM attendance_events WHERE tenant_id = ?{$who} AND recorded_at >= ? AND recorded_at < ?", $range);
    [$rejected] = $one('SELECT COUNT(*) FROM attendance_rejections WHERE tenant_id = ?' . $who . ' AND created_at >= ? AND created_at < ?', $range);
    [$staff] = $one("SELECT COUNT(*) FROM employees WHERE tenant_id = ? AND status = 'active'" . str_replace('employee_id', 'id', $who));
    [$pending] = $one("SELECT COUNT(*) FROM devices WHERE tenant_id = ?{$who} AND status = 'pending'");
    [$phones] = $one("SELECT COUNT(*) FROM devices WHERE tenant_id = ?{$who} AND status = 'approved'");
    // a dashboard visit also brings new punches into Ametecs Global attendance (QRONEX pings after each punch as well)
    try {
        is_file($pull = dirname(__DIR__, 2) . '/core/attendance/qronex-pull.php') && (require $pull)($tenant);
    } catch (\Throwable) {
        // the figures still show; the next punch or visit pulls again
    }
    $rate = $staff > 0 ? round($present * 100 / $staff) : 0;
    return ['tiles' => [
        ['key' => 'qx-users', 'label' => 'QRONEX employees', 'value' => (string) $staff, 'sub' => $phones . ' phones approved', 'tone' => 'blue'],
        ['key' => 'qx-present', 'label' => 'Present (QR)', 'value' => (string) $present, 'sub' => $rate . '% marked IN by QR', 'tone' => $present > 0 ? 'green' : ''],
        ['key' => 'qx-punches', 'label' => 'QR punches', 'value' => (string) $punches, 'sub' => 'IN and OUT, verified', 'tone' => 'blue'],
        ['key' => 'qx-rejected', 'label' => 'Rejected scans', 'value' => (string) $rejected, 'sub' => 'outside geofence, expired code…', 'tone' => $rejected > 0 ? 'amber' : 'green'],
        ['key' => 'qx-phones', 'label' => 'Phones to approve', 'value' => (string) $pending, 'sub' => 'waiting in QRONEX', 'tone' => $pending > 0 ? 'amber' : 'green'],
    ]];
};

