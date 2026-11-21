<?php
/**
 * MY SPACE - THE EMPLOYEE PORTAL (owner, 20 Nov 2026: "a single employee dashboard that combines relevant information
 * from all integrated applications ... only the information applicable to the employee"). Everyone with an employee
 * record gets it; it is an employee's home. Only this person's own data, only from apps the client has on, installed
 * and not switched off for them (App access):
 *   - profile, role and reporting line (Organization Setup master record)
 *   - today's attendance from every source (biometric, QR, QRONEX, SmartEPT PC, app) and the last 7 days
 *   - QRONEX scanner (scan.php) when QR attendance is on - the actual QRONEX punch, with QRONEX's own checks
 *   - leave requests and their status (SmartPRS), quick actions into SmartPRS's own self-service screens
 *   - productivity (SmartEPT), field work (ROVEXY), QR punches (QRONEX) through each app's own ag-person.php
 *   - notifications
 * Needs from index.php: $pdo, $tenant, $user, $myLic, $notes.
 */
declare(strict_types=1);

$meEmp = (string) ($user['employee_id'] ?? '');
$store = (new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant);
$me = $meEmp !== '' ? $store->find('employees', $meEmp) : null;
$today = AmetecsAccess::today();
$from7 = date('Y-m-d', strtotime($today . ' -6 days'));
$name = (string) ($me['name'] ?? ($user['name'] ?? ''));
$names = static function (string $t) use ($pdo, $tenant): array {
    try { $s = $pdo->prepare("SELECT id, name FROM `$t` WHERE tenant_id = ?"); $s->execute([$tenant]); return $s->fetchAll(\PDO::FETCH_KEY_PAIR); } catch (\Throwable) { return []; }
};
$nm = static fn (string $t, string $col): string => $me !== null ? (string) ($names($t)[(string) ($me[$col] ?? '')] ?? '') : '';
$everyone = $me !== null ? $store->all('employees') : [];
$level = $me !== null ? (AmetecsRoles::levels($everyone)[$meEmp] ?? 'employee') : 'admin';
$mgr = $me !== null && (string) ($me['reporting_manager_id'] ?? '') !== '' ? $store->find('employees', (string) $me['reporting_manager_id']) : null;
$on = static fn (string $app): bool => isset($myLic[$app]) && AmetecsLicences::stateOf($myLic[$app]) === 'on' && AmetecsLicences::installed($myLic[$app])
    && !in_array((int) $meEmp, array_map('intval', AmetecsAppAccess::hidden($pdo, $tenant, $app)), true);

// attendance: every source, one line per day (the same table Employee 360 reads)
$days = [];
if ($me !== null) {
    try {
        $s = $pdo->prepare("SELECT log_date, punch_at, direction, COALESCE(NULLIF(source, ''), 'web') source FROM attendance_logs WHERE tenant_id = ? AND emp_code = ? AND log_date BETWEEN ? AND ? ORDER BY punch_at");
        $s->execute([$tenant, (string) ($me['emp_code'] ?? ''), $from7, $today]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $d = (string) $p['log_date'];
            $days[$d]['in'] ??= $p['direction'] === 'in' ? substr((string) $p['punch_at'], 11, 5) : null;
            $p['direction'] === 'out' && $days[$d]['out'] = substr((string) $p['punch_at'], 11, 5);
            $days[$d]['src'][(string) $p['source']] = true;
        }
    } catch (\Throwable) {
    }
}
$srcName = static fn (string $s): string => ['push' => 'Biometric', 'pull' => 'Biometric', 'sbb' => 'Biometric', 'qr' => 'QR', 'qronex' => 'QR scan', 'smartept' => 'PC login', 'caller' => 'Field app', 'web' => 'Web', 'mobile' => 'App'][$s] ?? ucfirst($s);
$t0 = $days[$today] ?? null;

