<?php
/**
 * SMARTPRS 3'S FIGURES FOR THE AMETECSGLOBAL DASHBOARD (owner, 18 Nov 2026 - Phases 6 and 7).
 *
 * Every app may ship one file like this at plugins/<app>/ag-stats.php returning
 *   function (PDO $pdo, string $tenantId, array $filters = []): array
 * $filters (all optional): from, to (Y-m-d; to is never after today), ids (list of central employee ids the dashboard's
 * company / branch / department / team / staff type / employee filters leave; null = everyone).
 * It returns any of:
 *   'tiles'  => [['key' => 'present', 'label' => 'Present', 'value' => '42', 'sub' => 'of 50', 'tone' => 'green|amber|red|blue|']]
 *   'trend'  => ['title' => '...', 'labels' => [dates], 'sets' => ['Present' => [n, ...], ...]]   (a stacked bar chart)
 *   'people' => [employeeId => ['present' => n, 'absent' => n, 'leave' => n, 'late' => n]]       (Ametecs Global groups
 *               these by company / branch / team for the breakdown chart)
 *   'list'   => ['title' => '...', 'rows' => [['text' => '...', 'sub' => '...']]]
 * Cloud: run by the dashboard directly. On-premise: SmartPRS3 sends today's figures with its hourly licence check-in.
 *
 * Present / absent / leave / late / early come from the SAME muster SmartPRS3's own dashboard and Attendance Report use
 * (AttendanceReport::muster), so the numbers here and in the app agree.
 */
declare(strict_types=1);

use SmartPRS\Surface\AttendanceReport;

