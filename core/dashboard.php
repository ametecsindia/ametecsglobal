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
$tile = static fn (string $label, string $value, string $sub = '', string $tone = '', string $icon = ''): string
    => '<div class="kpi ' . ag_h($tone) . '">' . ($icon !== '' ? '<i class="fas ' . $icon . '"></i>' : '') . '<span>' . ag_h($label) . '</span><b>' . ag_h($value) . '</b>' . ($sub !== '' ? '<small>' . ag_h($sub) . '</small>' : '') . '</div>';

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
  <?= $tile('Employees', (string) count($chosen), $filtered ? 'in this selection' : 'active', '', 'fa-users') ?>
  <?= $tile('Field / Office', $field . ' / ' . $office, count($chosen) - $field - $office > 0 ? (count($chosen) - $field - $office) . ' without a staff type' : 'staff type', '', 'fa-person-walking') ?>
  <?php if ($working > 0 || isset($tot['present'])): ?>
  <?= $tile('Attendance', $working > 0 ? (int) round(100 * (($tot['present'] ?? 0) + ($tot['leave'] ?? 0)) / $working) . '%' : '-', 'present or on leave', 'green', 'fa-chart-pie') ?>
  <?php foreach ($combine as $k => $lab): if (!isset($tot[$k])) { continue; } ?><?= $tile($lab, (string) $tot[$k], $oneDay ? ($k === 'present' ? 'of ' . $working . ' working' : ($from === $today ? 'today' : 'that day')) : ($k === 'late' || $k === 'early' ? 'times' : 'person-days'), ['present' => 'green', 'absent' => $tot[$k] > 0 ? 'red' : '', 'leave' => 'blue', 'late' => $tot[$k] > 0 ? 'amber' : '', 'early' => $tot[$k] > 0 ? 'amber' : ''][$k]) ?><?php endforeach; ?>
  <?php endif; ?>
  <?= $tile('Applications', (string) count($held), $f['app'] !== '' ? 'filtered' : 'in your plan', '', 'fa-grip') ?>
</section>

<?php if ($trend !== null || $break !== []): ?>
<section class="charts">
  <?php if ($trend !== null): $max = max(1, ...array_map(static fn ($i) => array_sum(array_column($trend['sets'], $i)), array_keys($trend['labels']))); $cols = ['Present' => '#16a34a', 'On leave' => '#2563eb', 'Absent' => '#dc2626']; ?>
  <div class="chart"><h3><?= ag_h($trend['title']) ?><span class="legend2"><?php foreach (array_keys($trend['sets']) as $sn): ?><em><i style="background:<?= $cols[$sn] ?? '#94a3b8' ?>"></i><?= ag_h($sn) ?></em><?php endforeach; ?></span></h3>
    <div class="vbars"><?php foreach ($trend['labels'] as $i => $d): $tot2 = array_sum(array_column($trend['sets'], $i)); ?>
      <div class="vb" title="<?= ag_h(date('D d M', (int) strtotime($d)) . ': ' . implode(', ', array_map(static fn ($sn) => $sn . ' ' . $trend['sets'][$sn][$i], array_keys($trend['sets'])))) ?>"><div class="vbs" style="height:<?= round(100 * $tot2 / $max) ?>%"><?php foreach ($trend['sets'] as $sn => $vals): if ((int) $vals[$i] === 0) { continue; } ?><i style="flex:<?= (int) $vals[$i] ?>;background:<?= $cols[$sn] ?? '#94a3b8' ?>"></i><?php endforeach; ?></div><span><?= count($trend['labels']) <= 16 || $i % 3 === 0 ? ag_h(date('d', (int) strtotime($d))) : '' ?></span></div>
    <?php endforeach; ?></div></div>
  <?php endif; ?>
  <?php if ($break !== []): $bmax = max(1, ...array_values(array_map('array_sum', $break))); ?>
  <div class="chart"><h3>By <?= $dim ?><span class="legend2"><em><i style="background:#16a34a"></i>Present</em><em><i style="background:#2563eb"></i>Leave</em><em><i style="background:#dc2626"></i>Absent</em></span></h3>
    <div class="hbars"><?php foreach ($break as $label => $b): $sumb = array_sum($b); ?>
      <div class="hb"><span title="<?= ag_h((string) $label) ?>"><?= ag_h((string) $label) ?></span><div><div class="hbs" style="width:<?= max(2, round(100 * $sumb / $bmax)) ?>%"><?php foreach (['present' => '#16a34a', 'leave' => '#2563eb', 'absent' => '#dc2626'] as $k => $c): if ($b[$k] === 0) { continue; } ?><i style="flex:<?= $b[$k] ?>;background:<?= $c ?>" title="<?= ucfirst($k) . ' ' . $b[$k] ?>"></i><?php endforeach; ?></div></div>
        <b><?= $sumb > 0 ? round(100 * ($b['present'] + $b['leave']) / $sumb) . '%' : '-' ?></b></div>
    <?php endforeach; ?></div></div>
  <?php endif; ?>
</section>
<?php endif; ?>

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
<?php
$dashHtml = (string) ob_get_clean();
if (isset($_GET['frag'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $dashHtml;
    exit;
}
