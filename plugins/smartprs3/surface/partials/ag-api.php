<?php
/**
 * AMETECS GLOBAL NATIVE APP API (owner, 20 Nov 2026: "no WebViews ... develop the required screens directly within
 * Ametecs Global ... integrate existing APIs and business logic"). JSON for the phone app's own screens, read and written
 * through SmartPRS's own engines - the same muster, leave rules, payroll register and regularisation the web uses.
 * Included by app.php (?ag_api=<op>) once the signed-in page has all its services, after any POST action has run
 * (so a POST with action=leave-request / correction-raise is decided by the web's own handler; this only reports it).
 * Only this person's own record. Returns an array; app.php prints it as JSON.
 *
 * Ops: home | attendance (&m=YYYY-MM) | leave | payslips | payslip (&period=) | corrections | person (&app=smartept|qronex|rovexy)
 *      | notifications (POST csrf = mark all read) | admin (team today + approvals) | scan-ticket | result (after a POST action)
 */
declare(strict_types=1);

use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Payslip;

$aaOp = (string) ($_GET['ag_api'] ?? '');
$aaUser = $session->user();
$aaOwn = $scope->own();
$aaMe = $aaOwn === null ? null : $store->find('employees', (string) $aaOwn);
$aaCode = $aaMe === null ? '' : Employment::code($aaMe);
$aaTid = (string) ($aaUser['tenant_id'] ?? '');

if ($aaMe === null && $aaOp !== 'result') {
    return ['ok' => false, 'message' => 'This sign-in is not linked to an employee. Ask your administrator.'];
}

