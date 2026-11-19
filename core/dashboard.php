<?php
/**
 * THE CLIENT'S UNIFIED DASHBOARD (owner, 18 Nov 2026 - Phase 7): every application's live figures in one place, one set
 * of filters for all of them. Included by core/index.php for an administrator's home page; with &frag=1 only the inner
 * part is sent, which the page's script swaps in when a filter changes and every 60 seconds.
 *
 * Filters: date (preset or from/to), application, staff type (Field / Office, the employee's "Staff Type"), company,
 * branch, department, team, employee - read from the central organisation tables every app shares. The employee
 * filters become one list of employee ids handed to each app's ag-stats.php, so every app filters the same people.
 * Needs from index.php: $pdo, $tenant, $myLic, $groups, $notes, $logoOf, $now.
 */
declare(strict_types=1);

$q = static fn (string $k): string => trim((string) ($_GET[$k] ?? ''));
$today = AmetecsAccess::today();
$day = static fn (string $mod): string => (new DateTimeImmutable($today))->modify($mod)->format('Y-m-d');
$presets = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'lastmonth' => 'Last month', 'custom' => 'Custom dates'];
$preset = isset($presets[$q('d')]) ? $q('d') : 'today';
[$from, $to] = match ($preset) {
    'yesterday' => [$day('-1 day'), $day('-1 day')],
    '7d' => [$day('-6 days'), $today],
    '30d' => [$day('-29 days'), $today],
    'month' => [substr($today, 0, 8) . '01', $today],
    'lastmonth' => [$day('first day of last month'), $day('last day of last month')],
    'custom' => [preg_match('/^\d{4}-\d{2}-\d{2}$/', $q('from')) ? $q('from') : $today, preg_match('/^\d{4}-\d{2}-\d{2}$/', $q('to')) ? $q('to') : $today],
    default => [$today, $today],
};
[$from, $to] = $from > $to ? [$to, $from] : [$from, $to];

// The organisation, from the central tables (Organization Setup).
$orgList = static function (string $table) use ($pdo, $tenant): array {
    try {
        $s = $pdo->prepare("SELECT * FROM `{$table}` WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name");
        $s->execute([$tenant]);
        return array_column($s->fetchAll(\PDO::FETCH_ASSOC), 'name', 'id');
    } catch (\Throwable) {
        return [];
    }
};
$org = ['company' => $orgList('companies'), 'branch' => $orgList('branches'), 'department' => $orgList('departments'), 'team' => $orgList('teams')];
$col = ['company' => 'employer_company_id', 'branch' => 'branch_id', 'department' => 'department_id', 'team' => 'team_id'];
$f = ['app' => $q('app'), 'type' => in_array($q('type'), ['Field', 'Office'], true) ? $q('type') : '', 'employee' => $q('emp')];
foreach ($col as $k => $c) {
    $f[$k] = isset($org[$k][$q($k)]) ? $q($k) : '';
}
$everyone = array_values(array_filter((new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant)->all('employees'), static fn (array $e): bool
    => !\SmartPRS\Surface\Modules::isRetired($e) && strtolower(trim((string) ($e['status'] ?? 'active'))) === 'active'));
$match = static function (array $e, bool $withEmployee = true) use ($f, $col): bool {
    foreach ($col as $k => $c) {
        if ($f[$k] !== '' && (string) ($e[$c] ?? '') !== $f[$k]) {
            return false;
        }
    }
    return ($f['type'] === '' || strcasecmp(trim((string) ($e['type'] ?? '')), $f['type']) === 0)
        && (!$withEmployee || $f['employee'] === '' || (string) $e['id'] === $f['employee']);
};
$pool = array_values(array_filter($everyone, static fn ($e) => $match($e, false)));   // the Employee list follows the other filters
$f['employee'] = in_array($f['employee'], array_map(static fn ($e) => (string) $e['id'], $pool), true) ? $f['employee'] : '';
$chosen = array_values(array_filter($pool, static fn ($e) => $match($e)));
$filtered = $f['type'] !== '' || $f['employee'] !== '' || array_filter(array_intersect_key($f, $col)) !== [];
$appFilter = ['from' => $from, 'to' => $to, 'company' => $f['company']] + ($filtered ? ['ids' => array_map(static fn ($e) => (int) $e['id'], $chosen)] : []);

// The applications to show, and their figures.
$held = [];
foreach (array_merge($groups['live'], $groups['soon']) as $r) {
    $l = $myLic[$r['code']] ?? null;
    if ($l !== null && ($f['app'] === '' || $f['app'] === $r['code'])) {
        $held[$r['code']] = ['p' => $r['p'], 'l' => $l, 'live' => $r['p']['open'] !== null && $l['deployment'] !== 'onprem'];
    }
}
$allHeld = array_merge($groups['live'], $groups['soon']);
$f['app'] = isset($held[$f['app']]) ? $f['app'] : '';
foreach ($held as $code => &$h) {
    $h['s'] = AmetecsShell::appStats($tenant, $code, $h['l'], $h['l']['deployment'] === 'onprem' ? [] : $appFilter);
}
unset($h);