// my leave requests (SmartPRS)
$leaves = [];
if ($me !== null && $on('smartprs3')) {
    $types = $names('leave_types');
    foreach ($store->all('leaves') as $lv) {
        if ((string) ($lv['employee_id'] ?? '') === $meEmp) { $lv['type_name'] = (string) ($types[(string) ($lv['type_id'] ?? '')] ?? 'Leave'); $leaves[] = $lv; }
    }
    usort($leaves, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    $leaves = array_slice($leaves, 0, 5);
}

// quick actions: this person's own SmartPRS screens (exactly what its permissions allow) and the other apps' self-service
$menu = AmetecsMenu::groups($pdo, $user, AmetecsMenu::root());
$byRoute = [];
foreach ($menu['groups'] as $g) {
    foreach ($g['items'] as $it) {
        if (preg_match('/[?&]r=([a-z0-9-]+)/', (string) $it['href'], $x) === 1) { $byRoute[$x[1]] = $it; }
        if (preg_match('/open\.php\?app=([a-z0-9]+)&to=([a-z0-9%:-]*)/', (string) $it['href'], $x) === 1) { $byRoute[$x[1] . ':' . rawurldecode($x[2])] = $it; }
    }
}
$quick = [];
if ($on('qronex')) { $quick[] = ['label' => 'Scan QR attendance', 'icon' => 'fa-qrcode', 'href' => 'scan.php', 'main' => true]; }
if (isset($byRoute['my-attendance'])) {   // same permission as My Attendance (ess.attendance.punch); SmartPRS's own punch screen
    $quick[] = ['label' => 'Punch in / out', 'icon' => 'fa-fingerprint', 'href' => AmetecsMenu::root() . 'plugins/smartprs3/public/app.php?r=punch', 'main' => !$on('qronex')];
}
foreach (['my-attendance', 'attendance-corrections', 'leave', 'leave-absence', 'payslips', 'letters-mine', 'my-assets', 'smartept:dashboard', 'smartept:usage', 'smartept:screenshots', 'rovexy:', 'inbox'] as $k) {
    isset($byRoute[$k]) && $quick[] = $byRoute[$k];
}

// the other apps, this person only (each app's own ag-person.php)
$appData = [];
foreach (['smartept' => 'Productivity', 'rovexy' => 'Field work', 'qronex' => 'QR attendance'] as $ac => $label) {
    $file = __DIR__ . '/../plugins/' . $ac . '/ag-person.php';
    if ($me === null || !$on($ac) || !is_file($file)) { continue; }
    try { $appData[$ac] = ['label' => $label] + (require $file)($tenant, (int) $meEmp, $from7, $today); } catch (\Throwable) { $appData[$ac] = ['label' => $label, 'tiles' => [], 'rows' => [], 'note' => 'Not available right now.']; }
}
$hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('G');
?>
<style>
.ms{display:grid;gap:14px}.ms-h{display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:linear-gradient(135deg,#14100a,#2a2112);color:#f3e9d2;border-radius:18px;padding:18px 20px}
.ms-h .av{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;background:#c9a24c;color:#14100a;font-weight:800;font-size:20px;flex:none}
.ms-h h1{margin:0;font-size:20px;color:#fff}.ms-h p{margin:3px 0 0;color:#d9c9a3;font-size:13px}.ms-h .rl{margin-left:auto;text-align:right;font-size:12px;color:#d9c9a3}
.ms-h .rl b{display:inline-block;background:rgba(201,162,76,.18);color:#e8c977;border:1px solid rgba(201,162,76,.4);padding:3px 10px;border-radius:999px;font-size:12px;margin-bottom:4px}
.ms-q{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.ms-q a{display:flex;align-items:center;gap:10px;padding:12px;border-radius:14px;background:var(--card);border:1px solid var(--line);color:var(--ink);text-decoration:none;font-weight:600;font-size:13px}
.ms-q a i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(201,162,76,.14);color:#b08433;flex:none}
.ms-q a.main{background:#c9a24c;border-color:#c9a24c;color:#14100a}.ms-q a.main i{background:rgba(20,16,10,.12);color:#14100a}
.ms-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
.ms-c{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px}.ms-c h3{margin:0 0 10px;font-size:14px;display:flex;gap:8px;align-items:center}
.ms-c h3 i{color:#b08433}.ms-c h3 a{margin-left:auto;font-size:12px;font-weight:600;color:#b08433;text-decoration:none}
.ms-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px}.ms-k div{background:var(--bg);border-radius:12px;padding:10px}
.ms-k span{display:block;font-size:11px;color:var(--mute)}.ms-k b{display:block;font-size:18px;margin-top:2px}.ms-k small{font-size:11px;color:var(--mute)}
.ms-t{width:100%;border-collapse:collapse;font-size:12.5px}.ms-t td,.ms-t th{padding:6px 4px;border-bottom:1px solid var(--line);text-align:left}.ms-t th{color:var(--mute);font-weight:600}
.ms-st{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}.ms-st.pending{background:#fef3c7;color:#92400e}.ms-st.approved{background:#dcfce7;color:#166534}.ms-st.rejected,.ms-st.cancelled{background:#fee2e2;color:#991b1b}
.ms-e{color:var(--mute);font-size:13px;margin:4px 0}
</style>
<div class="ms">
  <div class="ms-h"><span class="av"><?= ag_h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
    <div><h1><?= $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening') ?>, <?= ag_h(explode(' ', trim($name))[0] ?: 'there') ?></h1>
      <p><?= ag_h(implode(' · ', array_filter([(string) ($me['emp_code'] ?? ''), $nm('designations', 'designation_id') ?: (string) ($me['designation'] ?? ''), $nm('departments', 'department_id'), $nm('branches', 'branch_id'), $nm('teams', 'team_id')]))) ?></p></div>
    <div class="rl"><b><?= ag_h(AmetecsRoles::LABELS[$level] ?? 'Employee') ?></b><br><?= $mgr !== null ? 'Reports to ' . ag_h((string) ($mgr['name'] ?? '')) : '' ?></div></div>

  <?php if ($me === null): ?><div class="ms-c"><p class="ms-e">This sign-in is not linked to an employee record, so there is no personal data to show. Ask your administrator to link it in Organization Setup → Logins.</p></div><?php else: ?>
  <?php if ($quick !== []): ?><div class="ms-q"><?php foreach ($quick as $q): ?><a class="<?= !empty($q['main']) ? 'main' : '' ?>" href="<?= ag_h((string) $q['href']) ?>"><i class="fas <?= ag_h((string) $q['icon']) ?>"></i><span><?= ag_h((string) $q['label']) ?></span></a><?php endforeach; ?></div><?php endif; ?>

  <div class="ms-g">
    <div class="ms-c"><h3><i class="fas fa-user-clock"></i>Attendance<?php if (isset($byRoute['my-attendance'])): ?><a href="<?= ag_h((string) $byRoute['my-attendance']['href']) ?>">History →</a><?php endif; ?></h3>
      <div class="ms-k"><div><span>Today</span><b><?= $t0 === null ? 'Not in' : ($t0['out'] ?? null ? 'Out' : 'In') ?></b><small><?= $t0 === null ? 'no punch yet' : 'from ' . ag_h(implode(', ', array_map($srcName, array_keys($t0['src'])))) ?></small></div>
        <div><span>First in</span><b><?= ag_h((string) ($t0['in'] ?? '—')) ?></b><small>today</small></div><div><span>Last out</span><b><?= ag_h((string) ($t0['out'] ?? '—')) ?></b><small>today</small></div>
        <div><span>Days present</span><b><?= count($days) ?></b><small>last 7 days</small></div></div>
      <?php if ($days !== []): krsort($days); ?><table class="ms-t" style="margin-top:10px"><tr><th>Day</th><th>In</th><th>Out</th><th>From</th></tr><?php foreach ($days as $d => $x): ?>
        <tr><td><?= ag_h(date('D d M', (int) strtotime($d))) ?></td><td><?= ag_h((string) ($x['in'] ?? '—')) ?></td><td><?= ag_h((string) ($x['out'] ?? '—')) ?></td><td><?= ag_h(implode(', ', array_map($srcName, array_keys($x['src'])))) ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>

    <?php if ($on('smartprs3')): ?><div class="ms-c"><h3><i class="fas fa-umbrella-beach"></i>Leave<?php if (isset($byRoute['leave'])): ?><a href="<?= ag_h((string) $byRoute['leave']['href']) ?>">Apply / balance →</a><?php endif; ?></h3>
      <?php if ($leaves === []): ?><p class="ms-e">No leave requests yet.</p><?php else: ?><table class="ms-t"><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr><?php foreach ($leaves as $lv): $st = strtolower((string) ($lv['status'] ?? '')); ?>
        <tr><td><?= ag_h((string) $lv['type_name']) ?></td><td><?= ag_h((string) ($lv['from_date'] ?? $lv['from'] ?? '')) ?></td><td><?= ag_h((string) ($lv['to_date'] ?? $lv['to'] ?? '')) ?></td><td><?= ag_h((string) ($lv['days'] ?? '')) ?></td><td><span class="ms-st <?= ag_h($st) ?>"><?= ag_h(ucfirst($st)) ?></span></td></tr><?php endforeach; ?></table><?php endif; ?></div><?php endif; ?>

    <?php foreach ($appData as $ac => $res): ?><div class="ms-c"><h3><i class="fas <?= ['smartept' => 'fa-display', 'rovexy' => 'fa-route', 'qronex' => 'fa-qrcode'][$ac] ?>"></i><?= ag_h($res['label']) ?> <small style="color:var(--mute);font-weight:500">last 7 days</small>
      <?php $more = ['smartept' => 'smartept:dashboard', 'rovexy' => 'rovexy:', 'qronex' => 'qronex:'][$ac]; if (isset($byRoute[$more])): ?><a href="<?= ag_h((string) $byRoute[$more]['href']) ?>">Open →</a><?php endif; ?></h3>
      <?php if (!empty($res['note'])): ?><p class="ms-e"><?= ag_h((string) $res['note']) ?></p><?php endif; ?>
      <?php if (!empty($res['tiles'])): ?><div class="ms-k"><?php foreach ($res['tiles'] as [$lab, $val, $sub]): ?><div><span><?= ag_h((string) $lab) ?></span><b><?= ag_h((string) $val) ?></b><small><?= ag_h((string) $sub) ?></small></div><?php endforeach; ?></div><?php endif; ?>
      <?php if (!empty($res['rows'])): ?><table class="ms-t" style="margin-top:10px"><?php foreach (array_slice($res['rows'], 0, 6) as [$when, $txt, $sub]): ?><tr><td><?= ag_h((string) $when) ?></td><td><?= ag_h((string) $txt) ?></td><td style="color:var(--mute)"><?= ag_h((string) $sub) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div><?php endforeach; ?>

    <div class="ms-c"><h3><i class="fas fa-bell"></i>Notifications<a href="?v=notifications">All →</a></h3>
      <?php if ($notes === []): ?><p class="ms-e">Nothing new.</p><?php else: foreach (array_slice($notes, 0, 5) as $n): ?><p class="ms-e" style="color:var(--ink)">• <?= ag_h((string) ($n['title'] ?? '')) ?></p><?php endforeach; endif; ?></div>
  </div>
  <?php endif; ?>
</div>
