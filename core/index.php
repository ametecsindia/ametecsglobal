<?php
/**
 * AMETECSGLOBAL — CLIENT VIEW (the front door). Sign in once, see every Ametecs app; the ones Super Admin
 * switched on for this company open, the rest are greyed out. Same login and same cookie as SmartPRS3
 * inside AmetecsGlobal (session name AMETECSGLOBAL), so "Open" goes straight in without a second sign-in.
 */
declare(strict_types=1);

require __DIR__ . '/access.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

session_name('AMETECSGLOBAL');
$pdo = Database::connect(AG_BASE . '/.env');
$problem = $pdo === null ? 'The database is not available: ' . Database::why() : null;
$platform = $pdo === null ? null : new SqlStore($pdo);
$session = $platform === null ? null : new Session($platform, PasswordHasher::platformDefault());
$session?->start();
$notice = null;

if ($session !== null) {
    $problem ??= isset($_SESSION['flash_problem']) ? (string) $_SESSION['flash_problem'] : null;
    unset($_SESSION['flash_problem']);
}

if ($session !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$session->verify((string) ($_POST['csrf'] ?? ''))) {
        $problem = 'This page had expired. Please try again.';
    } elseif (($_POST['do'] ?? '') === 'signin') {
        $r = $session->signIn((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($r['ok']) {
            header('Location: ./');
            exit;
        }
        $problem = $r['why'];
    } elseif (($_POST['do'] ?? '') === 'forgot') {
        // FORGOT PASSWORD: the same answer whether or not the email has a login, so this cannot be used to find out who has one.
        $times = array_values(array_filter((array) ($_SESSION['fp_times'] ?? []), static fn ($t): bool => (int) $t > time() - 900));
        $limit = (int) (json_decode((string) @file_get_contents(AG_BASE . '/config/password-reset.json'), true)['requests_per_15_minutes'] ?? 5);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (count($times) >= $limit) {
            $problem = 'Too many requests. Try again in 15 minutes.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $problem = 'Type the email you sign in with.';
        } elseif (!AmetecsMail::box($pdo)->ready()) {
            $problem = 'Password reset by email is not set up yet. Ask your company administrator, or Ametecs, to reset your password.';
        } else {
            $_SESSION['fp_times'] = [...$times, time()];
            $q = $pdo->prepare("SELECT u.id, u.tenant_id FROM users u JOIN tenants t ON t.id = u.tenant_id
                WHERE LOWER(u.email) = ? AND u.disabled_at IS NULL AND t.status_code NOT IN ('suspended', 'closed')");
            $q->execute([$email]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $u) {
                $sent = AmetecsMail::resetLink($pdo, (string) $u['tenant_id'], (string) $u['id']);
                $sent['ok'] || error_log('AmetecsGlobal forgot-password mail not sent: ' . $sent['why']);
            }
            $notice = 'If ' . $email . ' has a login, a link to set a new password is on its way. Check your inbox (and spam).';
        }
    } elseif (($_POST['do'] ?? '') === 'reset') {
        [$rt, $rk] = array_pad(explode('.', (string) ($_POST['reset'] ?? ''), 2), 2, '');
        $new = (string) ($_POST['new'] ?? '');
        if (!ctype_digit($rt)) {
            $problem = 'This link is not valid. Ask for a new one.';
        } elseif ($new !== (string) ($_POST['again'] ?? '')) {
            $problem = 'The two passwords are not the same.';
            $_GET['reset'] = (string) $_POST['reset'];
        } else {
            $r = (new \SmartPRS\Surface\Users($platform->forTenant($rt), new \SmartPRS\Surface\Authorisation(), PasswordHasher::platformDefault()))->redeemResetToken($rk, $new);
            if ($r['ok']) {
                $notice = 'Your password is set. Sign in with it below.';
            } else {
                $problem = $r['why'];
                str_contains($r['why'], 'characters') && $_GET['reset'] = (string) $_POST['reset'];
            }
        }
    } elseif (($_POST['do'] ?? '') === 'signout') {
        $session->signOut();
        header('Location: ./');
        exit;
    }
}

$user = $session?->user();
$mode = $user !== null ? 'apps' : (isset($_GET['reset']) ? 'reset' : (isset($_GET['forgot']) ? 'forgot' : 'signin'));
$resetFor = null;
if ($mode === 'reset' && $platform !== null) {
    [$rt, $rk] = array_pad(explode('.', (string) $_GET['reset'], 2), 2, '');
    $resetFor = ctype_digit($rt) ? (new \SmartPRS\Surface\Users($platform->forTenant($rt), new \SmartPRS\Surface\Authorisation(), PasswordHasher::platformDefault()))->byResetToken($rk) : null;
    if ($resetFor === null) {
        $problem ??= 'This link has expired or was already used. Ask for a new one below.';
        $mode = 'forgot';
    }
}
$tenant = (string) ($user['tenant_id'] ?? '');
// A SUSPENDED OR CLOSED CLIENT (Super Admin → client → Status): nobody there signs in to any app. Nothing is deleted.
if ($user !== null && $platform !== null && in_array((string) ($platform->find('tenants', $tenant)['status_code'] ?? ''), ['suspended', 'closed'], true)) {
    $session->signOut();
    $user = null;
    $tenant = '';
    $problem = 'Your company\'s AmetecsGlobal account is suspended. Please contact Ametecs.';
}
$all = AmetecsAccess::all();
$company = $user !== null && $platform !== null ? (string) ($platform->find('tenants', $tenant)['name'] ?? '') : '';
$used = $user !== null && $platform !== null ? AmetecsAccess::employeesUsed($platform, $tenant) : 0;
if (($_GET['blocked'] ?? '') !== '' && $user !== null) {
    $bp = AmetecsAccess::plugins()[(string) $_GET['blocked']]['name'] ?? 'That app';
    $problem ??= $bp . ' is not switched on for ' . ($company ?: 'your company') . ', or its licence has expired. Ask Ametecs to switch it on.';
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AmetecsGlobal</title>
<style>
:root{--navy:#1F3A5F;--gold:#B8893B;--ink:#1e293b;--mute:#64748b;--line:#e2e8f0}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f4f6fa}
header{background:var(--navy);color:#fff;padding:14px 28px;display:flex;align-items:center;gap:14px}
header .logo{font-weight:800;font-size:20px;letter-spacing:.3px}header .logo b{color:var(--gold)}
header .who{margin-left:auto;font-size:14px;opacity:.9;display:flex;gap:12px;align-items:center}
header button{background:transparent;border:1px solid rgba(255,255,255,.4);color:#fff;border-radius:8px;padding:6px 12px;cursor:pointer}
main{max-width:1080px;margin:0 auto;padding:28px}
h1{margin:0 0 4px;font-size:24px}.sub{color:var(--mute);margin:0 0 22px}
.msg{padding:12px 14px;border-radius:10px;margin-bottom:18px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.msg.ok{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
.tile{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:10px;border-top:5px solid var(--c)}
.tile h2{margin:0;font-size:19px}.tile .what{color:var(--mute);font-size:14px;min-height:20px}
.tag{display:inline-block;font-size:12px;font-weight:700;padding:3px 9px;border-radius:999px}
.on .tag{background:#dcfce7;color:#166534}.off .tag{background:#f1f5f9;color:#475569}.expired .tag{background:#fee2e2;color:#991b1b}
.facts{font-size:13px;color:var(--mute);line-height:1.6}
.btn{margin-top:auto;display:block;text-align:center;padding:11px;border-radius:10px;font-weight:700;text-decoration:none;border:0;font-size:15px}
.go{background:var(--c);color:#fff}.no{background:#f1f5f9;color:#94a3b8;cursor:not-allowed}
.off,.expired{opacity:.75}.off h2,.expired h2{color:#64748b}
.card{max-width:400px;margin:60px auto;background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px}
.card label{display:block;font-size:12px;font-weight:700;color:var(--mute);margin:14px 0 6px;text-transform:uppercase;letter-spacing:.4px}
.card input{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:10px;font-size:15px}
.card .btn{background:var(--navy);color:#fff;width:100%;margin-top:20px;cursor:pointer}
footer{text-align:center;color:var(--mute);font-size:12px;padding:24px}footer a{color:var(--mute)}
</style></head><body>
<header><div class="logo">Ametecs<b>Global</b></div>
<?php if ($user !== null): ?>
  <div class="who"><span><?= ag_h($user['email'] ?? '') ?><?= $company !== '' ? ' · ' . ag_h($company) : '' ?></span>
  <form method="post"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><button name="do" value="signout">Sign out</button></form></div>
<?php endif; ?>
</header>
<main>
<?php if ($user === null): ?>
  <div class="card">
    <?php if ($notice !== null): ?><div class="msg ok"><?= ag_h($notice) ?></div><?php endif; ?>
    <?php if ($mode === 'forgot'): ?>
      <h1>Forgot password</h1><p class="sub">Type the email you sign in with. We'll email you a link to set a new password.</p>
      <?php if ($problem !== null): ?><div class="msg"><?= ag_h($problem) ?></div><?php endif; ?>
      <form method="post" action="./?forgot=1">
        <input type="hidden" name="csrf" value="<?= ag_h($session?->token() ?? '') ?>"><input type="hidden" name="do" value="forgot">
        <label>Email</label><input type="email" name="email" required autofocus>
        <button class="btn">Email me a reset link</button>
      </form>
      <p class="sub" style="margin-top:16px;text-align:center"><a href="./">Back to sign in</a></p>
    <?php elseif ($mode === 'reset'): ?>
      <h1>Set a new password</h1><p class="sub">For <?= ag_h((string) $resetFor['email']) ?></p>
      <?php if ($problem !== null): ?><div class="msg"><?= ag_h($problem) ?></div><?php endif; ?>
      <form method="post" action="./">
        <input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="reset"><input type="hidden" name="reset" value="<?= ag_h((string) $_GET['reset']) ?>">
        <label>New password (<?= \SmartPRS\Surface\Session::PASSWORD_FLOOR ?>+ characters)</label><input type="password" name="new" required minlength="<?= \SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" autocomplete="new-password" autofocus>
        <label>New password again</label><input type="password" name="again" required autocomplete="new-password">
        <button class="btn">Set password</button>
      </form>
    <?php else: ?>
    <h1>Sign in</h1><p class="sub">One sign-in for all your Ametecs apps.</p>
    <?php if ($problem !== null): ?><div class="msg"><?= ag_h($problem) ?></div><?php endif; ?>
    <?php if ($session !== null && $session->needsEnrolment()): ?>
      <p>No account exists yet. <a href="../plugins/smartprs3/public/app.php?r=signin">Create the first administrator</a>.</p>
    <?php elseif ($session !== null): ?>
    <form method="post" action="./">
      <input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="signin">
      <label>Email</label><input type="email" name="email" required autofocus>
      <label>Password</label><input type="password" name="password" required>
      <button class="btn">Sign in</button>
    </form>
    <p class="sub" style="margin-top:14px;text-align:right"><a href="./?forgot=1">Forgot password?</a></p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>
  <h1>Your apps</h1>
  <p class="sub">Every Ametecs app is listed. The ones in your plan open; the others can be switched on by Ametecs.</p>
  <?php if ($problem !== null): ?><div class="msg"><?= ag_h($problem) ?></div><?php endif; ?>
  <div class="grid">
  <?php foreach (AmetecsAccess::plugins() as $code => $p):
      $st = AmetecsAccess::state($tenant, $code, $all);
      $a = AmetecsAccess::get($tenant, $code, $all);
      $seats = (int) ($a['seats'] ?? 0); ?>
    <div class="tile <?= $st ?>" style="--c:<?= ag_h($p['color']) ?>">
      <div><span class="tag"><?= $st === 'on' ? 'In your plan' : ($st === 'expired' ? 'Licence expired' : 'Not in your plan') ?></span></div>
      <h2><?= ag_h($p['name']) ?></h2>
      <div class="what"><?= ag_h($p['what']) ?></div>
      <?php if ($st !== 'off'): ?>
      <div class="facts">
        Seats: <?= $code === 'smartprs3' ? $used . ' of ' : '' ?><?= $seats > 0 ? $seats : 'no limit' ?> <?= ag_h($p['unit']) ?><br>
        Valid till: <?= ($a['until'] ?? '') !== '' ? ag_h(date('d M Y', strtotime((string) $a['until']))) : 'no end date' ?>
      </div>
      <?php endif; ?>
      <?php if ($st === 'on' && $p['open'] !== null): ?>
        <a class="btn go" href="<?= ag_h($p['open']) ?>">Open <?= ag_h($p['name']) ?> →</a>
      <?php elseif ($st === 'on'): ?>
        <span class="btn no">Switched on · arriving on AmetecsGlobal soon</span>
      <?php else: ?>
        <span class="btn no"><?= $st === 'expired' ? 'Renew with Ametecs to open' : 'Ask Ametecs to switch it on' ?></span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</main>
<footer>AmetecsGlobal by Ametecs India · <a href="super.php">Ametecs Super Admin</a></footer>
</body></html>
