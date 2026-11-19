<?php
/**
 * REPORTS (owner, 19 Nov 2026: "all reports see and download on AmetecsGlobal, with filters, multiple filters and app wise").
 * One page for every app's reports. AmetecsGlobal's own (attendance from every source, the employee directory) plus what
 * each licensed app answers through plugins/<app>/ag-report.php. Every row is tied to an AmetecsGlobal employee, so one
 * set of filters (dates, company, branch, department, team, role, employee, status) works for every app.
 * Team leads and managers see only their own people ($p3scope). ?csv=1 downloads exactly what the filters show.
 * Needs from index.php: $pdo, $tenant, $p3scope.
 */
declare(strict_types=1);

$today = AmetecsAccess::today();
$G = static fn (string $k): array => array_values(array_filter(array_map('strval', (array) ($_GET[$k] ?? [])), static fn ($v) => $v !== ''));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-d', strtotime($today . ' -6 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today;
$from > $to && [$from, $to] = [$to, $from];

// ---- what can be reported: AmetecsGlobal's own, then every licensed, installed app that answers ------------------
$catalog = ['ametecsglobal' => ['name' => 'AmetecsGlobal (all sources)', 'reports' => [
    'att-daily' => 'Attendance by day (every source)', 'att-summary' => 'Attendance summary by employee', 'directory' => 'Employee directory']]];
$runners = [];
foreach (AmetecsAccess::plugins(true) as $code => $p) {
    $file = __DIR__ . '/../plugins/' . $code . '/ag-report.php';
    if (is_file($file) && AmetecsAccess::state($tenant, $code) === 'on' && AmetecsLicences::installed(AmetecsLicences::row($tenant, $code))) {
        try { $h = require $file; $catalog[$code] = ['name' => (string) $p['name'], 'reports' => $h['reports']]; $runners[$code] = $h['run']; } catch (\Throwable) {}
    }
}
$app = isset($catalog[$_GET['app'] ?? '']) ? (string) $_GET['app'] : 'ametecsglobal';
$key = isset($catalog[$app]['reports'][$_GET['rep'] ?? '']) ? (string) $_GET['rep'] : (string) array_key_first($catalog[$app]['reports']);

