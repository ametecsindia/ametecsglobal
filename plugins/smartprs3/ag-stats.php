<?php
/**
 * SMARTPRS 3'S FIGURES FOR THE AMETECSGLOBAL DASHBOARD (owner, 18 Nov 2026 - Phase 6).
 *
 * AmetecsGlobal shows each app's own summary on the client's dashboard, so the administrator does not have to open the
 * app to see the basics. Every app may ship one file like this at plugins/<app>/ag-stats.php: it returns a function
 * (PDO $pdo, string $tenantId): array
 *   ['tiles' => [['label' => 'Present today', 'value' => '42', 'sub' => 'of 50', 'tone' => 'green|amber|red|blue|'], ...],
 *    'list'  => ['title' => '...', 'rows' => [['text' => '...', 'sub' => '...'], ...]]]   (list optional)
 * The cloud dashboard calls it directly; an on-premise install sends the same array with its hourly licence check-in.
 *
 * Today's Present / On leave / Absent / Late / Early exits come from the SAME muster SmartPRS3's own dashboard and
 * Attendance Report use (AttendanceReport::muster), so the numbers here and in the app agree.
 */
declare(strict_types=1);

use SmartPRS\Surface\AttendanceReport;

return static function (\PDO $pdo, string $tenant): array {
    $root = __DIR__;
    $store = (new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant);
    $cust = new \SmartPRS\Surface\Customisation($store);
    $calendar = new \SmartPRS\Surface\Calendar($root . '/config/calendar.json', $cust);
    $attendance = new \SmartPRS\Surface\Attendance($store, new \SmartPRS\Surface\DayRules($root . '/config/attendance-rules.json'), $calendar,
        new \SmartPRS\Surface\EarnRules($root . '/config/attendance-earn-rules.json'), new \SmartPRS\Surface\PenaltyRules($root . '/config/attendance-penalty-rules.json'),
        new \SmartPRS\Surface\LatePolicy($store));
    $policy = new \SmartPRS\Surface\LeavePolicy($root . '/config/leave-policy.json', $cust);
    $leave = new \SmartPRS\Surface\Leave($store, $policy, new \SmartPRS\Surface\LeaveRules($root . '/config/leave-rules.json'),
        new \SmartPRS\Surface\ChargeRules($root . '/config/leave-charge-rules.json'), $calendar,
        new \SmartPRS\Surface\Notifications($store, $root . '/config/notifications.json'));
    $report = new AttendanceReport($store, $attendance, $leave);

    $people = array_values(array_filter($store->all('employees'), static fn (array $e): bool
        => !\SmartPRS\Surface\Modules::isRetired($e) && strtolower(trim((string) ($e['status'] ?? 'active'))) === 'active'));
    $today = date('Y-m-d');
    $d = ['present' => 0, 'leave' => 0, 'absent' => 0, 'late' => 0, 'early' => 0, 'counted' => 0];
    foreach ($people === [] ? [] : $report->muster($people, $today, $today, $calendar->shiftStart(), $calendar->shiftEnd(), $today)['rows'] as $row) {
        $c = $row['cells'][$today] ?? null;
        if ($c === null || $c['status'] === AttendanceReport::NOT_JOINED) {
            continue;
        }
        $st = (string) $c['status'];
        if ($st === AttendanceReport::NOT_YET) {   // shift still under way: is today an off day for them?
            $st = (string) ($attendance->dayWithoutPunches($row['code'], $today, $calendar->shiftStart(), $calendar->shiftEnd())['classification']['status'] ?? '');
        }
        $punched = (int) ($c['punches'] ?? 0) > 0;
        if (in_array($st, ['HOLIDAY', 'WEEKLY_OFF'], true) && !$punched) {
            continue;
        }
        $d['counted']++;
        if (in_array($st, AttendanceReport::LEAVE_STATUSES, true)) {
            $d['leave']++;
        } elseif ($punched) {
            $d['present']++;
            $d['late'] += (int) ($c['late_minutes'] ?? 0) > 0 ? 1 : 0;
            $d['early'] += (int) ($c['early_exit_minutes'] ?? 0) > 0 && ($c['last_out'] ?? null) !== null ? 1 : 0;
        } else {
            $d['absent']++;
        }
    }
    $off = $people !== [] && $d['counted'] === 0;   // a holiday / weekly off for everyone: "-" rather than a misleading 0

    $pendingLeave = 0;
    foreach ($store->all('leaves') as $l) {
        $pendingLeave += strtolower((string) ($l['status'] ?? '')) === 'pending' ? 1 : 0;
    }
    $runs = array_filter($store->all('payroll_runs'), static fn (array $r): bool => (string) ($r['status'] ?? '') !== 'discarded');
    usort($runs, static fn ($a, $b) => strcmp((string) ($b['pay_date'] ?? $b['created_at'] ?? ''), (string) ($a['pay_date'] ?? $a['created_at'] ?? '')));
    $awaiting = count(array_filter($runs, static fn (array $r): bool => (string) ($r['status'] ?? '') !== 'approved'));
    $last = $runs[0] ?? null;
    $inr = static function ($v): string {   // 12,34,567 (Indian grouping), no paise
        $n = (string) (int) round((float) $v);
        $tail = substr($n, -3);
        $head = substr($n, 0, -3);
        return 'Rs ' . ($head === '' ? $tail : preg_replace('/\B(?=(\d{2})+$)/', ',', $head) . ',' . $tail);
    };

    return ['tiles' => [
        ['label' => 'Employees', 'value' => (string) count($people), 'sub' => 'active'],
        ['label' => 'Present today', 'value' => $off ? '-' : (string) $d['present'], 'sub' => $off ? 'holiday / weekly off' : 'of ' . $d['counted'], 'tone' => 'green'],
        ['label' => 'Absent', 'value' => $off ? '-' : (string) $d['absent'], 'sub' => 'no punch, no leave', 'tone' => $d['absent'] > 0 ? 'red' : ''],
        ['label' => 'On leave', 'value' => $off ? '-' : (string) $d['leave'], 'sub' => 'approved', 'tone' => 'blue'],
        ['label' => 'Late arrivals', 'value' => $off ? '-' : (string) $d['late'], 'sub' => 'today', 'tone' => $d['late'] > 0 ? 'amber' : ''],
        ['label' => 'Early logouts', 'value' => $off ? '-' : (string) $d['early'], 'sub' => 'today', 'tone' => $d['early'] > 0 ? 'amber' : ''],
        ['label' => 'Leave requests', 'value' => (string) $pendingLeave, 'sub' => 'waiting for approval', 'tone' => $pendingLeave > 0 ? 'amber' : ''],
        ['label' => 'Payroll', 'value' => $last === null ? '-' : $inr($last['net_total'] ?? 0),
            'sub' => $last === null ? 'not run yet' : trim((string) ($last['cycle_label'] ?? '')) . ($awaiting > 0 ? ' · ' . $awaiting . ' awaiting approval' : ' · approved'),
            'tone' => $awaiting > 0 ? 'amber' : ''],
    ]];
};