// what this person's menu allows (the same list the web menu draws: role + licence)
$aaHrefs = [];
foreach ((array) ($_SESSION['ag_prs_nav'] ?? []) as $aaS) {
    foreach ((array) ($aaS['items'] ?? []) as $aaI) {
        preg_match('/[?&]r=([a-z0-9-]+)/', (string) ($aaI['href'] ?? ''), $aaM) === 1 && $aaHrefs[$aaM[1]] = true;
    }
}
$aaPdo = static function (): \PDO {
    static $p = null;
    return $p ??= SmartPRS\Surface\Database::connect(dirname(__DIR__, 2) . '/.env');
};
$aaAppOn = static function (string $app) use ($aaTid, $aaOwn, $aaPdo): bool {
    try {
        return \AmetecsAccess::state($aaTid, $app) === 'on' && \AmetecsLicences::installed(\AmetecsLicences::row($aaTid, $app))
            && !in_array((int) $aaOwn, array_map('intval', \AmetecsAppAccess::hidden($aaPdo(), $aaTid, $app)), true);
    } catch (\Throwable) {
        return false;
    }
};
$aaDur = static fn (int $m): string => $m <= 0 ? '—' : ($m < 60 ? $m . 'm' : intdiv($m, 60) . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm');
$aaSrc = ['qr' => 'QR gate', 'qronex' => 'QR scan', 'smartept' => 'PC login', 'web' => 'App / web', 'mobile' => 'App', 'manual' => 'Manual', 'regularised' => 'Regularised',
    'bulk' => 'Upload', 'biometric' => 'Biometric', 'iclock' => 'Biometric', 'push' => 'Biometric', 'pull' => 'Biometric', 'sbb' => 'Biometric', 'fieldforce' => 'Field app', 'caller' => 'Field app'];
// OWN PAYSLIPS FOR EVERY EMPLOYEE (owner, 21 Nov 2026: "add also like payslips"): only this person's own slips, whatever
// the role's web menu shows. ponytail: one switch; tie it back to a role permission if a client asks for that.
$aaSlips = true;
$aaStatus = ['PRESENT' => 'Present', 'ABSENT' => 'Absent', 'HALF_DAY' => 'Half day', 'WEEKLY_OFF' => 'Weekly off', 'HOLIDAY' => 'Holiday', 'ON_LEAVE' => 'Leave',
    'UNPAID_LEAVE' => 'Unpaid leave', 'NOT_YET' => '—', 'NOT_JOINED' => '—'];

/** One month of this person's attendance: the muster the web's My Attendance and payroll read, plus each punch. */
$aaMonth = static function (string $month, ?string $day = null) use ($attendanceReport, $attendance, $calendar, $aaMe, $aaCode, $aaDur, $aaSrc, $aaStatus): array {
    // $day: just that day (the home card), not the whole month (owner, 21 Nov 2026: "app loading very slow")
    $from = $day ?? $month . '-01';
    $to = $day ?? date('Y-m-t', (int) strtotime($from));
    $row = $attendanceReport->muster([$aaMe], $from, $to, $calendar->shiftStart(), $calendar->shiftEnd(), date('Y-m-d'), 62, true)['rows'][0] ?? null;
    $cells = (array) ($row['cells'] ?? []);
    $punches = [];
    foreach ($attendance->punchesFor($aaCode) as $p) {
        $d = substr((string) $p['at'], 0, 10);
        $d >= $from && $d <= $to && $punches[$d][] = ['dir' => strtoupper((string) $p['direction']), 'at' => substr((string) $p['at'], 11, 5),
            'src' => $aaSrc[(string) $p['source']] ?? ucfirst((string) $p['source'])];
    }
    $count = [];
    $worked = 0;
    $late = 0;
    $days = [];
    foreach ($cells as $d => $c) {
        $st = (string) ($c['status'] ?? '');
        $count[$st] = ($count[$st] ?? 0) + 1;
        $worked += (int) ($c['worked_minutes'] ?? 0);
        (int) ($c['late_minutes'] ?? 0) > 0 && $late++;
        if ($st === 'NOT_YET' && ($punches[$d] ?? []) === []) {
            continue;
        }
        $days[] = ['date' => (string) $d, 'day' => date('D', (int) strtotime((string) $d)), 'label' => Dates::dmy((string) $d), 'status' => $st,
            'status_label' => $aaStatus[$st] ?? ucwords(strtolower(str_replace('_', ' ', $st))),
            'in' => (string) ($c['first_in'] ?? '') ?: null, 'out' => (string) ($c['last_out'] ?? '') ?: null,
            'worked' => $aaDur((int) ($c['worked_minutes'] ?? 0)), 'late' => (int) ($c['late_minutes'] ?? 0) > 0 ? $aaDur((int) $c['late_minutes']) : null,
            'planned' => (bool) ($c['planned'] ?? false), 'punches' => $punches[$d] ?? [],
            // the muster's own detail for the phone's day card (owner, 21 Nov 2026: "show more information like logs")
            'break' => (int) ($c['break_minutes'] ?? 0) > 0 ? $aaDur((int) $c['break_minutes']) : null,
            'early' => (int) ($c['early_exit_minutes'] ?? 0) > 0 ? $aaDur((int) $c['early_exit_minutes']) : null,
            'ot' => (int) ($c['overtime_minutes'] ?? 0) > 0 ? $aaDur((int) $c['overtime_minutes']) : null,
            'why' => (string) ($c['why'] ?? ''), 'fix' => (bool) ($c['needs_regularisation'] ?? false)];
    }
    return ['month' => $month, 'summary' => [
        ['Present', (int) ($count['PRESENT'] ?? 0)], ['Half day', (int) ($count['HALF_DAY'] ?? 0)], ['Absent', (int) ($count['ABSENT'] ?? 0)],
        ['Leave', (int) ($count['ON_LEAVE'] ?? 0) + (int) ($count['UNPAID_LEAVE'] ?? 0)], ['Off / holiday', (int) ($count['WEEKLY_OFF'] ?? 0) + (int) ($count['HOLIDAY'] ?? 0)],
        ['Late', $late]], 'worked' => $aaDur($worked), 'days' => array_reverse($days),
        'shift' => substr((string) $calendar->shiftStart(), 0, 5) . ' – ' . substr((string) $calendar->shiftEnd(), 0, 5)];
};

switch ($aaOp) {
    case 'home':
        $t = $aaMonth(date('Y-m'), date('Y-m-d'))['days'];
        $today = null;
        foreach ($t as $d) { $d['date'] === date('Y-m-d') && $today = $d; }
        $last = $today === null || $today['punches'] === [] ? null : end($today['punches'])['dir'];
        $mgr = (string) ($aaMe['reporting_manager_id'] ?? '') !== '' ? $store->find('employees', (string) $aaMe['reporting_manager_id']) : null;
        $unread = 0;
        try {
            $unread = $notifications->unreadFor($aaCode, [SmartPRS\Surface\Notifications::ROLE_PREFIX . (string) ($aaUser['role'] ?? '')]);
        } catch (\Throwable) {
        }
        $pendingLeave = count(array_filter($leave->requestsFor($aaCode), static fn (array $r): bool => (string) $r['state'] === 'pending'));
        return ['ok' => true, 'csrf' => $session->token(),
            'me' => ['name' => (string) ($aaMe['name'] ?? ''), 'code' => $aaCode, 'email' => (string) ($aaMe['email'] ?? $aaUser['email'] ?? ''),
                'mobile' => (string) ($aaMe['mobile'] ?? ''), 'designation' => orgValueOf($store, $aaMe, 'designation'), 'department' => orgValueOf($store, $aaMe, 'department'),
                'branch' => orgValueOf($store, $aaMe, 'branch'), 'company' => orgValueOf($store, $aaMe, 'company'), 'team' => orgValueOf($store, $aaMe, 'team'),
                'manager' => $mgr === null ? '' : (string) ($mgr['name'] ?? ''), 'joined' => (string) ($aaMe['date_of_joining'] ?? $aaMe['doj'] ?? ''),
                'role' => (string) ($aaUser['role'] ?? '')],
            'today' => ['in' => $today['in'] ?? null, 'out' => $today['out'] ?? null, 'worked' => $today['worked'] ?? '—', 'status' => $today['status_label'] ?? '—',
                'last' => $last, 'punches' => $today['punches'] ?? []],
            'can' => ['scan' => $aaAppOn('qronex'), 'attendance' => $may('ess.attendance.punch'), 'leave' => $may('leave.request.view'),
                'leave_apply' => $may('leave.request.submit'), 'payslips' => $aaSlips,
                'corrections' => isset($aaHrefs['attendance-corrections']), 'letters' => isset($aaHrefs['letters-mine']), 'assets' => isset($aaHrefs['my-assets']), 'productivity' => $aaAppOn('smartept'), 'field' => $aaAppOn('rovexy'),
                // ADMIN tile (owner, 21 Nov 2026): anyone who can see others or decide leave / corrections
                'admin' => $may('leave.request.decide') || $may('attendance.punch.create.manual') || hasDirectReports($store, $aaOwn === null ? null : (string) $aaOwn)],
            'unread' => $unread, 'pending_leave' => $pendingLeave,
            // BADGES (owner, 21 Nov 2026: "custom option to badges"): each company's own list, config/badges.json
            'badges' => array_values((array) (((array) json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/config/badges.json'), true))['badges'] ?? [])),
            // SUPPORT (owner, 21 Nov 2026): who this person can reach - their manager and their company's contact
            'support' => (static function () use ($store, $aaMe, $mgr): array {
                $co = (string) ($aaMe['employer_company_id'] ?? '') !== '' ? $store->find('companies', (string) $aaMe['employer_company_id']) : null;
                $pick = static fn (?array $r, array $keys): string => $r === null ? '' : (string) (array_values(array_filter(array_map(static fn (string $k): string => trim((string) ($r[$k] ?? '')), $keys)))[0] ?? '');
                return ['manager' => $mgr === null ? null : ['name' => (string) ($mgr['name'] ?? ''), 'mobile' => $pick($mgr, ['mobile', 'phone']), 'email' => $pick($mgr, ['email', 'official_email'])],
                    'company' => $co === null ? null : ['name' => (string) ($co['name'] ?? ''), 'phone' => $pick($co, ['phone', 'contact_phone', 'mobile']), 'email' => $pick($co, ['hr_email', 'email', 'contact_email'])]];
            })()];

    case 'attendance':
        $m = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) === 1 ? (string) $_GET['m'] : date('Y-m');
        return ['ok' => true] + $aaMonth($m);

    case 'leave':
        if (!$may('leave.request.view')) {
            return ['ok' => false, 'message' => 'Leave is not available for your role.'];
        }
        $types = [];
        foreach (leaveAvailability($store, $leave, $policy, $aaMe) as $code => $t) {
            $types[] = ['code' => (string) $code, 'name' => $t['name'], 'paid' => $t['paid'], 'balance' => $t['balance'], 'pending' => $t['pending'], 'available' => $t['available']];
        }
        $names = array_column($types, 'name', 'code');
        $reqs = array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'type' => $names[(string) $r['leave_type']] ?? (string) $r['leave_type'],
            'from' => Dates::dmy((string) $r['from']), 'to' => Dates::dmy((string) $r['to']), 'days' => (string) ($r['days'] ?? ''), 'state' => (string) $r['state'],
            'half' => SmartPRS\Surface\Leave::HALVES[(string) ($r['half'] ?? $r['half_day'] ?? '')] ?? null, 'reason' => (string) ($r['reason'] ?? ''),
            'by' => (string) ($r['decided_by'] ?? ''), 'note' => (string) ($r['decision_note'] ?? '')], array_slice($leave->requestsFor($aaCode), 0, 30));
        return ['ok' => true, 'csrf' => $session->token(), 'code' => $aaCode, 'can_apply' => $may('leave.request.submit'), 'types' => $types,
            'halves' => SmartPRS\Surface\Leave::HALVES, 'requests' => $reqs];

    case 'payslips':
    case 'payslip':
        if (!$aaSlips) {
            return ['ok' => false, 'message' => 'Payslips are not available for your role.'];
        }
        $list = [];
        foreach ($register->runs() as $run) {
            $period = (string) ($run['period'] ?? '');
            if ($aaOp === 'payslip' && $period !== (string) ($_GET['period'] ?? '')) {
                continue;
            }
            $full = $register->find($period);
            if (!isset(((array) ($full['rows'] ?? []))[$aaCode])) {
                continue;
            }
            $one = Payslip::from($full, $period, $aaCode);
            if (!$one['ok']) {
                continue;
            }
            $s = $one['slip'];
            $lines = static fn (array $l): array => array_map(static fn (array $x): array => [$x['code'], $x['amount']], $l);
            $item = ['period' => $period, 'label' => date('F Y', (int) strtotime($period . '-01')), 'net' => $s['net'], 'earnings_total' => $s['earnings_total'],
                'deductions_total' => $s['deductions_total'], 'payable_days' => $s['payable_days'], 'period_days' => $s['period_days'], 'approved' => (bool) $s['approved']];
            if ($aaOp === 'payslip') {
                return ['ok' => true, 'slip' => $item + ['name' => $s['name'], 'code' => $aaCode, 'earnings' => $lines($s['earnings']), 'deductions' => $lines($s['deductions']),
                    'net_in_words' => $s['net_in_words']]];
            }
            $list[] = $item;
        }
        return $aaOp === 'payslip' ? ['ok' => false, 'message' => 'No payslip for that month.'] : ['ok' => true, 'payslips' => $list];

    case 'corrections':
        $rows = array_map(static fn (array $r): array => ['date' => Dates::dmy((string) $r['date']), 'dir' => strtoupper((string) $r['direction']),
            'at' => substr((string) $r['at'], 11, 5), 'state' => (string) $r['state'], 'reason' => (string) ($r['reason'] ?? ''), 'note' => (string) ($r['note'] ?? '')],
            $regularisation->forEmployment($aaCode));
        return ['ok' => true, 'csrf' => $session->token(), 'code' => $aaCode, 'requests' => array_reverse($rows)];

    case 'person':   // another app's view of this person (its own ag-person.php): SmartEPT productivity, QRONEX punches, ROVEXY field work
        $app = (string) ($_GET['app'] ?? '');
        $file = dirname(__DIR__, 3) . '/' . $app . '/ag-person.php';
        if (!in_array($app, ['smartept', 'qronex', 'rovexy'], true) || !$aaAppOn($app) || !is_file($file)) {
            return ['ok' => false, 'message' => 'Not available for you.'];
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) === 1 ? (string) $_GET['from'] : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) === 1 ? (string) $_GET['to'] : date('Y-m-d');
        try {
            return ['ok' => true, 'from' => $from, 'to' => $to] + (array) (require $file)($aaTid, (int) $aaOwn, $from, $to);
        } catch (\Throwable $x) {
            error_log('ag-api person ' . $app . ': ' . $x->getMessage());
            return ['ok' => false, 'message' => 'Could not read it just now. Try again.'];
        }

    case 'admin':   // ADMIN ON THE PHONE (owner, 21 Nov 2026): team today + what waits on me, within this person's own reach
        $role = (string) ($aaUser['role'] ?? '');
        $team = array_values(array_filter($scope->only(liveRows($store, 'employees'), $may('attendance.day.view.history')), static fn (array $e): bool
            => Employment::code($e) !== $aaCode && in_array(strtolower(trim((string) ($e['status'] ?? 'active'))), ['active', ''], true)));
        $leaveQ = leaveQueueFor($store, $leave, $scope, $may, null, leaveSelfApproves($role));
        $regMay = regularisationDecidable($store, $regularisation, $scope, $may, $role);
        $regQ = array_values(array_filter($regularisation->all('pending'), static fn (array $r): bool => isset($regMay[(string) $r['employment_id']])));
        if ($team === [] && $leaveQ === [] && $regQ === []) {
            return ['ok' => false, 'message' => 'There is no team or approval on your account.'];
        }
        $who = [];
        foreach (liveRows($store, 'employees') as $e) { $who[Employment::code($e)] = (string) ($e['name'] ?? ''); }
        $types = [];
        foreach (leaveAvailability($store, $leave, $policy, $aaMe) as $tc => $t) { $types[(string) $tc] = (string) $t['name']; }
        $today = date('Y-m-d');
        $start = substr((string) $calendar->shiftStart(), 0, 5);
        // ponytail: the first 400 of the team; page it when one admin's reach is bigger
        $mus = $team === [] ? [] : $attendanceReport->muster(array_slice($team, 0, 400), $today, $today, $calendar->shiftStart(), $calendar->shiftEnd(), $today, 62, true)['rows'];
        $count = ['in' => 0, 'out' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0, 'late' => 0];
        $rows = [];
        foreach ($mus as $r) {
            $st = (string) ($r['cells'][$today]['status'] ?? '');
            $first = null;
            $lastDir = null;
            $n = 0;
            foreach ($attendance->punchesFor((string) $r['code']) as $p) {
                if (substr((string) $p['at'], 0, 10) !== $today) {
                    continue;
                }
                $n++;
                $d = strtoupper((string) $p['direction']);
                $d === 'IN' && $first === null && $first = substr((string) $p['at'], 11, 5);
                $lastDir = $d;
            }
            $state = match (true) {
                $lastDir === 'IN' => 'in', $lastDir === 'OUT' => 'out',
                in_array($st, ['ON_LEAVE', 'UNPAID_LEAVE'], true) => 'leave', in_array($st, ['WEEKLY_OFF', 'HOLIDAY'], true) => 'off', default => 'absent',
            };
            $late = $first !== null && $first > $start;
            $count[$state]++;
            $late && $count['late']++;
            $rows[] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'dept' => (string) $r['department'], 'state' => $state, 'in' => $first, 'late' => $late, 'punches' => $n];
        }
        return ['ok' => true, 'csrf' => $session->token(), 'date' => Dates::dmy($today), 'shift' => $start . ' – ' . substr((string) $calendar->shiftEnd(), 0, 5),
            'team_size' => count($team), 'count' => $count, 'team' => $rows,
            'leave' => array_map(static fn (array $q): array => ['id' => (string) $q['id'], 'code' => (string) $q['employment_id'], 'name' => $who[(string) $q['employment_id']] ?? (string) $q['employment_id'],
                'type' => $types[(string) $q['leave_type']] ?? (string) $q['leave_type'], 'from' => Dates::dmy((string) $q['from']), 'to' => Dates::dmy((string) $q['to']),
                'days' => (string) ($q['days'] ?? ''), 'half' => SmartPRS\Surface\Leave::HALVES[(string) ($q['half'] ?? $q['half_day'] ?? '')] ?? null, 'reason' => (string) ($q['reason'] ?? '')], $leaveQ),
            'corrections' => array_map(static fn (array $q): array => ['id' => (string) $q['id'], 'code' => (string) $q['employment_id'], 'name' => $who[(string) $q['employment_id']] ?? (string) $q['employment_id'],
                'date' => Dates::dmy((string) $q['date']), 'dir' => strtoupper((string) $q['direction']), 'at' => substr((string) $q['at'], 11, 5), 'reason' => (string) ($q['reason'] ?? '')], $regQ)];

    case 'profile':   // MY PROFILE (owner, 21 Nov 2026: "more premium ... custom features"): every field this company keeps on its
        // people, section by section as Organization Setup lays them out - its own custom fields included - with this
        // person's values. Numbers that identify accounts are masked to the last four.
        $pfExtra = (new SmartPRS\Surface\EmployeeExtras($store))->forEmployment($aaCode);
        $pfOrg = ['employer_company_id' => 'company', 'branch_id' => 'branch', 'department_id' => 'department', 'designation_id' => 'designation', 'team_id' => 'team'];
        $pfMask = static fn (string $code, string $v): string => preg_match('/account|acc_no|pan|aadha?ar|uan|passport|pf_no|esi_no|voter|licen[cs]e/i', $code) === 1 && strlen($v) > 4
            ? str_repeat('•', min(8, strlen($v) - 4)) . substr($v, -4) : $v;
        $pfSections = [];
        try {
            foreach ($employeeFields->sections() as $sec) {
                $rows = [];
                foreach ((array) $sec['fields'] as $f) {
                    if (!($f['captured'] ?? false) || ($f['document'] ?? false) || ($f['type'] ?? '') === 'Image/PDF/Doc') {
                        continue;
                    }
                    $code = (string) ($f['code'] ?? '');
                    $col = (string) ($f['column'] ?? '');
                    $v = isset($pfOrg[$col]) ? orgValueOf($store, $aaMe, $pfOrg[$col])
                        : ($col === 'reporting_manager_id' ? (string) ($store->find('employees', (string) ($aaMe[$col] ?? ''))['name'] ?? '')
                        : (($f['storage'] ?? '') === 'column' && $col !== '' ? (string) ($aaMe[$col] ?? '') : (string) ($pfExtra[$code] ?? '')));
                    $v = trim($v);
                    if ($v === '' || in_array($col, ['ctc', 'password', 'password_hash'], true)) {
                        continue;
                    }
                    ($f['type'] ?? '') === 'Date' && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1 && $v = Dates::dmy(substr($v, 0, 10));
                    in_array($v, ['0', '1'], true) && in_array(($f['type'] ?? ''), ['Yes/No', 'Boolean'], true) && $v = $v === '1' ? 'Yes' : 'No';
                    $rows[] = ['label' => (string) ($f['label'] ?? $code), 'value' => $pfMask($code, $v), 'custom' => (bool) ($f['custom'] ?? false)];
                }
                $rows !== [] && $pfSections[] = ['id' => (string) $sec['id'], 'title' => (string) $sec['title'], 'fields' => $rows];
            }
        } catch (\Throwable $x) {
            error_log('ag-api profile: ' . $x->getMessage());
        }
        $pfJoined = (string) ($aaMe['date_of_joining'] ?? $aaMe['doj'] ?? '');
        return ['ok' => true, 'code' => $aaCode, 'name' => (string) ($aaMe['name'] ?? ''), 'designation' => orgValueOf($store, $aaMe, 'designation'),
            'company' => orgValueOf($store, $aaMe, 'company'), 'status' => ucfirst(strtolower(trim((string) ($aaMe['status'] ?? 'Active')) ?: 'Active')),
            'joined' => $pfJoined, 'shift' => substr((string) $calendar->shiftStart(), 0, 5) . ' – ' . substr((string) $calendar->shiftEnd(), 0, 5),
            'sections' => $pfSections];

    case 'letters':   // My Letters: each letter as issued; one that needs acknowledgement carries ack = false until signed off
        if (!isset($aaHrefs['letters-mine'])) {
            return ['ok' => false, 'message' => 'Letters are not available for your role.'];
        }
        $acks = [];
        try {
            foreach ($store->all('letter_acks') as $a) { $acks[(string) ($a['letter_id'] ?? '')] = (string) ($a['acknowledged_at'] ?? ''); }
        } catch (\Throwable) {
            $acks = [];   // letter_acks not migrated yet: nothing acknowledged
        }
        $out = [];
        foreach ($letters->issued((string) $aaOwn) as $l) {
            $t = $letters->find((string) ($l['letter_type'] ?? ''));
            $needs = $t !== null && SmartPRS\Surface\Letters::needsAck($t);
            $out[] = ['id' => (string) $l['id'], 'title' => (string) ($t['label'] ?? $l['letter_type']), 'issued' => Dates::dmy(substr((string) ($l['issued_on'] ?? ''), 0, 10)),
                'body' => (string) ($l['body'] ?? ''), 'needs_ack' => $needs, 'acked' => $acks[(string) $l['id']] ?? null];
        }
        return ['ok' => true, 'csrf' => $session->token(), 'letters' => $out];

    case 'assets':   // My Assets: what is issued to me, and damaged / lost returns still to settle
        if (!isset($aaHrefs['my-assets'])) {
            return ['ok' => false, 'message' => 'Assets are not available for your role.'];
        }
        $o = (new SmartPRS\Surface\Assets($store, dirname(__DIR__, 2) . '/config/assets.json'))->outstandingFor((string) $aaOwn);
        $row = static fn (array $r): array => ['id' => (string) ($r['asset_id'] ?? ''), 'type' => (string) ($r['asset_type'] ?? ''),
            'model' => (string) ($r['make_model'] ?? '') ?: (string) ($r['asset_name'] ?? ''), 'serial' => (string) ($r['serial_no'] ?? ''),
            'issued' => (string) ($r['issue_date'] ?? '') !== '' ? Dates::dmy((string) $r['issue_date']) : '', 'returned' => (string) ($r['return_date'] ?? '') !== '' ? Dates::dmy((string) $r['return_date']) : '',
            'condition' => ucfirst((string) ($r['condition'] ?? '')), 'recovery' => trim((string) ($r['recovery_amount'] ?? '')) === '' ? '' : SmartPRS\Surface\Amount::of($r['recovery_amount'])->format(),
            'recovery_status' => (string) ($r['recovery_status'] ?? '')];
        return ['ok' => true, 'held' => array_map($row, $o['held']), 'unsettled' => array_map($row, $o['unsettled'])];

    case 'notifications':   // every kind in one feed (owner, 21 Nov 2026): approvals, notices, leave, attendance, payroll
        $out = [];
        $cat = static function (string $kind, string $title): string {
            $k = strtolower($kind . ' ' . $title);
            return match (true) {
                str_contains($k, 'approv') || str_contains($k, 'request') || str_contains($k, 'decid') || str_contains($k, 'reject') => 'approval',
                str_contains($k, 'leave') => 'leave',
                str_contains($k, 'attend') || str_contains($k, 'regulari') || str_contains($k, 'punch') || str_contains($k, 'late') => 'attendance',
                str_contains($k, 'pay') || str_contains($k, 'salary') || str_contains($k, 'tds') || str_contains($k, 'tax') => 'payroll',
                default => 'update',
            };
        };
        $aaFor = [SmartPRS\Surface\Notifications::ROLE_PREFIX . (string) ($aaUser['role'] ?? '')];
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($session->token(), (string) ($_POST['csrf'] ?? ''))) {   // "mark all read"
                foreach ($notifications->queuedFor($aaCode, 200, $aaFor) as $n) {
                    trim((string) ($n['read_at'] ?? '')) === '' && $notifications->markRead((string) $n['id'], $aaCode, false, date('Y-m-d H:i:s'), $aaFor);
                }
            }
            foreach ($notifications->queuedFor($aaCode, 60, $aaFor) as $n) {
                $out[] = ['id' => (string) ($n['id'] ?? ''), 'kind' => $cat((string) ($n['kind'] ?? ''), (string) ($n['title'] ?? '')),
                    'title' => (string) ($n['title'] ?? ''), 'body' => (string) ($n['body'] ?? ''), 'at' => (string) ($n['created_at'] ?? ''),
                    'read' => trim((string) ($n['read_at'] ?? '')) !== ''];
            }
        } catch (\Throwable) {
        }
        try {
            foreach (array_slice((new SmartPRS\Surface\Notices($store))->current(), 0, 20) as $nt) {
                $out[] = ['id' => 'notice-' . (string) ($nt['id'] ?? ''), 'kind' => 'notice', 'title' => (string) ($nt['title'] ?? 'Notice'), 'body' => (string) ($nt['body'] ?? ''),
                    'at' => (string) ($nt['posted_on'] ?? $nt['created_at'] ?? ''), 'read' => true, 'until' => (string) ($nt['valid_to'] ?? '') !== '' ? Dates::dmy((string) $nt['valid_to']) : ''];
            }
        } catch (\Throwable) {
            // no notices table on this store: nothing to add
        }
        usort($out, static fn ($a, $b) => strcmp($b['at'], $a['at']));
        return ['ok' => true, 'csrf' => $session->token(), 'items' => $out];

    case 'scan-ticket':   // QRONEX punch from the native scanner: the same one-time ticket core/scan.php issues
        if (!$aaAppOn('qronex')) {
            return ['ok' => false, 'message' => 'QR attendance is not on for you. Ask your administrator.'];
        }
        $keyFile = dirname(__DIR__, 4) . '/core/data/ag-sso.key';
        if (!is_file($keyFile)) {
            @mkdir(dirname($keyFile), 0700, true);
            file_put_contents($keyFile, bin2hex(random_bytes(32)), LOCK_EX);
        }
        $body = rtrim(strtr(base64_encode((string) json_encode(['kind' => 'scan', 'tid' => $aaTid, 'emp' => (int) $aaOwn,
            'exp' => time() + 60, 'jti' => bin2hex(random_bytes(12))])), '+/', '-_'), '=');
        return ['ok' => true, 't' => $body . '.' . hash_hmac('sha256', $body, trim((string) file_get_contents($keyFile))), 'url' => 'plugins/qronex/public/api/ag/scan'];

    case 'result':   // the outcome of the POST action the web handler just ran
        return ['ok' => $problem === null, 'message' => (string) ($problem ?? $notice ?? 'Done.')];
}
return ['ok' => false, 'message' => 'Unknown request.'];
