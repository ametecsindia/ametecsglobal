<?php
/**
 * ORGANIZATION SETUP — the central configuration every client has (owner, 17 Nov 2026): one fixed skeleton for all
 * clients (organisation, employees, logins, attendance devices) plus App access (which employees use which app).
 * Apps never show these screens; they use what is saved here. Each app's own settings stay inside the app.
 * The organisation and employee screens open on the right, framed (core/gate.php strips the app's menu from them);
 * App access is the core's own screen (core/app-access.php).
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

session_name('AMETECSGLOBAL');
$pdo = Database::connect(AG_BASE . '/.env');
$session = $pdo === null ? null : new Session(new SqlStore($pdo), PasswordHasher::platformDefault());
$session?->start();
$user = $session?->user();
if ($user === null) {
    header('Location: ./');
    exit;
}
$menu = AmetecsSetup::CORE;

// Which screen: ?s=<route>[&k=<module>] (also what the gate sends when someone opens a central screen inside an app).
$s = (string) ($_GET['s'] ?? 'setup-companies');
$k = (string) ($_GET['k'] ?? '');
$cur = [$s === '' ? 'setup-companies' : $s, $k];
$own = $s === 'ag-apps';   // App access: the core's own screen, not a framed one
$frame = '../plugins/smartprs3/public/app.php' . AmetecsSetup::href($cur) . '&ag_embed=1';
// Sent here from inside the app (core/gate.php): open exactly that screen, e.g. one employee's edit page.
if (isset($_GET['go'])) {
    parse_str((string) $_GET['go'], $go);
    unset($go['ag_embed']);
    $cur = [(string) ($go['r'] ?? ''), (string) ($go['k'] ?? '')];
    $frame = '../plugins/smartprs3/public/app.php?' . http_build_query($go + ['ag_embed' => 1]);
}
if ($own) {
    require __DIR__ . '/app-access.php';   // handles its save, then sets $body
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Organization Setup · AmetecsGlobal</title>
<link rel="stylesheet" href="../plugins/smartprs3/public/vendor/fontawesome/css/all.min.css" onerror="this.remove()">
<style>
:root{--navy:#1F3A5F;--gold:#B8893B;--ink:#1e293b;--mute:#64748b;--line:#e2e8f0}
*{box-sizing:border-box}html,body{height:100%}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f4f6fa;display:flex;flex-direction:column}
header{background:var(--navy);color:#fff;padding:12px 22px;display:flex;align-items:center;gap:16px}
header .logo{font-weight:800;font-size:19px}header .logo b{color:var(--gold)}
header a.back{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.4);border-radius:8px;padding:6px 12px;font-size:14px}
header a.back:hover{background:rgba(255,255,255,.12)}header .t{font-size:16px;opacity:.9}header .who{margin-left:auto;font-size:13px;opacity:.85}
.wrap{flex:1;display:flex;min-height:0}
nav{width:260px;background:#fff;border-right:1px solid var(--line);overflow-y:auto;padding:10px 0}
nav h3{font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:var(--mute);margin:16px 18px 6px}
nav a{display:flex;gap:10px;align-items:center;padding:8px 18px;color:var(--ink);text-decoration:none;font-size:14px;border-left:3px solid transparent}
nav a i{width:16px;text-align:center;color:var(--mute)}nav a:hover{background:#f8fafc}
nav a.on{background:#eef2f8;border-left-color:var(--gold);font-weight:600}nav a.on i{color:var(--navy)}
iframe{flex:1;border:0;background:#fff}
.pane{flex:1;overflow-y:auto;padding:22px 28px}.pane h1{margin:0 0 4px;font-size:22px}.pane .sub{color:var(--mute);margin:0 0 16px;font-size:14px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:18px}.card h2{font-size:16px;margin:0 0 10px}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}.tabs a{padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--ink);text-decoration:none;font-weight:600;font-size:14px}
.tabs a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.lv{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
table{width:100%;border-collapse:collapse;font-size:13px}th{background:var(--navy);color:#fff;text-align:left;padding:8px;font-weight:600}td{padding:7px 8px;border-bottom:1px solid var(--line)}
tr:nth-child(even) td{background:#f8fafc}select,input[type=text]{padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:13px}
.flt{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;align-items:end}.flt label{display:block;font-size:11px;font-weight:700;color:var(--mute);margin-bottom:4px;text-transform:uppercase}
.flt select,.flt input{width:100%}button.b{background:var(--navy);color:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer}
.on1{color:#166534;font-weight:700}.off1{color:#991b1b;font-weight:700}.from{color:var(--mute);font-size:12px}
.msg{padding:10px 14px;border-radius:10px;margin-bottom:14px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.msg.bad{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.bulk{display:flex;gap:8px;align-items:center;margin:10px 0}
</style></head><body>
<header><a class="back" href="./">&larr; All apps</a><div class="logo">Ametecs<b>Global</b></div><div class="t">Organization Setup</div>
<div class="who"><?= ag_h((string) ($user['email'] ?? '')) ?></div></header>
<div class="wrap">
<nav>
  <h3>Apps</h3>
  <a href="?s=ag-apps"<?= $own ? ' class="on"' : '' ?>><i class="fas fa-puzzle-piece"></i>App access</a>
<?php foreach ($menu as $group => $items): ?>
  <h3><?= ag_h($group) ?></h3>
  <?php foreach ($items as $label => $item): $h = AmetecsSetup::href($item); ?>
  <a href="<?= ag_h(str_replace('?r=', '?s=', $h)) ?>" data-q="<?= ag_h($h) ?>"<?= [$item[0], $item[1]] === $cur ? ' class="on"' : '' ?>><i class="fas <?= ag_h($item[2]) ?>"></i><?= ag_h($label) ?></a>
  <?php endforeach; ?>
<?php endforeach; ?>
</nav>
<?php if ($own): ?><main class="pane"><?= $body ?></main><?php else: ?><iframe id="f" src="<?= ag_h($frame) ?>" title="Setup screen"></iframe><?php endif; ?>
</div>
<script>
// Menu clicks load the screen without reloading the page; the address keeps up so refresh / Back work.
var f=document.getElementById('f');
document.querySelectorAll('nav a[data-q]').forEach(function(a){a.addEventListener('click',function(e){if(!f)return;e.preventDefault();
  f.src='../plugins/smartprs3/public/app.php'+a.dataset.q+'&ag_embed=1';history.pushState(null,'',a.getAttribute('href'));mark(a.getAttribute('href'))})});
function mark(h){document.querySelectorAll('nav a').forEach(function(a){a.classList.toggle('on',a.getAttribute('href')===h)})}
window.addEventListener('popstate',function(){location.reload()});
</script>
</body></html>