// ---- the people, and the filters on them ----------------------------------------------------------------------------
$everyone = (new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant)->all('employees');
$levels = AmetecsRoles::levels($everyone);
$names = static function (string $t) use ($pdo, $tenant): array {
    try { $s = $pdo->prepare("SELECT id, name FROM `$t` WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name"); $s->execute([$tenant]); return $s->fetchAll(\PDO::FETCH_KEY_PAIR); } catch (\Throwable) { return []; }
};
$org = ['company' => $names('companies'), 'branch' => $names('branches'), 'department' => $names('departments'), 'team' => $names('teams')];
$col = ['company' => 'employer_company_id', 'branch' => 'branch_id', 'department' => 'department_id', 'team' => 'team_id'];
$f = ['company' => $G('company'), 'branch' => $G('branch'), 'department' => $G('department'), 'team' => $G('team'), 'role' => $G('role'), 'emp' => $G('emp')];
$status = in_array($_GET['status'] ?? '', ['all', 'left'], true) ? (string) $_GET['status'] : 'active';
$q = trim((string) ($_GET['q'] ?? ''));
$people = [];
foreach ($everyone as $e) {
    $id = (string) $e['id'];
    if ($p3scope !== null && !isset($p3scope[$id])) { continue; }   // team leads and managers: only their own people
    $people[$id] = $e;
}
uasort($people, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
$roleOf = static fn (string $id): string => $levels[$id] ?? 'employee';
$keep = array_filter($people, static function (array $e) use ($f, $col, $status, $roleOf, $q): bool {
    foreach ($col as $k => $c) { if ($f[$k] !== [] && !in_array((string) ($e[$c] ?? ''), $f[$k], true)) { return false; } }
    if ($f['role'] !== [] && !in_array($roleOf((string) $e['id']), $f['role'], true)) { return false; }
    if ($f['emp'] !== [] && !in_array((string) $e['id'], $f['emp'], true)) { return false; }
    $act = strtolower((string) ($e['status'] ?? 'active')) === 'active' && !\SmartPRS\Surface\Modules::isRetired($e);
    if (($status === 'active' && !$act) || ($status === 'left' && $act)) { return false; }
    return $q === '' || str_contains(strtolower(($e['name'] ?? '') . ' ' . ($e['emp_code'] ?? '')), strtolower($q));
});
$filtered = array_filter($f) !== [] || $q !== '' || $status !== 'active';

// ---- run the report ------------------------------------------------------------------------------------------------
$problem2 = null;
$cols = [];
$rows = [];   // [employee id|null, ...cells]
if ($app === 'ametecsglobal') {
    if ($key === 'directory') {
        $cols = ['Type', 'Reports to', 'Status', 'Joined', 'Email', 'Mobile'];
        $nameOf = array_column($everyone, 'name', 'id');
        foreach ($keep as $id => $e) {
            $rows[] = [$id, (string) ($e['type'] ?? ''), (string) ($nameOf[(string) ($e['reporting_manager_id'] ?? '')] ?? ''),
                ucfirst((string) ($e['status'] ?? 'active')), (string) ($e['doj'] ?? ''), (string) ($e['email'] ?? ''), (string) ($e['mobile'] ?? '')];
        }
    } else {
        $byCode = [];
        foreach ($people as $id => $e) { $byCode[(string) ($e['emp_code'] ?? '')] = (string) $id; }
        $days = [];
        try {
            $s = $pdo->prepare("SELECT emp_code, log_date, punch_at, direction, COALESCE(NULLIF(source, ''), 'web') source FROM attendance_logs WHERE tenant_id = ? AND log_date BETWEEN ? AND ? ORDER BY punch_at");
            $s->execute([$tenant, $from, $to]);
            foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $id = $byCode[(string) $p['emp_code']] ?? null;
                if ($id === null) { continue; }
                $d = &$days[$id][(string) $p['log_date']];
                $t = substr((string) $p['punch_at'], 11, 5);
                $d['in'] ??= $p['direction'] === 'in' ? $t : null;
                $p['direction'] === 'out' && $d['out'] = $t;
                $d['src'][['push' => 'Biometric', 'pull' => 'Biometric', 'sbb' => 'Biometric', 'qr' => 'QR', 'qronex' => 'QRONEX', 'smartept' => 'SmartEPT PC', 'caller' => 'ROVEXY', 'web' => 'Web/app', 'mobile' => 'App', 'sample' => 'Sample'][$p['source']] ?? ucfirst((string) $p['source'])] = true;
                $d['n'] = ($d['n'] ?? 0) + 1;
                unset($d);
            }
        } catch (\Throwable $x) {
            $problem2 = 'Attendance could not be read: ' . $x->getMessage();
        }
        $mins = static fn (?string $a, ?string $b): int => $a && $b ? max(0, (int) ((strtotime($b) - strtotime($a)) / 60)) : 0;
        $hm = static fn (int $m): string => sprintf('%d:%02d', intdiv($m, 60), $m % 60);
        $late = static fn (?string $in): bool => $in !== null && $in > '09:15';   // ponytail: one 09:15 cut-off; per-shift times when shifts are assigned
        if ($key === 'att-summary') {
            $cols = ['Days present', 'Late days', 'Avg first in', 'Total hours', 'Avg hours/day'];
            foreach ($keep as $id => $e) {
                $ds = $days[(string) $id] ?? [];
                $ins = array_filter(array_column($ds, 'in'));
                $tot = array_sum(array_map(static fn ($d) => $mins($d['in'] ?? null, $d['out'] ?? null), $ds));
                $avgIn = $ins === [] ? '' : date('H:i', (int) (array_sum(array_map(static fn ($t) => strtotime('2000-01-01 ' . $t), $ins)) / count($ins)));
                $rows[] = [$id, count($ds), count(array_filter($ins, $late)), $avgIn, $hm($tot), $ds === [] ? '' : $hm(intdiv($tot, count($ds)))];
            }
        } else {
            $cols = ['Date', 'First in', 'Last out', 'Hours', 'Late', 'Punches', 'Source'];
            foreach ($keep as $id => $e) {
                foreach ($days[(string) $id] ?? [] as $day => $d) {
                    $rows[] = [$id, $day, (string) ($d['in'] ?? ''), (string) ($d['out'] ?? ''), $hm($mins($d['in'] ?? null, $d['out'] ?? null)), $late($d['in'] ?? null) ? 'Late' : '', (int) $d['n'], implode(', ', array_keys($d['src']))];
                }
            }
            usort($rows, static fn ($a, $b) => strcmp((string) $b[1], (string) $a[1]) ?: strcasecmp((string) ($people[$a[0]]['name'] ?? ''), (string) ($people[$b[0]]['name'] ?? '')));
        }
    }
} else {
    try {
        $out = ($runners[$app])($tenant, $key, $from, $to);
        $cols = $out['cols'];
        foreach ($out['rows'] as $r) {
            $id = $r[0] === null ? null : (string) $r[0];
            if ($id === null ? ($filtered || $p3scope !== null) : !isset($keep[$id])) { continue; }   // only people inside the filters / the lead's team
            $rows[] = $r;
        }
    } catch (\Throwable $x) {
        $problem2 = $catalog[$app]['name'] . ' could not answer this report: ' . $x->getMessage();
    }
}