// Combined across apps: the same figure from several apps is added up (attendance from SmartPRS3 and QR attendance, ...).
$combine = ['present' => 'Present', 'absent' => 'Absent', 'leave' => 'On leave', 'late' => 'Late arrivals', 'early' => 'Early logouts'];
$tot = [];
$people = [];
foreach ($held as $code => $h) {
    foreach ((array) ($h['s']['tiles'] ?? []) as $t) {
        if (isset($combine[$t['key']]) && empty($h['s']['reported']) && is_numeric($t['value'])) {
            $tot[$t['key']] = ($tot[$t['key']] ?? 0) + (int) $t['value'];
        }
    }
    foreach ((array) ($h['s']['people'] ?? []) as $id => $p) {
        foreach ($p as $k => $v) {
            $people[(string) $id][$k] = ($people[(string) $id][$k] ?? 0) + (int) $v;
        }
    }
}
$trend = null;
foreach ($held as $h) {
    $trend ??= $h['s']['trend'] ?? null;   // ponytail: the first app with a day-by-day series; merge series when a second app has one
}
$field = count(array_filter($chosen, static fn ($e) => strcasecmp(trim((string) ($e['type'] ?? '')), 'Field') === 0));
$office = count(array_filter($chosen, static fn ($e) => strcasecmp(trim((string) ($e['type'] ?? '')), 'Office') === 0));
$working = ($tot['present'] ?? 0) + ($tot['absent'] ?? 0) + ($tot['leave'] ?? 0);
$oneDay = $from === $to;

