<?php
/**
 * APP ACCESS (Organization Setup -> Apps -> App access): which employees use which app. Included by core/org.php
 * (needs $pdo, $session, $user); fills $body. Rules: AmetecsAppAccess in core/setup.php.
 * Filters are SmartPRS3's employee-directory filters, read from the central tables (owner, 17 Nov 2026).
 */
declare(strict_types=1);

$tenant = (string) ($user['tenant_id'] ?? '');
$isAdmin = strtolower(trim((string) ($user['role'] ?? ''))) === 'administrator';
$apps = array_filter(AmetecsAccess::plugins(), static fn (array $p, string $code): bool => AmetecsAccess::state($tenant, $code) === 'on', ARRAY_FILTER_USE_BOTH);
$app = (string) ($_GET['app'] ?? array_key_first($apps) ?? '');
$msg = null;
$bad = false;

// The organisation, from the central tables.
$list = static function (string $table, string $order = 'name') use ($pdo, $tenant): array {
    try {
        $q = $pdo->prepare("SELECT * FROM `{$table}` WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY `{$order}`");
        $q->execute([$tenant]);
        return array_column($q->fetchAll(\PDO::FETCH_ASSOC), null, 'id');
    } catch (\Throwable) {
        return [];
    }
};
$org = ['company' => $list('companies'), 'branch' => $list('branches'), 'department' => $list('departments'), 'team' => $list('teams')];
$desig = $list('designations');
$levelName = ['company' => 'Companies', 'branch' => 'Branches', 'department' => 'Departments', 'team' => 'Teams'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($apps[$app])) {
    if (!$session->verify((string) ($_POST['csrf'] ?? ''))) {
        [$msg, $bad] = ['This page had expired. Please try again.', true];
    } elseif (!$isAdmin) {
        [$msg, $bad] = ['Only an administrator can change app access.', true];
    } else {
        $to = static fn (string $v): ?bool => $v === 'on' ? true : ($v === 'off' ? false : null);
        if (($_POST['do'] ?? '') === 'levels') {
            foreach ((array) ($_POST['v'] ?? []) as $level => $vals) {
                foreach ((array) $vals as $id => $v) {
                    AmetecsAppAccess::set($tenant, $app, (string) $level, [(string) $id], $to((string) $v));
                }
            }
            $msg = 'Saved. Employees follow these unless set one by one below.';
        } elseif (($_POST['do'] ?? '') === 'employees') {
            $ids = array_map('strval', (array) ($_POST['ids'] ?? []));
            if ($ids === []) {
                [$msg, $bad] = ['Tick at least one employee first.', true];
            } else {
                AmetecsAppAccess::set($tenant, $app, 'employee', $ids, $to((string) ($_POST['set'] ?? '')));
                $msg = count($ids) . ' employee(s) updated.';
            }
        }
        AmetecsData::log((string) ($user['email'] ?? ''), 'App access changed', $tenant, $app . ': ' . $msg);
    }
}