// every row starts with the AmetecsGlobal employee
$head = ['Emp code', 'Name', 'Company', 'Branch', 'Department', 'Team', 'Role'];
$lead = static function (?string $id) use ($people, $org, $col, $roleOf): array {
    $e = $id === null ? null : ($people[$id] ?? null);
    if ($e === null) { return ['', '(not linked to an employee)', '', '', '', '', '']; }
    $o = static fn (string $k) => (string) ($org[$k][(string) ($e[$col[$k]] ?? '')] ?? '');
    return [(string) ($e['emp_code'] ?? ''), (string) ($e['name'] ?? ''), $o('company'), $o('branch'), $o('department'), $o('team'), AmetecsRoles::LABELS[$roleOf($id)] ?? 'Employee'];
};
$title = $catalog[$app]['name'] . ' · ' . $catalog[$app]['reports'][$key];

if (isset($_GET['csv'])) {   // DOWNLOAD: everything the filters show, as a spreadsheet file (opens in Excel)
    AmetecsData::log((string) ($user['email'] ?? ''), 'Downloaded report', $tenant, $title . ' ' . $from . ' to ' . $to . ' (' . count($rows) . ' rows)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9-]+/', '-', $app . '-' . $key) . '-' . $from . '-to-' . $to . '.csv"');
    header('Cache-Control: no-store');
    $h = fopen('php://output', 'w');
    fwrite($h, "\xEF\xBB\xBF");   // so Excel reads names in any language
    $safe = static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;   // no formulas from data
    fputcsv($h, array_merge($head, $cols));
    foreach ($rows as $r) { fputcsv($h, array_map($safe, array_merge($lead($r[0] === null ? null : (string) $r[0]), array_slice($r, 1)))); }
    fclose($h);
    exit;
}