return static function (\PDO $pdo, string $tenant, array $f = []): array {
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

    $today = date('Y-m-d');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) === 1 ? min((string) $f['to'], $today) : $today;
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) === 1 ? min((string) $f['from'], $to) : $to;
    // ponytail: the muster reads at most 62 days at a time; a longer range shows its last 62 days (the screen says so).
    $from = max($from, date('Y-m-d', (int) strtotime($to . ' -61 days')));
    $oneDay = $from === $to;
    $ids = isset($f['ids']) && is_array($f['ids']) ? array_flip(array_map('strval', $f['ids'])) : null;

    $people = array_values(array_filter($store->all('employees'), static fn (array $e): bool
        => !\SmartPRS\Surface\Modules::isRetired($e) && strtolower(trim((string) ($e['status'] ?? 'active'))) === 'active'
        && ($ids === null || isset($ids[(string) $e['id']]))));

    $dates = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', (int) strtotime($d . ' +1 day'))) {
        $dates[$d] = ['present' => 0, 'absent' => 0, 'leave' => 0];
    }
    $sum = ['present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'early' => 0, 'counted' => 0];
    $per = [];
    foreach ($people === [] ? [] : $report->muster($people, $from, $to, $calendar->shiftStart(), $calendar->shiftEnd(), $today)['rows'] as $row) {
        $p = ['present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0];
        foreach ($row['cells'] as $date => $c) {
            if ($c['status'] === AttendanceReport::NOT_JOINED || !isset($dates[$date])) {
                continue;
            }
            $st = (string) $c['status'];
            if ($st === AttendanceReport::NOT_YET) {   // today's shift still under way: is today an off day for them?
                $st = (string) ($attendance->dayWithoutPunches($row['code'], $date, $calendar->shiftStart(), $calendar->shiftEnd())['classification']['status'] ?? '');
            }
            $punched = (int) ($c['punches'] ?? 0) > 0;
            if (in_array($st, ['HOLIDAY', 'WEEKLY_OFF'], true) && !$punched) {
                continue;
            }
            $sum['counted']++;
            $k = in_array($st, AttendanceReport::LEAVE_STATUSES, true) ? 'leave' : ($punched ? 'present' : 'absent');
            $sum[$k]++;
            $p[$k]++;
            $dates[$date][$k]++;
            if ($k === 'present') {
                $late = (int) ($c['late_minutes'] ?? 0) > 0 ? 1 : 0;
                $sum['late'] += $late;
                $p['late'] += $late;
                $sum['early'] += (int) ($c['early_exit_minutes'] ?? 0) > 0 && ($c['last_out'] ?? null) !== null ? 1 : 0;
            }
        }
        $per[(string) $row['employment_id']] = $p;
    }
    $off = $people !== [] && $sum['counted'] === 0;   // a holiday / weekly off for everyone: "-" rather than a misleading 0
    $n = static fn (int $v): string => $off ? '-' : (string) $v;

    $pendingLeave = 0;
    foreach ($store->all('leaves') as $l) {
        $pendingLeave += strtolower((string) ($l['status'] ?? '')) === 'pending' && ($ids === null || isset($ids[(string) ($l['employee_id'] ?? '')])) ? 1 : 0;
    }
    $company = (string) ($f['company'] ?? '');
    $runs = array_filter($store->all('payroll_runs'), static fn (array $r): bool => (string) ($r['status'] ?? '') !== 'discarded'
        && ($company === '' || (string) ($r['employer_company_id'] ?? '') === $company));
    usort($runs, static fn ($a, $b) => strcmp((string) ($b['pay_date'] ?? $b['created_at'] ?? ''), (string) ($a['pay_date'] ?? $a['created_at'] ?? '')));
    $awaiting = count(array_filter($runs, static fn (array $r): bool => (string) ($r['status'] ?? '') !== 'approved'));
    $last = $runs[0] ?? null;
    $inr = static function ($v): string {   // Rs 12,34,567 (Indian grouping), no paise
        $s = (string) (int) round((float) $v);
        $tail = substr($s, -3);
        $head = substr($s, 0, -3);
        return 'Rs ' . ($head === '' ? $tail : preg_replace('/\B(?=(\d{2})+$)/', ',', $head) . ',' . $tail);
    };
    $rate = $sum['counted'] > 0 ? (int) round(100 * ($sum['present'] + $sum['leave']) / $sum['counted']) . '%' : '-';

    $tiles = $oneDay ? [
        ['key' => 'employees', 'label' => 'Employees', 'value' => (string) count($people), 'sub' => 'active'],
        ['key' => 'present', 'label' => 'Present', 'value' => $n($sum['present']), 'sub' => $off ? 'holiday / weekly off' : 'of ' . $sum['counted'] . ' working', 'tone' => 'green'],
        ['key' => 'absent', 'label' => 'Absent', 'value' => $n($sum['absent']), 'sub' => 'no punch, no leave', 'tone' => $sum['absent'] > 0 ? 'red' : ''],
        ['key' => 'leave', 'label' => 'On leave', 'value' => $n($sum['leave']), 'sub' => 'approved', 'tone' => 'blue'],
        ['key' => 'late', 'label' => 'Late arrivals', 'value' => $n($sum['late']), 'tone' => $sum['late'] > 0 ? 'amber' : ''],
        ['key' => 'early', 'label' => 'Early logouts', 'value' => $n($sum['early']), 'tone' => $sum['early'] > 0 ? 'amber' : ''],
    ] : [
        ['key' => 'employees', 'label' => 'Employees', 'value' => (string) count($people), 'sub' => 'active'],
        ['key' => 'rate', 'label' => 'Attendance', 'value' => $rate, 'sub' => 'present or on leave, of working days', 'tone' => 'green'],
        ['key' => 'present', 'label' => 'Present', 'value' => (string) $sum['present'], 'sub' => 'person-days', 'tone' => 'green'],
        ['key' => 'absent', 'label' => 'Absent', 'value' => (string) $sum['absent'], 'sub' => 'person-days', 'tone' => $sum['absent'] > 0 ? 'red' : ''],
        ['key' => 'leave', 'label' => 'On leave', 'value' => (string) $sum['leave'], 'sub' => 'person-days', 'tone' => 'blue'],
        ['key' => 'late', 'label' => 'Late arrivals', 'value' => (string) $sum['late'], 'sub' => 'times', 'tone' => $sum['late'] > 0 ? 'amber' : ''],
        ['key' => 'early', 'label' => 'Early logouts', 'value' => (string) $sum['early'], 'sub' => 'times', 'tone' => $sum['early'] > 0 ? 'amber' : ''],
    ];
    $tiles[] = ['key' => 'leave_requests', 'label' => 'Leave requests', 'value' => (string) $pendingLeave, 'sub' => 'waiting for approval', 'tone' => $pendingLeave > 0 ? 'amber' : ''];
    $tiles[] = ['key' => 'payroll', 'label' => 'Payroll', 'value' => $last === null ? '-' : $inr($last['net_total'] ?? 0),
        'sub' => $last === null ? 'not run yet' : trim((string) ($last['cycle_label'] ?? '')) . ($awaiting > 0 ? ' · ' . $awaiting . ' awaiting approval' : ' · approved'),
        'tone' => $awaiting > 0 ? 'amber' : ''];

    return ['tiles' => $tiles, 'people' => $per,
        'trend' => $oneDay ? null : ['title' => 'Attendance by day', 'labels' => array_keys($dates),
            'sets' => ['Present' => array_column($dates, 'present'), 'On leave' => array_column($dates, 'leave'), 'Absent' => array_column($dates, 'absent')]],
        'range' => [$from, $to]];
};
