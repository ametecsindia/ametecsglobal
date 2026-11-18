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
// USER MANAGEMENT -> DEFAULT PASSWORD (owner, 17 Nov 2026): set = every new employee's login gets it and is enabled;
// empty = random, sent with Reset password. Shown above SmartPRS3's own User Management screen.
$tid = (string) ($user['tenant_id'] ?? '');
$umNote = '';
if ($cur[0] === 'users' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'default-password' && $session->verify((string) ($_POST['csrf'] ?? ''))) {
    $pw = isset($_POST['clear']) ? '' : (string) ($_POST['password'] ?? '');
    if ($pw !== '' && strlen($pw) < Session::PASSWORD_FLOOR) {
        $umNote = '<div class="msg bad">At least ' . Session::PASSWORD_FLOOR . ' characters. Not saved.</div>';
    } else {
        AmetecsLogins::setDefaultPassword($tid, $pw);
        AmetecsData::log((string) ($user['email'] ?? ''), $pw === '' ? 'Cleared the default login password' : 'Set the default login password', $tid);
        $umNote = '<div class="msg">' . ($pw === '' ? 'Default password cleared: new logins get a random one; use Reset password to give them theirs.' : 'Default password saved: every employee added from now on can sign in with it straight away.') . '</div>';
    }
}
$umBar = '';
if ($cur[0] === 'users') {
    $hasDefault = AmetecsLogins::defaultPassword($tid) !== null;
    $umBar = '<form method="post" class="umbar">' . $umNote . '<input type="hidden" name="csrf" value="' . ag_h($session->token()) . '"><input type="hidden" name="do" value="default-password">'
        . '<b><i class="fas fa-key"></i> Default password for new logins</b><span class="from">' . ($hasDefault ? 'Set &mdash; new employees can sign in with it right away.' : 'Not set &mdash; new employees get a login without a usable password; use Reset password.') . '</span>'
        . '<input type="password" name="password" minlength="' . Session::PASSWORD_FLOOR . '" placeholder="' . ($hasDefault ? 'change it' : 'set one') . '" autocomplete="new-password">'
        . '<button class="b">Save</button>' . ($hasDefault ? '<button class="b" name="clear" value="1" style="background:#64748b">Clear</button>' : '') . '</form>';
}
// THE SHARED CLIENT SHELL (Phase 4, 18 Nov 2026): same sidebar, top bar and notification bell as the client panel.
$isAdmin = strtolower((string) ($user['role'] ?? '')) !== 'employee';
$cq2 = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
$cq2->execute([$tid]);
$company = (string) ($cq2->fetchColumn() ?: '');
$notes = AmetecsShell::notifications($tid, $isAdmin);
$active = $own ? 'org:ag-apps' : 'org:' . $cur[0] . ($cur[1] !== '' ? ':' . $cur[1] : '');
$titles = ['ag-apps' => 'App access', 'users' => 'Users & permissions', 'setup-companies' => 'Company profile'];
$title = $titles[$cur[0]] ?? 'Organization setup';
foreach (AmetecsSetup::CORE as $items) { foreach ($items as $label => $it) { if ($it[0] === $cur[0] && $it[1] === $cur[1] && !isset($titles[$cur[0]])) { $title = $label; } } }
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><script>try{localStorage.getItem("agTheme")==="classic"||document.documentElement.classList.add("t-gold")}catch(e){document.documentElement.classList.add("t-gold")}</script><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Organization Setup · AmetecsGlobal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#1F3A5F;--gold:#B8893B;--ink:#1e293b;--mute:#64748b;--line:#e2e8f0}
*{box-sizing:border-box}html,body{height:100%}body{margin:0;font-family:Inter,"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);background:#f4f6fa}
.orgmain{display:flex;flex-direction:column;height:100vh;min-width:0}
.top{background:rgba(255,255,255,.9);backdrop-filter:blur(10px);border-bottom:1px solid var(--line);flex:none}
.top-in{display:flex;align-items:center;gap:14px;height:52px;padding:0 24px}
.top-in h1{margin:0;font-size:17px;font-weight:700}.top-in h1 small{display:block;font-size:11.5px;font-weight:500;color:var(--mute)}
.top-in .who{font-size:13px;color:var(--mute);margin-left:14px;white-space:nowrap}
iframe{flex:1;border:0;background:#fff;width:100%;min-height:0}
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
.col{flex:1;display:flex;flex-direction:column;min-width:0;min-height:0}.umbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 18px;background:#fff;border-bottom:1px solid var(--line);font-size:13px}.umbar .msg{flex-basis:100%;margin:0}.umbar input{padding:6px 8px;border:1px solid var(--line);border-radius:8px}
@media (max-width:860px){.top-in .who{display:none}.top-in{padding:0 14px}}
</style>
<link rel="stylesheet" href="assets/shell.css?v=10"><link rel="stylesheet" href="assets/theme-gold.css?v=2"></head><body>
<div class="shell">
<?= AmetecsShell::nav($company, $isAdmin, $active, ['notifications' => count($notes)], './') ?>
<div class="orgmain">
<header class="top"><div class="top-in">
  <button type="button" class="drawer-btn" data-drawer aria-label="Menu"><i class="fas fa-bars"></i></button>
  <h1><span id="ttl"><?= ag_h($title) ?></span><small>Organization setup &middot; used by every app</small></h1>
  <a class="bell" href="./?v=notifications" title="Notifications"><i class="far fa-bell"></i><?= $notes !== [] ? '<em>' . count($notes) . '</em>' : '' ?></a>
  <span class="who"><?= ag_h((string) ($user['email'] ?? '')) ?></span>
</div></header>
<?php if ($own): ?><main class="pane"><?= $body ?></main><?php else: ?><div class="col"><?= $umBar ?><iframe id="f" src="<?= ag_h($frame) ?>" title="Setup screen"></iframe></div><?php endif; ?>
</div></div>
<script>
// Menu clicks load the screen without reloading the page; the address keeps up so refresh / Back work.
var f=document.getElementById('f');
document.querySelectorAll('.side a[data-q]').forEach(function(a){a.addEventListener('click',function(e){if(!f||a.dataset.q.indexOf('r=users')>=0||location.search.indexOf('s=users')>=0)return;e.preventDefault();
  f.src='../plugins/smartprs3/public/app.php'+a.dataset.q+'&ag_embed=1';history.pushState(null,'',a.getAttribute('href'));mark(a.getAttribute('href'));
  var t=document.getElementById('ttl');t&&(t.textContent=(a.querySelector('span')||a).textContent);document.body.classList.remove('nav-open')})});
function mark(h){document.querySelectorAll('.side a').forEach(function(a){a.classList.toggle('on',a.getAttribute('href')===h)})}
window.addEventListener('popstate',function(){location.reload()});
</script>
<script src="assets/ui.js?v=7"></script>
</body></html>