$show = array_slice($rows, 0, 500);
$qsBase = static fn (array $over = []): string => '?' . http_build_query(array_merge(['v' => 'reports', 'app' => $app, 'rep' => $key, 'from' => $from, 'to' => $to, 'status' => $status, 'q' => $q] + $f, $over));
$multi = static function (string $name, string $label, array $opts, array $on): string {
    $h = '<details class="rpm"><summary>' . ag_h($label) . ($on !== [] ? ' <em>' . count($on) . '</em>' : '') . '</summary><div>';
    if (count($opts) > 8) { $h .= '<input type="search" placeholder="Find…" data-rpfind>'; }
    foreach ($opts as $v => $t) { $h .= '<label><input type="checkbox" name="' . $name . '[]" value="' . ag_h((string) $v) . '"' . (in_array((string) $v, $on, true) ? ' checked' : '') . '> ' . ag_h((string) $t) . '</label>'; }
    return $h . ($opts === [] ? '<span class="muted2">None set up</span>' : '') . '</div></details>';
};
?>
  <div class="pgh"><div><h1>Reports</h1><p>Every app's reports in one place. Pick the app and the report, narrow it with as many filters as you need, then view or download.<?= $p3scope !== null ? ' You see your own team only.' : '' ?></p></div></div>
  <form class="rpf" method="get">
    <input type="hidden" name="v" value="reports">
    <div class="rpapps"><?php foreach ($catalog as $c => $x): ?><a href="<?= ag_h($qsBase(['app' => $c, 'rep' => array_key_first($x['reports'])])) ?>" class="<?= $c === $app ? 'on' : '' ?>"><?= ag_h($x['name']) ?></a><?php endforeach; ?></div>
    <input type="hidden" name="app" value="<?= ag_h($app) ?>">
    <div class="rprow">
      <label>Report<select name="rep" onchange="this.form.submit()"><?php foreach ($catalog[$app]['reports'] as $k => $t): ?><option value="<?= ag_h($k) ?>"<?= $k === $key ? ' selected' : '' ?>><?= ag_h($t) ?></option><?php endforeach; ?></select></label>
      <label>From<input type="date" name="from" value="<?= ag_h($from) ?>" max="<?= ag_h($today) ?>"></label>
      <label>To<input type="date" name="to" value="<?= ag_h($to) ?>" max="<?= ag_h($today) ?>"></label>
      <div class="rpq"><?php foreach (['Today' => [$today, $today], '7 days' => [date('Y-m-d', strtotime($today . ' -6 days')), $today], '30 days' => [date('Y-m-d', strtotime($today . ' -29 days')), $today], 'This month' => [substr($today, 0, 8) . '01', $today], 'Last month' => [date('Y-m-01', strtotime(substr($today, 0, 8) . '01 -1 month')), date('Y-m-t', strtotime(substr($today, 0, 8) . '01 -1 month'))]] as $t => [$a, $b]): ?><a href="<?= ag_h($qsBase(['from' => $a, 'to' => $b])) ?>" class="<?= $from === $a && $to === $b ? 'on' : '' ?>"><?= $t ?></a><?php endforeach; ?></div>
    </div>
    <div class="rprow">
      <?= $multi('company', 'Company', $org['company'], $f['company']) ?>
      <?= $multi('branch', 'Branch', $org['branch'], $f['branch']) ?>
      <?= $multi('department', 'Department', $org['department'], $f['department']) ?>
      <?= $multi('team', 'Team', $org['team'], $f['team']) ?>
      <?= $multi('role', 'Role', ['manager' => 'Manager', 'team_lead' => 'Team Lead', 'employee' => 'Employee'], $f['role']) ?>
      <?= $multi('emp', 'Employees', array_map(static fn ($e) => $e['name'] . ' · ' . ($e['emp_code'] ?? ''), $people), $f['emp']) ?>
      <label class="rps">Status<select name="status"><?php foreach (['active' => 'Active', 'left' => 'Left', 'all' => 'All'] as $k => $t): ?><option value="<?= $k ?>"<?= $k === $status ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <input type="search" name="q" value="<?= ag_h($q) ?>" placeholder="Name or code…" class="rpsearch">
      <button class="btn2 pri" type="submit"><i class="fas fa-filter"></i> Show</button>
      <?php if ($filtered): ?><a class="btn2" href="<?= ag_h('?' . http_build_query(['v' => 'reports', 'app' => $app, 'rep' => $key, 'from' => $from, 'to' => $to])) ?>">Clear filters</a><?php endif; ?>
    </div>
  </form>
  <?php if ($problem2 !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem2) ?></span></div><?php endif; ?>
  <div class="lic-t rpt">
    <div class="rph"><div><b><?= ag_h($title) ?></b><span class="muted2"><?= ag_h(date('d M Y', strtotime($from))) ?> – <?= ag_h(date('d M Y', strtotime($to))) ?> · <?= count($rows) ?> rows · <?= count($keep) ?> employees in filter</span></div>
      <a class="btn2 pri" href="<?= ag_h($qsBase(['csv' => 1])) ?>"><i class="fas fa-download"></i> Download (Excel / CSV)</a></div>
    <?php if ($rows === []): ?><div class="empty2" style="padding:30px">Nothing for these dates and filters.<?= $app !== 'ametecsglobal' ? ' ' . ag_h($catalog[$app]['name']) . ' copies people the next time an administrator opens it.' : '' ?></div>
    <?php else: ?>
    <div class="rpscroll"><table><thead><tr><?php foreach (array_merge($head, $cols) as $c): ?><th><?= ag_h((string) $c) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($show as $r): ?><tr><?php foreach (array_merge($lead($r[0] === null ? null : (string) $r[0]), array_slice($r, 1)) as $i => $c): ?><td<?= $i === 1 ? ' class="nm"' : '' ?>><?= ag_h((string) $c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if (count($rows) > 500): ?><p class="muted2" style="padding:10px 14px;margin:0">Showing the first 500 of <?= count($rows) ?> rows. Download for all of them.</p><?php endif; ?>
    <?php endif; ?>
  </div>
  <script>document.querySelectorAll('[data-rpfind]').forEach(function(i){i.addEventListener('input',function(){var t=i.value.toLowerCase();i.parentNode.querySelectorAll('label').forEach(function(l){l.style.display=l.textContent.toLowerCase().indexOf(t)<0?'none':''})})});
  document.addEventListener('click',function(e){document.querySelectorAll('details.rpm[open]').forEach(function(d){if(!d.contains(e.target))d.open=false})});</script>