// Employees, with SmartPRS3's directory filters.
$f = [];
foreach (['company' => 'employer_company_id', 'branch' => 'branch_id', 'department' => 'department_id', 'designation' => 'designation_id',
    'team' => 'team_id', 'type' => 'type', 'staff' => 'employment_type', 'status' => 'status'] as $key => $col) {
    if (($_GET[$key] ?? '') !== '') { $f[$col] = (string) $_GET[$key]; }
}
$search = trim((string) ($_GET['q'] ?? ''));
$emps = [];
$distinct = ['type' => [], 'employment_type' => [], 'status' => []];
try {
    $q = $pdo->prepare('SELECT * FROM employees WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
    $q->execute([$tenant]);
    $emps = $q->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable) {
}
foreach ($emps as $e) {
    foreach ($distinct as $col => $_) { if ((string) ($e[$col] ?? '') !== '') { $distinct[$col][(string) $e[$col]] = true; } }
}
$rules = AmetecsAppAccess::rules($tenant, $app);
$shown = array_values(array_filter($emps, static function (array $e) use ($f, $search): bool {
    foreach ($f as $col => $v) { if ((string) ($e[$col] ?? '') !== $v) { return false; } }
    return $search === '' || stripos((string) $e['name'] . ' ' . (string) ($e['emp_code'] ?? '') . ' ' . (string) ($e['email'] ?? ''), $search) !== false;
}));
$onCount = count(array_filter($emps, static fn (array $e): bool => AmetecsAppAccess::resolve($rules, $e)[0]));
$seats = AmetecsAccess::seats($tenant, $app);

$sel = static function (string $name, array $opts, string $cur, string $all = 'All'): string {
    $h = '<select name="' . ag_h($name) . '"><option value="">' . ag_h($all) . '</option>';
    foreach ($opts as $v => $label) { $h .= '<option value="' . ag_h((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . ag_h((string) $label) . '</option>'; }
    return $h . '</select>';
};
$names = static fn (array $rows): array => array_map(static fn (array $r): string => (string) $r['name'], $rows);
$csrf = '<input type="hidden" name="csrf" value="' . ag_h($session->token()) . '">';

ob_start();
?>
<h1>App access</h1>
<p class="sub">Choose which employees use each app. Switch an app on or off for a company, branch, department or team and its employees follow; set any employee on its own below. Employees switched off are hidden in that app and use none of its seats.</p>
<?php if ($msg !== null): ?><div class="msg<?= $bad ? ' bad' : '' ?>"><?= ag_h($msg) ?></div><?php endif; ?>
<?php if ($apps === []): ?>
  <div class="card">No app is switched on for your company yet. Ametecs switches apps on from Super Admin.</div>
<?php else: ?>
<div class="tabs"><?php foreach ($apps as $code => $p): ?><a href="?s=ag-apps&amp;app=<?= ag_h($code) ?>"<?= $code === $app ? ' class="on"' : '' ?>><?= ag_h($p['name']) ?></a><?php endforeach; ?></div>
<div class="card"><b><?= $onCount ?></b> of <?= count($emps) ?> employees use <?= ag_h($apps[$app]['name']) ?><?= $seats ? ' · licence: ' . (int) $seats . ' seats' : '' ?><?= !$isAdmin ? ' · <span class="from">view only — an administrator can change this</span>' : '' ?></div>

<form method="post" action="?s=ag-apps&amp;app=<?= ag_h($app) ?>" class="card"><?= $csrf ?><input type="hidden" name="do" value="levels">
  <h2>By organisation</h2>
  <div class="lv">
  <?php foreach ($levelName as $level => $title): ?>
    <div><table><tr><th><?= ag_h($title) ?></th><th style="width:110px"><?= ag_h($apps[$app]['name']) ?></th></tr>
    <?php if ($org[$level] === []): ?><tr><td colspan="2" class="from">None set up yet</td></tr><?php endif; ?>
    <?php foreach ($org[$level] as $id => $row): $v = $rules[$level][(string) $id] ?? null; ?>
      <tr><td><?= ag_h((string) $row['name']) ?></td><td><select name="v[<?= $level ?>][<?= ag_h((string) $id) ?>]">
        <option value=""><?= $level === 'company' ? 'On (default)' : 'Same as above' ?></option>
        <option value="on"<?= $v === 1 ? ' selected' : '' ?>>On</option><option value="off"<?= $v === 0 ? ' selected' : '' ?>>Off</option></select></td></tr>
    <?php endforeach; ?></table></div>
  <?php endforeach; ?>
  </div>
  <?php if ($isAdmin): ?><p><button class="b">Save</button></p><?php endif; ?>
</form>

<div class="card">
  <h2>Employees</h2>
  <form method="get" class="flt"><input type="hidden" name="s" value="ag-apps"><input type="hidden" name="app" value="<?= ag_h($app) ?>">
    <div><label>Company</label><?= $sel('company', $names($org['company']), (string) ($_GET['company'] ?? '')) ?></div>
    <div><label>Branch</label><?= $sel('branch', $names($org['branch']), (string) ($_GET['branch'] ?? '')) ?></div>
    <div><label>Department</label><?= $sel('department', $names($org['department']), (string) ($_GET['department'] ?? '')) ?></div>
    <div><label>Designation</label><?= $sel('designation', $names($desig), (string) ($_GET['designation'] ?? '')) ?></div>
    <div><label>Team</label><?= $sel('team', $names($org['team']), (string) ($_GET['team'] ?? '')) ?></div>
    <div><label>Employee type</label><?= $sel('type', array_combine(array_keys($distinct['type']), array_keys($distinct['type'])) ?: [], (string) ($_GET['type'] ?? '')) ?></div>
    <div><label>Staff type</label><?= $sel('staff', array_combine(array_keys($distinct['employment_type']), array_keys($distinct['employment_type'])) ?: [], (string) ($_GET['staff'] ?? '')) ?></div>
    <div><label>Status</label><?= $sel('status', array_combine(array_keys($distinct['status']), array_keys($distinct['status'])) ?: [], (string) ($_GET['status'] ?? '')) ?></div>
    <div><label>Search</label><input type="text" name="q" value="<?= ag_h($search) ?>" placeholder="Name, code or email"></div>
    <div><button class="b">Show</button></div>
  </form>
  <form method="post" action="?<?= ag_h(http_build_query(array_diff_key($_GET, ['x' => 1]))) ?>"><?= $csrf ?><input type="hidden" name="do" value="employees">
  <?php if ($isAdmin && $shown !== []): ?>
  <div class="bulk">Ticked employees: <select name="set"><option value="on">On</option><option value="off">Off</option><option value="">Same as their team / department / branch / company</option></select> <button class="b">Apply</button></div>
  <?php endif; ?>
  <table><tr><th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.ck').forEach(c=>c.checked=this.checked)"></th><th>Code</th><th>Name</th><th>Company</th><th>Branch</th><th>Department</th><th>Team</th><th><?= ag_h($apps[$app]['name']) ?></th></tr>
  <?php if ($shown === []): ?><tr><td colspan="8" class="from">No employees match.</td></tr><?php endif; ?>
  <?php foreach ($shown as $e): [$on, $from] = AmetecsAppAccess::resolve($rules, $e); ?>
    <tr><td><input type="checkbox" class="ck" name="ids[]" value="<?= ag_h((string) $e['id']) ?>"></td><td><?= ag_h((string) ($e['emp_code'] ?? '')) ?></td><td><?= ag_h((string) $e['name']) ?></td>
      <td><?= ag_h((string) ($org['company'][$e['employer_company_id'] ?? '']['name'] ?? '')) ?></td><td><?= ag_h((string) ($org['branch'][$e['branch_id'] ?? '']['name'] ?? $e['branch'] ?? '')) ?></td>
      <td><?= ag_h((string) ($org['department'][$e['department_id'] ?? '']['name'] ?? $e['department'] ?? '')) ?></td><td><?= ag_h((string) ($org['team'][$e['team_id'] ?? '']['name'] ?? $e['team'] ?? '')) ?></td>
      <td><span class="<?= $on ? 'on1' : 'off1' ?>"><?= $on ? 'On' : 'Off' ?></span> <span class="from"><?= $from === 'employee' ? '(set for this employee)' : ($from !== '' ? '(from ' . $from . ')' : '') ?></span></td></tr>
  <?php endforeach; ?>
  </table></form>
</div>
<?php endif;
$body = (string) ob_get_clean();