// Breakdown: by company, else branch, else team, else employee - one level below the narrowest filter chosen.
$dim = $f['employee'] !== '' ? null : ($f['team'] !== '' ? 'employee' : ($f['branch'] !== '' ? 'team' : ($f['company'] !== '' ? 'branch' : (count($org['company']) > 1 ? 'company' : 'branch'))));
$break = [];
if ($dim !== null && $people !== []) {
    $byId = array_column($chosen, null, 'id');
    foreach ($people as $id => $p) {
        $e = $byId[$id] ?? null;
        if ($e === null) {
            continue;
        }
        $label = $dim === 'employee' ? trim((string) ($e['name'] ?? '')) ?: '#' . $id : ($org[$dim][(string) ($e[$col[$dim]] ?? '')] ?? 'Not set');
        foreach (['present', 'leave', 'absent'] as $k) {
            $break[$label][$k] = ($break[$label][$k] ?? 0) + (int) ($p[$k] ?? 0);
        }
    }
    uasort($break, static fn ($a, $b) => array_sum($b) <=> array_sum($a));
    $break = array_slice($break, 0, 12, true);
}
$qs = static function (array $over = []) use ($preset, $from, $to, $f): string {
    $p = array_filter(['d' => $preset === 'today' ? '' : $preset, 'from' => $preset === 'custom' ? $from : '', 'to' => $preset === 'custom' ? $to : '',
        'app' => $f['app'], 'type' => $f['type'], 'company' => $f['company'], 'branch' => $f['branch'], 'department' => $f['department'], 'team' => $f['team'], 'emp' => $f['employee']]);
    return http_build_query(array_filter($over + $p, static fn ($v) => $v !== ''));
};
$opt = static function (array $list, string $cur, string $all): string {
    $h = '<option value="">' . ag_h($all) . '</option>';
    foreach ($list as $v => $label) {
        $h .= '<option value="' . ag_h((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . ag_h((string) $label) . '</option>';
    }
    return $h;
};
$range = $oneDay ? date('d M Y', (int) strtotime($from)) : date('d M', (int) strtotime($from)) . ' – ' . date('d M Y', (int) strtotime($to));
// CLICKABLE TILES (owner, 19 Nov 2026: "add here more features and clickable"): a tile with a list opens it in a pop-up
// (<dialog>, ui.js); the list is the people behind the number, with company, department and a note.
$byIdAll = array_column($chosen, null, 'id');
$lists = [];
$desig = $orgList('designations');
$who = static function (array $e, string $note = '') use ($org, $col, $desig, $people): array {
    $nm = static fn (string $k) => $org[$k][(string) ($e[$col[$k]] ?? '')] ?? '';
    $dt = static fn ($d) => strlen((string) $d) >= 10 ? date('d M Y', (int) strtotime((string) $d)) : '';
    $p = $people[(string) ($e['id'] ?? '')] ?? [];
    // full record for the detail card (row click, ui.js)
    $d = ['name' => trim((string) ($e['name'] ?? '')), 'code' => (string) ($e['emp_code'] ?? ''), 'status' => ucfirst((string) ($e['status'] ?? 'active')),
        'Staff type' => trim((string) ($e['type'] ?? '')), 'Company' => $nm('company'), 'Branch' => $nm('branch'), 'Department' => $nm('department'), 'Team' => $nm('team'),
        'Designation' => $desig[(string) ($e['designation_id'] ?? '')] ?? (string) ($e['designation'] ?? ''), 'Gender' => (string) ($e['gender'] ?? ''),
        'Date of joining' => $dt($e['doj'] ?? ''), 'Date of birth' => $dt($e['dob'] ?? ''), 'Probation ends' => $dt($e['probation_end'] ?? ''),
        'Mobile' => (string) ($e['mobile'] ?? ''), 'Email' => (string) ($e['email'] ?? ''),
        'att' => ['Present' => (int) ($p['present'] ?? 0), 'Absent' => (int) ($p['absent'] ?? 0), 'On leave' => (int) ($p['leave'] ?? 0), 'Late' => (int) ($p['late'] ?? 0), 'Early out' => (int) ($p['early'] ?? 0)]];
    return [$d['name'], $d['code'], $d['Company'], $d['Department'], $note, $d];
};
$lists['employees'] = array_map(static fn ($e) => $who($e, (string) ($e['type'] ?? '')), $chosen);
$lists['field'] = array_map(static fn ($e) => $who($e, trim((string) ($e['type'] ?? '')) ?: 'No staff type'), $chosen);
usort($lists['field'], static fn ($a, $b) => [$a[4], $a[0]] <=> [$b[4], $b[0]]);
foreach (array_keys($combine) as $k) {
    foreach ($people as $id => $p) {
        if ((int) ($p[$k] ?? 0) > 0 && isset($byIdAll[$id])) {
            $lists[$k][] = $who($byIdAll[$id], $oneDay ? '' : $p[$k] . ($k === 'late' || $k === 'early' ? ' times' : ' days'));
        }
    }
}
$lists['notmarked'] = array_map(static fn ($e) => $who($e), array_values(array_filter($chosen, static fn ($e) => !isset($people[(string) $e['id']]) || array_sum(array_intersect_key($people[(string) $e['id']], ['present' => 1, 'absent' => 1, 'leave' => 1])) === 0)));
$soon = static fn (string $d, int $days) => $d !== '' && $d >= $today && $d <= $day('+' . $days . ' days');
$lists['joiners'] = array_map(static fn ($e) => $who($e, 'Joined ' . date('d M Y', (int) strtotime((string) $e['doj']))), array_values(array_filter($chosen, static fn ($e) => (string) ($e['doj'] ?? '') >= $day('-30 days'))));
$lists['birthdays'] = array_map(static fn ($e) => $who($e, date('d M', (int) strtotime((string) $e['dob']))), array_values(array_filter($chosen, static fn ($e) => strlen((string) ($e['dob'] ?? '')) >= 10 && substr((string) $e['dob'], 5, 2) === substr($today, 5, 2))));
$lists['probation'] = array_map(static fn ($e) => $who($e, 'Ends ' . date('d M Y', (int) strtotime((string) $e['probation_end']))), array_values(array_filter($chosen, static fn ($e) => $soon(substr((string) ($e['probation_end'] ?? ''), 0, 10), 30))));
$coCount = array_count_values(array_map(static fn ($e) => (string) ($e[$col['company']] ?? ''), $chosen));
$deptCount = array_count_values(array_map(static fn ($e) => (string) ($e[$col['department']] ?? ''), $chosen));
$lists['companies'] = array_map(static fn ($id, $n) => [$org['company'][$id] ?? 'Not set', '', '', '', $n . ' employees'], array_keys($coCount), $coCount);
$lists['departments'] = array_map(static fn ($id, $n) => [$org['department'][$id] ?? 'Not set', '', '', '', $n . ' employees'], array_keys($deptCount), $deptCount);
$tile = static function (string $label, string $value, string $sub = '', string $tone = '', string $icon = '', string $key = '', string $href = '') use ($lists): string {
    $inner = ($icon !== '' ? '<i class="fas ' . $icon . '"></i>' : '') . '<span>' . ag_h($label) . '</span><b>' . ag_h($value) . '</b>' . ($sub !== '' ? '<small>' . ag_h($sub) . '</small>' : '');
    if ($key !== '' && isset($lists[$key])) { return '<a class="kpi kgo ' . ag_h($tone) . '" href="#" data-dlg="dl-' . $key . '">' . $inner . '</a>'; }
    return $href !== '' ? '<a class="kpi kgo ' . ag_h($tone) . '" href="' . ag_h($href) . '">' . $inner . '</a>' : '<div class="kpi ' . ag_h($tone) . '">' . $inner . '</div>';
};
$dialog = static function (string $key, string $title) use ($lists, $range): string {
    $rows = $lists[$key] ?? [];
    $people = isset($rows[0][5]);
    $h = '<dialog class="kdlg" id="dl-' . $key . '"><div class="kdh"><b>' . ag_h($title) . '</b><em>' . count($rows) . '</em><input type="search" placeholder="Search name, code, company…" data-kfind>'
        . '<button type="button" class="kcsv" data-kcsv="' . ag_h($key . '-' . date('Y-m-d') . '.csv') . '" title="Download as CSV"><i class="fas fa-download"></i></button><button type="button" data-close aria-label="Close">&times;</button></div>';
    if ($rows === []) { return $h . '<p class="kempty"><i class="fas fa-mug-hot"></i>Nobody here for ' . ag_h($range) . '.</p></dialog>'; }
    $h .= '<div class="kbody"><div class="kdb"><table><thead><tr>' . ($people ? '<th>Employee</th><th>Staff type</th><th>Company</th><th>Branch</th><th>Department</th><th>Team</th><th class="r">Note</th>' : '<th>Name</th><th class="r">Employees</th>') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        [$n, $c, $co, $dp, $note] = $r;
        if (!$people) { $h .= '<tr><td><b>' . ag_h($n) . '</b></td><td class="r">' . ag_h((string) $note) . '</td></tr>'; continue; }
        $d = $r[5];
        $ini = mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', $n) ?: [], 0, 2))));
        $h .= '<tr class="krow" tabindex="0" data-d="' . ag_h((string) json_encode($d)) . '"><td><span class="kav">' . ag_h($ini) . '</span><b>' . ag_h($n) . '</b><small>' . ag_h($c) . '</small></td><td><span class="ktag ' . strtolower(ag_h($d['Staff type'])) . '">' . ag_h($d['Staff type'] ?: '—') . '</span></td><td>' . ag_h($co) . '</td><td>' . ag_h($d['Branch']) . '</td><td>' . ag_h($dp) . '</td><td>' . ag_h($d['Team']) . '</td><td class="r">' . ag_h((string) $note) . ' <i class="fas fa-chevron-right kgo2"></i></td></tr>';
    }
    return $h . '</tbody></table></div><aside class="kdet" hidden></aside></div></dialog>';
};

ob_start(); ?>
<form class="dfilters" id="dfilters" method="get" autocomplete="off">
  <div class="df"><label><i class="far fa-calendar"></i> Date</label><select name="d"><?php foreach ($presets as $k => $v): ?><option value="<?= $k ?>"<?= $k === $preset ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="df dcust"<?= $preset === 'custom' ? '' : ' hidden' ?>><label>From</label><input type="date" name="from" value="<?= ag_h($from) ?>" max="<?= ag_h($today) ?>"></div>
  <div class="df dcust"<?= $preset === 'custom' ? '' : ' hidden' ?>><label>To</label><input type="date" name="to" value="<?= ag_h($to) ?>" max="<?= ag_h($today) ?>"></div>
  <div class="df"><label><i class="fas fa-grip"></i> Application</label><select name="app"><?= $opt(array_combine(array_column($allHeld, 'code'), array_map(static fn ($r) => (string) $r['p']['name'], $allHeld)) ?: [], $f['app'], 'All applications') ?></select></div>
  <div class="df"><label><i class="fas fa-person-walking"></i> Staff type</label><select name="type"><?= $opt(['Field' => 'Field agents', 'Office' => 'Office agents'], $f['type'], 'Field & office') ?></select></div>
  <?php foreach (['company' => ['Company', 'fa-building'], 'branch' => ['Branch', 'fa-code-branch'], 'department' => ['Department', 'fa-sitemap'], 'team' => ['Team', 'fa-people-group']] as $k => [$lab, $ic]): if ($org[$k] === []) { continue; } ?>
  <div class="df"><label><i class="fas <?= $ic ?>"></i> <?= $lab ?></label><select name="<?= $k ?>"><?= $opt($org[$k], $f[$k], 'All') ?></select></div>
  <?php endforeach; ?>
  <div class="df"><label><i class="fas fa-user"></i> Employee</label><select name="emp"><?= $opt(array_combine(array_map(static fn ($e) => (string) $e['id'], $pool), array_map(static fn ($e) => trim((string) ($e['name'] ?? '')) . (trim((string) ($e['emp_code'] ?? '')) !== '' ? ' (' . $e['emp_code'] . ')' : ''), $pool)) ?: [], $f['employee'], 'All employees (' . count($pool) . ')') ?></select></div>
  <div class="df dact"><?php if ($qs() !== ''): ?><a class="btn2 ghost" href="./" data-reset><i class="fas fa-xmark"></i>Clear</a><?php endif; ?><noscript><button class="btn2 primary">Apply</button></noscript></div>
</form>
<div class="dsub"><span class="live"><i></i>Live</span> <?= ag_h($range) ?><?= $filtered ? ' &middot; ' . count($chosen) . ' of ' . count($everyone) . ' employees' : '' ?><?= $f['app'] !== '' ? ' &middot; ' . ag_h((string) $held[$f['app']]['p']['name']) : '' ?>
  <span class="dupd">Updated <span id="dupd"><?= ag_h($now->format('h:i:s A')) ?></span> &middot; refreshes every minute</span></div>

<?php if ($notes !== [] && !$filtered && $f['app'] === ''): ?>
<details class="panel attn"<?= count(array_filter($notes, static fn ($n) => $n['level'] === 'red')) > 0 ? ' open' : '' ?>><summary><i class="fas fa-bell" style="color:var(--gold)"></i> Needs attention <em><?= count($notes) ?></em><a href="?v=notifications">All &rarr;</a></summary><?= AmetecsShell::notificationList($notes, 4) ?></details>
<?php endif; ?>

<section class="kpis">
  <?= $tile('Employees', (string) count($chosen), $filtered ? 'in this selection' : 'active', '', 'fa-users', 'employees') ?>
  <?= $tile('Field / Office', $field . ' / ' . $office, count($chosen) - $field - $office > 0 ? (count($chosen) - $field - $office) . ' without a staff type' : 'staff type', '', 'fa-person-walking', 'field') ?>
  <?php if ($working > 0 || isset($tot['present'])): ?>
  <?= $tile('Attendance', $working > 0 ? (int) round(100 * (($tot['present'] ?? 0) + ($tot['leave'] ?? 0)) / $working) . '%' : '-', 'present or on leave', 'green', 'fa-chart-pie', 'present') ?>
  <?php foreach ($combine as $k => $lab): if (!isset($tot[$k])) { continue; } ?><?= $tile($lab, (string) $tot[$k], $oneDay ? ($k === 'present' ? 'of ' . $working . ' working' : ($from === $today ? 'today' : 'that day')) : ($k === 'late' || $k === 'early' ? 'times' : 'person-days'), ['present' => 'green', 'absent' => $tot[$k] > 0 ? 'red' : '', 'leave' => 'blue', 'late' => $tot[$k] > 0 ? 'amber' : '', 'early' => $tot[$k] > 0 ? 'amber' : ''][$k], ['present' => 'fa-user-check', 'absent' => 'fa-user-xmark', 'leave' => 'fa-umbrella-beach', 'late' => 'fa-clock', 'early' => 'fa-person-walking-arrow-right'][$k], $k) ?><?php endforeach; ?>
  <?php endif; ?>
  <?= $tile('Applications', (string) count($held), $f['app'] !== '' ? 'filtered' : 'in your plan', '', 'fa-grip', '', '?v=apps') ?>
  <?= $tile('Not marked', (string) count($lists['notmarked']), 'no attendance ' . ($oneDay ? 'that day' : 'in range'), count($lists['notmarked']) > 0 ? 'amber' : '', 'fa-user-clock', 'notmarked') ?>
  <?= $tile('Companies', (string) count($lists['companies']), count($lists['departments']) . ' departments', '', 'fa-building', 'companies') ?>
  <?= $tile('Departments', (string) count($lists['departments']), 'with employees', '', 'fa-sitemap', 'departments') ?>
  <?= $tile('New joiners', (string) count($lists['joiners']), 'last 30 days', '', 'fa-user-plus', 'joiners') ?>
  <?= $tile('Birthdays', (string) count($lists['birthdays']), 'this month', '', 'fa-cake-candles', 'birthdays') ?>
  <?= $tile('Probation ending', (string) count($lists['probation']), 'next 30 days', count($lists['probation']) > 0 ? 'blue' : '', 'fa-user-check', 'probation') ?>
</section>
<?php foreach (['employees' => 'Employees', 'field' => 'Field / office staff', 'present' => 'Present', 'absent' => 'Absent', 'leave' => 'On leave', 'late' => 'Late arrivals', 'early' => 'Early logouts',
    'notmarked' => 'Not marked', 'companies' => 'Companies', 'departments' => 'Departments', 'joiners' => 'New joiners', 'birthdays' => 'Birthdays this month', 'probation' => 'Probation ending'] as $k => $t) { echo $dialog($k, $t . ' · ' . $range); } ?>
<nav class="qbar"><a href="org.php?s=employees"><i class="fas fa-user-plus"></i>Add employee</a><a href="org.php?s=users"><i class="fas fa-user-shield"></i>Users &amp; permissions</a><a href="?v=apps"><i class="fas fa-grip"></i>My applications</a><a href="?v=plugins"><i class="fas fa-puzzle-piece"></i>Modules &amp; plugins</a><a href="?v=billing"><i class="fas fa-file-invoice"></i>Payments</a><a href="?v=support"><i class="fas fa-headset"></i>Get help</a></nav>

<?php
// MORE INSIGHT (owner, 18 Nov 2026: "add more features on this page and also graphic"): an attendance donut and a
// 7-day trend even on a single day, the workforce make-up (field / office, departments, gender, joiners), people
// moments (birthdays, anniversaries, probation, expiring documents), plan seats and recent activity.
if ($trend === null) {   // one day picked: still show the week leading up to it
    foreach ($held as $code => $h) {
        if ($h['l']['deployment'] !== 'onprem') {
            $trend = AmetecsShell::appStats($tenant, $code, $h['l'], ['from' => (new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d')] + $appFilter)['trend'] ?? null;
            if ($trend !== null) { $trend['title'] = 'Last 7 days'; break; }
        }
    }
}
$donut = static function (array $parts, string $mid, string $sub): string {   // [label => [n, colour]]
    $sum = array_sum(array_column($parts, 0)); $at = 0; $g = [];
    foreach ($parts as [$n, $c]) { $deg = $sum > 0 ? 360 * $n / $sum : 0; $g[] = $c . ' ' . round($at, 2) . 'deg ' . round($at + $deg, 2) . 'deg'; $at += $deg; }
    $lg = '';
    foreach ($parts as $lab => [$n, $c]) { $lg .= '<li><i style="background:' . $c . '"></i>' . ag_h((string) $lab) . '<b>' . (int) $n . '</b><small>' . ($sum > 0 ? round(100 * $n / $sum) . '%' : '') . '</small></li>'; }
    return '<div class="dnut"><div class="dring" style="background:' . ($sum > 0 ? 'conic-gradient(' . implode(',', $g) . ')' : '#eef1f6') . '"><b>' . ag_h($mid) . '</b><small>' . ag_h($sub) . '</small></div><ul>' . $lg . '</ul></div>';
};
$cnt = static function (array $rows, callable $key): array { $o = []; foreach ($rows as $r) { $k = $key($r); $o[$k] = ($o[$k] ?? 0) + 1; } arsort($o); return $o; };
$hbar = static function (array $counts, string $colour, int $top = 7): string {
    $counts = array_slice($counts, 0, $top, true); $mx = max(1, ...array_values($counts) ?: [1]); $h = '';
    foreach ($counts as $lab => $n) { $h .= '<div class="hrow"><span title="' . ag_h((string) $lab) . '">' . ag_h((string) $lab) . '</span><em><i style="width:' . max(3, round(100 * $n / $mx)) . '%;background:' . $colour . '"></i></em><b>' . $n . '</b></div>'; }
    return $h;
};
$deptOf = static fn ($e) => trim((string) ($org['department'][(string) ($e['department_id'] ?? '')] ?? $e['department'] ?? '')) ?: 'Not set';
$genders = $cnt($chosen, static fn ($e) => ucfirst(strtolower(trim((string) ($e['gender'] ?? '')))) ?: 'Not set');
$joiners = [];
for ($k = 5; $k >= 0; $k--) { $joiners[(new DateTimeImmutable(substr($today, 0, 8) . '01'))->modify("-{$k} months")->format('Y-m')] = 0; }
foreach ($chosen as $e) { $m = substr((string) ($e['doj'] ?? ''), 0, 7); isset($joiners[$m]) && $joiners[$m]++; }
$jmax = max(1, ...array_values($joiners));
// people moments in the next 30 days
$moments = [];
$in = static function (string $md) use ($today): ?int {   // days until this month-day comes round again
    if (!preg_match('/^\d{2}-\d{2}$/', $md)) { return null; }
    $y = (int) substr($today, 0, 4); $d = strtotime($y . '-' . $md); $d = $d !== false && date('Y-m-d', $d) < $today ? strtotime(($y + 1) . '-' . $md) : $d;
    return $d === false ? null : (int) round(($d - strtotime($today)) / 86400);
};
$nm = static fn ($e) => trim((string) ($e['name'] ?? '')) ?: 'Employee #' . $e['id'];
foreach ($chosen as $e) {
    $dob = (string) ($e['dob'] ?? ''); $doj = (string) ($e['doj'] ?? '');
    if (strlen($dob) >= 10 && ($n = $in(substr($dob, 5, 5))) !== null && $n <= 30) { $moments[] = [$n, 'fa-cake-candles', 'pink', $nm($e), 'Birthday']; }
    if (strlen($doj) >= 10 && substr($doj, 0, 4) < substr($today, 0, 4) && ($n = $in(substr($doj, 5, 5))) !== null && $n <= 30) {
        $yrs = (int) substr($today, 0, 4) - (int) substr($doj, 0, 4) + ($n > 0 && substr($doj, 5, 5) < substr($today, 5, 5) ? 1 : 0);
        $moments[] = [$n, 'fa-award', 'gold', $nm($e), $yrs . ' year' . ($yrs === 1 ? '' : 's') . ' with you'];
    }
    foreach (['probation_end' => ['fa-user-check', 'blue', 'Probation ends'], 'passport_expiry' => ['fa-passport', 'red', 'Passport expires'], 'dra_expiry' => ['fa-id-card', 'red', 'DRA certificate expires'], 'pcc_expiry' => ['fa-file-shield', 'red', 'Police verification expires']] as $fk => [$ic, $tn, $lab]) {
        $v = substr((string) ($e[$fk] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && $v <= (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d') && $v >= (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d')) {
            $n = (int) round((strtotime($v) - strtotime($today)) / 86400);
            $moments[] = [$n, $ic, $tn, $nm($e), $lab . ($n < 0 ? ' (overdue)' : '')];
        }
    }
}
usort($moments, static fn ($a, $b) => $a[0] <=> $b[0]);
$acts = array_slice(array_values(array_filter(AmetecsData::activity($tenant, 100), static fn ($r) => !in_array($r['what'], ['Signed in', 'Signed out'], true))), 0, 5);
$cols = ['Present' => '#c9a24c', 'On leave' => '#1f3a5f', 'Absent' => '#8b1e2d'];
?>
<section class="ins3">
  <div class="chart"><h3><i class="fas fa-chart-pie"></i>Attendance <span class="muted2"><?= ag_h($range) ?></span></h3>
    <?= $donut(['Present' => [$tot['present'] ?? 0, '#c9a24c'], 'On leave' => [$tot['leave'] ?? 0, '#1f3a5f'], 'Absent' => [$tot['absent'] ?? 0, '#8b1e2d']],
        $working > 0 ? round(100 * (($tot['present'] ?? 0) + ($tot['leave'] ?? 0)) / $working) . '%' : '-', $working > 0 ? 'attendance' : 'no working day') ?>
    <div class="mini2"><span><i class="fas fa-clock"></i>Late <b><?= (int) ($tot['late'] ?? 0) ?></b></span><span><i class="fas fa-person-walking-arrow-right"></i>Early out <b><?= (int) ($tot['early'] ?? 0) ?></b></span></div></div>
  <div class="chart wide"><?php if ($trend !== null): $max = max(1, ...array_map(static fn ($i) => array_sum(array_column($trend['sets'], $i)), array_keys($trend['labels']))); ?>
    <h3><i class="fas fa-chart-column"></i><?= ag_h($trend['title']) ?><span class="legend2"><?php foreach (array_keys($trend['sets']) as $sn): ?><em><i style="background:<?= $cols[$sn] ?? '#94a3b8' ?>"></i><?= ag_h($sn) ?></em><?php endforeach; ?></span></h3>
    <div class="vbars"><?php foreach ($trend['labels'] as $i => $d): $tot2 = array_sum(array_column($trend['sets'], $i)); ?>
      <div class="vb" title="<?= ag_h(date('D d M', (int) strtotime($d)) . ': ' . implode(', ', array_map(static fn ($sn) => $sn . ' ' . $trend['sets'][$sn][$i], array_keys($trend['sets'])))) ?>"><div class="vbs" style="height:<?= round(100 * $tot2 / $max) ?>%"><?php foreach ($trend['sets'] as $sn => $vals): if ((int) $vals[$i] === 0) { continue; } ?><i style="flex:<?= (int) $vals[$i] ?>;background:<?= $cols[$sn] ?? '#94a3b8' ?>"></i><?php endforeach; ?></div><span><?= count($trend['labels']) <= 16 || $i % 3 === 0 ? ag_h(count($trend['labels']) <= 7 ? date('D', (int) strtotime($d)) : date('d', (int) strtotime($d))) : '' ?></span></div>
    <?php endforeach; ?></div>
  <?php else: ?><h3><i class="fas fa-chart-column"></i>Attendance trend</h3><div class="emp3"><i class="fas fa-chart-column"></i>The day-by-day chart appears once an attendance app reports figures.</div><?php endif; ?></div>
</section>

<?php if ($break !== []): $bmax = max(1, ...array_values(array_map('array_sum', $break))); ?>
<section class="charts"><div class="chart"><h3><i class="fas fa-layer-group"></i>By <?= $dim ?><span class="legend2"><em><i style="background:#c9a24c"></i>Present</em><em><i style="background:#1f3a5f"></i>Leave</em><em><i style="background:#8b1e2d"></i>Absent</em></span></h3>
  <div class="hbars"><?php foreach ($break as $label => $b): $sumb = array_sum($b); ?>
    <div class="hb"><span title="<?= ag_h((string) $label) ?>"><?= ag_h((string) $label) ?></span><div><div class="hbs" style="width:<?= max(2, round(100 * $sumb / $bmax)) ?>%"><?php foreach (['present' => '#c9a24c', 'leave' => '#1f3a5f', 'absent' => '#8b1e2d'] as $k => $c): if ($b[$k] === 0) { continue; } ?><i style="flex:<?= $b[$k] ?>;background:<?= $c ?>" title="<?= ucfirst($k) . ' ' . $b[$k] ?>"></i><?php endforeach; ?></div></div>
      <b><?= $sumb > 0 ? round(100 * ($b['present'] + $b['leave']) / $sumb) . '%' : '-' ?></b></div>
  <?php endforeach; ?></div></div></section>
<?php endif; ?>

<h2 class="dsec"><i class="fas fa-users"></i>Workforce<span><?= count($chosen) ?> employees<?= $filtered ? ' in this selection' : '' ?></span></h2>
<?php if ($chosen === []): ?>
<div class="chart emp3 big"><i class="fas fa-user-plus"></i><div><b>No employees <?= $filtered ? 'match these filters' : 'yet' ?></b><br><?= $filtered ? 'Clear a filter to see more.' : 'Add your people once; every application uses the same list.' ?></div><?php if (!$filtered): ?><a class="btn2 primary" href="org.php?s=employees"><i class="fas fa-plus"></i>Add employees</a><?php endif; ?></div>
<?php else: ?>
<section class="ins4">
  <div class="chart"><h3><i class="fas fa-person-walking"></i>Staff type</h3><?= $donut(['Field' => [$field, '#c9a24c'], 'Office' => [$office, '#1f3a5f'], 'Not set' => [count($chosen) - $field - $office, '#cbd5e1']], (string) count($chosen), 'employees') ?></div>
  <div class="chart"><h3><i class="fas fa-venus-mars"></i>Gender</h3><?php $gc = ['Male' => '#c9a24c', 'Female' => '#1f3a5f']; $gp = []; foreach ($genders as $g => $n) { $gp[$g] = [$n, $gc[$g] ?? '#94a3b8']; } ?><?= $donut($gp, (string) count($chosen), 'employees') ?></div>
  <div class="chart"><h3><i class="fas fa-sitemap"></i>By department</h3><div class="hlist"><?= $hbar($cnt($chosen, $deptOf), 'linear-gradient(90deg,#d4a65a,#b8893b)') ?></div></div>
  <div class="chart"><h3><i class="fas fa-user-plus"></i>New joiners<span class="muted2">last 6 months</span></h3>
    <div class="jbars"><?php foreach ($joiners as $m => $n): ?><div title="<?= ag_h(date('M Y', (int) strtotime($m . '-01')) . ': ' . $n) ?>"><b><?= $n ?: '' ?></b><i style="height:<?= max(3, round(100 * $n / $jmax)) ?>%"></i><span><?= ag_h(date('M', (int) strtotime($m . '-01'))) ?></span></div><?php endforeach; ?></div></div>
</section>
<?php endif; ?>

<h2 class="dsec"><i class="fas fa-grip"></i>Applications<span><?= count($held) ?> in your plan</span></h2>

<?php $later = []; foreach ($held as $code => $h): $p = $h['p']; $s = $h['s'];
    $extra = array_values(array_filter((array) ($s['tiles'] ?? []), static fn ($t) => !isset($combine[$t['key']]) && $t['key'] !== 'employees' && $t['key'] !== 'rate'));
    if (!$h['live'] && empty($s['tiles']) && $h['l']['deployment'] !== 'onprem') { $later[] = (string) $p['name']; continue; } ?>
  <section class="dapp" style="--c:<?= ag_h((string) $p['color']) ?>">
    <header><div class="logo"><?= $logoOf($code, $p) ?></div><span class="muted2 dmeta"><?= ag_h((string) ($p['what'] ?: '')) ?></span>
      <?php if ($h['live']): ?><a class="btn2 primary" href="<?= ag_h((string) $p['open']) ?>">Open <i class="fas fa-arrow-right"></i></a><?php elseif ($h['l']['deployment'] === 'onprem'): ?><span class="lp lp-grey">On your server</span><?php endif; ?></header>
    <?php if (!empty($s['reported'])): ?>
      <div class="ftiles"><?php foreach ($s['tiles'] as $t): ?><div class="ft <?= ag_h($t['tone']) ?>"><span><?= ag_h($t['label']) ?></span><b><?= ag_h($t['value']) ?></b><?= $t['sub'] !== '' ? '<small>' . ag_h($t['sub']) . '</small>' : '' ?></div><?php endforeach; ?></div>
      <p class="dnote">Today's figures as your server reported them at <?= ag_h(date('d M H:i', (int) strtotime((string) $s['at']))) ?>. Filters do not apply to on-premise figures.</p>
    <?php elseif ($extra !== []): ?>
      <div class="ftiles"><?php foreach ($extra as $t): ?><div class="ft <?= ag_h($t['tone']) ?>"><span><?= ag_h($t['label']) ?></span><b><?= ag_h($t['value']) ?></b><?= $t['sub'] !== '' ? '<small>' . ag_h($t['sub']) . '</small>' : '' ?></div><?php endforeach; ?></div>
    <?php elseif (!empty($s['tiles'])): ?><p class="dnote">Included in the figures above.</p>
    <?php else: ?><p class="dnote"><?= !empty($s['error']) ? ag_h($s['error']) : 'A summary appears here once ' . ag_h((string) $p['name']) . ' reports its figures.' ?></p><?php endif; ?>
    <?php if (!empty($s['list']['rows'])): ?><ul class="dlist"><li class="dl-h"><?= ag_h($s['list']['title']) ?></li><?php foreach ($s['list']['rows'] as $lr): ?><li><?= ag_h($lr['text']) ?><small><?= ag_h($lr['sub']) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (!empty($s['range']) && $s['range'][0] !== $from): ?><p class="dnote">Shows <?= ag_h(date('d M', (int) strtotime($s['range'][0]))) ?> onwards: at most 62 days at a time.</p><?php endif; ?>
  </section>
<?php endforeach; ?>
<?php if ($later !== []): ?><p class="dnote later"><i class="fas fa-hourglass-half"></i> Also in your plan, arriving on AmetecsGlobal soon: <?= ag_h(implode(', ', $later)) ?>.</p><?php endif; ?>
<?php if ($held === []): ?><div class="lic-t"><div class="empty2" style="padding:30px">No applications are switched on for your company yet. <a href="?v=support">Contact Ametecs</a>.</div></div><?php endif; ?>

<section class="ins3 eq dbot">
  <div class="chart"><h3><i class="fas fa-calendar-day"></i>Coming up<span class="muted2">next 30 days</span></h3>
    <?php if ($moments === []): ?><div class="emp3"><i class="fas fa-mug-hot"></i>No birthdays, anniversaries, probation ends or expiring documents in the next 30 days.</div>
    <?php else: ?><ul class="mom"><?php foreach (array_slice($moments, 0, 7) as [$n, $ic, $tn, $who, $what]): ?><li><span class="mi <?= $tn ?>"><i class="fas <?= $ic ?>"></i></span><div><b><?= ag_h($who) ?></b><small><?= ag_h($what) ?></small></div><em><?= $n === 0 ? 'Today' : ($n === 1 ? 'Tomorrow' : ($n < 0 ? -$n . 'd ago' : 'in ' . $n . 'd')) ?></em></li><?php endforeach; ?></ul><?php endif; ?></div>
  <div class="chart"><h3><i class="fas fa-key"></i>Your plan<a class="more2" href="?v=licences">Licences <i class="fas fa-arrow-right"></i></a></h3>
    <ul class="seatl"><?php foreach ($held as $code => $h): $l = $h['l']; $seats = (int) $l['seats']; $usedN = $code === 'smartprs3' ? count($everyone) : null; $until = (string) ($l['valid_until'] ?? ''); $left = $until !== '' ? (int) floor((strtotime($until) - strtotime($today)) / 86400) : null; ?>
      <li><div class="logo"><?= $logoOf($code, $h['p']) ?></div><div class="sinfo"><span><?= $usedN !== null ? $usedN . ' / ' : '' ?><?= $seats > 0 ? $seats : '&infin;' ?> <?= ag_h((string) $h['p']['unit']) ?><small><?= $left === null ? 'no end date' : ($left < 0 ? 'ended' : $left . ' days left') ?></small></span>
        <?php if ($usedN !== null && $seats > 0): $pc = min(100, (int) round(100 * $usedN / $seats)); ?><em class="sbar"><i style="width:<?= $pc ?>%;background:<?= $pc >= 90 ? '#dc2626' : ($pc >= 75 ? '#d97706' : '#16a34a') ?>"></i></em><?php endif; ?></div></li>
    <?php endforeach; ?></ul></div>
  <div class="chart">    <h3><i class="fas fa-clock-rotate-left"></i>Recent activity<a class="more2" href="?v=activity">All <i class="fas fa-arrow-right"></i></a></h3>
    <?php if ($acts === []): ?><div class="emp3 sm">No changes yet.</div><?php else: ?><ul class="ract"><?php foreach ($acts as $r): ?><li><b><?= ag_h($r['what']) ?></b><small><?= ag_h($r['who']) ?> &middot; <time data-at="<?= ag_h($r['at']) ?>"><?= ag_h($r['at']) ?></time></small></li><?php endforeach; ?></ul><?php endif; ?></div>
</section>
<?php
$dashHtml = (string) ob_get_clean();
if (isset($_GET['frag'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $dashHtml;
    exit;
}
