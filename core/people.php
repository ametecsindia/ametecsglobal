<?php
/**
 * EMPLOYEE 360 (owner, 19 Nov 2026 - unified ecosystem, Phase 2): one page per person with everything every licensed app
 * knows about them - the master record (Organization Setup), attendance from every source in one timeline, and one
 * section per app (SmartEPT PC time, ROVEXY calls, QRONEX punches, ...). Each app answers through its own
 * plugins/<app>/ag-person.php, reading only this client's data. Administrators only (index.php adminViews).
 * Needs from index.php: $pdo, $tenant, $myLic, $logoOf.
 */
declare(strict_types=1);

$today = AmetecsAccess::today();
$rng = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month'];
$r = isset($rng[$_GET['r'] ?? '']) ? (string) $_GET['r'] : '7d';
$from = ['today' => $today, '7d' => date('Y-m-d', strtotime($today . ' -6 days')), '30d' => date('Y-m-d', strtotime($today . ' -29 days')), 'month' => substr($today, 0, 8) . '01'][$r];
$to = $today;
$apps = AmetecsAccess::plugins(true);
$store = (new \SmartPRS\Surface\SqlStore($pdo))->forTenant($tenant);
$everyone = $store->all('employees');
$levels = AmetecsRoles::levels($everyone);
$all = array_values(array_filter($everyone, static fn (array $e): bool => !\SmartPRS\Surface\Modules::isRetired($e)
    && ($p3scope === null || isset($p3scope[(string) $e['id']]))));   // team leads and managers: only their own people
