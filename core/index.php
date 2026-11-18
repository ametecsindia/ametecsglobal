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
$pdo !== null && AmetecsAccess::ensureHouseClient($pdo);   // Ametecs India at /c/ametecs, once per PC

// A COMPANY LINK (/c/<slug>, like SmartPRS3's): the page carries the company's name and only that company's logins are accepted.
$slug = strtolower(trim((string) ($_GET['c'] ?? $_POST['c'] ?? '')));
$ws = null;
if ($slug !== '' && $pdo !== null) {
    $wq = $pdo->prepare('SELECT * FROM tenants WHERE LOWER(subdomain) = ?');
    $wq->execute([$slug]);
    $ws = $wq->fetch(\PDO::FETCH_ASSOC) ?: null;
}
$badLink = $slug !== '' && $ws === null;
// By reference: a password reset switches the page to the reset login's company after these are made.
$cq = static function (string $more = '') use (&$ws): string {
    return $ws !== null ? '?c=' . rawurlencode((string) $ws['subdomain']) . ($more !== '' ? '&' . $more : '') : ($more !== '' ? '?' . $more : './');
};
$cField = static function () use (&$ws): string {
    return $ws !== null ? '<input type="hidden" name="c" value="' . ag_h((string) $ws['subdomain']) . '">' : '';
};

if ($session !== null) {
    $problem ??= isset($_SESSION['flash_problem']) ? (string) $_SESSION['flash_problem'] : null;
    unset($_SESSION['flash_problem']);
}

if ($session !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$session->verify((string) ($_POST['csrf'] ?? ''))) {
        $problem = 'This page had expired. Please try again.';
    } elseif (($_POST['do'] ?? '') === 'signin') {
        $r = $badLink ? ['ok' => false, 'why' => 'This sign-in link is not valid.'] : $session->signIn((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), $ws === null ? null : (string) $ws['id']);
        if ($r['ok']) {
            header('Location: ' . $cq());
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
                WHERE LOWER(u.email) = ? AND u.disabled_at IS NULL AND t.status_code NOT IN ('suspended', 'closed') AND (? = '' OR u.tenant_id = ?)");
            $q->execute([$email, (string) ($ws['id'] ?? ''), (string) ($ws['id'] ?? '')]);
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
                $wq = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
                $wq->execute([$rt]);
                $ws = $wq->fetch(\PDO::FETCH_ASSOC) ?: null;   // back to the company's own sign-in page
            } else {
                $problem = $r['why'];
                str_contains($r['why'], 'characters') && $_GET['reset'] = (string) $_POST['reset'];
            }
        }
    } elseif (($_POST['do'] ?? '') === 'signout') {
        $session->signOut();
        header('Location: ' . $cq());
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

// ---- view helpers (presentation only) -------------------------------------------------------------------------
$icons = ['smartprs3' => 'fa-people-group', 'smartept' => 'fa-chart-line', 'smartdcm' => 'fa-hand-holding-dollar',
    'leadger' => 'fa-bullseye', 'rovexy' => 'fa-route', 'qronex' => 'fa-qrcode'];
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$hour = (int) $now->format('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$who = trim((string) ($user['name'] ?? '')) ?: ucfirst((string) strtok((string) ($user['email'] ?? ''), '@'));
$initials = strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/[\s._-]+/', $who) ?: ['U'], 0, 2))));
$isAdmin = $user !== null && strtolower((string) ($user['role'] ?? '')) !== 'employee';
$groups = ['live' => [], 'soon' => [], 'other' => []];
if ($user !== null) {
    foreach (AmetecsAccess::plugins() as $code => $p) {
        $st = AmetecsAccess::state($tenant, $code, $all);
        $a = AmetecsAccess::get($tenant, $code, $all);
        $row = ['code' => $code, 'p' => $p, 'st' => $st, 'a' => $a, 'seats' => (int) ($a['seats'] ?? 0), 'until' => (string) ($a['until'] ?? '')];
        $groups[$st === 'on' ? ($p['open'] !== null ? 'live' : 'soon') : 'other'][] = $row;
    }
}
// UPDATES (Phase 2, 18 Nov 2026): what each licensed app is offered. Cloud = the version Ametecs' server runs; on-premise = what
// the client's server last reported. Only administrators see this; only apps this client holds.
// PHASE 4 (18 Nov 2026): every section of the client administrator's panel (core/shell.php draws the menu).
$adminViews = ['apps', 'plugins', 'updates', 'licences', 'billing', 'invoice', 'activity'];
// PHASE 6 (18 Nov 2026): fewer pages - old addresses land on the page that now holds their content.
$view = ['plans' => 'licences', 'installs' => 'apps', 'upgrades' => 'activity'][(string) ($_GET['v'] ?? '')] ?? (string) ($_GET['v'] ?? 'home');
$view = $user !== null && (in_array($view, ['notifications', 'support', 'account'], true) || ($isAdmin && in_array($view, $adminViews, true))) ? $view : 'home';
$upd = [];
if ($user !== null && $isAdmin && AmetecsReleases::ready()) {
    $upd = AmetecsShell::updates($tenant);
    // A client administrator downloading a package for their own on-premise server.
    if (isset($_GET['dl'])) {
        $dr = AmetecsReleases::byId((string) $_GET['dl']);
        $dl = $dr === null ? null : ($upd[$dr['app']] ?? null);
        $why = match (true) {
            $dr === null || $dl === null || !in_array((string) $dr['id'], array_map(static fn ($x) => (string) $x['id'],
                array_filter(array_merge($dl['offer']['updates'], $dl['offer']['plugins'], [$dl['offer']['rollback']]))), true) => 'That update is not available to your company.',
            $dl['l']['deployment'] !== 'onprem' => 'Your ' . $dl['p']['name'] . ' runs on Ametecs\' cloud: updates are installed for you.',
            empty($dr['downloadable']) => 'Ametecs has not enabled downloading this package. It installs from your server\'s Updates screen.',
            AmetecsReleases::payment($dr, $tenant) === 'required' => 'This upgrade is paid. Complete the payment first.',
            !is_file(AmetecsReleases::path($dr)) => 'The package is not on the update server. Contact Ametecs.',
            default => null,
        };
        if ($why !== null) {
            $_SESSION['flash_problem'] = $why;
            header('Location: ?v=apps');
            exit;
        }
        AmetecsLicences::$actor = (string) ($user['email'] ?? 'client');
        AmetecsReleases::event($dr, (string) $dr['app'], $tenant, 'downloaded', $dl['current'], (string) $dr['version'], 'from the AmetecsGlobal panel', (string) $dl['l']['id']);
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize(AmetecsReleases::path($dr)));
        header('Content-Disposition: attachment; filename="' . basename(AmetecsReleases::path($dr)) . '"');
        readfile(AmetecsReleases::path($dr));
        exit;
    }
}
// MODULES & PLUGINS (Phase 6): switch a held plugin on/off; download a held plugin for an on-premise server.
$myLic = $user !== null ? array_filter(AmetecsLicences::rows()[$tenant] ?? [], static fn ($l) => $l['status'] !== 'cancelled') : [];
$plugOk = $user !== null && $isAdmin && AmetecsPlugins::ready();
$plugFind = static function (string $app, string $code) use ($tenant, $myLic): ?array {
    return isset($myLic[$app]) && AmetecsLicences::stateOf($myLic[$app]) === 'on' ? (AmetecsPlugins::catalogue($app, $tenant)[$code] ?? null) : null;
};
if ($plugOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'plugin-toggle') {
    $pp = $plugFind((string) ($_POST['app'] ?? ''), (string) ($_POST['plugin'] ?? ''));
    $on = ($_POST['on'] ?? '') === '1';
    if (!$session->verify((string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['flash_problem'] = 'This page had expired. Please try again.';
    } elseif ($pp === null || !AmetecsPlugins::authorised(AmetecsPlugins::state($tenant, $pp['latest']))) {
        $_SESSION['flash_problem'] = 'That plugin is not available to your company.';
    } else {
        AmetecsLicences::$actor = (string) ($user['email'] ?? 'client');
        AmetecsPlugins::put($tenant, $pp['latest'], ['enabled' => $on ? 1 : 0], $on ? 'plugin switched on' : 'plugin switched off');
        $_SESSION['flash_notice'] = $pp['title'] . ($on ? ' is switched on.' : ' is switched off. It stays yours; switch it on again any time.');
    }
    header('Location: ?v=plugins#p-' . rawurlencode((string) ($_POST['app'] ?? '')));
    exit;
}
if ($plugOk && isset($_GET['pdl'])) {
    [$pa, $pc] = array_pad(explode(':', (string) $_GET['pdl'], 2), 2, '');
    $pp = $plugFind($pa, $pc);
    $pv = $pp === null ? null : AmetecsPlugins::view($tenant, $pp, ($myLic[$pa]['deployment'] ?? '') === 'onprem');
    if ($pv === null || !$pv['canDownload']) {
        $_SESSION['flash_problem'] = $pv === null ? 'That plugin is not available to your company.' : ($pv['why'] ?: 'Buy this plugin first.');
        header('Location: ?v=plugins');
        exit;
    }
    AmetecsLicences::$actor = (string) ($user['email'] ?? 'client');
    AmetecsPlugins::put($tenant, $pp['latest'], ['downloaded_version' => (string) $pp['latest']['version']], 'plugin downloaded');
    $f = AmetecsReleases::path($pp['latest']);
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($f));
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    readfile($f);
    exit;
}
$updCount = array_sum(array_map(static fn ($u) => count($u['offer']['updates']), $upd));

// PAYMENTS (Phase 3, 18 Nov 2026): the client's administrator pays upgrade and renewal invoices with Razorpay. The browser
// only starts the payment; the server checks Razorpay's signature before anything is unlocked (core/billing.php).
$billOn = $user !== null && $isAdmin && AmetecsBilling::ready();
$payOnline = $billOn && AmetecsBilling::gateway() !== null;
if ($billOn && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_POST['do'] ?? ''), 'pay-')) {
    $pdo2 = (string) $_POST['do'];
    $isJson = in_array($pdo2, ['pay-order', 'pay-complete'], true);
    $out = static function (bool $ok, string $msg, array $more = [], string $to = '?v=billing') use ($isJson): never {
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $ok, ($ok ? 'message' : 'error') => $msg] + $more);
            exit;
        }
        $_SESSION[$ok ? 'flash_notice' : 'flash_problem'] = $msg;
        header('Location: ' . $to);
        exit;
    };
    if (!$session->verify((string) ($_POST['csrf'] ?? ''))) {
        $out(false, 'This page had expired. Reload it and try again.');
    }
    AmetecsLicences::$actor = (string) ($user['email'] ?? 'client');
    try {
        if ($pdo2 === 'pay-upgrade') {
            $pr = AmetecsReleases::byId((string) ($_POST['release'] ?? ''));
            $offered = $pr !== null && in_array((string) $pr['id'], array_map(static fn ($x) => (string) $x['id'], array_merge(...array_values(array_map(static fn ($u) => array_merge($u['offer']['updates'], $u['offer']['plugins']), $upd)) ?: [[]])), true);
            if (!$offered || AmetecsReleases::payment($pr, $tenant) !== 'required' || ($pr['kind'] === 'plugin' && AmetecsPlugins::state($tenant, $pr) === 'blocked')) {
                $out(false, 'That upgrade does not need a payment from your company.', [], '?v=updates');
            }
            $inv = AmetecsBilling::forUpgrade($pr, $tenant);
            $out(true, 'Invoice ' . $inv['number'] . ' is ready. Pay it below to unlock the upgrade.', [], '?v=billing&pay=' . (int) $inv['id']);
        }
        if ($pdo2 === 'pay-renew') {
            $pl = AmetecsLicences::byId((string) ($_POST['licence'] ?? ''));
            if ($pl === null || (string) $pl['tenant_id'] !== $tenant || (float) ($pl['price'] ?? 0) <= 0 || $pl['plan'] === 'perpetual' || $pl['status'] === 'cancelled') {
                $out(false, 'That licence cannot be renewed online. Contact Ametecs.', [], './');
            }
            $inv = AmetecsBilling::forRenewal($pl);
            $out(true, 'Invoice ' . $inv['number'] . ' is ready. Pay it below to renew.', [], '?v=billing&pay=' . (int) $inv['id']);
        }
        $pi = AmetecsBilling::byId((string) ($_POST['invoice'] ?? ''));
        if ($pi === null || (string) $pi['tenant_id'] !== $tenant) {
            $out(false, 'Unknown invoice.');
        }
        if ($pdo2 === 'pay-order') {
            $o = AmetecsBilling::order($pi);
            $o['ok'] ? $out(true, 'ok', $o + ['description' => $pi['number'] . ' - ' . $pi['description'], 'email' => (string) ($user['email'] ?? '')]) : $out(false, $o['why']);
        }
        if ($pdo2 === 'pay-complete') {
            $r = AmetecsBilling::completeSigned($pi, (string) ($_POST['razorpay_order_id'] ?? ''), (string) ($_POST['razorpay_payment_id'] ?? ''), (string) ($_POST['razorpay_signature'] ?? ''));
            $out($r['ok'], $r['why']);
        }
    } catch (\Throwable $x) {
        $out(false, $x->getMessage());
    }
    $out(false, 'Nothing to do.');
}
// ACCOUNT SETTINGS + SUPPORT (Phase 4): change own password; send a message to Ametecs.
$okMsg = null;
if ($user !== null && $session !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['do'] ?? '', ['account-password', 'support-send'], true)) {
    if (!$session->verify((string) ($_POST['csrf'] ?? ''))) {
        $problem = 'This page had expired. Please try again.';
    } elseif ($_POST['do'] === 'account-password') {
        $new = (string) ($_POST['new'] ?? '');
        if ($new !== (string) ($_POST['again'] ?? '')) {
            $problem = 'The two new passwords are not the same.';
        } else {
            $r = (new \SmartPRS\Surface\Users($platform->forTenant($tenant), new \SmartPRS\Surface\Authorisation(), PasswordHasher::platformDefault()))
                ->changeOwnPassword((string) $user['id'], (string) ($_POST['current'] ?? ''), $new);
            $r['ok'] ? ($okMsg = 'Your password is changed. Use it next time you sign in.') : ($problem = (string) $r['why']);
            $r['ok'] && AmetecsData::log((string) ($user['email'] ?? ''), 'Changed own password', $tenant);
        }
    } else {
        $subj = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 150);
        $msg = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 5000);
        $to = (string) (AmetecsBilling::settings()['support_email'] ?? '') ?: (string) AmetecsBilling::settings()['email'];
        if ($subj === '' || $msg === '') {
            $problem = 'Write a subject and a message.';
        } elseif ($to === '' || !AmetecsMail::box($pdo)->ready()) {
            $problem = 'Messages cannot be sent from here yet. Please email or call Ametecs using the details on this page.';
        } else {
            $sent = AmetecsMail::send($pdo, $to, '[AmetecsGlobal support] ' . $company . ': ' . $subj, "From: " . ($user['email'] ?? '') . " (" . $company . ", client #" . $tenant . ")\n\n" . $msg);
            $sent['ok'] ? ($okMsg = 'Your message was sent to Ametecs. They reply to ' . ($user['email'] ?? 'your email') . '.') : ($problem = 'The message was not sent: ' . $sent['why']);
            $sent['ok'] && AmetecsData::log((string) ($user['email'] ?? ''), 'Sent a support request', $tenant, $subj);
        }
    }
}
$notes = $user !== null ? AmetecsShell::notifications($tenant, $isAdmin) : [];
if ($view === 'invoice' && $billOn && ($pv = AmetecsBilling::byId((string) ($_GET['id'] ?? ''))) !== null && (string) $pv['tenant_id'] === $tenant) {
    echo AmetecsBilling::printHtml($pv);
    exit;
}
$notice ??= isset($_SESSION['flash_notice']) ? (string) $_SESSION['flash_notice'] : null;
unset($_SESSION['flash_notice']);
$fmtDate = static fn (string $d): string => $d === '' ? 'No end date' : date('d M Y', strtotime($d));
// APP LOGOS (owner, 17 Nov 2026): the real logos, full colour on white cards. Super Admin -> Apps can set any app's
// own logo address ('logo'); otherwise these. Local copies where we have them, so they work offline. 'inv' = the only
// version we have is white text, shown inverted so it reads on white.
$logos = ['smartprs3' => 'assets/logos/smartprs3.png', 'smartept' => 'assets/logos/smartept.png',
    'leadger' => 'https://rovexy.app/brand/products/leadger.png inv', 'rovexy' => 'https://rovexy.app/brand/rovexy-lockup.svg'];