usort($all, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
$names = static function (string $t) use ($pdo, $tenant): array {
    try { $s = $pdo->prepare("SELECT id, name FROM `$t` WHERE tenant_id = ?"); $s->execute([$tenant]); return $s->fetchAll(\PDO::FETCH_KEY_PAIR); } catch (\Throwable) { return []; }
};
$org = ['branch' => $names('branches'), 'department' => $names('departments'), 'team' => $names('teams'), 'designation' => $names('designations'), 'company' => $names('companies')];
$sel = null;
foreach ($all as $e) { if ((string) $e['id'] === (string) ($_GET['id'] ?? '')) { $sel = $e; } }
$sel ??= $all[0] ?? null;
$qs = static fn (array $o) => '?' . http_build_query($o + ['v' => 'people', 'r' => $r, 'id' => $sel['id'] ?? '']);
$ini = static fn (string $n) => mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
?>
  <div class="pgh"><div><h1>Employee 360</h1><p>One person, every application: the master record, attendance from every source, and what each licensed app knows. Employees are added and edited in Organization Setup.</p></div>
    <form class="p3r" method="get"><input type="hidden" name="v" value="people"><input type="hidden" name="id" value="<?= ag_h((string) ($sel['id'] ?? '')) ?>"><select name="r" onchange="this.form.submit()"><?php foreach ($rng as $k => $v): ?><option value="<?= $k ?>"<?= $k === $r ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></form></div>
  <?php if ($all === []): ?><div class="lic-t"><div class="empty2" style="padding:30px">No employees yet. <a href="org.php?s=employees">Add employees</a> in Organization Setup.</div></div><?php else: ?>
  <div class="p3">
    <aside class="p3l"><input type="search" placeholder="Search name or code…" data-p3find><div class="p3list">
      <?php foreach ($all as $e): $on = (string) $e['id'] === (string) $sel['id']; $act = strtolower((string) ($e['status'] ?? 'active')) === 'active'; ?>
      <a href="<?= ag_h($qs(['id' => $e['id']])) ?>" class="<?= $on ? 'on' : '' ?><?= $act ? '' : ' off' ?>" data-q="<?= ag_h(strtolower(($e['name'] ?? '') . ' ' . ($e['emp_code'] ?? ''))) ?>"><span class="kav"><?= ag_h($ini((string) ($e['name'] ?? '?'))) ?></span><b><?= ag_h((string) ($e['name'] ?? '')) ?></b><small><?= ag_h((string) ($e['emp_code'] ?? '')) ?><?= $act ? '' : ' · ' . ag_h(ucfirst((string) $e['status'])) ?></small></a>
      <?php endforeach; ?></div></aside>
    <section class="p3r2">
    <?php
    $e = $sel;
    $code = (string) ($e['emp_code'] ?? '');
    // attendance from every source, one line per day
    $days = [];
    try {
        $s = $pdo->prepare("SELECT log_date, punch_at, direction, COALESCE(NULLIF(source, ''), 'web') source FROM attendance_logs WHERE tenant_id = ? AND emp_code = ? AND log_date BETWEEN ? AND ? ORDER BY punch_at");
        $s->execute([$tenant, $code, $from, $to]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $d = (string) $p['log_date'];
            $days[$d]['in'] ??= $p['direction'] === 'in' ? substr((string) $p['punch_at'], 11, 5) : null;
            $p['direction'] === 'out' && $days[$d]['out'] = substr((string) $p['punch_at'], 11, 5);
            $days[$d]['src'][$p['source']] = true;
            $days[$d]['n'] = ($days[$d]['n'] ?? 0) + 1;
        }
    } catch (\Throwable) {
    }
    krsort($days);
    $srcName = static fn (string $s) => ['push' => 'Biometric', 'pull' => 'Biometric', 'sbb' => 'Biometric', 'qr' => 'QR', 'qronex' => 'QRONEX', 'smartept' => 'SmartEPT PC', 'caller' => 'ROVEXY', 'web' => 'Web/app', 'mobile' => 'App'][$s] ?? ucfirst($s);
    $nm = static fn (string $k, string $col) => $org[$k][(string) ($e[$col] ?? '')] ?? '';
    $hidden = [];
    foreach ($myLic as $ac => $l) { try { in_array((int) $e['id'], array_map('intval', AmetecsAppAccess::hidden($pdo, $tenant, $ac)), true) && $hidden[$ac] = true; } catch (\Throwable) {} }
    ?>
    <div class="p3h"><span class="kav big"><?= ag_h($ini((string) ($e['name'] ?? '?'))) ?></span><div><h2><?= ag_h((string) ($e['name'] ?? '')) ?></h2>
      <p><?= ag_h(implode(' · ', array_filter([$code, $nm('designation', 'designation_id') ?: (string) ($e['designation'] ?? ''), $nm('department', 'department_id'), $nm('branch', 'branch_id'), $nm('team', 'team_id')]))) ?></p>
      <p class="muted2"><?= ag_h(implode(' · ', array_filter([(string) ($e['email'] ?? ''), (string) ($e['mobile'] ?? ''), trim((string) ($e['type'] ?? '')) !== '' ? $e['type'] . ' staff' : '', ($e['doj'] ?? '') ? 'joined ' . date('d M Y', (int) strtotime((string) $e['doj'])) : '']))) ?></p></div>
      <div class="p3act"><span class="lp lp-blue" title="From the reporting line in Organization Setup; the same role in every app"><?= ag_h(AmetecsRoles::LABELS[$levels[(string) $e['id']] ?? 'employee']) ?></span><span class="lp lp-<?= strtolower((string) ($e['status'] ?? 'active')) === 'active' ? 'green' : 'grey' ?>"><?= ag_h(ucfirst((string) ($e['status'] ?? 'active'))) ?></span><?php if ($p3scope === null): ?><a class="btn2 ghost" href="org.php?s=employees"><i class="fas fa-pen"></i>Edit in Organization Setup</a><?php endif; ?></div></div>
    <div class="p3apps"><?php foreach ($myLic as $ac => $l): if (AmetecsLicences::stateOf($l) !== 'on') { continue; } ?><span class="<?= isset($hidden[$ac]) ? 'no' : 'yes' ?>" title="<?= isset($hidden[$ac]) ? 'App access switched off for this employee' : 'Has access' ?>"><i class="fas fa-<?= isset($hidden[$ac]) ? 'xmark' : 'check' ?>"></i><?= ag_h((string) ($apps[$ac]['name'] ?? $ac)) ?></span><?php endforeach; ?><?php if ($p3scope === null): ?><a href="org.php?s=ag-apps">App access &rarr;</a><?php endif; ?></div>

    <div class="p3c"><h3><i class="fas fa-fingerprint"></i>Attendance <small>every source · <?= ag_h($rng[$r]) ?></small></h3>
      <?php if ($days === []): ?><p class="emp3 sm">No punches <?= $r === 'today' ? 'today' : 'in this period' ?> from any source.</p><?php else: ?>
      <table class="p3t"><tr><th>Day</th><th>In</th><th>Out</th><th>Punches</th><th>From</th></tr><?php foreach ($days as $d => $x): ?>
        <tr><td><?= ag_h(date('D d M', (int) strtotime($d))) ?></td><td><?= ag_h((string) ($x['in'] ?? '—')) ?></td><td><?= ag_h((string) ($x['out'] ?? '—')) ?></td><td><?= (int) $x['n'] ?></td><td><?= ag_h(implode(', ', array_map($srcName, array_keys($x['src'])))) ?></td></tr>
      <?php endforeach; ?></table><?php endif; ?></div>

    <?php foreach ($myLic as $ac => $l):
        $file = __DIR__ . '/../plugins/' . preg_replace('/[^a-z0-9]/', '', $ac) . '/ag-person.php';
        if (!is_file($file) || AmetecsLicences::stateOf($l) !== 'on' || !AmetecsLicences::installed($l)) { continue; }
        try { $res = (require $file)($tenant, (int) $e['id'], $from, $to); } catch (\Throwable $x) { $res = ['tiles' => [], 'rows' => [], 'note' => $ac . ' is not set up on this server yet.']; }
        $p = $apps[$ac] ?? ['name' => $ac, 'color' => '#B8893B']; ?>
    <div class="p3c" style="--c:<?= ag_h((string) ($p['color'] ?? '#B8893B')) ?>"><h3><span class="p3logo"><?= $logoOf($ac, $p) ?></span><small><?= ag_h($rng[$r]) ?></small><?php if ($p3scope === null): ?><a href="<?= ag_h((string) ($p['open'] ?? '?v=apps')) ?>">Open <i class="fas fa-arrow-right"></i></a><?php endif; ?></h3>
      <?php if (!empty($res['note'])): ?><p class="emp3 sm"><?= ag_h((string) $res['note']) ?></p><?php endif; ?>
      <?php if (!empty($res['tiles'])): ?><div class="p3k"><?php foreach ($res['tiles'] as [$lab, $val, $sub]): ?><div><span><?= ag_h((string) $lab) ?></span><b><?= ag_h((string) $val) ?></b><small><?= ag_h((string) $sub) ?></small></div><?php endforeach; ?></div><?php endif; ?>
      <?php if (!empty($res['rows'])): ?><ul class="p3f"><?php foreach ($res['rows'] as [$when, $txt, $sub]): ?><li><time><?= ag_h((string) $when) ?></time><b><?= ag_h((string) $txt) ?></b><small><?= ag_h((string) $sub) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php endforeach; ?>
    </section>
  </div>
  <script>document.querySelector('[data-p3find]')?.addEventListener('input',function(){var v=this.value.toLowerCase();document.querySelectorAll('.p3list a').forEach(function(a){a.hidden=v&&a.dataset.q.indexOf(v)<0;});});</script>
  <?php endif; ?>