$logoOf = static function (string $code, array $p) use ($logos, $icons): string {
    [$src, $inv] = array_pad(explode(' ', trim((string) ($p['logo'] ?? '')) ?: ($logos[$code] ?? ''), 2), 2, '');
    // No logo yet: icon tile + name in the same style, so every card looks alike.
    $lock = '<span class="lockup"' . ($src !== '' ? ' hidden' : '') . '><i class="fas ' . ag_h($p['icon'] ?? ($icons[$code] ?? 'fa-cube')) . '"></i>' . ag_h((string) $p['name']) . '</span>';
    return ($src !== '' ? '<img src="' . ag_h($src) . '" alt="' . ag_h((string) $p['name']) . '"' . ($inv === 'inv' ? ' class="inv"' : '') . ' loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">' : '') . $lock;
};
// PHASE 7: the administrator's home is the unified dashboard (core/dashboard.php; ?frag=1 = its inner part only).
if ($user !== null && $isAdmin && $view === 'home' && $pdo !== null) {
    require __DIR__ . '/dashboard.php';
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><script>try{localStorage.getItem("agTheme")==="classic"||document.documentElement.classList.add("t-gold")}catch(e){document.documentElement.classList.add("t-gold")}</script><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $user !== null ? 'Home' : 'Sign in' ?> · AmetecsGlobal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#1F3A5F;--navy-2:#16304f;--navy-3:#0f2440;--gold:#B8893B;--gold-2:#d4a65a;--ink:#0f172a;--ink-2:#334155;--mute:#64748b;--line:#e5e9f0;--bg:#f5f7fb;--card:#fff;
  --ok:#15803d;--ok-bg:#ecfdf3;--info:#1d4ed8;--info-bg:#eff4ff;--bad:#b91c1c;--bad-bg:#fef2f2;--r:14px;--sh:0 1px 2px rgba(15,23,42,.04),0 4px 14px -6px rgba(15,23,42,.08)}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;font:14px/1.5 Inter,"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased}
a{color:inherit}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;letter-spacing:-.2px;text-decoration:none;color:#fff}
.brand .logo-mark{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--gold-2),var(--gold));display:grid;place-items:center;color:var(--navy-3);font-size:14px;font-weight:900;letter-spacing:-.5px}
.brand b{color:var(--gold-2);font-weight:800}
.msg{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;margin:0 0 18px;background:var(--bad-bg);color:var(--bad);border:1px solid #fecaca;font-size:13.5px}
.msg.ok{background:var(--ok-bg);color:var(--ok);border-color:#bbf7d0}.msg i{margin-top:3px}

/* ---------- sign-in ---------- */
.auth{min-height:100vh;display:grid;grid-template-columns:minmax(380px,1.05fr) 1fr}
.auth-side{background:radial-gradient(1200px 600px at -10% -10%,#2b4f7d 0%,transparent 60%),linear-gradient(160deg,var(--navy) 0%,var(--navy-3) 100%);color:#fff;padding:44px 52px;display:flex;flex-direction:column;position:relative;overflow:hidden}
.auth-side:after{content:"";position:absolute;right:-120px;bottom:-120px;width:420px;height:420px;border-radius:50%;border:1px solid rgba(212,166,90,.18);box-shadow:0 0 0 60px rgba(212,166,90,.04),0 0 0 120px rgba(212,166,90,.03)}
.auth-side h1{font-size:34px;line-height:1.15;letter-spacing:-.6px;margin:auto 0 14px;max-width:460px}
.auth-side h1 em{font-style:normal;color:var(--gold-2)}
.auth-side p{color:#c7d3e3;max-width:440px;margin:0 0 30px;font-size:15px}
.auth-apps{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:auto;position:relative;z-index:1}
.auth-apps span{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);font-size:12.5px;color:#e2e8f0}
.auth-apps i{color:var(--gold-2)}
.auth-side small{color:#8ea2bd;font-size:12px;position:relative;z-index:1}
.auth-main{display:grid;place-items:center;padding:40px 24px;background:#fff}
.auth-box{width:100%;max-width:380px}
.auth-box h2{font-size:26px;letter-spacing:-.4px;margin:0 0 6px}.auth-box .lead{color:var(--mute);margin:0 0 26px}
.co{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:#f1f5f9;color:var(--ink-2);font-size:12.5px;font-weight:600;margin-bottom:18px}
.field{margin-bottom:16px}.field label{display:flex;justify-content:space-between;font-size:12.5px;font-weight:600;color:var(--ink-2);margin-bottom:6px}
.field label a{font-weight:600;color:var(--navy);text-decoration:none}.field label a:hover{text-decoration:underline}
.inp{position:relative}.inp i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px}
.inp input{width:100%;height:46px;padding:0 14px 0 40px;border:1px solid #d6dde7;border-radius:10px;font:inherit;font-size:14.5px;background:#fff;transition:border-color .15s,box-shadow .15s}
.inp input:focus{outline:0;border-color:var(--navy);box-shadow:0 0 0 4px rgba(31,58,95,.12)}
.primary{width:100%;height:46px;border:0;border-radius:10px;background:var(--navy);color:#fff;font:inherit;font-weight:700;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;transition:background .15s}
.primary:hover{background:var(--navy-2)}
.alt{margin-top:22px;text-align:center;color:var(--mute);font-size:13px}.alt a{color:var(--navy);font-weight:600;text-decoration:none}
.secure{margin-top:30px;padding-top:18px;border-top:1px solid var(--line);color:var(--mute);font-size:12px;display:flex;gap:8px;align-items:center}

/* ---------- app shell ---------- */
.top{background:var(--navy-3);color:#fff;position:sticky;top:0;z-index:5}
.top-in{max-width:1180px;margin:0 auto;padding:0 28px;height:62px;display:flex;align-items:center;gap:18px}
.top .sep{width:1px;height:24px;background:rgba(255,255,255,.15)}
.top .org{font-size:14px;color:#cbd5e1;display:flex;align-items:center;gap:8px}.top .org i{color:var(--gold-2)}
.user{margin-left:auto;position:relative}
.user summary{list-style:none;display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 8px 6px 6px;border-radius:10px}
.user summary::-webkit-details-marker{display:none}.user summary:hover{background:rgba(255,255,255,.08)}
.av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--gold-2),var(--gold));color:var(--navy-3);display:grid;place-items:center;font-weight:800;font-size:13px}
.user .nm{font-size:13.5px;font-weight:600;line-height:1.2}.user .nm small{display:block;color:#94a3b8;font-weight:500;font-size:12px}
.menu{position:absolute;right:0;top:calc(100% + 8px);background:#fff;color:var(--ink);border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 40px -12px rgba(15,23,42,.3);min-width:240px;padding:8px}
.menu .mh{padding:10px 12px 12px;border-bottom:1px solid var(--line);margin-bottom:6px;font-size:13px}.menu .mh b{display:block}.menu .mh span{color:var(--mute);font-size:12px}
.menu a,.menu button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 12px;border-radius:8px;border:0;background:none;font:inherit;font-size:13.5px;color:var(--ink-2);text-decoration:none;cursor:pointer;text-align:left}
.menu a:hover,.menu button:hover{background:#f1f5f9}.menu i{width:16px;color:var(--mute)}.menu form{margin:0}
.wrap{max-width:1180px;margin:0 auto;padding:30px 28px 50px}
.hello{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:22px}
.hello h1{margin:0;font-size:26px;letter-spacing:-.5px}.hello p{margin:4px 0 0;color:var(--mute)}
.date{color:var(--mute);font-size:13px;display:flex;gap:8px;align-items:center}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;box-shadow:var(--sh);display:flex;gap:14px;align-items:center}
.stat .si{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:17px;flex:none}
.stat b{display:block;font-size:20px;letter-spacing:-.3px;line-height:1.2}.stat span{color:var(--mute);font-size:12.5px}
.s1 .si{background:#eef3fa;color:var(--navy)}.s2 .si{background:#fbf5ea;color:var(--gold)}.s3 .si{background:var(--ok-bg);color:var(--ok)}.s4 .si{background:#f1f5f9;color:var(--ink-2)}
.s4.warn .si{background:var(--bad-bg);color:var(--bad)}
.sec{display:flex;align-items:baseline;gap:10px;margin:0 0 12px}.sec h2{margin:0;font-size:15px;letter-spacing:-.1px}.sec span{color:var(--mute);font-size:12.5px}
.admin{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-3) 100%);color:#fff;border-radius:var(--r);padding:20px 22px;display:grid;grid-template-columns:auto 1fr auto;gap:18px;align-items:center;margin-bottom:30px;box-shadow:var(--sh)}
.admin .ai{width:48px;height:48px;border-radius:12px;background:rgba(212,166,90,.16);color:var(--gold-2);display:grid;place-items:center;font-size:21px}
.admin h3{margin:0;font-size:17px}.admin p{margin:2px 0 10px;color:#b9c7da;font-size:13px}
.chips{display:flex;flex-wrap:wrap;gap:8px}.chips a{display:inline-flex;align-items:center;gap:7px;padding:6px 11px;border-radius:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#e2e8f0;text-decoration:none;font-size:12.5px;font-weight:500}
.chips a:hover{background:rgba(255,255,255,.16)}.chips i{color:var(--gold-2);font-size:11.5px}
.admin .open{background:var(--gold);color:var(--navy-3);padding:11px 18px;border-radius:10px;font-weight:700;text-decoration:none;white-space:nowrap;display:flex;gap:8px;align-items:center}.admin .open:hover{background:var(--gold-2)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px;margin-bottom:30px}
.app{position:relative;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:18px;display:flex;flex-direction:column;text-decoration:none;color:var(--ink);box-shadow:var(--sh);transition:transform .15s,box-shadow .15s,border-color .15s;overflow:hidden}
.app:before{content:"";position:absolute;inset:0 0 auto;height:3px;background:var(--c)}
.app.is-live:hover{transform:translateY(-2px);box-shadow:0 14px 30px -14px rgba(15,23,42,.28);border-color:color-mix(in srgb,var(--c) 35%,var(--line))}
.app-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px}
.mark{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:18px;color:var(--c);background:color-mix(in srgb,var(--c) 11%,#fff)}
.pill{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;display:inline-flex;align-items:center;gap:6px}
.pill:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.pill.ok{background:var(--ok-bg);color:var(--ok)}.pill.info{background:var(--info-bg);color:var(--info)}.pill.bad{background:var(--bad-bg);color:var(--bad)}.pill.mute{background:#f1f5f9;color:var(--mute)}
.app h3{margin:0;font-size:16.5px;letter-spacing:-.2px}.desc{margin:3px 0 14px;color:var(--mute);font-size:13px;min-height:19px}
.meta{border-top:1px dashed var(--line);padding-top:12px;display:grid;gap:7px;margin-bottom:14px}
.kv{display:flex;justify-content:space-between;font-size:12.5px}.kv span,.meter-top span{color:var(--mute)}.kv b,.meter-top b{font-weight:600;color:var(--ink-2)}
.meter-top{display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px}
.bar{height:6px;border-radius:99px;background:#eef1f6;overflow:hidden}.bar i{display:block;height:100%;border-radius:99px;background:var(--c)}
.app-foot{margin-top:auto}
.launch{display:flex;align-items:center;justify-content:center;gap:8px;height:40px;border-radius:10px;background:var(--c);color:#fff;font-weight:700;font-size:13.5px}
.app.is-live:hover .launch i{transform:translateX(3px)}.launch i{transition:transform .15s}
.hint{display:flex;align-items:center;justify-content:center;gap:8px;height:40px;border-radius:10px;background:#f6f8fb;color:var(--mute);font-size:12.5px;font-weight:600;border:1px dashed #dbe2ec}
.is-idle .mark{filter:saturate(.75)}
.foot{text-align:center;color:#94a3b8;font-size:12px;padding:10px 0 30px}.foot a{color:#94a3b8;text-decoration:none}.foot a:hover{color:var(--mute)}
@media (max-width:960px){.stats{grid-template-columns:repeat(2,1fr)}.admin{grid-template-columns:1fr}.auth{grid-template-columns:1fr}.auth-side{display:none}}
@media (max-width:560px){.stats{grid-template-columns:1fr}.wrap,.top-in{padding-left:16px;padding-right:16px}.top .org,.top .sep,.user .nm{display:none}}

/* ---------- v2 shell: Organization left, apps right ---------- */
.shell{display:grid;grid-template-columns:264px 1fr;min-height:100vh}
.side{background:var(--navy-3);color:#cbd5e1;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;padding:18px 12px;overflow-y:auto}
.side .brand{padding:2px 8px 18px}
.ws{display:flex;gap:10px;align-items:center;margin:0 4px 14px;padding:10px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08)}
.ws-i{width:34px;height:34px;border-radius:9px;background:#24426b;color:#fff;display:grid;place-items:center;font-weight:800}
.ws b{display:block;color:#fff;font-size:13.5px;line-height:1.2}.ws small{color:#8193ab;font-size:11.5px}
.snav{display:grid;gap:2px}
.nav-l{font-size:10.5px;letter-spacing:1px;text-transform:uppercase;color:#64748b;padding:16px 12px 6px}
.side a.nv,.grp summary{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:9px;color:#cbd5e1;text-decoration:none;font-weight:500;cursor:pointer;list-style:none;position:relative}
.grp summary::-webkit-details-marker{display:none}
.side a.nv i,.grp summary>i:first-child{width:18px;text-align:center;color:#8193ab}
.side a.nv:hover,.grp summary:hover{background:rgba(255,255,255,.06);color:#fff}
.side a.nv.on{background:rgba(212,166,90,.14);color:#fff}.side a.nv.on i{color:var(--gold-2)}
.side a.nv.on:before{content:"";position:absolute;left:-12px;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--gold-2)}
.grp .car{margin-left:auto;font-size:10px;transition:transform .2s;color:#64748b}.grp[open] .car{transform:rotate(180deg)}
.grp a.sub{display:flex;align-items:center;gap:10px;padding:7px 12px 7px 42px;border-radius:8px;color:#a9b8cc;text-decoration:none;font-size:13px;position:relative}
.grp a.sub:before{content:"";position:absolute;left:21px;top:0;bottom:0;width:1px;background:rgba(255,255,255,.08)}
.grp a.sub i{font-size:11.5px;width:14px;color:#64748b}.grp a.sub:hover{color:#fff;background:rgba(255,255,255,.05)}.grp a.sub:hover i{color:var(--gold-2)}
.side-foot{margin-top:auto;padding:14px 12px 4px;font-size:12px;color:#8193ab;display:flex;align-items:center;gap:8px;border-top:1px solid rgba(255,255,255,.07)}
.dotlive{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.6);animation:pulse 2s infinite}
@keyframes pulse{70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
.mainc{min-width:0}
.top{background:rgba(255,255,255,.85);backdrop-filter:blur(10px);color:var(--ink);border-bottom:1px solid var(--line)}
.top-in{max-width:none;padding:0 30px;height:64px}
.search{flex:1;max-width:440px;display:flex;align-items:center;gap:10px;height:40px;padding:0 12px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;color:var(--mute);cursor:pointer}
.search kbd{margin-left:auto;font:600 11px/1 inherit;background:#fff;border:1px solid var(--line);border-radius:6px;padding:4px 6px}
.clock{margin-left:auto;color:var(--mute);font-size:13px;display:flex;gap:8px;align-items:center;font-variant-numeric:tabular-nums}
.user{margin-left:6px}.user summary:hover{background:#f1f5f9}.user .nm{color:var(--ink)}.user .nm small{color:var(--mute)}
.wrap{max-width:1240px;padding:26px 30px 50px;margin:0}
.hero{position:relative;overflow:hidden;border-radius:18px;padding:26px 28px;margin-bottom:26px;color:#fff;display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center;
  background:radial-gradient(600px 300px at 100% 0%,rgba(212,166,90,.28),transparent 60%),radial-gradient(500px 300px at 0% 100%,rgba(59,130,246,.22),transparent 60%),linear-gradient(135deg,#1F3A5F,#0c1f38);box-shadow:0 20px 40px -24px rgba(12,31,56,.7)}
.hero:after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:28px 28px;mask-image:linear-gradient(90deg,transparent,#000 60%);pointer-events:none}
.hero>*{position:relative;z-index:1}
.eyebrow{display:inline-flex;gap:8px;align-items:center;font-size:12px;color:#c7d3e3;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);padding:4px 10px;border-radius:99px}
.hero h1{margin:12px 0 6px;font-size:28px;letter-spacing:-.6px}.hero p{margin:0;color:#c7d3e3;max-width:460px}
.wave{display:inline-block;animation:wave 2.2s ease-in-out 1;transform-origin:70% 70%}@keyframes wave{10%,30%{transform:rotate(14deg)}20%{transform:rotate(-8deg)}40%{transform:rotate(-4deg)}50%,100%{transform:none}}
.hero-stats{display:flex;gap:10px;align-items:stretch}
.hs,.ring{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px 16px;min-width:120px;backdrop-filter:blur(4px)}
.hs b,.ring b{display:block;font-size:24px;font-weight:800;letter-spacing:-.5px;line-height:1.1}.hs b small{font-size:13px;color:#c7d3e3;margin-left:2px}
.hs>span,.ring>div>span{font-size:12px;color:#c7d3e3}.hs.warn{border-color:rgba(248,113,113,.6);background:rgba(220,38,38,.18)}
.ring{display:flex;gap:12px;align-items:center}.ring svg{width:56px;height:56px;transform:rotate(-90deg)}
.ring circle{fill:none;stroke-width:7}.ring .rt{stroke:rgba(255,255,255,.14)}.ring .rv{stroke:var(--gold-2);stroke-linecap:round;transition:stroke-dasharray 1s cubic-bezier(.2,.8,.2,1)}
.apps-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px}.apps-bar h2{margin:0;font-size:17px;letter-spacing:-.2px;margin-right:6px}
.seg{display:flex;gap:4px;background:#e9edf3;padding:4px;border-radius:11px}
.seg button{border:0;background:none;font:inherit;font-size:13px;font-weight:600;color:var(--mute);padding:6px 12px;border-radius:8px;cursor:pointer;display:flex;gap:7px;align-items:center}
.seg button em{font-style:normal;font-size:11px;background:rgba(15,23,42,.07);padding:1px 7px;border-radius:99px}
.seg button.on{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.12)}.seg button.on em{background:var(--navy);color:#fff}
.fsearch{margin-left:auto;position:relative}.fsearch i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px}
.fsearch input{height:38px;border:1px solid var(--line);border-radius:10px;padding:0 12px 0 34px;font:inherit;width:220px;background:#fff}.fsearch input:focus{outline:0;border-color:var(--navy);box-shadow:0 0 0 4px rgba(31,58,95,.1)}
.grid{grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}
.app{animation:rise .5s cubic-bezier(.2,.8,.2,1) both}.app[hidden]{display:none}
@keyframes rise{from{opacity:0;transform:translateY(14px)}}
.app.is-live:after{content:"";position:absolute;inset:0;background:radial-gradient(260px circle at var(--mx,50%) var(--my,0),color-mix(in srgb,var(--c) 12%,transparent),transparent 70%);opacity:0;transition:opacity .2s;pointer-events:none}
.app.is-live:hover:after{opacity:1}
.app.is-live:hover .mark{transform:scale(1.06) rotate(-4deg)}.mark{transition:transform .2s}
.bar i{transition:width 1s cubic-bezier(.2,.8,.2,1)}
.noapps{text-align:center;color:var(--mute);padding:40px}.noapps i{display:block;font-size:24px;color:#cbd5e1;margin-bottom:8px}
.pal{position:fixed;inset:0;background:rgba(12,31,56,.45);backdrop-filter:blur(3px);display:none;align-items:flex-start;justify-content:center;padding-top:12vh;z-index:80}.pal.open{display:flex}
.pal .pb{width:min(560px,92vw);background:#fff;border-radius:14px;box-shadow:0 30px 60px -20px rgba(0,0,0,.45);overflow:hidden}
.pal input{width:100%;border:0;border-bottom:1px solid var(--line);padding:16px 18px;font:inherit;font-size:15px;outline:0}
.pal ul{list-style:none;margin:0;padding:6px;max-height:50vh;overflow:auto}.pal li a{display:flex;gap:12px;align-items:center;padding:10px 12px;border-radius:8px;text-decoration:none;color:var(--ink)}
.pal li a i{width:18px;color:var(--mute)}.pal li.sel a,.pal li a:hover{background:#f1f5f9}.pal small{margin-left:auto;color:var(--mute)}
@media (max-width:1100px){.hero{grid-template-columns:1fr}.hero-stats{flex-wrap:wrap}}
@media (max-width:860px){.shell{grid-template-columns:1fr}.side{display:none}.search,.clock{display:none}.fsearch{margin-left:0}}

/* ---------- v3 right block ---------- */
.wrap{max-width:1180px}
.hero{grid-template-columns:1fr 400px;padding:30px 32px;gap:30px}
.hero h1{font-size:30px;margin:14px 0 6px}.hero p{font-size:15px}
.hero-cta{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.hb{display:inline-flex;align-items:center;gap:9px;height:42px;padding:0 18px;border-radius:11px;font-weight:700;text-decoration:none;font-size:14px;transition:transform .15s,background .15s}
.hb.gold{background:linear-gradient(135deg,var(--gold-2),var(--gold));color:var(--navy-3);box-shadow:0 8px 20px -8px rgba(212,166,90,.7)}.hb.gold:hover{transform:translateY(-1px)}
.hb.ghost{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);color:#fff}.hb.ghost:hover{background:rgba(255,255,255,.14)}
.ov{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:16px;backdrop-filter:blur(6px)}
.ov-h{font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#8ea2bd;margin:0 4px 10px}
.ov-g{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:rgba(255,255,255,.1);border-radius:12px;overflow:hidden}
.ov-i{background:#132c4b;padding:14px;display:flex;gap:12px;align-items:center}.ov-i>i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(212,166,90,.15);color:var(--gold-2);flex:none}
.ov-i b{display:block;font-size:20px;font-weight:800;letter-spacing:-.4px;line-height:1.15}.ov-i b em{font-style:normal}.ov-i span{font-size:11.5px;color:#9fb0c7}
.ov-i.warn>i{background:rgba(248,113,113,.18);color:#fca5a5}
.ov-i.ring{background:#132c4b;border:0;border-radius:0;padding:14px;min-width:0;backdrop-filter:none}.ov-i.ring svg{width:40px;height:40px;flex:none}
.sh{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:30px 0 14px}.sh h2{margin:0;font-size:18px;letter-spacing:-.3px}.sh span{color:var(--mute);font-size:13px}
.sh:first-of-type{margin-top:0}
.feats{display:grid;grid-template-columns:repeat(auto-fill,minmax(520px,1fr));gap:18px}
.feat{display:grid;grid-template-columns:230px 1fr;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;text-decoration:none;color:var(--ink);position:relative;box-shadow:var(--sh);
  transition:transform .2s,box-shadow .2s;animation:rise .5s cubic-bezier(.2,.8,.2,1) both}
.feat:hover{transform:translateY(-3px);box-shadow:0 24px 44px -22px color-mix(in srgb,var(--c) 55%,rgba(15,23,42,.5))}
.feat:after{content:"";position:absolute;inset:0;background:radial-gradient(320px circle at var(--mx,50%) var(--my,0),color-mix(in srgb,var(--c) 10%,transparent),transparent 70%);opacity:0;transition:opacity .2s;pointer-events:none}.feat:hover:after{opacity:1}
.fp,.tp{position:relative;background:radial-gradient(140% 120% at 0% 0%,color-mix(in srgb,var(--c) 45%,#0c1f38) 0%,#0c1f38 70%);display:grid;place-items:center;overflow:hidden}
.fp:before,.tp:before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.09) 1px,transparent 1px);background-size:14px 14px;mask-image:linear-gradient(135deg,#000,transparent 70%)}
.fp{padding:26px}.logo{position:relative;z-index:1;display:grid;place-items:center;width:100%}
.logo img{max-width:100%;max-height:58px;object-fit:contain;filter:drop-shadow(0 4px 12px rgba(0,0,0,.35));transition:transform .3s}
.feat:hover .logo img{transform:scale(1.05)}
.lockup{display:inline-flex;align-items:center;gap:10px;color:#fff;font-weight:800;font-size:22px;letter-spacing:-.4px}
.lockup i{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--c);color:#fff;font-size:17px;box-shadow:0 6px 16px -6px var(--c)}
.fb{padding:22px 24px;display:flex;flex-direction:column}
.frow{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.valid{font-size:12px;color:var(--mute);display:flex;gap:6px;align-items:center}
.fb h3{margin:0;font-size:19px;letter-spacing:-.3px}.fb .desc{margin:4px 0 16px;min-height:0}
.seats{background:#f7f9fc;border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:16px}
.seats .st{display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:8px}.seats .st span{color:var(--mute)}.seats .st b{font-weight:700}
.seats .bar{background:#e6ebf2}
.go{margin-top:auto;display:inline-flex;align-items:center;justify-content:center;gap:9px;height:44px;border-radius:11px;background:var(--c);color:#fff;font-weight:700;font-size:14px;box-shadow:0 10px 20px -12px var(--c)}
.go i{transition:transform .2s}.feat:hover .go i{transform:translateX(4px)}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px}
.tile{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--sh);display:flex;flex-direction:column;animation:rise .5s cubic-bezier(.2,.8,.2,1) both;transition:transform .2s,box-shadow .2s}
.tile:hover{transform:translateY(-2px);box-shadow:0 18px 34px -20px rgba(15,23,42,.35)}
.tp{height:118px;padding:18px 22px}.tp .logo img{max-height:44px}.tp .lockup{font-size:18px}.tp .lockup i{width:34px;height:34px;font-size:14px}
.tp .pill{position:absolute;top:12px;right:12px;z-index:2;backdrop-filter:blur(4px)}
.tp .pill.info{background:rgba(219,234,254,.92)}.tp .pill.mute{background:rgba(241,245,249,.9)}
.tb{padding:16px 18px 18px;display:flex;flex-direction:column;flex:1}.tb .desc{margin:0 0 12px;min-height:0}
.tb .kv{padding:5px 0;border-top:1px dashed var(--line)}
.ftag{margin-top:14px;display:flex;align-items:center;justify-content:center;gap:9px;height:38px;border-radius:10px;background:#f6f8fb;color:var(--mute);font-size:12.5px;font-weight:600;border:1px dashed #dbe2ec}
.spin{width:12px;height:12px;border-radius:50%;border:2px solid #cbd5e1;border-top-color:var(--c);animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
.t-other .tp{filter:saturate(.35) brightness(.92)}.t-other{opacity:.92}.t-other:hover .tp{filter:none}
.empty-live{background:#fff;border:1px dashed #cbd5e1;border-radius:16px;padding:26px;text-align:center;color:var(--mute)}.empty-live i{display:block;font-size:24px;color:#cbd5e1;margin-bottom:8px}
[hidden]{display:none!important}
@media (max-width:1180px){.hero{grid-template-columns:1fr}.feats{grid-template-columns:1fr}}
@media (max-width:620px){.feat{grid-template-columns:1fr}.fp{height:120px}}

/* ---------- v4: one consistent grid, full width ---------- */
.wrap{max-width:1500px;margin:0 auto;padding:26px 32px 50px}
.hero{grid-template-columns:1fr minmax(360px,420px)}
.tiles{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}
.tile{text-decoration:none;color:var(--ink);position:relative}
.tp{height:104px;padding:26px 20px 14px}.tp .pill{top:10px;right:10px;font-size:10.5px;padding:2px 8px}.tp .logo img{max-height:40px;max-width:82%}
.tb{padding:14px 18px 18px}.tb .desc{font-size:13px;margin:0 0 10px}
.tb .bar{margin:2px 0 6px;height:6px}
.t-live{border-color:color-mix(in srgb,var(--c) 30%,var(--line))}
.t-live:hover{transform:translateY(-3px);box-shadow:0 22px 40px -22px color-mix(in srgb,var(--c) 60%,rgba(15,23,42,.5))}
.t-live:after{content:"";position:absolute;inset:0;background:radial-gradient(260px circle at var(--mx,50%) var(--my,0),color-mix(in srgb,var(--c) 9%,transparent),transparent 70%);opacity:0;transition:opacity .2s;pointer-events:none}.t-live:hover:after{opacity:1}
.t-live .tp .pill.ok{background:rgba(236,253,243,.95)}
.t-live .go{margin-top:14px;height:40px;font-size:13.5px}.t-live:hover .go i{transform:translateX(4px)}.t-live .go i{transition:transform .2s}
.t-live:hover .logo img{transform:scale(1.05)}
.sh{margin:28px 0 12px}
@media (max-width:1180px){.hero{grid-template-columns:1fr}}

/* ---------- v5: light, minimal ---------- */
.wrap{max-width:1440px;margin:0 auto;padding:30px 36px 56px}
.ph{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:20px}
.ph h1{margin:0;font-size:26px;letter-spacing:-.5px;font-weight:700}.ph p{margin:4px 0 0;color:var(--mute);font-size:14px}
.ph-act{display:flex;gap:10px}
.btn2{white-space:nowrap;display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 16px;border-radius:10px;font-weight:600;font-size:13.5px;text-decoration:none;transition:background .15s,border-color .15s}
.btn2.primary{background:var(--navy);color:#fff}.btn2.primary:hover{background:var(--navy-2)}
.btn2.ghost{background:#fff;border:1px solid var(--line);color:var(--ink-2)}.btn2.ghost:hover{border-color:#cbd5e1;background:#f8fafc}
.strip{display:grid;grid-template-columns:repeat(4,1fr);background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 1px 2px rgba(15,23,42,.04);margin-bottom:32px}
.si{display:flex;gap:14px;align-items:center;padding:18px 22px;position:relative}.si+.si:before{content:"";position:absolute;left:0;top:18px;bottom:18px;width:1px;background:var(--line)}
.si .ic{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-size:15px;flex:none}
.ic.c1{background:#eef3fa;color:var(--navy)}.ic.c2{background:#fbf5ea;color:var(--gold)}.ic.c3{background:#ecfdf3;color:#15803d}.ic.c4{background:#f1f5f9;color:var(--ink-2)}
.si.warn .ic.c4{background:#fef2f2;color:#b91c1c}
.si>div{min-width:0;flex:1}.si b{display:block;font-size:20px;font-weight:700;letter-spacing:-.4px;line-height:1.2;white-space:nowrap}.si b small{font-size:13px;color:var(--mute);font-weight:500}
.si>div>span{font-size:12.5px;color:var(--mute)}
.mini{height:4px;background:#eef1f6;border-radius:99px;margin-top:8px;overflow:hidden;max-width:160px}.mini i{display:block;height:100%;background:var(--navy);border-radius:99px;transition:width 1s cubic-bezier(.2,.8,.2,1)}
.sh{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 14px}.sh h2{margin:0;font-size:17px;font-weight:700;letter-spacing:-.2px}.sh span{color:var(--mute);font-size:13px}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:18px}
.card{position:relative;background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;display:flex;flex-direction:column;text-decoration:none;color:var(--ink);
  box-shadow:0 1px 2px rgba(15,23,42,.04);transition:transform .18s,box-shadow .18s,border-color .18s;animation:rise .45s cubic-bezier(.2,.8,.2,1) both;overflow:hidden}
.card:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--c);opacity:.9}
.c-live:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--c) 40%,var(--line));box-shadow:0 16px 32px -18px rgba(15,23,42,.28)}
.c-live:after{content:"";position:absolute;inset:0;background:radial-gradient(240px circle at var(--mx,50%) var(--my,0),color-mix(in srgb,var(--c) 7%,transparent),transparent 70%);opacity:0;transition:opacity .2s;pointer-events:none}.c-live:hover:after{opacity:1}
.ch{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;min-height:46px;margin-bottom:14px}
.card .logo{display:flex;align-items:center;height:42px;min-width:0}
.card .logo img{max-height:38px;max-width:170px;object-fit:contain;object-position:left center;filter:none;transform:none!important}
.card .logo img.inv{filter:invert(1) hue-rotate(180deg) saturate(1.3)}
.lockup{display:inline-flex;align-items:center;gap:10px;font-weight:700;font-size:17px;letter-spacing:-.3px;color:var(--ink)}
.lockup i{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c);font-size:15px;box-shadow:none}
.card .pill{flex:none;font-size:11px}
.card .desc{margin:0 0 16px;color:var(--mute);font-size:13px;line-height:1.5;min-height:39px}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:12px 0;border-top:1px solid #f0f3f7}
.meta div{display:flex;flex-direction:column}.meta span{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;font-weight:600}
.meta b{font-size:14px;font-weight:600;margin-top:2px}.meta small{font-size:11.5px;color:var(--mute)}
.card .bar{height:5px;margin:0 0 4px}.card .bar i{background:var(--c)}
.cf{margin-top:auto;padding-top:14px}
.card .go{display:flex;align-items:center;justify-content:center;gap:8px;height:40px;border-radius:10px;background:var(--c);color:#fff;font-weight:600;font-size:13.5px}
.card .go i{font-size:12px;transition:transform .2s}.c-live:hover .go i{transform:translateX(3px)}
.card .note{display:flex;align-items:center;justify-content:center;gap:8px;height:40px;border-radius:10px;background:#f8fafc;color:var(--mute);font-size:12.5px;font-weight:500}
.c-other{background:#fcfdfe}.c-other .logo{filter:grayscale(1);opacity:.7;transition:filter .2s,opacity .2s}.c-other:hover .logo{filter:none;opacity:1}.c-other:before{opacity:.35}
@media (max-width:1100px){.strip{grid-template-columns:1fr 1fr}.si:nth-child(3):before{display:none}}
@media (max-width:620px){.strip{grid-template-columns:1fr}.si:before{display:none}}

/* ---------- v6: fits one screen ---------- */
.side{padding:12px 10px}.side .brand{padding:2px 8px 10px;font-size:16px}.side .brand .logo-mark{width:30px;height:30px}
.ws{margin:0 2px 8px;padding:7px 9px}.ws-i{width:28px;height:28px;font-size:13px}.ws b{font-size:12.5px}
.nav-l{padding:10px 12px 4px}
.side a.nv,.grp summary{padding:6px 12px;font-size:13.5px}
.grp a.sub{padding:4px 12px 4px 42px;font-size:12.5px}
.side-foot{padding:8px 12px 2px}
.top-in{height:52px}
.wrap{padding:16px 28px 20px}
.ph{margin-bottom:12px;align-items:center}.ph h1{font-size:21px}.ph p{font-size:13px;margin-top:2px}
.btn2{height:34px;padding:0 13px;font-size:13px}
.strip{margin-bottom:16px;border-radius:12px}.si{padding:10px 18px;gap:12px}.si+.si:before{top:10px;bottom:10px}
.si .ic{width:34px;height:34px;font-size:13px}.si b{font-size:17px}.si>div>span{font-size:12px}.mini{margin-top:5px;height:3px}
.sh{margin-bottom:10px;align-items:center}.sh h2{font-size:15.5px}.sh span{font-size:12.5px}.fsearch input{height:34px;width:200px}
.cards{gap:14px}
.card{padding:14px 16px;border-radius:12px}
.ch{min-height:0;margin-bottom:8px;align-items:center}.card .logo{height:32px}.card .logo img{max-height:30px;max-width:150px}
.lockup{font-size:15px;gap:8px}.lockup i{width:30px;height:30px;font-size:13px;border-radius:8px}
.card .desc{min-height:0;margin:0 0 8px;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.meta{display:flex;gap:14px;flex-wrap:wrap;padding:8px 0 0;border-top:1px solid #f0f3f7;font-size:12.5px;color:var(--ink-2)}
.meta span{display:inline-flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;color:var(--ink-2);font-weight:500;font-size:12.5px}.meta i{color:#94a3b8;font-size:11.5px}
.card .bar{margin:8px 0 0;height:4px}
.cf{padding-top:10px}.card .go,.card .note{height:34px;font-size:12.5px;border-radius:9px}

/* ---------- v6b: tighter ---------- */
.wrap{padding:12px 28px 14px}
.ph{margin-bottom:10px}.strip{margin-bottom:12px}.si{padding:8px 18px}
.sh{margin-bottom:8px}.sh>div{display:flex;align-items:baseline;gap:10px}.fsearch input{height:32px}
.cards{gap:12px}.card{padding:12px 14px}
.ch{margin-bottom:6px}.card .desc{margin-bottom:6px}.meta{padding-top:7px}
.cf{padding-top:9px}.card .go,.card .note{height:32px}
.ph>div:first-child{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}.ph p{margin:0}.wrap{padding-top:10px}

/* ---------- status checkboxes ---------- */
.chk{width:22px;height:22px;border-radius:6px;display:inline-grid;place-items:center;flex:none;font-size:12px;border:2px solid transparent}
.chk.on{background:#16a34a;color:#fff;box-shadow:0 2px 6px -1px rgba(22,163,74,.5)}
.chk.soon{background:#e5e7eb;color:#9ca3af;border-color:#d1d5db}
.chk.off{background:#fff;border-color:#cbd5e1}
.chk.bad{background:#dc2626;color:#fff}
.legend{display:inline-flex;gap:14px;flex-wrap:wrap}.legend em{font-style:normal;display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--mute)}
.legend .chk{width:16px;height:16px;font-size:9px;border-radius:4px;border-width:1.5px;box-shadow:none}
.c-soon .logo{opacity:.85}.muted2{color:#94a3b8!important}
/* licences table (Phase 1, 18 Nov 2026) */
.lic-t{background:#fff;border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);overflow-x:auto}
.lic-t table{width:100%;border-collapse:collapse;font-size:13.5px}.lic-t th{text-align:left;font-size:11.5px;color:var(--mute);font-weight:600;padding:10px 14px;border-bottom:1px solid var(--line);white-space:nowrap}
.lic-t td{padding:10px 14px;border-bottom:1px solid #f1f4f8;white-space:nowrap}.lic-t tr:last-child td{border-bottom:0}.lic-t td i{color:var(--mute);margin-right:4px}
.lic-t .btn2{width:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:12.5px;vertical-align:middle}.lic-t td.r form{display:inline}
.ucnt{margin-left:auto;font-style:normal;font-size:11px;background:var(--gold);color:#fff;padding:1px 8px;border-radius:99px}.lic-t td.r,.lic-t th.r{text-align:right}
.lp{display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:600}.lp-green{background:var(--ok-bg);color:var(--ok)}.lp-amber{background:#fffbeb;color:#b45309}.lp-red{background:var(--bad-bg);color:var(--bad)}.lp-grey{background:#f1f5f9;color:var(--mute)}
</style>
<link rel="stylesheet" href="assets/shell.css?v=9"><link rel="stylesheet" href="assets/theme-gold.css?v=3"></head><body>
<?php if ($user === null): ?>
<div class="auth">
  <aside class="auth-side">
    <a class="brand" href="./"><span class="logo-mark">AG</span><span>Ametecs<b>Global</b></span></a>
    <h1>One sign-in.<br><em>Every Ametecs app.</em></h1>
    <p>HR, payroll, attendance, productivity, collections and lead management &mdash; run your whole organisation from one secure workspace.</p>
    <div class="auth-apps">
      <?php foreach (AmetecsAccess::plugins() as $code => $p): ?><span><i class="fas <?= ag_h($p['icon'] ?? ($icons[$code] ?? 'fa-cube')) ?>"></i><?= ag_h((string) $p['name']) ?></span><?php endforeach; ?>
    </div>
    <small>&copy; <?= $now->format('Y') ?> Ametecs India Private Limited</small>
  </aside>
  <main class="auth-main"><div class="auth-box">
    <?php if ($ws !== null): ?><div class="co"><i class="fas fa-building"></i><?= ag_h((string) $ws['name']) ?></div><?php endif; ?>
    <?php if ($notice !== null): ?><div class="msg ok"><i class="fas fa-circle-check"></i><span><?= ag_h($notice) ?></span></div><?php endif; ?>
    <?php if ($mode === 'forgot'): ?>
      <h2>Reset your password</h2><p class="lead">Enter the email you sign in with and we&rsquo;ll send you a secure link.</p>
      <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
      <form method="post" action="<?= ag_h($cq('forgot=1')) ?>"><?= $cField() ?>
        <input type="hidden" name="csrf" value="<?= ag_h($session?->token() ?? '') ?>"><input type="hidden" name="do" value="forgot">
        <div class="field"><label>Work email</label><div class="inp"><i class="fas fa-envelope"></i><input type="email" name="email" required autofocus placeholder="name@company.com"></div></div>
        <button class="primary">Email me a reset link <i class="fas fa-arrow-right"></i></button>
      </form>
      <div class="alt"><a href="<?= ag_h($cq()) ?>"><i class="fas fa-arrow-left"></i> Back to sign in</a></div>
    <?php elseif ($mode === 'reset'): ?>
      <h2>Set a new password</h2><p class="lead">For <b><?= ag_h((string) $resetFor['email']) ?></b></p>
      <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
      <form method="post" action="./">
        <input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="reset"><input type="hidden" name="reset" value="<?= ag_h((string) $_GET['reset']) ?>">
        <div class="field"><label>New password <span style="color:var(--mute);font-weight:500"><?= \SmartPRS\Surface\Session::PASSWORD_FLOOR ?>+ characters</span></label><div class="inp"><i class="fas fa-lock"></i><input type="password" name="new" required minlength="<?= \SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" autocomplete="new-password" autofocus></div></div>
        <div class="field"><label>Confirm new password</label><div class="inp"><i class="fas fa-lock"></i><input type="password" name="again" required autocomplete="new-password"></div></div>
        <button class="primary">Set password</button>
      </form>
    <?php else: ?>
      <h2>Welcome back</h2><p class="lead"><?= $ws !== null ? 'Sign in to ' . ag_h((string) $ws['name']) . '&rsquo;s workspace.' : 'Sign in to your organisation&rsquo;s workspace.' ?></p>
      <?php if ($ws !== null && in_array((string) $ws['status_code'], ['suspended', 'closed'], true)) { $problem ??= 'This company\'s AmetecsGlobal account is suspended. Please contact Ametecs.'; } ?>
      <?php if ($badLink) { $problem ??= 'This sign-in link is not valid. Check the link with your company administrator.'; } ?>
      <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
      <?php if ($session !== null && $session->needsEnrolment()): ?>
        <p>No account exists yet. <a href="../plugins/smartprs3/public/app.php?r=signin">Create the first administrator</a>.</p>
      <?php elseif ($badLink): ?>
        <div class="alt"><a href="./">Go to the general sign-in page</a></div>
      <?php elseif ($session !== null): ?>
      <form method="post" action="<?= ag_h($cq()) ?>"><?= $cField() ?>
        <input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="signin">
        <div class="field"><label>Work email</label><div class="inp"><i class="fas fa-envelope"></i><input type="email" name="email" required autofocus autocomplete="username" placeholder="name@company.com"></div></div>
        <div class="field"><label>Password <a href="<?= ag_h($cq('forgot=1')) ?>">Forgot password?</a></label><div class="inp"><i class="fas fa-lock"></i><input type="password" name="password" required autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"></div></div>
        <button class="primary">Sign in <i class="fas fa-arrow-right"></i></button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <div class="secure"><i class="fas fa-shield-halved"></i> Secure sign-in &middot; your session is encrypted</div>
  </div></main>
</div>
<?php else:
  $orgIcons = ['Organization' => 'fa-sitemap', 'Employees' => 'fa-users', 'Logins & access' => 'fa-user-shield', 'Attendance devices' => 'fa-fingerprint'];
  $paletteItems = [];
  foreach (array_merge($groups['live'], $groups['soon'], $groups['other']) as $r) {
      $r['p']['open'] !== null && $r['st'] === 'on' && $paletteItems[] = ['t' => 'Open ' . $r['p']['name'], 'h' => (string) $r['p']['open'], 'i' => $icons[$r['code']] ?? 'fa-cube', 's' => 'App'];
  }
  if ($isAdmin) {
      $paletteItems[] = ['t' => 'App access', 'h' => 'org.php?s=ag-apps', 'i' => 'fa-puzzle-piece', 's' => 'Organization'];
      foreach (AmetecsSetup::CORE as $grp => $items) { foreach ($items as $label => $it) { $paletteItems[] = ['t' => $label, 'h' => 'org.php' . str_replace('?r=', '?s=', AmetecsSetup::href($it)), 'i' => $it[2], 's' => $grp]; } }
  } ?>
<div class="shell">
<?= AmetecsShell::nav($company, $isAdmin, $view === 'invoice' ? 'billing' : $view, ['updates' => $updCount, 'invoices' => $billOn ? count(AmetecsBilling::invoices($tenant, 'pending')) : 0, 'notifications' => count($notes)]) ?>
<div class="mainc">
<header class="top"><div class="top-in">
  <button type="button" class="drawer-btn" data-drawer aria-label="Menu"><i class="fas fa-bars"></i></button>
  <div class="crumb"><?= ag_h($company ?: 'AmetecsGlobal') ?> <i class="fas fa-chevron-right"></i> <?= ag_h(['home' => 'Dashboard', 'apps' => 'My applications', 'updates' => 'Updates', 'plugins' => 'Modules & plugins', 'licences' => 'Licences & plans', 'billing' => 'Payments & invoices', 'activity' => 'Activity history', 'support' => 'Support & help', 'account' => 'Account settings', 'notifications' => 'Notifications'][$view] ?? 'Dashboard') ?></div>
  <a class="bell" href="?v=notifications" title="Notifications"><i class="far fa-bell"></i><?= $notes !== [] ? '<em>' . count($notes) . '</em>' : '' ?></a>
  <div class="clock"><i class="far fa-clock"></i><span id="clock"><?= $now->format('h:i A') ?></span></div>
  <details class="user"><summary><span class="av"><?= ag_h($initials) ?></span><span class="nm"><?= ag_h($who) ?><small><?= ag_h(ucfirst((string) ($user['role'] ?? ''))) ?></small></span><i class="fas fa-chevron-down" style="font-size:11px;color:var(--mute)"></i></summary>
    <div class="menu"><div class="mh"><b><?= ag_h($who) ?></b><span><?= ag_h((string) ($user['email'] ?? '')) ?></span></div>
      <a href="?v=account"><i class="fas fa-user-gear"></i>Account settings</a><a data-theme-toggle><i class="fas fa-palette"></i><span data-theme-label>Classic navy theme</span></a>
      <?php if ($isAdmin): ?><a href="org.php?s=users"><i class="fas fa-user-shield"></i>User Management</a><?php endif; ?>
      <form method="post"><?= $cField() ?><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><button name="do" value="signout"><i class="fas fa-arrow-right-from-bracket"></i>Sign out</button></form>
    </div></details>
</div></header>
<main class="wrap">
<?php if ($view === 'updates'): ?>
  <?php $onlyApp = (string) ($_GET['app'] ?? ''); $updShow = $onlyApp !== '' && isset($upd[$onlyApp]) ? [$onlyApp => $upd[$onlyApp]] : $upd; ?>
  <div class="ph"><div><p style="margin:0 0 4px"><a href="?v=apps" style="color:var(--mute);text-decoration:none"><i class="fas fa-arrow-left"></i> My applications</a></p><h1>Updates<?= count($updShow) === 1 ? ': ' . ag_h((string) reset($updShow)['p']['name']) : '' ?></h1><p>New versions and patches released by Ametecs, with what changed. Add-on modules are under <a href="?v=plugins">Modules &amp; plugins</a>.</p></div></div>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <?php if (!AmetecsReleases::ready() || $upd === []): ?><div class="lic-t" style="padding:26px;text-align:center;color:var(--mute)">No updates to show yet.</div>
  <?php else: foreach ($updShow as $code => $u): $onprem = $u['l']['deployment'] === 'onprem'; ?>
    <div class="sh" style="margin-top:18px"><div><h2><?= ag_h((string) $u['p']['name']) ?></h2><span class="legend"><em><i class="fas fa-<?= $onprem ? 'server' : 'cloud' ?>"></i>&nbsp;<?= $onprem ? 'On-premise' : 'Cloud' ?> &middot; running <b><?= ag_h($u['current'] ?: ($onprem ? 'version not reported yet' : 'the latest, kept current by Ametecs')) ?></b></em></span></div></div>
    <?php if ($u['offer']['rollback'] !== null): ?><div class="msg"><i class="fas fa-rotate-left"></i><span>The version you run was withdrawn by Ametecs. Version <?= ag_h((string) $u['offer']['rollback']['version']) ?> is offered to your server instead.</span></div><?php endif; ?>
    <div class="lic-t"><table><tr><th>Version</th><th>What's new</th><th>Type</th><th>Price</th><th class="r"></th></tr>
    <?php foreach ($u['offer']['updates'] as $r): $pay = AmetecsReleases::payment($r, $tenant); ?><tr>
      <td style="white-space:nowrap"><b><?= ag_h($r['version']) ?></b><?= $r['mandatory'] ? ' <span class="lp lp-red">Mandatory</span>' : '' ?></td>
      <td style="white-space:normal;min-width:260px"><?= $r['title'] ? '<b>' . ag_h($r['title']) . '</b><br>' : '' ?><span class="muted2"><?= nl2br(ag_h((string) ($r['notes'] ?? ''))) ?></span><?= $r['requires_version'] ? '<br><small class="muted2">Needs ' . ag_h($r['requires_version']) . ' or newer</small>' : '' ?></td>
      <td><?= ag_h(AmetecsReleases::KINDS[$r['kind']] ?? '') ?></td>
      <td><?= ['free' => 'Free', 'granted' => '<span class="lp lp-green">Free for you</span>', 'paid' => '<span class="lp lp-green">Paid</span>', 'required' => ag_h($r['currency']) . ' ' . number_format((float) $r['price'], 2)][$pay] ?></td>
      <td class="r"><?php if (!$onprem): ?><span class="muted2">Ametecs installs this on the cloud for you</span>
        <?php elseif ($pay === 'required'): ?><?php if ($payOnline && $r['currency'] === 'INR'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="pay-upgrade"><input type="hidden" name="release" value="<?= (int) $r['id'] ?>"><button class="btn2 primary"><i class="fas fa-lock-open"></i>Pay &amp; upgrade</button></form>
          <?php else: ?><span class="lp lp-amber">Payment needed first</span> <span class="muted2">contact Ametecs to pay</span><?php endif; ?>
        <?php else: ?><span class="muted2">Install from your server's Updates screen</span><?= $r['downloadable'] ? ' &nbsp;<a class="btn2 ghost" href="?v=updates&amp;dl=' . (int) $r['id'] . '"><i class="fas fa-download"></i>Download</a>' : '' ?><?php endif; ?></td></tr>
    <?php endforeach; ?><?php if ($u['offer']['updates'] === []): ?><tr><td colspan="5" class="muted2"><i class="fas fa-circle-check" style="color:var(--ok)"></i> Up to date.</td></tr><?php endif; ?></table></div>
  <?php endforeach; ?>
    <p class="muted2" style="margin-top:18px"><i class="fas fa-code-branch"></i> Everything installed, downloaded or rolled back so far is in <a href="?v=activity">Activity history</a>.</p>
  <?php endif; ?>
<?php elseif ($view === 'billing'):
    $myInv = AmetecsBilling::invoices($tenant); $payId = (int) ($_GET['pay'] ?? 0); ?>
  <div class="ph"><div><h1>Payments &amp; invoices</h1><p>Invoices from Ametecs for <?= ag_h($company ?: 'your company') ?>: upgrades, licence renewals and services. Pay online with Razorpay (cards, UPI, net banking).</p></div></div>
  <?php if ($notice !== null): ?><div class="msg" style="background:var(--ok-bg);border-color:#bbf7d0;color:var(--ok)"><i class="fas fa-circle-check"></i><span><?= ag_h($notice) ?></span></div><?php endif; ?>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <div id="paymsg" class="msg" hidden></div>
  <div class="lic-t"><table><tr><th>Invoice</th><th>For</th><th>Total</th><th>Status</th><th>Issued</th><th class="r"></th></tr>
  <?php foreach ($myInv as $i): [$sl, $sc] = AmetecsBilling::STATUSES[$i['status']] ?? [$i['status'], 'grey']; ?><tr<?= $payId === (int) $i['id'] ? ' style="background:#fffbeb"' : '' ?>>
    <td><b><?= ag_h($i['number']) ?></b></td><td style="white-space:normal"><?= ag_h($i['description']) ?></td><td><?= ag_h($i['currency']) ?> <?= number_format((float) $i['total'], 2) ?><?= (float) $i['tax'] > 0 ? '<br><small class="muted2">incl. GST ' . ag_h($i['currency']) . ' ' . number_format((float) $i['tax'], 2) . '</small>' : '' ?></td>
    <td><span class="lp lp-<?= $sc ?>"><?= ag_h($sl) ?></span><?= $i['status'] === 'pending' ? '<br><small class="muted2">due ' . ag_h(date('d M Y', (int) strtotime((string) $i['due_on']))) . '</small>' : '' ?></td>
    <td><?= ag_h(date('d M Y', (int) strtotime((string) $i['issued_on']))) ?></td>
    <td class="r"><?php if ($i['status'] === 'pending' && $payOnline && $i['currency'] === 'INR'): ?><button class="btn2 primary paybtn" data-inv="<?= (int) $i['id'] ?>"><i class="fas fa-credit-card"></i>Pay now</button> <?php elseif ($i['status'] === 'pending'): ?><span class="muted2">contact Ametecs to pay</span> <?php endif; ?>
      <a class="btn2 ghost" href="?v=invoice&amp;id=<?= (int) $i['id'] ?>" target="_blank"><i class="fas fa-print"></i>Invoice</a></td></tr>
  <?php endforeach; ?><?php if ($myInv === []): ?><tr><td colspan="6" class="muted2">No invoices yet.</td></tr><?php endif; ?></table></div>
  <?php if ($payOnline): ?>
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <script>
  (function () {
    var csrf = <?= json_encode($session->token()) ?>, box = document.getElementById('paymsg');
    function say(ok, t) { box.hidden = false; box.textContent = t; box.style.background = ok ? '#ecfdf3' : ''; box.style.color = ok ? '#15803d' : ''; }
    function post(d) { return fetch('?v=billing', {method: 'POST', body: new URLSearchParams(Object.assign({csrf: csrf}, d))}).then(function (r) { return r.json(); }); }
    document.querySelectorAll('.paybtn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        post({do: 'pay-order', invoice: btn.dataset.inv}).then(function (o) {
          if (!o.ok) { say(false, o.error); btn.disabled = false; return; }
          if (typeof Razorpay === 'undefined') { say(false, 'The Razorpay window could not load - check the internet connection.'); btn.disabled = false; return; }
          new Razorpay({key: o.keyId, amount: o.amountPaise, currency: 'INR', order_id: o.orderId, name: 'Ametecs India', description: o.description,
            prefill: {email: o.email}, theme: {color: '#1F3A5F'},
            handler: function (r) {
              say(true, 'Confirming the payment…');
              post({do: 'pay-complete', invoice: btn.dataset.inv, razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature})
                .then(function (c) { say(c.ok, c.ok ? c.message : c.error); if (c.ok) { setTimeout(function () { location.href = '?v=billing'; }, 1800); } else { btn.disabled = false; } });
            },
            modal: {ondismiss: function () { btn.disabled = false; }}}).open();
        }).catch(function () { say(false, 'Could not reach the server. Check the connection and try again.'); btn.disabled = false; });
      });
    });
    <?= $payId > 0 ? "var auto = document.querySelector('.paybtn[data-inv=\"" . $payId . "\"]'); auto && auto.click();" : '' ?>
  })();
  </script>
  <?php endif; ?>
<?php elseif ($view === 'apps'): ?>
  <div class="pgh"><div><h1>My applications</h1><p>The applications <?= ag_h($company ?: 'your company') ?> holds: where each runs, which version, its licence, modules and updates.</p></div></div>
  <?php if ($notice !== null): ?><div class="okmsg"><i class="fas fa-circle-check"></i><?= ag_h($notice) ?></div><?php endif; ?>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <div class="myapps">
  <?php foreach (AmetecsAccess::plugins(true) as $code => $p): $l = $myLic[$code] ?? null; if ($l === null) { continue; }
      $st = AmetecsLicences::stateOf($l); [$sl, $sc] = AmetecsLicences::STATES[$st]; $op = $l['deployment'] === 'onprem'; $u = $upd[$code] ?? null;
      $ver = $op ? (string) ($l['app_version'] ?? '') : (string) ($u['current'] ?? AmetecsReleases::platformVersion($code));
      $next = $u['offer']['updates'][0] ?? null; $pl = $plugOk ? AmetecsPlugins::catalogue($code, $tenant) : [];
      $plOn = array_filter($pl, static fn ($x) => AmetecsPlugins::view($tenant, $x, $op)['enabled']);
      $seen = (string) ($l['last_seen_at'] ?? ''); $live = $st === 'on' && $p['open'] !== null && !$op; $arriving = $st === 'on' && !$op && $p['open'] === null; ?>
    <article class="ma" style="--c:<?= ag_h((string) $p['color']) ?>">
      <header><div class="logo"><?= $logoOf($code, $p) ?></div><span class="lp lp-<?= $sc ?>"><?= ag_h($sl) ?></span></header>
      <p class="desc"><?= ag_h((string) ($p['what'] ?: 'Ametecs application')) ?></p>
      <dl>
        <dt>Version</dt><dd><?= $ver !== '' ? ag_h($ver) : '<span class="muted2">' . ($op ? 'not reported yet' : '&mdash;') . '</span>' ?></dd>
        <dt>Licence</dt><dd><i class="fas fa-<?= $op ? 'server' : 'cloud' ?>"></i> <?= $op ? 'On-premise' : 'Cloud' ?> &middot; <?= ag_h(AmetecsLicences::PLANS[$l['plan']] ?? $l['plan']) ?> &middot; <?= ag_h($fmtDate((string) ($l['valid_until'] ?? ''))) ?></dd>
        <dt>Seats</dt><dd><?= $code === 'smartprs3' ? $used . ' / ' : '' ?><?= (int) $l['seats'] > 0 ? (int) $l['seats'] : '&infin;' ?> <?= ag_h((string) $p['unit']) ?></dd>
        <dt>Modules</dt><dd><?= $pl === [] ? '<span class="muted2">none offered</span>' : '<a href="?v=plugins#p-' . ag_h($code) . '">' . count($plOn) . ' on &middot; ' . count($pl) . ' available</a>' ?></dd>
        <?php if ($op): ?><dt>Server</dt><dd><?= ($l['fingerprint'] ?? '') === '' ? '<span class="lp lp-amber">not activated</span>' : ag_h((string) ($l['server_name'] ?: 'activated')) . ($seen !== '' ? ' <small class="muted2">checked in ' . ag_h(date('d M H:i', (int) strtotime($seen))) . '</small>' : '') ?></dd><?php endif; ?>
      </dl>
      <?php if (!$arriving): ?><div class="ma-upd <?= $next !== null ? 'has' : '' ?>"><?php if ($st !== 'on'): ?><i class="fas fa-circle-info"></i> <?= $st === 'expired' ? 'Renew the licence to receive updates.' : 'Contact Ametecs.' ?>
        <?php elseif ($next !== null && !$op): ?><i class="fas fa-cloud-arrow-down"></i> <span><b><?= ag_h((string) $next['version']) ?> released</b> &middot; Ametecs installs it on the cloud</span><a class="btn2 ghost" href="?v=updates&amp;app=<?= ag_h($code) ?>">What's new</a>
        <?php elseif ($next !== null): ?><i class="fas fa-cloud-arrow-down"></i> <b><?= ag_h((string) $next['version']) ?> available</b><?= $next['mandatory'] ? ' <span class="lp lp-red">Mandatory</span>' : '' ?><a class="btn2 primary" href="?v=updates&amp;app=<?= ag_h($code) ?>">Update</a>
        <?php elseif ($u !== null && $u['offer']['rollback'] !== null): ?><i class="fas fa-rotate-left"></i> <b>Your version was withdrawn</b><a class="btn2 primary" href="?v=updates&amp;app=<?= ag_h($code) ?>">See fix</a>
        <?php else: ?><i class="fas fa-circle-check"></i> Up to date<?php endif; ?></div><?php endif; ?>
      <footer><?php if ($live): ?><a class="btn2 primary" href="<?= ag_h((string) $p['open']) ?>"><i class="fas fa-arrow-up-right-from-square"></i>Open</a><?php elseif ($arriving): ?><span class="lp lp-grey">Arriving on AmetecsGlobal soon</span><?php elseif ($op && $st === 'on' && ($l['fingerprint'] ?? '') !== ''): ?><span class="muted2" style="font-size:12.5px"><i class="fas fa-server"></i> Open it on your own server</span><?php endif; ?>
        <?php if ($op && ($l['fingerprint'] ?? '') === '' && $st === 'on'): ?><span class="muted2" style="font-size:12px">Activate: enter the licence key on the app's activation screen on your server.</span><?php endif; ?></footer>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if ($myLic === []): ?><div class="lic-t"><div class="empty2" style="padding:30px">Your company does not hold any application yet. <a href="?v=support">Contact Ametecs</a>.</div></div><?php endif; ?>

<?php elseif ($view === 'plugins'): $apps = AmetecsAccess::plugins(true); $anyPl = false; ?>
  <div class="pgh"><div><h1>Modules &amp; plugins</h1><p>Add-ons for your applications. Buy one, or use those Ametecs gives you; then switch them on or off whenever you like.</p></div></div>
  <?php if ($notice !== null): ?><div class="okmsg"><i class="fas fa-circle-check"></i><?= ag_h($notice) ?></div><?php endif; ?>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <?php foreach ($myLic as $code => $l): if (AmetecsLicences::stateOf($l) !== 'on' || !$plugOk) { continue; } $pl = AmetecsPlugins::catalogue($code, $tenant); if ($pl === []) { continue; } $anyPl = true; $op = $l['deployment'] === 'onprem';
      uasort($pl, static fn ($a, $b) => (int) !AmetecsPlugins::authorised(AmetecsPlugins::state($tenant, $a['latest'])) <=> (int) !AmetecsPlugins::authorised(AmetecsPlugins::state($tenant, $b['latest']))); ?>
    <div class="sh" id="p-<?= ag_h($code) ?>"><div><h2><?= ag_h((string) ($apps[$code]['name'] ?? $code)) ?></h2><span class="legend"><em><i class="fas fa-<?= $op ? 'server' : 'cloud' ?>"></i>&nbsp;<?= $op ? 'On-premise: download and install on your server' : 'Cloud: Ametecs installs them for you' ?></em></span></div></div>
    <div class="plist">
    <?php foreach ($pl as $pc => $pp): $pv = AmetecsPlugins::view($tenant, $pp, $op); $r = $pp['latest']; [$psl, $psc] = AmetecsPlugins::STATES[$pv['state']]; $mine = AmetecsPlugins::authorised($pv['state']); ?>
      <div class="pl<?= $mine ? ' mine' : '' ?><?= $mine && !$pv['enabled'] ? ' off' : '' ?>">
        <div class="pl-i"><i class="fas fa-puzzle-piece"></i></div>
        <div class="pl-b"><b><?= ag_h($pp['title']) ?></b> <span class="lp lp-<?= $psc ?>"><?= ag_h($psl) ?></span>
          <p><?= ag_h(mb_strimwidth($pp['notes'], 0, 220, '…')) ?></p>
          <small class="muted2">Latest <?= ag_h((string) $r['version']) ?><?php if ($mine && $op): ?> &middot; <?= $pv['installed'] !== '' ? 'installed ' . ag_h($pv['installed']) . ($pv['update'] ? '' : ' (up to date)') : ($pv['downloaded'] !== '' ? 'downloaded ' . ag_h($pv['downloaded']) . ', waiting for your server to report the install' : 'not downloaded yet') ?><?php endif; ?></small>
          <?php if ($pv['update'] && trim((string) ($r['notes'] ?? '')) !== ''): ?><details><summary>What's new in <?= ag_h((string) $r['version']) ?></summary><p><?= nl2br(ag_h((string) $r['notes'])) ?></p></details><?php endif; ?></div>
        <div class="pl-a">
        <?php if ($pv['state'] === 'locked'): ?><span class="price"><?= ag_h($r['currency'] . ' ' . number_format((float) $r['price'], 2)) ?><small>+ GST</small></span>
          <?php if ($payOnline && $r['currency'] === 'INR'): ?><form method="post"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="pay-upgrade"><input type="hidden" name="release" value="<?= (int) $r['id'] ?>"><button class="btn2 primary"><i class="fas fa-cart-shopping"></i>Buy</button></form>
          <?php else: ?><a class="btn2 ghost" href="?v=support&amp;subject=<?= rawurlencode('Buy plugin: ' . $pp['title']) ?>">Contact to buy</a><?php endif; ?>
        <?php elseif ($pv['state'] === 'blocked'): ?><span class="muted2">Contact Ametecs</span>
        <?php else: ?>
          <?php if ($pv['update'] && $pv['canDownload']): ?><a class="btn2 primary" href="?v=plugins&amp;pdl=<?= ag_h(rawurlencode($code . ':' . $pc)) ?>"><i class="fas fa-arrow-up"></i>Update to <?= ag_h((string) $r['version']) ?></a>
          <?php elseif ($pv['canDownload'] && $pv['installed'] === '' && $pv['downloaded'] === ''): ?><a class="btn2 ghost" href="?v=plugins&amp;pdl=<?= ag_h(rawurlencode($code . ':' . $pc)) ?>"><i class="fas fa-download"></i>Download</a>
          <?php elseif ($op && $pv['why'] !== '' && $pv['installed'] === ''): ?><small class="muted2" style="max-width:180px;text-align:right"><?= ag_h($pv['why']) ?></small><?php endif; ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="plugin-toggle"><input type="hidden" name="app" value="<?= ag_h($code) ?>"><input type="hidden" name="plugin" value="<?= ag_h($pc) ?>"><input type="hidden" name="on" value="<?= $pv['enabled'] ? '0' : '1' ?>">
            <button class="tgl<?= $pv['enabled'] ? ' on' : '' ?>" title="<?= $pv['enabled'] ? 'On - click to switch off' : 'Off - click to switch on' ?>"><span></span><?= $pv['enabled'] ? 'On' : 'Off' ?></button></form>
        <?php endif; ?></div>
      </div>
    <?php endforeach; ?></div>
  <?php endforeach; ?>
  <?php if (!$anyPl): ?><div class="lic-t"><div class="empty2" style="padding:30px">No add-on modules are offered for your applications yet.</div></div><?php endif; ?>

<?php elseif ($view === 'licences'): $apps = AmetecsAccess::plugins(true); $lics = $myLic;
    uasort($lics, static fn ($a, $b) => strcmp((string) ($a['valid_until'] ?: '9999'), (string) ($b['valid_until'] ?: '9999'))); ?>
  <div class="pgh"><div><h1>Licences &amp; plans</h1><p>Your plan for each application: seats, price and validity. Renew online, or ask Ametecs to change a plan or add seats.</p></div></div>
  <?php if ($notice !== null): ?><div class="okmsg"><i class="fas fa-circle-check"></i><?= ag_h($notice) ?></div><?php endif; ?>
  <div class="grid2c"><?php foreach ($lics as $code => $l): $st = AmetecsLicences::stateOf($l); [$sl, $sc] = AmetecsLicences::STATES[$st]; $until = (string) ($l['valid_until'] ?? '');
      $days = $until === '' ? null : (int) floor((strtotime($until) - strtotime(AmetecsAccess::today())) / 86400); ?>
    <div class="ibox"><h3><i class="fas fa-key" style="color:var(--gold)"></i><?= ag_h((string) ($apps[$code]['name'] ?? $code)) ?><span class="lp lp-<?= $sc ?>" style="margin-left:auto"><?= ag_h($sl) ?></span></h3>
      <dl><dt>Plan</dt><dd><?= ag_h(AmetecsLicences::PLANS[$l['plan']] ?? $l['plan']) ?> &middot; <?= $l['deployment'] === 'onprem' ? 'On-premise' : 'Cloud' ?></dd>
        <dt>Seats</dt><dd><?= (int) $l['seats'] > 0 ? (int) $l['seats'] : 'Unlimited' ?> <?= ag_h((string) ($apps[$code]['unit'] ?? 'users')) ?><?= $code === 'smartprs3' ? ' <small class="muted2">(' . $used . ' in use)</small>' : '' ?></dd>
        <dt>Price</dt><dd><?= (float) ($l['price'] ?? 0) > 0 ? ag_h($l['currency'] . ' ' . number_format((float) $l['price'], 2)) . ' <small class="muted2">per ' . (['monthly' => 'month', 'yearly' => 'year'][$l['plan']] ?? 'period') . ' + GST</small>' : '<span class="muted2">as agreed with Ametecs</span>' ?></dd>
        <dt>Valid till</dt><dd><?= $until === '' ? 'No end date' : ag_h(date('d M Y', (int) strtotime($until))) . ($days !== null ? ' <small style="' . ($days < 0 ? 'color:#b91c1c' : ($days <= 30 ? 'color:#b45309' : 'color:#94a3b8')) . '">' . ($days < 0 ? 'ended ' . -$days . ' days ago' : $days . ' days left') . '</small>' : '') ?></dd></dl>
      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><?php if ($payOnline && (float) ($l['price'] ?? 0) > 0 && $l['currency'] === 'INR' && in_array($l['plan'], ['monthly', 'yearly'], true) && $st !== 'off'): ?>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="pay-renew"><input type="hidden" name="licence" value="<?= (int) $l['id'] ?>"><button class="btn2 primary"><i class="fas fa-rotate"></i>Renew online</button></form><?php endif; ?>
        <a class="btn2 ghost" href="?v=support&amp;subject=<?= rawurlencode('Change plan / seats: ' . ($apps[$code]['name'] ?? $code)) ?>"><i class="fas fa-sliders"></i>Change plan or seats</a></div></div>
  <?php endforeach; ?></div>
  <?php if ($lics === []): ?><div class="lic-t"><div class="empty2" style="padding:30px">No licences yet.</div></div><?php endif; ?>

<?php elseif ($view === 'notifications'): ?>
  <div class="pgh"><div><h1>Notifications</h1><p>Updates, invoices, renewals and installation alerts for <?= ag_h($company ?: 'your company') ?>, most urgent first. They clear by themselves once handled.</p></div></div>
  <div class="panel"><?= AmetecsShell::notificationList($notes) ?></div>

<?php elseif ($view === 'activity'): $apps = AmetecsAccess::plugins(true);
    $feed = [];
    foreach (AmetecsData::activity($tenant, 300) as $a) { $feed[] = [$a['at'], $a['who'], $a['what'], (string) $a['detail'], 'fa-circle-dot']; }
    foreach (AmetecsReleases::ready() ? AmetecsReleases::events(['tenant_id' => $tenant], 300) : [] as $e) { $feed[] = [$e['created_at'], $e['by_who'], ($apps[$e['app']]['name'] ?? $e['app']) . ' ' . $e['to_version'] . ': ' . str_replace('_', ' ', $e['action']), mb_strimwidth((string) $e['detail'], 0, 120, '…'), 'fa-code-branch']; }
    foreach (AmetecsLicences::events(null, $tenant, 300) as $e) { $feed[] = [$e['created_at'], $e['by_who'], ($apps[$e['app']]['name'] ?? $e['app']) . ' licence: ' . $e['action'], mb_strimwidth((string) $e['detail'], 0, 120, '…'), 'fa-key']; }
    usort($feed, static fn ($a, $b) => strcmp((string) $b[0], (string) $a[0])); ?>
  <div class="pgh"><div><h1>Activity history</h1><p>Everything that changed for your company: logins and settings, licences, payments, updates and installs, by whom and when.</p></div></div>
  <div style="margin-bottom:10px"><label class="fsearch" style="margin:0"><i class="fas fa-magnifying-glass"></i><input data-live="#act-t" placeholder="Filter by who or what" autocomplete="off"></label></div>
  <div class="lic-t"><table id="act-t" class="paged"><tr><th>When</th><th>What</th><th>Who</th><th>Detail</th></tr>
  <?php foreach ($feed as [$at, $who2, $what, $det, $ic]): ?><tr><td style="white-space:nowrap"><?= ag_h(date('d M Y H:i', (int) strtotime((string) $at))) ?></td><td style="white-space:normal"><i class="fas <?= $ic ?>" style="color:var(--gold)"></i> <?= ag_h((string) $what) ?></td><td class="muted2"><?= ag_h((string) $who2) ?></td><td class="muted2" style="white-space:normal;max-width:420px"><?= ag_h($det) ?></td></tr><?php endforeach; ?>
  <?php if ($feed === []): ?><tr><td colspan="4" class="muted2">Nothing yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'support'): $bs = AmetecsBilling::settings(); $sEmail = (string) (($bs['support_email'] ?? '') ?: $bs['email']); $sPhone = (string) ($bs['support_phone'] ?? ''); ?>
  <div class="pgh"><div><h1>Support &amp; help</h1><p>Questions, changes to your plan, or something not working: Ametecs is here to help.</p></div></div>
  <?php if ($okMsg !== null): ?><div class="okmsg"><i class="fas fa-circle-check"></i><?= ag_h($okMsg) ?></div><?php endif; ?>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <div class="grid2c">
    <div class="ibox"><h3><i class="fas fa-headset" style="color:var(--gold)"></i>Contact Ametecs</h3><dl>
      <dt>Company</dt><dd><?= ag_h((string) $bs['seller']) ?></dd>
      <?php if ($sEmail !== ''): ?><dt>Email</dt><dd><a href="mailto:<?= ag_h($sEmail) ?>"><?= ag_h($sEmail) ?></a></dd><?php endif; ?>
      <?php if ($sPhone !== ''): ?><dt>Phone</dt><dd><a href="tel:<?= ag_h(preg_replace('/[^0-9+]/', '', $sPhone)) ?>"><?= ag_h($sPhone) ?></a></dd><?php endif; ?>
      <?php if (($bs['support_hours'] ?? '') !== ''): ?><dt>Hours</dt><dd><?= ag_h((string) $bs['support_hours']) ?></dd><?php endif; ?>
      <dt>Address</dt><dd><?= nl2br(ag_h((string) $bs['address'])) ?></dd></dl></div>
    <div class="ibox"><h3><i class="fas fa-paper-plane" style="color:var(--gold)"></i>Send a message</h3>
      <form method="post" class="frm"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="support-send">
        <div><label>Subject</label><input name="subject" required maxlength="150" value="<?= ag_h((string) ($_POST['subject'] ?? $_GET['subject'] ?? '')) ?>"></div>
        <div><label>Message</label><textarea name="message" rows="5" required maxlength="5000"><?= ag_h($okMsg === null ? (string) ($_POST['message'] ?? '') : '') ?></textarea></div>
        <div><button class="btn2 primary"><i class="fas fa-paper-plane"></i>Send to Ametecs</button></div></form></div>
  </div>
  <div class="sh"><div><h2>Common questions</h2></div></div>
  <div class="faq">
    <details><summary>How do I add employees or give someone a login?</summary><p>Administrators add employees under Organization &rarr; Employee directory. Every employee with an email gets a login straight away; set or reset passwords in Users &amp; permissions.</p></details>
    <details><summary>An update is available. What do I do?</summary><p>Cloud applications are updated by Ametecs for you. For on-premise applications, open the app on your server and use its Updates screen; paid upgrades are unlocked once the invoice is paid under Payments &amp; invoices.</p></details>
    <details><summary>How do I renew or change my plan?</summary><p>Subscription &amp; plans shows each renewal date. Use Renew online where it is offered, or "Change plan or seats" to ask Ametecs.</p></details>
    <details><summary>We are moving our server. What happens to the licence?</summary><p>A licence works on one server at a time. Ask Ametecs to release it from the old server, then activate it on the new one with the same key.</p></details>
    <details><summary>Where are our invoices?</summary><p>Payments &amp; invoices lists every invoice with a printable copy you can save as PDF.</p></details>
  </div>

<?php elseif ($view === 'account'): ?>
  <div class="pgh"><div><h1>Account settings</h1><p>Your own login to AmetecsGlobal.</p></div></div>
  <?php if ($okMsg !== null): ?><div class="okmsg"><i class="fas fa-circle-check"></i><?= ag_h($okMsg) ?></div><?php endif; ?>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <div class="grid2c">
    <div class="ibox"><h3><i class="fas fa-user" style="color:var(--gold)"></i>Profile</h3><dl><dt>Name</dt><dd><?= ag_h($who) ?></dd><dt>Email</dt><dd><?= ag_h((string) ($user['email'] ?? '')) ?></dd>
      <dt>Role</dt><dd><?= ag_h(ucfirst((string) ($user['role'] ?? ''))) ?></dd><dt>Company</dt><dd><?= ag_h($company) ?></dd></dl>
      <p class="muted2" style="font-size:12.5px;margin:12px 0 0">Your name and email are kept with your employee record<?= $isAdmin ? ' (Organization &rarr; Employee directory)' : '; ask your administrator to change them' ?>.</p></div>
    <div class="ibox"><h3><i class="fas fa-lock" style="color:var(--gold)"></i>Change password</h3>
      <form method="post" class="frm"><input type="hidden" name="csrf" value="<?= ag_h($session->token()) ?>"><input type="hidden" name="do" value="account-password">
        <div><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
        <div><label>New password (<?= \SmartPRS\Surface\Session::PASSWORD_FLOOR ?>+ characters)</label><input type="password" name="new" required minlength="<?= \SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" autocomplete="new-password"></div>
        <div><label>New password again</label><input type="password" name="again" required autocomplete="new-password"></div>
        <div><button class="btn2 primary"><i class="fas fa-check"></i>Change password</button></div></form></div>
  </div>
<?php else: ?>
  <div class="ph"><div><h1><?= $greet ?>, <?= ag_h((string) strtok($who, ' ')) ?></h1>
      <p><?= $now->format('l, d M Y') ?><?= $company !== '' ? ' &middot; ' . ag_h($company) : '' ?></p></div></div>
  <?php if ($problem !== null): ?><div class="msg"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
  <?php if (isset($dashHtml)): ?><div id="dash" data-every="60"><?= $dashHtml ?></div>
  <script>
  (function () {   // filters change -> fetch the dashboard for them; every minute -> fetch again (live)
    var box = document.getElementById('dash'), busy = false;
    function qs() { var p = new URLSearchParams(new FormData(document.getElementById('dfilters'))); [...p.keys()].forEach(function (k) { if (!p.get(k)) p.delete(k); }); if (p.get('d') !== 'custom') { p.delete('from'); p.delete('to'); } return p.toString(); }
    function load(q, push) {
      if (busy) return; busy = true; box.classList.add('loading');
      fetch('?' + (q ? q + '&' : '') + 'frag=1', {credentials: 'same-origin'}).then(function (r) { if (!r.ok || r.redirected) throw 0; return r.text(); })
        .then(function (h) { box.innerHTML = h; if (push) history.replaceState(null, '', q ? '?' + q : './'); })
        .catch(function () { var u = document.getElementById('dupd'); if (u) u.textContent = 'could not refresh - retrying'; })
        .finally(function () { busy = false; box.classList.remove('loading'); });
    }
    box.addEventListener('change', function (e) {
      if (!e.target.closest('#dfilters')) return;
      var custom = e.target.name === 'd' && e.target.value === 'custom';
      box.querySelectorAll('.dcust').forEach(function (x) { x.hidden = document.querySelector('#dfilters [name=d]').value !== 'custom'; });
      if (!custom) load(qs(), true);
    });
    box.addEventListener('click', function (e) { if (e.target.closest('[data-reset]')) { e.preventDefault(); load('', true); } });
    setInterval(function () { if (!document.hidden) load(location.search.replace(/^\?/, ''), false); }, 60000);
  })();
  </script>
  <?php else: ?>
  <?php // PHASE 6 (owner, 18 Nov 2026): one block per application the company uses, with that app's own figures, so the
        // administrator sees the business at a glance without opening each app. Apps not held are not shown at all.
  $dashApps = array_merge($groups['live'], $groups['soon']); $later = [];
  foreach ($dashApps as $r): $code = $r['code']; $p = $r['p']; $l = $myLic[$code] ?? null; $live = $r['p']['open'] !== null && ($l['deployment'] ?? 'cloud') !== 'onprem';
      $stx = $isAdmin && $l !== null ? AmetecsShell::appStats($tenant, $code, $l) : null;
      if (!$live && empty($stx['tiles']) && ($l['deployment'] ?? 'cloud') !== 'onprem') { $later[] = (string) $p['name']; continue; }   // nothing to show yet: one line below, not an empty block ?>
    <section class="dapp" style="--c:<?= ag_h((string) $p['color']) ?>">
      <header><div class="logo"><?= $logoOf($code, $p) ?></div>
        <span class="muted2 dmeta"><?= ag_h((string) ($p['what'] ?: '')) ?></span>
        <?php if ($live): ?><a class="btn2 primary" href="<?= ag_h((string) $p['open']) ?>">Open <i class="fas fa-arrow-right"></i></a><?php elseif (($l['deployment'] ?? '') === 'onprem'): ?><span class="lp lp-grey">On your server</span><?php else: ?><span class="lp lp-grey">Arriving soon</span><?php endif; ?></header>
      <?php if (!empty($stx['tiles'])): ?><div class="ftiles"><?php foreach ($stx['tiles'] as $t): ?><div class="ft <?= ag_h($t['tone']) ?>"><span><?= ag_h($t['label']) ?></span><b><?= ag_h($t['value']) ?></b><?= $t['sub'] !== '' ? '<small>' . ag_h($t['sub']) . '</small>' : '' ?></div><?php endforeach; ?></div>
        <?php if (!empty($stx['list']['rows'])): ?><ul class="dlist"><li class="dl-h"><?= ag_h($stx['list']['title']) ?></li><?php foreach ($stx['list']['rows'] as $lr): ?><li><?= ag_h($lr['text']) ?><small><?= ag_h($lr['sub']) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($stx['at'])): ?><p class="dnote">As reported by your server at <?= ag_h(date('d M H:i', (int) strtotime($stx['at']))) ?></p><?php endif; ?>
      <?php elseif ($isAdmin): ?><p class="dnote"><?= !empty($stx['error']) ? ag_h($stx['error']) : 'A summary appears here once ' . ag_h((string) $p['name']) . ' reports its figures.' ?></p><?php endif; ?>
    </section>
  <?php endforeach; ?>
  <?php if ($later !== []): ?><p class="dnote later"><i class="fas fa-hourglass-half"></i> Also in your plan, arriving on AmetecsGlobal soon: <?= ag_h(implode(', ', $later)) ?>.</p><?php endif; ?>
  <?php if ($dashApps === []): ?><div class="lic-t"><div class="empty2" style="padding:30px">No applications are switched on for your company yet.<?= $isAdmin ? ' <a href="?v=support">Contact Ametecs</a>.' : '' ?></div></div><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</main>
<footer class="foot">&copy; <?= $now->format('Y') ?> Ametecs India Private Limited &middot; AmetecsGlobal</footer>
</div></div>
<div class="pal" id="pal" onclick="if(event.target===this)pal(false)"><div class="pb"><input id="palq" placeholder="Search apps and settings…" autocomplete="off"><ul id="pall"></ul></div></div>
<script>
(function(){
document.querySelectorAll('[data-count]').forEach(function(el){var n=+el.dataset.count,t0=null;function f(t){t0=t0||t;var k=Math.min(1,(t-t0)/800);el.textContent=Math.round(n*(1-Math.pow(1-k,3))).toLocaleString('en-IN');if(k<1)requestAnimationFrame(f)}requestAnimationFrame(f)});
setTimeout(function(){document.querySelectorAll('[data-da]').forEach(function(c){c.setAttribute('stroke-dasharray',c.dataset.da)});document.querySelectorAll('[data-w]').forEach(function(i){i.style.width=i.dataset.w+'%'})},120);
var ck=document.getElementById('clock');function tick(){if(!ck)return;ck.textContent=new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',timeZone:'Asia/Kolkata'})}setInterval(tick,15000);
// app search across all sections
var q=document.getElementById('appq'),cards=[].slice.call(document.querySelectorAll('[data-n]'));
q&&q.addEventListener('input',function(){var t=q.value.toLowerCase().trim(),n=0;cards.forEach(function(c){var ok=!t||c.dataset.n.indexOf(t)>=0;c.hidden=!ok;n+=ok?1:0});document.getElementById('noapps').hidden=n>0;
});
// spotlight follows the mouse on live cards
document.querySelectorAll('.c-live').forEach(function(c){c.addEventListener('mousemove',function(e){var r=c.getBoundingClientRect();c.style.setProperty('--mx',(e.clientX-r.left)+'px');c.style.setProperty('--my',(e.clientY-r.top)+'px')})});
// palette
var items=<?= json_encode($paletteItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,P=document.getElementById('pal'),Q=document.getElementById('palq'),L=document.getElementById('pall'),sel=0,cur=[];
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function draw(){var t=Q.value.toLowerCase();cur=items.filter(function(x){return !t||(x.t+' '+x.s).toLowerCase().indexOf(t)>=0}).slice(0,12);sel=Math.min(sel,Math.max(0,cur.length-1));
  L.innerHTML=cur.map(function(x,i){return '<li'+(i===sel?' class="sel"':'')+'><a href="'+esc(x.h)+'"><i class="fas '+esc(x.i)+'"></i>'+esc(x.t)+'<small>'+esc(x.s)+'</small></a></li>'}).join('')||'<li style="padding:14px;color:#64748b">Nothing matches.</li>'}
window.pal=function(o){P.classList.toggle('open',o);if(o){Q.value='';sel=0;draw();setTimeout(function(){Q.focus()},10)}};
Q.addEventListener('input',function(){sel=0;draw()});
Q.addEventListener('keydown',function(e){if(e.key==='ArrowDown'){sel=Math.min(sel+1,cur.length-1);draw();e.preventDefault()}else if(e.key==='ArrowUp'){sel=Math.max(sel-1,0);draw();e.preventDefault()}else if(e.key==='Enter'&&cur[sel]){location.href=cur[sel].h}});
document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();pal(!P.classList.contains('open'))}else if(e.key==='Escape')pal(false)});
document.addEventListener('click',function(e){document.querySelectorAll('details.user[open]').forEach(function(d){if(!d.contains(e.target))d.removeAttribute('open')})});
})();
</script>
<?php endif; ?>
<script src="assets/ui.js?v=7"></script>
</body></html>
