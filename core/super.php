<?php
/**
 * AMETECSGLOBAL — SUPER ADMIN CONSOLE (Ametecs staff only).
 *
 *   Dashboard   counts, apps in use, licences expiring
 *   Clients     every client (tenant); New client = company + master company + first administrator + app licences
 *   Client      details, suspend/reactivate/close, apps & licences, logins (create admin, set password, disable/enable)
 *   Licences    every licence of every app: cloud / on-premise, plan, status, seats, validity, keys, servers, history
 *   Updates     releases, patches and plugins for every app: upload, publish / schedule, who gets them, free grants,
 *               install on Ametecs' cloud server (with backup) and roll back; every download / install / failure logged
 *   Billing     invoices and Razorpay payments: raise, check with Razorpay, mark paid offline, cancel, refund, print
 *   Payments    (Settings) Razorpay keys, GST %, seller details on invoices
 *   Apps        the catalogue, who uses each app, seats
 *   Activity    every change made here: who, when, what
 *   Super Admins Ametecs staff accounts (platform_users)
 *   My account  change own password
 *
 * Clients are SmartPRS3's `tenants` rows (same database), so a client made here works in SmartPRS3 at once.
 * Licences live in the central database (core/licensing.php: ag_licences). Nothing is ever deleted: clients are suspended
 * or closed, licences suspended or cancelled.
 * Sign-in: `platform_users`, 5 wrong tries = 5 minutes locked; cookie AMETECSGLOBAL_SUPER (shared with SmartPRS3's console).
 */
declare(strict_types=1);

require __DIR__ . '/access.php';
require __DIR__ . '/sample.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;
use SmartPRS\Surface\Users;

session_name('AMETECSGLOBAL_SUPER');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$pdo = Database::connect(AG_BASE . '/.env');
$platform = $pdo === null ? null : new SqlStore($pdo);
$hasher = PasswordHasher::platformDefault();
$plugins = AmetecsAccess::plugins();
$statuses = ['active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Deactivated'];
$timezones = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'UTC'];
$problem = $pdo === null ? 'The database is not available: ' . Database::why() : null;
$notice = null;
foreach (['flash_problem' => 'problem', 'flash_notice' => 'notice'] as $k => $var) {
    if (isset($_SESSION[$k])) {
        $$var = (string) $_SESSION[$k];
        unset($_SESSION[$k]);
    }
}

$me = (($_SESSION['user']['platform'] ?? false) === true) ? $_SESSION['user'] : null;
$meEmail = (string) ($me['email'] ?? '');
$me !== null && AmetecsLicences::$actor = $meEmail;
$back = static function (string $to, ?string $ok = null, ?string $bad = null): never {
    $ok !== null && $_SESSION['flash_notice'] = $ok;
    $bad !== null && $_SESSION['flash_problem'] = $bad;
    header('Location: super.php' . $to);
    exit;
};
$newPassword = static fn (): string => substr(strtr(base64_encode(random_bytes(12)), '+/=', 'kQz'), 0, 12);
$tenant = static function (string $id) use ($pdo): ?array {
    if ($pdo === null || $id === '' || !ctype_digit($id)) {
        return null;
    }
    $q = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
};
$admins = static function (string $tid) use ($pdo): array {
    $q = $pdo->prepare("SELECT id, name, email, status, disabled_at, created_at FROM users WHERE tenant_id = ? ORDER BY (status = 'administrator') DESC, id");
    $q->execute([$tid]);
    return $q->fetchAll(\PDO::FETCH_ASSOC);
};
/** Licences from the form, validated: [plugin => [on, seats, until]] or an error string. */
$readLicences = static function (array $in) use ($plugins): array|string {
    $out = [];
    // An app switched on with no valid-till date runs for one year from today (a half-typed date arrives empty).
    $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('+1 year -1 day')->format('Y-m-d');
    foreach ($plugins as $code => $p) {
        $r = (array) ($in[$code] ?? []);
        $until = trim((string) ($r['until'] ?? ''));
        if ($until !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            return $p['name'] . ': the valid-till date is not a date.';
        }
        $on = isset($r['on']);
        $out[$code] = ['on' => $on, 'seats' => max(0, (int) ($r['seats'] ?? 0)), 'until' => $on && $until === '' ? $year : $until];
        if ($on && $out[$code]['seats'] < 1) {
            return $p['name'] . ' is switched on: give it the number of ' . $p['unit'] . ' (at least 1).';
        }
    }
    return $out;
};
$describe = static fn (array $lic): string => implode(', ', array_map(static fn (string $c, array $l): string
    => $c . ($l['on'] ? ' ON ' . $l['seats'] . ' till ' . $l['until'] : ' off'), array_keys($lic), $lic));

/* ------------------------------------------------------------------ actions (POST) */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $pdo !== null) {
    $do = (string) ($_POST['do'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $back('', null, 'This page had expired. Please try again.');
    }

    if ($do === 'signin') {
        $tries = (int) ($_SESSION['super_tries'] ?? 0);
        if ((int) ($_SESSION['super_locked'] ?? 0) > time()) {
            $back('', null, 'Too many attempts. Try again in a few minutes.');
        }
        $q = $pdo->prepare('SELECT * FROM platform_users WHERE LOWER(email) = ? LIMIT 1');
        $q->execute([strtolower(trim((string) ($_POST['email'] ?? '')))]);
        $u = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($u === null || trim((string) ($u['disabled_at'] ?? '')) !== '' || !$hasher->verify((string) ($_POST['password'] ?? ''), (string) $u['password'])) {
            $_SESSION['super_tries'] = $tries + 1;
            $_SESSION['super_locked'] = $tries + 1 >= 5 ? time() + 300 : 0;
            $back('', null, 'Those credentials were not accepted.');
        }
        session_regenerate_id(true);
        $_SESSION['super_tries'] = 0;
        $pdo->prepare('UPDATE platform_users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        // The shape SmartPRS3's console writes, so its console accepts this sign-in too.
        $_SESSION['user'] = ['id' => 'platform-' . $u['id'], 'email' => (string) $u['email'], 'name' => (string) ($u['name'] ?? ''),
            'role' => 'administrator', 'employee_id' => null, 'tenant_id' => '', 'platform' => true, 'platform_role' => (string) $u['role'],
            'platform_id' => (string) $u['id']];
        AmetecsData::log((string) $u['email'], 'Signed in');
        $back('');
    }

    if ($me === null) {
        $back('', null, 'Please sign in.');
    }

    if ($do === 'signout') {
        AmetecsData::log($meEmail, 'Signed out');
        $_SESSION = [];
        session_regenerate_id(true);
        $back('');
    }

    if ($do === 'client-create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_POST['code'] ?? '')));
        $slug = (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) (($_POST['slug'] ?? '') ?: $name)));
        $tz = in_array($_POST['timezone'] ?? '', $timezones, true) ? (string) $_POST['timezone'] : 'Asia/Kolkata';
        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $adminPassword = (string) ($_POST['admin_password'] ?? '') ?: $newPassword();
        $lic = $readLicences((array) ($_POST['p'] ?? []));
        $keep = $_POST;
        unset($keep['csrf'], $keep['admin_password']);
        $_SESSION['form'] = $keep;
        $err = match (true) {
            $name === '' || $code === '' => 'A client needs a company name and a client code.',
            filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false => 'Give the client administrator a valid email — it is their sign-in.',
            strlen($adminPassword) < Session::PASSWORD_FLOOR => 'The administrator password needs at least ' . Session::PASSWORD_FLOOR . ' characters (or leave it empty to generate one).',
            is_string($lic) => $lic,
            !in_array(true, array_column($lic, 'on'), true) => 'Switch on at least one app — a client without a licence cannot use anything.',
            default => null,
        };
        if ($err === null) {
            $dup = $pdo->prepare('SELECT name FROM tenants WHERE UPPER(code) = ?');
            $dup->execute([$code]);
            $err = ($n = $dup->fetchColumn()) !== false ? "Client code {$code} is already used by {$n}." : null;
        }
        if ($err === null) {
            // A client login and a Super Admin login may share an email: they sign in on different pages and never mix.
            $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?');
            $dup->execute([$adminEmail]);
            $err = (int) $dup->fetchColumn() > 0 ? 'That email already has a client login. Use a different email.' : null;
        }
        if ($err !== null) {
            $back('?p=client-new', null, $err);
        }
        $taken = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE subdomain = ?');
        $taken->execute([$slug]);
        if ($slug === '' || (int) $taken->fetchColumn() > 0) {
            $slug .= (string) random_int(100, 999);
        }

        $pdo->beginTransaction();
        try {
            $t = $platform->put('tenants', ['code' => $code, 'name' => $name, 'status_code' => 'active', 'business_timezone' => $tz,
                'subdomain' => $slug, 'state' => trim((string) ($_POST['state'] ?? '')) ?: null, 'onboarded_on' => date('Y-m-d'), 'provisioned_at' => date('Y-m-d H:i:s')]);
            $tid = (string) $t['id'];
            $tStore = $platform->forTenant($tid);
            $tStore->put('companies', ['name' => trim((string) ($_POST['legal_name'] ?? '')) ?: $name, 'timezone' => $tz,
                'email' => trim((string) ($_POST['contact_email'] ?? '')) ?: null, 'phone' => trim((string) ($_POST['contact_phone'] ?? '')) ?: null]);
            $made = (new Users($tStore, new Authorisation(), $hasher))->create($adminEmail, $adminPassword, 'administrator');
            if (!$made['ok']) {
                throw new \RuntimeException('The administrator login was not created: ' . $made['why']);
            }
            $pdo->commit();
        } catch (\Throwable $x) {
            $pdo->inTransaction() && $pdo->rollBack();
            $back('?p=client-new', null, 'Nothing was saved. ' . $x->getMessage());
        }
        $all = AmetecsAccess::all();
        foreach ($lic as $c => $l) {
            $all[$tid][$c] = $l + ['changed' => date('c') . ' by ' . $meEmail];
        }
        AmetecsAccess::save($all);
        AmetecsData::saveClient($tid, ['contact_name' => trim((string) ($_POST['contact_name'] ?? '')), 'contact_email' => trim((string) ($_POST['contact_email'] ?? '')),
            'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')), 'notes' => trim((string) ($_POST['notes'] ?? ''))]);
        AmetecsData::log($meEmail, 'Created client ' . $name . ' (' . $code . ')', $tid, 'admin ' . $adminEmail . '; ' . $describe($lic));
        unset($_SESSION['form']);
        $_SESSION['reveal'] = ['tid' => $tid, 'email' => $adminEmail, 'password' => $adminPassword, 'link' => AmetecsMail::clientLink($slug)];
        $back('?p=client&id=' . $tid, 'Client ' . $name . ' created with its administrator login and licences.');
    }

    $t = $tenant($id);
    if (in_array($do, ['client-save', 'client-status', 'licences-save', 'admin-create', 'login-password', 'login-toggle', 'login-link'], true) && $t === null) {
        $back('?p=clients', null, 'Unknown client.');
    }

    if ($do === 'client-save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_POST['code'] ?? '')));
        $slug = (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) ($_POST['slug'] ?? '')));
        $dup = $pdo->prepare('SELECT name FROM tenants WHERE (UPPER(code) = ? OR (subdomain = ? AND ? <> \'\')) AND id <> ?');
        $dup->execute([$code, $slug, $slug, $id]);
        if ($name === '' || $code === '') {
            $back('?p=client&id=' . $id, null, 'A client needs a name and a code.');
        }
        if (($n = $dup->fetchColumn()) !== false) {
            $back('?p=client&id=' . $id, null, 'That code or sign-in link is already used by ' . $n . '.');
        }
        $platform->put('tenants', ['id' => $id, 'name' => $name, 'code' => $code, 'subdomain' => $slug ?: null,
            'business_timezone' => in_array($_POST['timezone'] ?? '', $timezones, true) ? (string) $_POST['timezone'] : $t['business_timezone'],
            'state' => trim((string) ($_POST['state'] ?? '')) ?: null]);
        AmetecsData::saveClient($id, ['contact_name' => trim((string) ($_POST['contact_name'] ?? '')), 'contact_email' => trim((string) ($_POST['contact_email'] ?? '')),
            'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')), 'notes' => trim((string) ($_POST['notes'] ?? ''))]);
        AmetecsData::log($meEmail, 'Updated client details', $id, $name . ' (' . $code . ')');
        $back('?p=client&id=' . $id, 'Details saved.');
    }

    if ($do === 'client-status') {
        $to = (string) ($_POST['to'] ?? '');
        if (!isset($statuses[$to])) {
            $back('?p=client&id=' . $id, null, 'Unknown status.');
        }
        $platform->put('tenants', ['id' => $id, 'status_code' => $to]);
        AmetecsData::log($meEmail, 'Client is now ' . $statuses[$to], $id, 'was ' . $t['status_code']);
        $back('?p=client&id=' . $id, $t['name'] . ' is now ' . strtolower($statuses[$to]) . '.' . ($to === 'active' ? '' : ' Its people can no longer sign in. Nothing was deleted.'));
    }

    if ($do === 'licences-save') {
        $lic = $readLicences((array) ($_POST['p'] ?? []));
        if (is_string($lic)) {
            $_SESSION['form'] = ['lic_for' => $id, 'p' => (array) ($_POST['p'] ?? [])];   // shown again exactly as typed
            $back('?p=client&id=' . $id . '#apps', null, $lic . ' Nothing was saved yet - your entries are still below.');
        }
        $defaulted = array_keys(array_filter($lic, static fn ($l, $c) => $l['on'] && trim((string) ($_POST['p'][$c]['until'] ?? '')) === '', ARRAY_FILTER_USE_BOTH));
        $all = AmetecsAccess::all();
        foreach ($lic as $c => $l) {
            $all[$id][$c] = $l + ['changed' => date('c') . ' by ' . $meEmail];
        }
        AmetecsAccess::save($all);
        AmetecsData::log($meEmail, 'Changed apps & licences', $id, $describe($lic));
        $back('?p=client&id=' . $id . '#apps', 'Apps & licences saved. The client sees the change on their next page.'
            . ($defaulted === [] ? '' : ' No valid-till date was given for ' . implode(', ', array_map(static fn ($c) => $plugins[$c]['name'], $defaulted)) . ', so it runs for one year (till ' . date('d M Y', (int) strtotime($lic[$defaulted[0]]['until'])) . ').'));
    }

    if ($do === 'admin-create') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pw = (string) ($_POST['password'] ?? '') ?: $newPassword();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?');
        $dup->execute([$email]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || (int) $dup->fetchColumn() > 0) {
            $back('?p=client&id=' . $id . '#logins', null, 'Give a valid email that has no client login yet.');
        }
        $tStore = $platform->forTenant($id);
        if ($tStore->all('companies') === []) {
            $tStore->put('companies', ['name' => (string) $t['name']]);
        }
        $made = (new Users($tStore, new Authorisation(), $hasher))->create($email, $pw, 'administrator');
        if (!$made['ok']) {
            $back('?p=client&id=' . $id . '#logins', null, $made['why']);
        }
        AmetecsData::log($meEmail, 'Created administrator login', $id, $email);
        $_SESSION['reveal'] = ['tid' => $id, 'email' => $email, 'password' => $pw];
        $back('?p=client&id=' . $id . '#logins', 'Administrator ' . $email . ' created.');
    }

    if ($do === 'login-password' || $do === 'login-toggle' || $do === 'login-link') {
        $uid = (string) ($_POST['user'] ?? '');
        $own = array_values(array_filter($admins($id), static fn (array $u): bool => (string) $u['id'] === $uid));
        if ($own === []) {
            $back('?p=client&id=' . $id . '#logins', null, 'That login does not belong to this client.');
        }
        if ($do === 'login-link') {
            if (trim((string) ($own[0]['disabled_at'] ?? '')) !== '') {
                $back('?p=client&id=' . $id . '#logins', null, 'That login is deactivated. Reactivate it first.');
            }
            $sent = AmetecsMail::resetLink($pdo, $id, $uid);
            $sent['ok'] && AmetecsData::log($meEmail, 'Emailed a password reset link', $id, (string) $own[0]['email']);
            $back('?p=client&id=' . $id . '#logins', $sent['ok'] ? 'Reset link emailed to ' . $own[0]['email'] . ' (valid once, for a limited time).' : null,
                $sent['ok'] ? null : 'The reset link was not sent: ' . $sent['why'] . ' Use "Set password now" instead.');
        }
        if ($do === 'login-toggle') {
            $off = trim((string) ($own[0]['disabled_at'] ?? '')) === '';
            $pdo->prepare('UPDATE users SET disabled_at = ' . ($off ? 'NOW()' : 'NULL') . ', updated_at = NOW() WHERE id = ? AND tenant_id = ?')->execute([$uid, $id]);
            AmetecsData::log($meEmail, ($off ? 'Deactivated' : 'Reactivated') . ' login', $id, (string) $own[0]['email']);
            $back('?p=client&id=' . $id . '#logins', (string) $own[0]['email'] . ($off ? ' can no longer sign in.' : ' can sign in again.'));
        }
        $pw = (string) ($_POST['password'] ?? '') ?: $newPassword();
        $set = (new Users($platform->forTenant($id), new Authorisation(), $hasher))->setPassword($uid, $pw, (string) ($me['id'] ?? 'platform'));
        if (!$set['ok']) {
            $back('?p=client&id=' . $id . '#logins', null, $set['why']);
        }
        AmetecsData::log($meEmail, 'Set a new password', $id, (string) $own[0]['email']);
        $_SESSION['reveal'] = ['tid' => $id, 'email' => (string) $own[0]['email'], 'password' => $pw];
        $back('?p=client&id=' . $id . '#logins', 'New password set for ' . $own[0]['email'] . '.');
    }

    /* ---------------- licences (Phase 1, 18 Nov 2026) */
    if (str_starts_with($do, 'licence-') && $do !== 'licences-save') {
        if (!AmetecsLicences::ready()) {
            $back('?p=licences', null, 'The licence tables are not on this PC yet. They arrive with the next sync (or run SYNC.bat).');
        }
        if ($do === 'licence-new') {
            $tid = (string) ($_POST['tenant'] ?? '');
            $app = (string) ($_POST['app'] ?? '');
            if ($tenant($tid) === null || !isset($plugins[$app])) {
                $back('?p=licences', null, 'Choose a client and an app.');
            }
            if (($ex = AmetecsLicences::row($tid, $app)) !== null && $ex['status'] !== 'cancelled') {
                $back('?p=licence&id=' . (int) $ex['id'], null, 'That client already has a ' . $plugins[$app]['name'] . ' licence - here it is.');
            }
            $dep = isset(AmetecsLicences::DEPLOYMENTS[$_POST['deployment'] ?? '']) ? (string) $_POST['deployment'] : 'cloud';
            $plan = isset(AmetecsLicences::PLANS[$_POST['plan'] ?? '']) ? (string) $_POST['plan'] : 'yearly';
            $seed = ['plan' => $plan, 'valid_until' => null];
            $until = trim((string) ($_POST['valid_until'] ?? '')) ?: AmetecsLicences::nextUntil($seed);
            $r = AmetecsLicences::put($tid, $app, ['status' => 'active', 'deployment' => $dep, 'plan' => $plan, 'seats' => max(1, (int) ($_POST['seats'] ?? 1)),
                'starts_on' => $today = AmetecsAccess::today(), 'valid_until' => $until]);
            AmetecsData::log($meEmail, 'Added ' . $plugins[$app]['name'] . ' licence (' . AmetecsLicences::DEPLOYMENTS[$dep] . ', ' . AmetecsLicences::PLANS[$plan] . ')', $tid);
            $back('?p=licence&id=' . (int) $r['id'], 'Licence created.' . ($dep === 'onprem' ? ' Issue its licence key below for the installing engineer.' : ''));
        }
        $lic = AmetecsLicences::byId($id);
        if ($lic === null) {
            $back('?p=licences', null, 'Unknown licence.');
        }
        $tid = (string) $lic['tenant_id'];
        $app = (string) $lic['app'];
        $to = '?p=licence&id=' . (int) $id;
        $an = $plugins[$app]['name'] ?? $app;
        if ($do === 'licence-save') {
            $dep = (string) ($_POST['deployment'] ?? '');
            $plan = (string) ($_POST['plan'] ?? '');
            $date = static fn (string $k): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($_POST[$k] ?? ''))) === 1 ? trim((string) $_POST[$k]) : null;
            $price = trim((string) ($_POST['price'] ?? ''));
            $err = match (true) {
                !isset(AmetecsLicences::DEPLOYMENTS[$dep]) => 'Choose cloud or on-premise.',
                !isset(AmetecsLicences::PLANS[$plan]) => 'Choose a plan.',
                (int) ($_POST['seats'] ?? 0) < 1 => 'Seats must be at least 1.',
                $price !== '' && !is_numeric($price) => 'The price must be a number.',
                $plan !== 'perpetual' && $date('valid_until') === null => 'A ' . strtolower(AmetecsLicences::PLANS[$plan]) . ' licence needs a valid-till date.',
                default => null,
            };
            if ($err !== null) {
                $back($to, null, $err . ' Nothing was saved.');
            }
            AmetecsLicences::put($tid, $app, ['deployment' => $dep, 'plan' => $plan, 'seats' => (int) $_POST['seats'], 'starts_on' => $date('starts_on'),
                'valid_until' => $date('valid_until'), 'price' => $price === '' ? null : number_format((float) $price, 2, '.', ''),
                'currency' => in_array($_POST['currency'] ?? '', ['INR', 'USD', 'AED', 'SAR', 'EUR', 'GBP'], true) ? (string) $_POST['currency'] : 'INR',
                'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0, 'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null]);
            AmetecsData::log($meEmail, 'Changed ' . $an . ' licence', $tid);
            $back($to, 'Licence saved.');
        }
        // UNDO AN ONLINE PURCHASE (owner, 19 Nov 2026, option B): a client's payment applies Super Admin's prices at once;
        // Super Admin can put the licence back to how it was before that payment (the money is refunded separately).
        if ($do === 'licence-undo') {
            $ev = null;
            foreach (AmetecsLicences::events((string) $lic['id']) as $e) {
                (string) $e['id'] === (string) ($_POST['event'] ?? '') && $ev = $e;
            }
            if ($ev === null || !preg_match('/paid |bought online/i', (string) $ev['action'])) {
                $back($to, null, 'Only an online purchase can be undone here.');
            }
            preg_match_all('/(\w+) (.*?) -> (.*?)(?:, |$)/', (string) $ev['detail'], $mm, PREG_SET_ORDER);
            $set = [];
            foreach ($mm as [, $k, $was]) {
                in_array($k, ['plan', 'status', 'seats', 'valid_until', 'price', 'currency'], true) && $set[$k] = $was === '(empty)' ? null : $was;
            }
            if ($set === []) {
                $back($to, null, 'Nothing to put back for that entry.');
            }
            AmetecsLicences::put($tid, $app, $set, 'Undid: ' . $ev['action']);
            AmetecsData::log($meEmail, 'Undid an online purchase on ' . $an, $tid, (string) $ev['action']);
            $back($to, 'Put back as it was before that payment. Refund the money from Payments & invoices if needed.');
        }
        if ($do === 'licence-status') {
            $act = (string) ($_POST['to'] ?? '');
            ($_POST['back'] ?? '') === 'licences' && $to = '?p=licences';
            ($_POST['back'] ?? '') === 'dashboard' && $to = '?p=dashboard';   // quick action on the Licences list: stay there
            $set = match ($act) {
                'active' => ['status' => 'active'],
                'suspended' => ['status' => 'suspended'],
                'cancelled' => ['status' => 'cancelled', 'auto_renew' => 0] + ($lic['deployment'] === 'cloud' ? ['activated_at' => null] : []),   // cloud: uninstalled too - buying again means Download again
                'renew' => ($n = AmetecsLicences::nextUntil($lic)) === null ? null : ['valid_until' => $n, 'status' => $lic['status'] === 'cancelled' ? 'active' : $lic['status']],
                'unbind' => ['fingerprint' => null, 'activated_at' => null],
                'uninstall' => $lic['deployment'] === 'cloud' ? ['activated_at' => null] : null,   // the client downloads and installs it again
                default => null,
            };
            if ($set === null) {
                $back($to, null, $act === 'renew' ? 'A perpetual licence does not renew - change the plan or the date instead.' : 'Unknown action.');
            }
            $label = ['active' => 'Activated', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'renew' => 'Renewed', 'unbind' => 'Released from its server', 'uninstall' => 'Uninstalled (client downloads it again)'][$act];
            AmetecsLicences::put($tid, $app, $set, $label);
            AmetecsData::log($meEmail, $label . ' ' . $an . ' licence', $tid, $act === 'renew' ? 'valid till ' . $set['valid_until'] : '');
            $back($to, ['active' => 'Licence is active: the client can open ' . $an . '.', 'suspended' => 'Licence suspended: ' . $an . ' no longer opens for this client. Nothing was deleted.',
                'cancelled' => 'Licence cancelled. Its history and the client\'s data are kept; Activate brings it back.', 'renew' => 'Renewed till ' . date('d M Y', (int) strtotime((string) ($set['valid_until'] ?? ''))) . '.',
                'unbind' => 'The licence is free to activate on a new server.', 'uninstall' => 'Uninstalled. The client sees Download again; their data is kept.'][$act]);
        }
        // PLUGINS FOR THIS CLIENT (Phase 6): give free / take back, block, allow or stop downloads.
        if ($do === 'licence-plugin') {
            $pp = AmetecsPlugins::ready() ? (AmetecsPlugins::catalogue($app)[(string) ($_POST['plugin'] ?? '')] ?? null) : null;
            $set = match ((string) ($_POST['act'] ?? '')) {
                'grant' => [['granted' => 1, 'enabled' => 1], 'plugin given free', 'given free: the client can use it without paying'],
                'ungrant' => [['granted' => 0], 'plugin free grant removed', 'free grant taken back (a purchase, if any, still counts)'],
                'block' => [['blocked' => 1], 'plugin blocked', 'disabled for this client'],
                'unblock' => [['blocked' => 0], 'plugin unblocked', 'enabled again for this client'],
                'dl-off' => [['download' => 0], 'plugin downloads off', 'downloads turned off for this client'],
                'dl-on' => [['download' => 1], 'plugin downloads on', 'downloads allowed for this client'],
                default => null,
            };
            if ($pp === null || $set === null) {
                $back($to . '#plugins', null, 'Unknown plugin or action.');
            }
            AmetecsPlugins::put($tid, $pp['latest'], $set[0], $set[1]);
            AmetecsData::log($meEmail, $an . ' plugin ' . $pp['title'] . ': ' . $set[2], $tid);
            $back($to . '#plugins', $pp['title'] . ': ' . $set[2] . '.');
        }
        if ($do === 'licence-code') {   // a licence code (LC) the client enters to apply these approved terms (owner, 19 Nov 2026)
            $cu = (string) ($_POST['valid_until'] ?? ''); $cs = (int) ($_POST['seats'] ?? 0); $cp = (string) ($_POST['plan'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cu) || $cs < 1 || !isset(AmetecsLicences::PLANS[$cp])) {
                $back($to . '#codes', null, 'Choose the plan, seats and valid-till date for the code.');
            }
            $code = AmetecsLicences::issueCode($lic, ['plan' => $cp, 'seats' => $cs, 'valid_until' => $cu], max(1, min(365, (int) ($_POST['days'] ?? 30))));
            AmetecsData::log($meEmail, 'Issued a ' . $an . ' licence code', $tid, $cs . ' seats till ' . $cu);
            $_SESSION['reveal_code'] = ['id' => $id, 'code' => $code];
            $back($to . '#codes', 'Licence code issued. Send it to the client: they enter it on Licences & plans and it applies straight away.');
        }
        if ($do === 'licence-code-revoke') {
            $n = AmetecsLicences::revokeCodes($tid, $app, (string) ($_POST['tail'] ?? ''));
            AmetecsLicences::event($lic, 'Withdrew a licence code', '…' . ($_POST['tail'] ?? ''));
            $back($to . '#codes', $n > 0 ? 'Code withdrawn: it no longer works.' : 'That code was already used, withdrawn or lapsed.');
        }
        if ($do === 'licence-key') {
            $key = AmetecsLicences::issueKey($tid, $app);
            AmetecsData::log($meEmail, 'Issued a new ' . $an . ' licence key', $tid);
            $_SESSION['reveal_key'] = ['id' => $id, 'key' => $key];
            $back($to, 'New licence key issued.' . (trim((string) ($lic['key_hash'] ?? '')) !== '' ? ' The old key no longer works.' : ''));
        }
        if ($do === 'licence-lic') {
            try {
                $tok = AmetecsLicences::licFile($lic, trim((string) ($_POST['fingerprint'] ?? '')) ?: trim((string) ($lic['fingerprint'] ?? '')));
            } catch (\Throwable $x) {
                $back($to, null, $x->getMessage());
            }
            AmetecsData::log($meEmail, 'Downloaded a ' . $an . ' .lic file', $tid);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $app . '-' . preg_replace('/[^a-z0-9]+/i', '-', AmetecsAccess::tenantName($tid)) . '.lic"');
            echo $tok;
            exit;
        }
        $back($to, null, 'Nothing to do.');
    }

    /* ---------------- updates & releases (Phase 2, 18 Nov 2026) */
    if (str_starts_with($do, 'release-')) {
        if (!AmetecsReleases::ready()) {
            $back('?p=releases', null, 'The update tables are not on this PC yet. They arrive with the next sync (or run SYNC.bat).');
        }
        if ($do === 'release-upload' || $do === 'release-import') {
            $app = (string) ($_POST['app'] ?? '');
            if (!isset($plugins[$app])) {
                $back('?p=releases', null, 'Choose the app this package is for.');
            }
            if ($do === 'release-upload') {
                $f = $_FILES['package'] ?? null;
                $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($code !== UPLOAD_ERR_OK) {
                    $back('?p=releases', null, $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                        ? 'The file is bigger than this server accepts (' . ini_get('upload_max_filesize') . '). Copy it into core\\data\\releases\\incoming and use "Add from the incoming folder".'
                        : 'Choose the package (.zip) to upload.');
                }
                $file = (string) $f['tmp_name'];
            } else {
                $pick = basename((string) ($_POST['incoming'] ?? ''));
                $file = AmetecsReleases::dir('incoming') . '/' . $pick;
                if ($pick === '' || !in_array($pick, AmetecsReleases::incoming(), true)) {
                    $back('?p=releases', null, 'Choose a zip from the incoming folder.');
                }
            }
            $res = AmetecsReleases::upload($app, (string) ($_POST['kind'] ?? 'full'), $file, $_POST);
            if (!$res['ok']) {
                $back('?p=releases', null, $res['why'] . ' Nothing was added.');
            }
            $do === 'release-import' && @unlink($file);
            AmetecsData::log($meEmail, 'Uploaded ' . $plugins[$app]['name'] . ' ' . $res['release']['version'] . ' (' . strtolower(AmetecsReleases::KINDS[$res['release']['kind']]) . ')');
            $back('?p=release&id=' . (int) $res['release']['id'], 'Package checked and stored as a draft. Choose who gets it, then publish.'
                . (trim((string) $res['release']['signature']) === '' ? ' It is NOT signed: this server has no signing key, so it can be tested here but never installed on a production server.' : ''));
        }
        if ($do === 'release-rollback') {
            $app = (string) ($_POST['app'] ?? '');
            $res = isset($plugins[$app]) ? AmetecsReleases::rollbackPlatform($app) : ['ok' => false, 'message' => 'Unknown app.'];
            $res['ok'] && AmetecsData::log($meEmail, 'Rolled back ' . $plugins[$app]['name'] . ' on the cloud server', '', $res['message']);
            $back('?p=releases', $res['ok'] ? $res['message'] : null, $res['ok'] ? null : $res['message']);
        }
        $rel = AmetecsReleases::byId($id);
        if ($rel === null) {
            $back('?p=releases', null, 'Unknown release.');
        }
        $to = '?p=release&id=' . (int) $id;
        $rn = ($plugins[$rel['app']]['name'] ?? $rel['app']) . ' ' . $rel['version'];
        if ($do === 'release-save') {
            $price = trim((string) ($_POST['price'] ?? ''));
            $req = trim((string) ($_POST['requires_version'] ?? ''));
            if (($price !== '' && !is_numeric($price)) || ($req !== '' && preg_match('/^\d+(\.\d+){0,3}$/', $req) !== 1)) {
                $back($to, null, 'Price must be a number and "requires" a version like 2.1.0. Nothing was saved.');
            }
            AmetecsReleases::save($rel, ['title' => trim((string) ($_POST['title'] ?? '')) ?: null, 'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'requires_version' => $req ?: null, 'min_php' => trim((string) ($_POST['min_php'] ?? '')) ?: null, 'mandatory' => isset($_POST['mandatory']) ? 1 : 0,
                'downloadable' => isset($_POST['downloadable']) ? 1 : 0, 'price' => is_numeric($price) && (float) $price > 0 ? number_format((float) $price, 2, '.', '') : null,
                'currency' => in_array($_POST['currency'] ?? '', ['INR', 'USD', 'AED', 'SAR', 'EUR', 'GBP'], true) ? (string) $_POST['currency'] : 'INR'], 'details changed');
            AmetecsData::log($meEmail, 'Changed release ' . $rn);
            $back($to, 'Saved.');
        }
        if ($do === 'release-audience') {
            $all = ($_POST['audience'] ?? '') === 'all';
            $pick = [];
            foreach ((array) ($_POST['t'] ?? []) as $tid => $v) {
                if (ctype_digit((string) $tid) && $tenant((string) $tid) !== null && !empty($v['on'])) {
                    $pick[(string) $tid] = !empty($v['free']);
                }
            }
            if (!$all && $pick === []) {
                $back($to . '#who', null, 'Tick at least one client, or choose "every client with this app".');
            }
            AmetecsReleases::save($rel, ['audience' => $all ? 'all' : 'selected'], 'audience changed');
            AmetecsReleases::setAccess($rel, $pick);
            AmetecsData::log($meEmail, 'Changed who gets ' . $rn, '', $all ? 'every client' . ($pick ? ', ' . count(array_filter($pick)) . ' free' : '') : count($pick) . ' client(s)');
            $back($to . '#who', 'Saved who gets this release.');
        }
        if ($do === 'release-status') {
            $st = (string) ($_POST['to'] ?? '');
            $when = trim((string) ($_POST['publish_at'] ?? ''));
            $set = match ($st) {
                'publish' => ['status' => 'published', 'publish_at' => null],
                'schedule' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $when) === 1 ? ['status' => 'published', 'publish_at' => str_replace('T', ' ', $when) . ':00'] : null,
                'disable' => ['status' => 'disabled'],
                'withdraw' => ['status' => 'withdrawn'],
                'draft' => ['status' => 'draft', 'publish_at' => null],
                default => null,
            };
            if ($set === null) {
                $back($to, null, $st === 'schedule' ? 'Choose the date and time to publish.' : 'Unknown action.');
            }
            if ($rel['status'] === 'withdrawn' && $st !== 'draft') {
                $back($to, null, 'A withdrawn release is never offered again. Upload a new version instead.');
            }
            $label = ['publish' => 'published', 'schedule' => 'scheduled', 'disable' => 'disabled', 'withdraw' => 'withdrawn', 'draft' => 'back to draft'][$st];
            AmetecsReleases::save($rel, $set, $label);
            AmetecsData::log($meEmail, ucfirst($label) . ' ' . $rn, '', $st === 'schedule' ? 'for ' . $set['publish_at'] : '');
            $back($to, ['publish' => $rn . ' is live: clients it goes to see it now.', 'schedule' => $rn . ' goes live on ' . date('d M Y, h:i A', (int) strtotime((string) $set['publish_at'])) . '.',
                'disable' => $rn . ' is paused: no client sees it until you publish it again.', 'withdraw' => $rn . ' is withdrawn. Clients already on it are offered the previous stable version.',
                'draft' => $rn . ' is a draft again.'][$st]);
        }
        if ($do === 'release-install') {
            session_write_close();
            $res = AmetecsReleases::installOnPlatform($rel);
            session_start();
            AmetecsData::log($meEmail, ($res['ok'] ? 'Installed ' : 'Install failed: ') . $rn . ' on the cloud server', '', $res['message']);
            $_SESSION['install_log'] = $res['log'];
            $back($to, $res['ok'] ? $res['message'] . ' The sync now shares the new code with every PC.' : null, $res['ok'] ? null : 'Not installed: ' . $res['message']);
        }
        if ($do === 'release-file') {
            $path = AmetecsReleases::path($rel);
            if (!is_file($path)) {
                $back($to, null, 'The package file is not on this server.');
            }
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
            exit;
        }
        $back($to, null, 'Nothing to do.');
    }

    /* ---------------- billing (Phase 3, 18 Nov 2026) */
    if ($do === 'prices-save') {   // self-service price list (owner, 18 Nov 2026: clients buy and change plans themselves)
        $pr = [];
        foreach (array_keys($plugins) as $c) {
            foreach (['monthly', 'yearly'] as $k) {
                $v = trim((string) ($_POST['price'][$c][$k] ?? ''));
                if ($v !== '' && (!is_numeric($v) || (float) $v < 0)) {
                    $back('?p=gateway', null, 'Prices must be numbers (leave empty for "not sold online").');
                }
                (float) $v > 0 && $pr[$c][$k] = round((float) $v, 2);
            }
        }
        AmetecsBilling::savePrices($pr);
        AmetecsData::log($meEmail, 'Changed self-service prices', '', implode(', ', array_map(static fn ($c) => $c . ' ' . json_encode($pr[$c]), array_keys($pr))));
        $back('?p=gateway', 'Prices saved. Clients see them under Licences & plans.');
    }
    if ($do === 'gateway-save') {
        $gst = (string) ($_POST['gst_percent'] ?? '18');
        if (!is_numeric($gst) || (float) $gst < 0 || (float) $gst > 50) {
            $back('?p=gateway', null, 'GST % must be a number between 0 and 50.');
        }
        $on = isset($_POST['enabled']);
        $kid = trim((string) ($_POST['key_id'] ?? ''));
        if ($on && ($kid === '' || (trim((string) ($_POST['secret'] ?? '')) === '' && trim((string) (AmetecsBilling::gatewayRow()['secret'] ?? '')) === ''))) {
            $back('?p=gateway', null, 'To switch online payment on, enter the Razorpay Key ID and Key Secret.');
        }
        AmetecsBilling::saveGateway($kid, (string) ($_POST['secret'] ?? ''), (string) ($_POST['mode'] ?? 'test'), $on);
        AmetecsBilling::saveSettings(['seller' => trim((string) ($_POST['seller'] ?? '')) ?: 'Ametecs India Private Limited', 'gstin' => strtoupper(trim((string) ($_POST['gstin'] ?? ''))),
            'address' => trim((string) ($_POST['address'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? '')), 'gst_percent' => (float) $gst, 'due_days' => max(0, (int) ($_POST['due_days'] ?? 7)),
            'support_email' => trim((string) ($_POST['support_email'] ?? '')), 'support_phone' => trim((string) ($_POST['support_phone'] ?? '')), 'support_hours' => trim((string) ($_POST['support_hours'] ?? ''))]);
        AmetecsData::log($meEmail, 'Changed payment settings', '', 'Razorpay ' . ($on ? 'ON (' . ((string) ($_POST['mode'] ?? '')) . ')' : 'off') . ', GST ' . $gst . '%');
        $back('?p=gateway', 'Payment settings saved.' . ($on ? ' Online payment is on.' : ' Online payment is off: clients see "contact Ametecs".'));
    }
    if (str_starts_with($do, 'invoice-') || $do === 'payment-refund') {
        if (!AmetecsBilling::ready()) {
            $back('?p=billing', null, 'The billing tables are not on this PC yet. They arrive with the next sync.');
        }
        if ($do === 'invoice-new') {
            $tid = (string) ($_POST['tenant'] ?? '');
            $amt = trim((string) ($_POST['amount'] ?? ''));
            $lic = AmetecsLicences::byId((string) ($_POST['licence'] ?? ''));
            try {
                if ($lic !== null) {
                    if ((float) ($lic['price'] ?? 0) <= 0 || $lic['plan'] === 'perpetual') {
                        throw new \RuntimeException('Set a price on the licence (and a monthly or yearly plan) first.');
                    }
                    $inv = AmetecsBilling::forRenewal($lic);
                } else {
                    if ($tenant($tid) === null || !is_numeric($amt) || trim((string) ($_POST['description'] ?? '')) === '') {
                        throw new \RuntimeException('Choose a client and give a description and an amount.');
                    }
                    $inv = AmetecsBilling::create($tid, 'other', trim((string) $_POST['description']), (float) $amt,
                        in_array($_POST['currency'] ?? '', ['INR', 'USD', 'AED', 'SAR', 'EUR', 'GBP'], true) ? (string) $_POST['currency'] : 'INR');
                }
            } catch (\Throwable $x) {
                $back($lic !== null ? '?p=licence&id=' . (int) $lic['id'] : '?p=billing', null, $x->getMessage());
            }
            $back('?p=invoice&id=' . (int) $inv['id'], 'Invoice ' . $inv['number'] . ' raised. The client pays it from Payments & invoices in their panel.');
        }
        if ($do === 'payment-refund') {
            $pq = $pdo->prepare('SELECT * FROM ag_payments WHERE id = ?');
            $pq->execute([$id]);
            $pay = $pq->fetch(\PDO::FETCH_ASSOC);
            if (!$pay || trim((string) ($_POST['why'] ?? '')) === '') {
                $back('?p=billing', null, $pay ? 'Give the reason for the refund.' : 'Unknown payment.');
            }
            $res = AmetecsBilling::refund($pay, trim((string) $_POST['why']));
            $back('?p=invoice&id=' . (int) $pay['invoice_id'], $res['ok'] ? $res['why'] : null, $res['ok'] ? null : $res['why']);
        }
        $inv = AmetecsBilling::byId($id);
        if ($inv === null) {
            $back('?p=billing', null, 'Unknown invoice.');
        }
        $to = '?p=invoice&id=' . (int) $id;
        if ($do === 'invoice-reconcile') {
            $res = AmetecsBilling::reconcile($inv);
            $back($to, $res['ok'] ? $res['why'] : null, $res['ok'] ? null : $res['why']);
        }
        if ($do === 'invoice-paid') {
            $ref = trim((string) ($_POST['reference'] ?? ''));
            if ($ref === '') {
                $back($to, null, 'Give the bank / UTR / cheque reference of the payment.');
            }
            $res = AmetecsBilling::markPaid($inv, (float) $inv['total'], 'offline', 'OFF-' . $inv['number'], 'recorded by ' . $meEmail . ': ' . $ref, (string) ($_POST['method'] ?? 'bank transfer'));
            $back($to, $res['ok'] ? $res['why'] : null, $res['ok'] ? null : $res['why']);
        }
        if ($do === 'invoice-cancel') {
            try {
                AmetecsBilling::cancel($inv, trim((string) ($_POST['why'] ?? '')) ?: 'cancelled by ' . $meEmail);
            } catch (\Throwable $x) {
                $back($to, null, $x->getMessage());
            }
            $back($to, 'Invoice cancelled.');
        }
        $back($to, null, 'Nothing to do.');
    }

    if ($do === 'app-save') {
        $catalogue = AmetecsAccess::plugins(true);
        $code = (string) ($_POST['code'] ?? '');
        $isNew = $code === '';
        $name = trim((string) ($_POST['name'] ?? ''));
        $open = trim((string) ($_POST['open'] ?? ''));
        $color = (string) ($_POST['color'] ?? '');
        if ($name === '') {
            $back('?p=apps', null, 'An app needs a name.');
        }
        // Where the app opens: a web address (https://…) or a path on this server (../something/). Nothing else.
        if ($open !== '' && preg_match('~^(https?://[^\s"<>]+|(\.\./|/)[A-Za-z0-9._/\-?=&%]*)$~', $open) !== 1) {
            $back('?p=apps', null, 'The "Opens at" link must be a web address (https://…) or a path on this server (../folder/page.php).');
        }
        if ($isNew) {
            $code = (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
            if ($code === '' || isset($catalogue[$code])) {
                $code = ($code ?: 'app') . random_int(10, 99);
            }
        } elseif (!isset($catalogue[$code])) {
            $back('?p=apps', null, 'Unknown app.');
        }
        foreach ($catalogue as $c => $a) {
            if ($c !== $code && strcasecmp((string) $a['name'], $name) === 0) {
                $back('?p=apps', null, 'There is already an app called ' . $name . '.');
            }
        }
        // LOGO (owner, 17 Nov 2026): a web address or a file in Ametecs Global (assets/logos/x.png). Best: a white logo with a transparent
        // background - it sits on a white card on the client's home (add " inv" after the address for a white-only logo). Empty = Ametecs Global's built-in logo for that app, if any.
        $logo = trim((string) ($_POST['logo'] ?? ''));
        if ($logo !== '' && preg_match('~^(https?://[^\s"<>]+|[A-Za-z0-9._/\-]+\.(png|svg|webp|jpe?g))( inv)?$~i', $logo) !== 1) {
            $back('?p=apps', null, 'The logo must be a web address (https://…) or a file like assets/logos/myapp.png.');
        }
        $app = ['name' => $name, 'what' => trim((string) ($_POST['what'] ?? '')), 'unit' => trim((string) ($_POST['unit'] ?? '')) ?: 'users', 'logo' => $logo,
            'open' => $open === '' ? null : $open, 'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : '#64748B',
            'retired' => isset($_POST['retired'])];
        AmetecsAccess::saveApp($code, $app);
        AmetecsData::log($meEmail, ($isNew ? 'Added app ' : 'Changed app ') . $name, '', ($app['open'] ?? 'not live yet') . ($app['retired'] ? ' · retired' : ''));
        $back('?p=apps', $isNew ? $name . ' added. Switch it on for clients from each client\'s page.' : $name . ' saved.' . ($app['retired'] ? ' It is retired: clients no longer see it; their licences are kept.' : ''));
    }

    if ($do === 'sample-add' || $do === 'sample-remove') {   // sample data (18 Nov 2026), this PC's database only
        if (strtolower((string) (\SmartPRS\Surface\Database::env(AG_BASE . '/.env')['APP_ENV'] ?? 'production')) === 'production') {
            $back('?p=system', null, 'Sample data is switched off on a production server.');
        }
        try {
            $msg = $do === 'sample-add' ? 'Sample data added: ' . AmetecsSample::add($pdo, $platform, (string) ($_POST['tenant'] ?? ''), $meEmail) . '.' : AmetecsSample::remove($pdo, $meEmail);
        } catch (\Throwable $x) {
            $back('?p=system', null, 'Sample data was not added: ' . $x->getMessage());
        }
        $back('?p=system', $msg);
    }
    if ($do === 'email-save') {
        AmetecsMail::box($pdo)->save($_POST);
        AmetecsData::log($meEmail, 'Changed email settings', '', (string) ($_POST['host'] ?? '') . ' from ' . (string) ($_POST['from_email'] ?? ''));
        $back('?p=email', 'Email settings saved.' . (AmetecsMail::box($pdo)->ready() ? ' Send a test to check them.' : ' Email stays off until it is switched on with a server and a From address.'));
    }

    if ($do === 'email-test') {
        $to = strtolower(trim((string) ($_POST['to'] ?? ''))) ?: $meEmail;   // any address (owner, 17 Nov 2026)
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $back('?p=email', null, '"' . $to . '" is not a valid email address.');
        }
        $r = AmetecsMail::send($pdo, $to, 'Ametecs Global test email', "This is a test from Ametecs Global.\nIf you can read this, password reset emails will work.");
        $r['ok'] && AmetecsData::log($meEmail, 'Sent a test email', '', $to);
        $back('?p=email', $r['ok'] ? 'Test email sent to ' . $to . '. Check the inbox (and spam).' : null, $r['ok'] ? null : 'The test email to ' . $to . ' failed: ' . $r['why']);
    }

    if ($do === 'staff-add') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $dup = $pdo->prepare('SELECT COUNT(*) FROM platform_users WHERE LOWER(email) = ?');
        $dup->execute([$email]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '' || (int) $dup->fetchColumn() > 0) {
            $back('?p=staff', null, 'Give a name and a valid email that is not already a Super Admin.');
        }
        $pw = $newPassword();
        $pdo->prepare('INSERT INTO platform_users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
            ->execute([$name, $email, $hasher->hash($pw), 'super_admin']);
        AmetecsData::log($meEmail, 'Added Super Admin', '', $email);
        $_SESSION['reveal'] = ['tid' => 'staff', 'email' => $email, 'password' => $pw];
        $back('?p=staff', 'Super Admin ' . $email . ' added.');
    }

    if ($do === 'staff-password') {   // Phase 7: set a new password for another Super Admin, shown once
        $q = $pdo->prepare('SELECT * FROM platform_users WHERE id = ?');
        $q->execute([$id]);
        $u = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$u || strcasecmp((string) $u['email'], $meEmail) === 0) {
            $back('?p=staff', null, $u ? 'Change your own password under My account.' : 'Unknown Super Admin.');
        }
        $pw = $newPassword();
        $pdo->prepare('UPDATE platform_users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([$hasher->hash($pw), $id]);
        AmetecsData::log($meEmail, 'Set a new password for Super Admin', '', (string) $u['email']);
        $_SESSION['reveal'] = ['tid' => 'staff', 'email' => (string) $u['email'], 'password' => $pw];
        $back('?p=staff', 'New password set for ' . $u['email'] . '.');
    }

    if ($do === 'staff-toggle') {
        $q = $pdo->prepare('SELECT * FROM platform_users WHERE id = ?');
        $q->execute([$id]);
        $u = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$u || (string) $u['id'] === (string) ($me['platform_id'] ?? '') || strcasecmp((string) $u['email'], $meEmail) === 0) {
            $back('?p=staff', null, 'You cannot disable your own account.');
        }
        $off = trim((string) ($u['disabled_at'] ?? '')) === '';
        $pdo->prepare('UPDATE platform_users SET disabled_at = ' . ($off ? 'NOW()' : 'NULL') . ', updated_at = NOW() WHERE id = ?')->execute([$id]);
        AmetecsData::log($meEmail, ($off ? 'Disabled' : 'Enabled') . ' Super Admin', '', (string) $u['email']);
        $back('?p=staff', (string) $u['email'] . ($off ? ' is disabled.' : ' is enabled.'));
    }

    if ($do === 'my-password') {
        $q = $pdo->prepare('SELECT * FROM platform_users WHERE LOWER(email) = ?');
        $q->execute([strtolower($meEmail)]);
        $u = $q->fetch(\PDO::FETCH_ASSOC);
        $new = (string) ($_POST['new'] ?? '');
        if (!$u || !$hasher->verify((string) ($_POST['current'] ?? ''), (string) $u['password'])) {
            $back('?p=account', null, 'Your current password is not right.');
        }
        if (strlen($new) < Session::PASSWORD_FLOOR || $new !== (string) ($_POST['again'] ?? '')) {
            $back('?p=account', null, 'The new password needs at least ' . Session::PASSWORD_FLOOR . ' characters, typed the same twice.');
        }
        $pdo->prepare('UPDATE platform_users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([$hasher->hash($new), $u['id']]);
        AmetecsData::log($meEmail, 'Changed own password');
        $back('?p=account', 'Your password is changed.');
    }

    $back('', null, 'Nothing to do.');
}

/* ------------------------------------------------------------------ data for the pages */
if ($me !== null && $pdo !== null) {
    AmetecsAccess::ensureHouseClient($pdo);
}
$page = $me === null ? 'signin' : (string) ($_GET['p'] ?? 'dashboard');
if ($page === 'invoice-print' && ($pi = AmetecsBilling::byId((string) ($_GET['id'] ?? ''))) !== null) {
    echo AmetecsBilling::printHtml($pi);
    exit;
}
$all = AmetecsAccess::all();
$meta = AmetecsData::clients();
$tenants = [];
$used = [];
if ($me !== null && $pdo !== null) {
    $tenants = $pdo->query('SELECT * FROM tenants ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($tenants as $tr) {
        $used[(string) $tr['id']] = AmetecsAccess::employeesUsed($platform, (string) $tr['id']);
    }
}
$today = AmetecsAccess::today();
$soon = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
$reveal = $_SESSION['reveal'] ?? null;
unset($_SESSION['reveal']);
$form = (array) ($_SESSION['form'] ?? []);
unset($_SESSION['form']);
$csrf = static fn (): string => '<input type="hidden" name="csrf" value="' . ag_h($_SESSION['csrf']) . '">';
$d = static fn (?string $ymd): string => ($ymd ?? '') === '' ? '—' : date('d M Y', (int) strtotime((string) $ymd));
$appsOn = static function (string $tid) use ($plugins, $all): array {
    $on = [];
    foreach ($plugins as $c => $p) {
        $st = AmetecsAccess::state($tid, $c, $all);
        if ($st !== 'off') {
            $on[$c] = $st;
        }
    }
    return $on;
};
/** The apps & licences table, used on New client and on the client page. */
$licenceRows = static function (string $tid, array $prefill = []) use ($plugins, $all, $used): string {
    $h = '<table><tr><th>App</th><th>On</th><th>Seats</th><th>Valid till</th><th>Now</th></tr>';
    foreach ($plugins as $c => $p) {
        $a = $prefill[$c] ?? AmetecsAccess::get($tid, $c, $all);
        $st = $tid === '' ? (!empty($a['on']) ? 'on' : 'off') : AmetecsAccess::state($tid, $c, $all);
        $h .= '<tr><td><span class="dot" style="background:' . ag_h($p['color']) . '"></span><b>' . ag_h($p['name']) . '</b>'
            . ($p['open'] === null ? ' <span class="pill grey">not on Ametecs Global yet</span>' : '') . '</td>'
            . '<td><label class="sw"><input type="checkbox" name="p[' . $c . '][on]"' . (!empty($a['on']) ? ' checked' : '') . '> On</label></td>'
            . '<td><input type="number" min="0" name="p[' . $c . '][seats]" value="' . (int) ($a['seats'] ?? 0) . '"> ' . ag_h($p['unit'])
            . ($c === 'smartprs3' && $tid !== '' ? ' <span class="muted">(' . (int) ($used[$tid] ?? 0) . ' in use)</span>' : '') . '</td>'
            . '<td><input type="date" name="p[' . $c . '][until]" value="' . ag_h((string) ($a['until'] ?? '')) . '"></td>'
            . '<td><span class="pill ' . ['on' => 'green', 'off' => 'grey', 'expired' => 'red', 'suspended' => 'amber'][$st] . '">' . ['on' => 'Opens', 'off' => 'Off', 'expired' => 'Expired', 'suspended' => 'Suspended'][$st] . '</span>'
            . ($tid !== '' && ($lr = AmetecsLicences::row($tid, $c)) !== null ? ' <a class="muted" style="font-size:12px" href="?p=licence&id=' . (int) $lr['id'] . '">' . ag_h(AmetecsLicences::DEPLOYMENTS[$lr['deployment']] ?? '') . ' · details</a>' : '') . '</td></tr>';
    }
    return $h . '</table>';
};
$statusPill = static fn (string $s): string => '<span class="pill ' . (['active' => 'green', 'suspended' => 'amber', 'closed' => 'red'][$s] ?? 'grey') . '">' . ag_h(ucfirst($s ?: 'unknown')) . '</span>';
$f = static fn (string $k, string $default = '') => ag_h((string) ($form[$k] ?? $default));
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><link rel="icon" href="assets/brand/favicon.svg" type="image/svg+xml"><link rel="icon" href="assets/brand/favicon-32x32.png" sizes="32x32"><link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png"><script>try{var R=document.documentElement,T=localStorage.getItem("agTheme");R.classList.add("t-gold");(T==="navy"||T==="classic")&&R.classList.add("th-navy");T==="light"&&R.classList.add("th-light");T==="dark"&&R.classList.add("th-dark")}catch(e){document.documentElement.classList.add("t-gold")}</script><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Super Admin · Ametecs Global</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#1F3A5F;--navy2:#16304f;--navy3:#0c1f38;--gold:#B8893B;--gold2:#d4a65a;--ink:#0f172a;--ink2:#334155;--mute:#64748b;--line:#e6eaf1;--bg:#f4f6fa;--card:#fff;
  --ok:#15803d;--okb:#ecfdf3;--warn:#b45309;--warnb:#fffbeb;--bad:#b91c1c;--badb:#fef2f2;--r:14px;--sh:0 1px 2px rgba(15,23,42,.04),0 6px 18px -8px rgba(15,23,42,.10)}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;font:14px/1.5 Inter,"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased}
a{color:var(--navy)}
/* shell */
.shell{display:grid;grid-template-columns:248px 1fr;min-height:100vh;transition:grid-template-columns .2s}
body.mini .shell{grid-template-columns:72px 1fr}
.side{background:var(--navy3);color:#cbd5e1;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;padding:16px 12px;overflow:hidden}
.brand{display:flex;align-items:center;gap:10px;padding:4px 8px 18px;color:#fff;text-decoration:none;font-weight:800;font-size:17px;white-space:nowrap}
.brand .lm{width:34px;height:34px;flex:none;border-radius:9px;background:linear-gradient(135deg,var(--gold2),var(--gold));display:grid;place-items:center;color:var(--navy3);font-weight:900;font-size:13px}
.brand b{color:var(--gold2)}.brand small{display:block;font-size:10.5px;letter-spacing:1.2px;color:var(--gold2);font-weight:700;margin-top:-2px}
.nav-l{font-size:10.5px;letter-spacing:1px;text-transform:uppercase;color:#64748b;padding:14px 12px 6px;white-space:nowrap}
.side a.nv{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:9px;color:#cbd5e1;text-decoration:none;font-weight:500;white-space:nowrap;margin-bottom:2px;position:relative}
.side a.nv i{width:18px;text-align:center;font-size:15px;color:#8193ab}
.side a.nv:hover{background:rgba(255,255,255,.06);color:#fff}
.side a.nv.on{background:rgba(212,166,90,.14);color:#fff}.side a.nv.on i{color:var(--gold2)}
.side a.nv.on:before{content:"";position:absolute;left:-12px;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--gold2)}
.side .cnt{margin-left:auto;font-size:11px;background:rgba(255,255,255,.08);padding:1px 8px;border-radius:99px;color:#cbd5e1}
.side .bottom{margin-top:auto;border-top:1px solid rgba(255,255,255,.08);padding-top:12px}
body.mini .brand span,body.mini .nav-l,body.mini .side a.nv span,body.mini .side .cnt{display:none}
body.mini .side a.nv{justify-content:center}
.top{height:64px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:14px;padding:0 26px;position:sticky;top:0;z-index:20}
.ib{width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:#fff;display:grid;place-items:center;color:var(--ink2);cursor:pointer}.ib:hover{background:#f6f8fb}
.search{flex:1;max-width:460px;display:flex;align-items:center;gap:10px;height:40px;padding:0 12px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;color:var(--mute);cursor:pointer}
.search kbd{margin-left:auto;font:600 11px/1 inherit;background:#fff;border:1px solid var(--line);border-radius:6px;padding:4px 6px}
.top .right{margin-left:auto;display:flex;align-items:center;gap:12px}
.top .cv{display:inline-flex;gap:8px;align-items:center;height:38px;padding:0 12px;border-radius:10px;border:1px solid var(--line);color:var(--ink2);text-decoration:none;font-weight:600;font-size:13px}.top .cv:hover{background:#f6f8fb}
.me{position:relative}.me summary{list-style:none;display:flex;align-items:center;gap:10px;cursor:pointer;padding:4px 8px 4px 4px;border-radius:10px}.me summary::-webkit-details-marker{display:none}.me summary:hover{background:#f6f8fb}
.av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy3));color:var(--gold2);display:grid;place-items:center;font-weight:800;font-size:13px}
.me .nm{font-size:13px;font-weight:600;line-height:1.2}.me .nm small{display:block;color:var(--mute);font-weight:500;font-size:11.5px}
.dd{position:absolute;right:0;top:calc(100% + 8px);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 20px 40px -14px rgba(15,23,42,.3);min-width:220px;padding:6px;z-index:30}
.dd a,.dd button{display:flex;gap:10px;align-items:center;width:100%;padding:9px 12px;border:0;background:none;border-radius:8px;font:inherit;color:var(--ink2);text-decoration:none;cursor:pointer}.dd a:hover,.dd button:hover{background:#f1f5f9}.dd i{width:16px;color:var(--mute)}.dd form{margin:0}
main{padding:26px 30px 50px;max-width:1320px}
h1{margin:0;font-size:24px;letter-spacing:-.4px}h2{font-size:15px;margin:0;letter-spacing:-.1px}.sub{color:var(--mute);margin:4px 0 22px}
.ph{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}.ph .sub{margin-bottom:0}
/* bits */
.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:9px;padding:9px 15px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;font-size:13.5px;transition:background .15s,box-shadow .15s}
.gold{background:var(--gold);color:#fff}.gold:hover{background:#a67a32}.navy{background:var(--navy);color:#fff}.navy:hover{background:var(--navy2)}
.ghost{background:#fff;border:1px solid var(--line);color:var(--ink2)}.ghost:hover{background:#f6f8fb}.danger{background:#fff;border:1px solid #fecaca;color:var(--bad)}.danger:hover{background:var(--badb)}
button[disabled]{opacity:.45;cursor:not-allowed}
.pill{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:999px;white-space:nowrap}
.pill:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.8}
.green{background:var(--okb);color:var(--ok)}.grey{background:#f1f5f9;color:#475569}.red{background:var(--badb);color:var(--bad)}.amber{background:var(--warnb);color:var(--warn)}
.dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:8px;vertical-align:middle}.muted{color:var(--mute)}.right{text-align:right}
.box{background:var(--card);border:1px solid var(--line);border-radius:var(--r);margin-bottom:20px;overflow:hidden;box-shadow:var(--sh)}
.box .hd{display:flex;align-items:center;gap:12px;padding:15px 18px;border-bottom:1px solid var(--line)}.box .hd h2{display:flex;align-items:center;gap:9px}.box .hd h2 i{color:var(--gold)}
.box .bd{padding:18px}.box .ft{padding:12px 18px;border-top:1px solid var(--line);text-align:right;background:#fafbfd}
table{width:100%;border-collapse:collapse}
th{background:#f8fafc;color:var(--mute);text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;padding:10px 16px;font-weight:600;border-bottom:1px solid var(--line);white-space:nowrap}
th.sort{cursor:pointer;user-select:none}th.sort:hover{color:var(--ink)}th.sort:after{content:"\f0dc";font:900 10px "Font Awesome 6 Free";margin-left:6px;opacity:.35}th.asc:after{content:"\f0de";opacity:.9}th.desc:after{content:"\f0dd";opacity:.9}
td{padding:11px 16px;border-top:1px solid #f0f3f7;vertical-align:middle}tr:first-child td{border-top:0}tbody tr,table tr{transition:background .1s}table tr:hover td{background:#fafcff}
input,select,textarea{font:inherit;padding:9px 11px;border:1px solid #d9e0ea;border-radius:9px;background:#fff;color:var(--ink);transition:border-color .15s,box-shadow .15s}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--navy);box-shadow:0 0 0 4px rgba(31,58,95,.10)}td input[type=number]{width:90px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px 20px}.grid2 label,.stack label{display:block;font-size:12.5px;font-weight:600;color:var(--ink2);margin-bottom:6px}
.grid2 input,.grid2 select,.grid2 textarea,.stack input{width:100%}.full{grid-column:1/-1}.req{color:#dc2626}
.sw{display:inline-flex;align-items:center;gap:8px;font-weight:600;cursor:pointer}
.sw input{appearance:none;width:38px;height:22px;border-radius:99px;background:#cbd5e1;position:relative;cursor:pointer;padding:0;border:0;transition:background .15s;flex:none}
.sw input:after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .15s}
.sw input:checked{background:#16a34a}.sw input:checked:after{transform:translateX(16px)}.sw input:focus{box-shadow:0 0 0 4px rgba(22,163,74,.15)}
.chips{display:flex;flex-wrap:wrap;gap:4px}.inline{display:inline}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.act{display:inline-block;position:relative;text-align:left}.act summary{list-style:none;display:inline-flex}.act summary::-webkit-details-marker{display:none}
.act .menu{position:absolute;right:0;top:44px;z-index:3;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 40px -12px rgba(15,23,42,.25);padding:6px;width:340px}
.act .menu form{display:flex;flex-direction:column;gap:6px;padding:12px;border-bottom:1px solid var(--line)}.act .menu form:last-child{border-bottom:0}.act .menu .row input{flex:1}
.toolbar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}.toolbar .si{position:relative;flex:1;max-width:380px}.toolbar .si i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8}.toolbar .si input{width:100%;padding-left:36px}
.msg{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;margin-bottom:16px}.bad{background:var(--badb);color:var(--bad);border:1px solid #fecaca}.ok{background:var(--okb);color:var(--ok);border:1px solid #bbf7d0}
.reveal{background:var(--warnb);border:1px solid #fcd34d;color:#78350f;padding:14px 16px;border-radius:12px;margin-bottom:18px}.reveal code{font-size:14px;background:#fff;padding:2px 8px;border-radius:6px;border:1px solid #fcd34d}
/* toasts */
.toasts{position:fixed;right:22px;bottom:22px;display:grid;gap:10px;z-index:60}
.toast{display:flex;gap:12px;align-items:flex-start;min-width:300px;max-width:420px;background:#fff;border:1px solid var(--line);border-left:4px solid var(--ok);border-radius:12px;padding:13px 14px;box-shadow:0 20px 40px -14px rgba(15,23,42,.35);animation:tin .3s ease-out}
.toast.bad{border-left-color:var(--bad);background:#fff;color:var(--ink)}.toast i{margin-top:2px;color:var(--ok)}.toast.bad i{color:var(--bad)}.toast button{margin-left:auto;border:0;background:none;color:var(--mute);cursor:pointer}
@keyframes tin{from{transform:translateY(12px);opacity:0}}
/* dashboard */
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;box-shadow:var(--sh);position:relative;overflow:hidden;text-decoration:none;color:inherit;transition:transform .15s,box-shadow .15s}
a.kpi:hover{transform:translateY(-2px);box-shadow:0 14px 28px -14px rgba(15,23,42,.3)}
.kpi .ki{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;font-size:16px;margin-bottom:12px}
.kpi .n{font-size:28px;font-weight:800;letter-spacing:-.6px;line-height:1}.kpi .l{color:var(--mute);font-size:12.5px;margin-top:6px}
.kpi .tr{position:absolute;right:16px;top:18px;font-size:11.5px;font-weight:600}
.k1 .ki{background:#eef3fa;color:var(--navy)}.k2 .ki{background:var(--okb);color:var(--ok)}.k3 .ki{background:var(--badb);color:var(--bad)}.k4 .ki{background:#fbf5ea;color:var(--gold)}.k5 .ki{background:var(--warnb);color:var(--warn)}.k6 .ki{background:#f3efff;color:#6d28d9}
.dgrid{display:grid;grid-template-columns:1.35fr 1fr;gap:20px}.dgrid .box{margin-bottom:0}.dcol{display:grid;gap:20px;align-content:start}
.bars{display:grid;gap:14px}.brow{display:grid;grid-template-columns:150px 1fr 70px;gap:12px;align-items:center;font-size:13px}
.brow .bn{display:flex;align-items:center;gap:8px;font-weight:600}.track{height:10px;background:#eef1f6;border-radius:99px;overflow:hidden;position:relative}
.track i{display:block;height:100%;border-radius:99px;width:0;transition:width .9s cubic-bezier(.2,.8,.2,1)}
.brow .bv{text-align:right;color:var(--ink2);font-weight:600;font-variant-numeric:tabular-nums}
.donut{display:flex;gap:22px;align-items:center}.donut svg{flex:none}.donut circle{transition:stroke-dasharray .9s cubic-bezier(.2,.8,.2,1);cursor:pointer}
.legend{display:grid;gap:8px;font-size:13px}.legend div{display:flex;align-items:center;gap:8px}.legend b{margin-left:auto;padding-left:14px}
.feed{list-style:none;margin:0;padding:6px 0}.feed li{display:flex;gap:12px;padding:10px 18px;position:relative}
.feed li:not(:last-child):before{content:"";position:absolute;left:33px;top:42px;bottom:-6px;width:1px;background:var(--line)}
.feed .fi{width:30px;height:30px;flex:none;border-radius:50%;background:#eef3fa;color:var(--navy);display:grid;place-items:center;font-size:12px}
.feed .ft2{font-size:13px}.feed .fm{font-size:12px;color:var(--mute)}
.empty{padding:28px;text-align:center;color:var(--mute)}.empty i{font-size:26px;color:#cbd5e1;display:block;margin-bottom:8px}
.tip{position:fixed;pointer-events:none;background:var(--navy3);color:#fff;font-size:12px;padding:6px 10px;border-radius:8px;z-index:70;opacity:0;transition:opacity .1s}
/* palette */
.pal{position:fixed;inset:0;background:rgba(12,31,56,.45);backdrop-filter:blur(3px);display:none;align-items:flex-start;justify-content:center;padding-top:12vh;z-index:80}.pal.open{display:flex}
.pal .pb{width:min(560px,92vw);background:#fff;border-radius:14px;box-shadow:0 30px 60px -20px rgba(0,0,0,.45);overflow:hidden}
.pal input{width:100%;border:0;border-bottom:1px solid var(--line);border-radius:0;padding:16px 18px;font-size:15px;box-shadow:none!important}
.pal ul{list-style:none;margin:0;padding:6px;max-height:50vh;overflow:auto}.pal li a{display:flex;gap:12px;align-items:center;padding:10px 12px;border-radius:8px;text-decoration:none;color:var(--ink)}
.pal li a i{width:18px;color:var(--mute)}.pal li.sel a,.pal li a:hover{background:#f1f5f9}.pal small{margin-left:auto;color:var(--mute)}
/* sign-in */
.auth{min-height:100vh;display:grid;grid-template-columns:1fr 1fr}
.auth-side{background:radial-gradient(900px 500px at 0% 0%,#2b4f7d 0%,transparent 60%),linear-gradient(160deg,var(--navy),var(--navy3));color:#fff;padding:44px 52px;display:flex;flex-direction:column}
.auth-side .brand{padding:0}.auth-side h1{font-size:32px;line-height:1.2;margin:auto 0 12px;max-width:420px}.auth-side h1 em{font-style:normal;color:var(--gold2)}
.auth-side p{color:#c7d3e3;max-width:420px;margin:0 0 auto}.auth-side ul{list-style:none;padding:0;margin:26px 0 auto;display:grid;gap:12px;color:#dbe4f0}.auth-side li i{color:var(--gold2);width:22px}
.auth-main{display:grid;place-items:center;background:#fff;padding:30px}.auth-box{width:100%;max-width:380px}.auth-box h2{font-size:24px;margin:0 0 6px}
.auth-box .stack input{height:46px;margin-bottom:16px}.auth-box button{width:100%;justify-content:center;height:46px}
@media (max-width:1100px){.dgrid{grid-template-columns:1fr}}
@media (max-width:800px){.shell{grid-template-columns:1fr}.side{display:none}.grid2{grid-template-columns:1fr}.auth{grid-template-columns:1fr}.auth-side{display:none}.search{display:none}}

/* ---------- dashboard v2: light, compact, one screen ---------- */
main{max-width:1560px;padding:16px 26px 20px}
.top{height:54px}
.dh{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.dh-t{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}.dh h1{font-size:21px}.dh-t span{color:var(--mute);font-size:13px}
.dh .btn{padding:7px 13px;font-size:13px}
.kstrip{display:grid;grid-template-columns:repeat(6,1fr);row-gap:2px;background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.kstrip>a,.kstrip>div{display:flex;gap:11px;align-items:center;padding:10px 16px;text-decoration:none;color:inherit;position:relative;border-radius:12px}
.kstrip>a:hover{background:#f8fafc}.kstrip>*+*:before{content:"";position:absolute;left:0;top:12px;bottom:12px;width:1px;background:var(--line)}
.ki{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:13px;flex:none}
.kstrip b{display:block;font-size:18px;font-weight:700;letter-spacing:-.3px;line-height:1.2}.kstrip span:not(.ki){font-size:11.5px;color:var(--mute);white-space:nowrap}
.k1{background:#eef3fa;color:var(--navy)}.k2{background:var(--okb);color:var(--ok)}.k3{background:var(--badb);color:var(--bad)}.k4{background:#fbf5ea;color:var(--gold)}.k5{background:var(--warnb);color:var(--warn)}.k6{background:#f3efff;color:#6d28d9}
.dgrid2{display:grid;grid-template-columns:minmax(0,1.9fr) minmax(300px,1fr);gap:14px;align-items:start}
.dgrid2 .box{margin-bottom:0}.dcol2{display:grid;gap:14px}
.box .hd{padding:11px 16px;gap:10px;flex-wrap:wrap}.box .hd h2{font-size:14.5px}.more{margin-left:auto;font-size:12.5px;text-decoration:none;font-weight:600}.sm{margin-left:auto;font-size:12px}
.lg{display:inline-flex;gap:12px;flex-wrap:wrap}.lg em{font-style:normal;display:inline-flex;gap:5px;align-items:center;font-size:11.5px;color:var(--mute)}
.chk{width:20px;height:20px;border-radius:6px;display:inline-grid;place-items:center;font-size:11px;border:2px solid transparent;vertical-align:middle}
.chk.on{background:#16a34a;color:#fff}.chk.soon{background:#e5e7eb;color:#9ca3af;border-color:#d1d5db}.chk.off{background:#fff;border-color:#cbd5e1}.chk.bad{background:#dc2626;color:#fff}
.lg .chk{width:14px;height:14px;font-size:8px;border-radius:4px;border-width:1.5px}
.mx{overflow-x:auto}.mxt th{background:#fff;text-transform:none;letter-spacing:0;font-size:11.5px;padding:10px 8px;vertical-align:bottom}.mxt th:first-child,.mxt td:first-child{padding-left:16px}
.mxt th.ac,.mxt td.ac{text-align:center}.mxt th.ac small{display:block;margin-top:5px;color:var(--mute);font-weight:600;font-size:11px;white-space:nowrap}
.mxt td{padding:9px 8px}.cn{text-decoration:none;color:var(--ink)}.cn b{display:block;font-size:13.5px}.cn small{color:var(--mute);font-size:11.5px}.cn:hover b{color:var(--navy);text-decoration:underline}
.am{width:28px;height:28px;border-radius:8px;display:inline-grid;place-items:center;background:color-mix(in srgb,var(--c) 13%,#fff);color:var(--c);font-size:12.5px}
.attn{border-top:1px solid var(--line);padding:10px 16px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;font-size:12.5px}
.attn b{display:inline-flex;gap:7px;align-items:center;color:var(--warn)}.attn a{text-decoration:none;color:var(--ink-2)}.attn.ok{color:var(--ok);gap:8px}
.apl{list-style:none;margin:0;padding:4px 0}.apl li{display:flex;align-items:center;gap:11px;padding:7px 16px}.apl li+li{border-top:1px solid #f3f5f8}
.apl .an{flex:1;font-weight:600;font-size:13px;line-height:1.25}.apl .an small{display:block;font-weight:500;color:var(--mute);font-size:11px}
.av2{font-size:12.5px;color:var(--mute)}.av2 b{color:var(--ink);font-size:14px}
.feed li{padding:7px 16px;gap:10px}.feed li:not(:last-child):before{display:none}.fd{width:8px;height:8px;border-radius:50%;background:var(--gold);margin-top:6px;flex:none}
.feed .ft2{font-size:12.5px;line-height:1.4}.feed .fm{font-size:11.5px}.feed a{text-decoration:none}
@media (max-width:1100px){.kstrip{grid-template-columns:repeat(3,1fr)}.dgrid2{grid-template-columns:1fr}}
.cn b{white-space:nowrap}.mxt td:last-child,.mxt th:last-child{padding-right:16px;white-space:nowrap}.mxt th.ac{padding-left:4px;padding-right:4px}.mxt th:first-child{width:30%}
</style>
<link rel="stylesheet" href="assets/shell.css?v=29"><link rel="stylesheet" href="assets/super.css?v=13"><link rel="stylesheet" href="assets/theme-gold.css?v=3"><link rel="stylesheet" href="assets/theme-sa-light.css?v=6"><link rel="stylesheet" href="assets/theme-alt.css?v=11"></head><body>
<?php
$myName = trim((string) ($me['name'] ?? '')) ?: ucfirst((string) strtok($meEmail, '@'));
$myIni = strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/[\s._-]+/', $myName) ?: ['S'], 0, 2))));
?>
<?php if ($me === null): ?>
<div class="auth">
  <aside class="auth-side">
    <span class="brand"><span class="lm">AG</span><span>Ametecs<b>Global</b><small>SUPER ADMIN</small></span></span>
    <h1>Run every client.<br><em>From one console.</em></h1>
    <ul><li><i class="fas fa-building"></i>Create and manage client companies</li><li><i class="fas fa-puzzle-piece"></i>Switch apps on, set seats and validity</li><li><i class="fas fa-key"></i>Reset logins, suspend or reactivate</li><li><i class="fas fa-clock-rotate-left"></i>Every change logged</li></ul>
    <small style="color:#8ea2bd">&copy; <?= date('Y') ?> Ametecs India Private Limited</small>
  </aside>
  <div class="auth-main"><div class="auth-box">
    <h2>Super Admin sign-in</h2><p class="sub">Ametecs staff only.</p>
    <?php if ($problem !== null): ?><div class="msg bad"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span></div><?php endif; ?>
    <?php $noStaff = $pdo !== null && (int) $pdo->query('SELECT COUNT(*) FROM platform_users')->fetchColumn() === 0; ?>
    <?php if ($noStaff): ?>
      <p>No Super Admin exists yet. In the Laragon Terminal, in the Ametecs Global folder, run:<br><code>php plugins\smartprs3\tools\make-super-admin.php you@company.com "Your Name"</code><br>It prints a one-time password.</p>
    <?php elseif ($pdo !== null): ?>
    <form method="post" class="stack"><?= $csrf() ?><input type="hidden" name="do" value="signin">
      <label>Email</label><input type="email" name="email" required autofocus autocomplete="username" placeholder="you@ametecsindia.com">
      <label>Password</label><input type="password" name="password" required autocomplete="current-password">
      <button class="btn navy">Sign in <i class="fas fa-arrow-right"></i></button></form>
    <p class="muted" style="font-size:12px;margin-top:16px">Forgot the password? Another Super Admin can't see it; run <code>php plugins\smartprs3\tools\make-super-admin.php your@email "Name"</code> to set a new one.</p>
    <?php endif; ?>
  </div></div>
</div>
<?php else:
  // PHASE 7 (owner, 18 Nov 2026: "organise everything properly"): grouped by what Ametecs staff do - customers,
  // products, money, the platform itself. New client is the sidebar's action button; My account is under the user menu.
  $navItems = ['' => ['dashboard' => ['Dashboard', 'fa-gauge-high']],
      'Customers' => ['clients' => ['Clients', 'fa-building'], 'licences' => ['Licences', 'fa-key']],
      'Products' => ['apps' => ['Applications', 'fa-layer-group'], 'releases' => ['Updates & releases', 'fa-cloud-arrow-up']],
      'Revenue' => ['billing' => ['Payments & invoices', 'fa-file-invoice-dollar'], 'gateway' => ['Payment settings', 'fa-credit-card']],
      'Platform' => ['system' => ['System & health', 'fa-heart-pulse'], 'email' => ['Email settings', 'fa-envelope'], 'staff' => ['Super Admins', 'fa-user-shield'], 'activity' => ['Activity log', 'fa-clock-rotate-left']]];
  $navGroupOf = ['client' => 'clients', 'client-new' => 'clients', 'licence' => 'licences', 'release' => 'releases', 'invoice' => 'billing'];
  $crumb = '';
  foreach ($navItems as $grp => $items) { foreach ($items as $k => [$label]) { if ($k === ($navGroupOf[$page] ?? $page)) { $crumb = ($grp !== '' ? $grp . ' <i class="fas fa-chevron-right"></i> ' : '') . ag_h($label); } } }
  $titles = ['dashboard' => 'Dashboard', 'clients' => 'Clients', 'client-new' => 'New client', 'client' => 'Client', 'licences' => 'Licences', 'licence' => 'Licence', 'releases' => 'Updates & releases', 'release' => 'Release', 'billing' => 'Payments & invoices', 'invoice' => 'Invoice', 'gateway' => 'Payment settings', 'system' => 'System & health', 'apps' => 'Apps', 'activity' => 'Activity log', 'email' => 'Email settings', 'staff' => 'Super Admins', 'account' => 'My account']; ?>
<div class="shell">
<aside class="side">
  <a class="brand" href="?p=dashboard"><span class="lm">AG</span><span>Ametecs<b>Global</b><small>SUPER ADMIN</small></span></a>
  <?php foreach ($navItems as $grp => $items): ?><?= $grp !== '' ? '<div class="nav-l">' . $grp . '</div>' : '' ?>
    <?php foreach ($items as $k => [$label, $ic]): ?><a class="nv<?= $k === ($navGroupOf[$page] ?? $page) ? ' on' : '' ?>" href="?p=<?= $k ?>" title="<?= ag_h($label) ?>"><i class="fas <?= $ic ?>"></i><span><?= ag_h($label) ?></span><?= $k === 'clients' ? '<em class="cnt">' . count($tenants) . '</em>' : '' ?></a><?php endforeach; ?>
  <?php endforeach; ?>
  <div class="bottom"><a class="nv" href="./" target="_blank" title="Client view"><i class="fas fa-arrow-up-right-from-square"></i><span>Client view</span></a></div>
</aside>
<div>
<header class="top">
  <button class="ib" type="button" data-drawer onclick="if(innerWidth<=860)return;document.body.classList.toggle('mini');try{localStorage.setItem('agMini',document.body.classList.contains('mini')?1:0)}catch(e){}" title="Collapse menu"><i class="fas fa-bars"></i></button>
  <div class="crumb"><?= $crumb ?></div>
  <div class="search" onclick="pal(true)"><i class="fas fa-magnifying-glass"></i><span>Jump to a client or page…</span><kbd>Ctrl K</kbd></div>
  <div class="right">
    <a class="cv" href="./" target="_blank"><i class="fas fa-eye"></i>Client view</a>
    <details class="me"><summary><span class="av"><?= ag_h($myIni) ?></span><span class="nm"><?= ag_h($myName) ?><small>Super Admin</small></span><i class="fas fa-chevron-down" style="font-size:11px;color:var(--mute)"></i></summary>
      <div class="dd"><a href="?p=account"><i class="fas fa-circle-user"></i>My account</a><div class="throw"><span>Theme</span><a data-theme-set="gold" title="Golden"><span class="sw" style="background:linear-gradient(135deg,#f3dc95,#d6ae55 45%,#b08433);color:#14100a"><i class="fas fa-crown"></i></span></a><a data-theme-set="navy" title="Navy"><span class="sw" style="background:linear-gradient(145deg,#132b50,#0a1a33);color:#e8c977"><i class="fas fa-gem"></i></span></a><a data-theme-set="light" title="Light"><span class="sw" style="background:#fff;color:#d6ae55"><i class="fas fa-sun"></i></span></a><a data-theme-set="dark" title="Dark"><span class="sw" style="background:#0d0b08;color:#e8c977"><i class="fas fa-moon"></i></span></a></div><a href="?p=staff"><i class="fas fa-user-shield"></i>Super Admins</a>
        <form method="post"><?= $csrf() ?><button name="do" value="signout"><i class="fas fa-arrow-right-from-bracket"></i>Sign out</button></form></div></details>
  </div>
</header>
<main class="pg-<?= ag_h($page) ?>">
<div class="toasts" id="toasts">
<?php if ($problem !== null): ?><div class="toast bad"><i class="fas fa-circle-exclamation"></i><span><?= ag_h($problem) ?></span><button type="button" onclick="this.parentNode.remove()">&times;</button></div><?php endif; ?>
<?php if ($notice !== null): ?><div class="toast" data-auto><i class="fas fa-circle-check"></i><span><?= ag_h($notice) ?></span><button type="button" onclick="this.parentNode.remove()">&times;</button></div><?php endif; ?>
</div>
<?php if (is_array($reveal)): ?><div class="reveal"><b><i class="fas fa-key"></i> Shown once — copy it now.</b> Sign-in: <code><?= ag_h($reveal['email']) ?></code> Password: <code><?= ag_h($reveal['password']) ?></code><br>
  <span style="font-size:13px"><?php if ($reveal['tid'] === 'staff'): ?>They sign in at this Super Admin page.<?php else:
      $rl = $reveal['link'] ?? (($rt = $tenant((string) $reveal['tid'])) !== null && ($rt['subdomain'] ?? '') !== '' ? AmetecsMail::clientLink((string) $rt['subdomain']) : ''); ?>
      They sign in at <?= $rl !== '' ? 'their company link <code>' . ag_h($rl) . '</code>' : 'the Ametecs Global front door' ?> and can change the password from their profile.<?php endif; ?></span></div><?php endif; ?>

<?php if ($page === 'dashboard'):
    // PHASE 7 (owner, 18 Nov 2026: "redesign and add required features on dashboard"): the business at a glance -
    // recurring revenue, this month vs last, money owed, the renewals coming up, payments in, the licence mix, the
    // health of on-premise servers and how far they are behind, plus the client grid and real changes (not sign-ins).
    $tz = new DateTimeZone('Asia/Kolkata');
    $hourNow = (int) (new DateTimeImmutable('now', $tz))->format('G');
    $saIcons = ['smartprs3' => 'fa-people-group', 'smartept' => 'fa-chart-line', 'smartdcm' => 'fa-hand-holding-dollar', 'leadger' => 'fa-bullseye', 'rovexy' => 'fa-route', 'qronex' => 'fa-qrcode'];
    $saMark = static fn (string $c, array $p): string => '<span class="am" style="--c:' . ag_h((string) $p['color']) . '"><i class="fas ' . ag_h($saIcons[$c] ?? 'fa-cube') . '"></i></span>';
    $chk = static fn (string $st, bool $live): string => match (true) {
        $st === 'on' && $live => '<span class="chk on" title="Licensed - live"><i class="fas fa-check"></i></span>',
        $st === 'on' => '<span class="chk soon" title="Licensed - app arriving on Ametecs Global soon"><i class="fas fa-check"></i></span>',
        $st === 'expired' => '<span class="chk bad" title="Licence expired"><i class="fas fa-xmark"></i></span>',
        $st === 'suspended' => '<span class="chk bad" title="Licence suspended" style="background:#d97706"><i class="fas fa-pause"></i></span>',
        default => '<span class="chk off" title="Not licensed"></span>' };
    $inr = static function (float $v): string {   // ₹12,34,567 (Indian grouping)
        $n = (string) (int) round($v); $t = substr($n, -3); $h = substr($n, 0, -3);
        return '₹' . ($h === '' ? $t : preg_replace('/\B(?=(\d{2})+$)/', ',', $h) . ',' . $t);
    };
    $short = static fn (float $v): string => $v >= 10000000 ? '₹' . round($v / 10000000, 1) . 'Cr' : ($v >= 100000 ? '₹' . round($v / 100000, 1) . 'L' : ($v >= 1000 ? '₹' . round($v / 1000, 1) . 'k' : '₹' . number_format($v)));
    $lr = AmetecsLicences::ready() ? array_merge(...array_map('array_values', array_values(AmetecsLicences::rows()) ?: [[]])) : [];
    $live = array_values(array_filter($lr, static fn ($r) => $r['status'] !== 'cancelled'));
    $on = array_values(array_filter($live, static fn ($r) => AmetecsLicences::stateOf($r) === 'on'));
    // recurring revenue: monthly price, or a twelfth of a yearly one (INR, active licences)
    $mrr = array_sum(array_map(static fn ($r) => $r['currency'] === 'INR' ? (float) ($r['price'] ?? 0) * (['monthly' => 1, 'yearly' => 1 / 12][$r['plan']] ?? 0) : 0, $on));
    $m0 = (new DateTimeImmutable('first day of this month', $tz))->format('Y-m-d 00:00:00');
    $mPrev = (new DateTimeImmutable('first day of last month', $tz))->format('Y-m-d 00:00:00');
    $revThis = AmetecsBilling::revenue($m0);
    $revLast = AmetecsBilling::revenue($mPrev) - $revThis;
    $revDelta = $revLast > 0 ? (int) round(100 * ($revThis - $revLast) / $revLast) : null;
    $pend = AmetecsBilling::ready() ? AmetecsBilling::invoices('', 'pending') : [];
    $owed = array_sum(array_map(static fn ($i) => $i['currency'] === 'INR' ? (float) $i['total'] : 0, $pend));
    $overdue = count(array_filter($pend, static fn ($i) => (string) $i['due_on'] < $today));
    $newClients = count(array_filter($tenants, static fn ($t) => (string) ($t['created_at'] ?? '') >= $m0));
    $admActive = $pdo !== null ? (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'administrator' AND disabled_at IS NULL")->fetchColumn() : 0;
    $pendingInstalls = count(array_filter($on, static fn ($r) => $r['deployment'] === 'onprem'
        && AmetecsReleases::offerFor((string) $r['tenant_id'], (string) $r['app'], (string) ($r['app_version'] ?? ''))['updates'] !== []));
    $in60 = (new DateTimeImmutable($today))->modify('+60 days')->format('Y-m-d');
    $renewals = array_values(array_filter($live, static fn ($r) => ($r['valid_until'] ?? '') !== '' && $r['plan'] !== 'perpetual' && $r['valid_until'] <= $in60
        && $r['valid_until'] >= (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d')));
    usort($renewals, static fn ($a, $b) => strcmp((string) $a['valid_until'], (string) $b['valid_until']));
    $due30 = count(array_filter($renewals, static fn ($r) => $r['valid_until'] >= $today && $r['valid_until'] <= $soon));
    $renewValue = array_sum(array_map(static fn ($r) => $r['currency'] === 'INR' ? (float) ($r['price'] ?? 0) : 0, $renewals));
    $op = array_values(array_filter($on, static fn ($r) => $r['deployment'] === 'onprem'));
    $weekAgo = (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
    $dayAgo = (new DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d H:i:s');
    $fleet = ['online' => 0, 'silent' => 0, 'pending' => 0];
    foreach ($op as $r) {
        $seen = (string) ($r['last_seen_at'] ?? '');
        $k = ($r['fingerprint'] ?? '') === '' ? 'pending' : ($seen !== '' && $seen >= $dayAgo ? 'online' : ($seen === '' || $seen < $weekAgo ? 'silent' : 'online'));
        $fleet[$k]++;
    }
    $names = array_column($tenants, 'name', 'id');
    $tiles = [
        'Pending installations' => ['?p=releases', 'fa-hourglass-half', $pendingInstalls, 'amber', 'on-premise servers behind'],
        'Pending payments' => ['?p=billing&amp;st=pending', 'fa-file-invoice', count($pend), 'amber', $pend !== [] ? ($owed > 0 ? $inr($owed) : count($pend) . ' open') . ($overdue > 0 ? ' · ' . $overdue . ' overdue' : '') : 'all paid'],
        'Renewals in 30 days' => ['?p=licences&amp;st=expiring', 'fa-calendar-check', $due30, 'blue', 'licences ending soon'],
        'Expired subscriptions' => ['?p=licences&amp;st=expired', 'fa-hourglass-end', count(array_filter($live, static fn ($r) => AmetecsLicences::stateOf($r) === 'expired')), 'red', 'not renewed'],
        'Failed installations' => ['?p=releases', 'fa-triangle-exclamation', AmetecsReleases::countEvents('failed'), 'red', 'last 30 days'],
        'Silent servers' => ['?p=licences&amp;dep=onprem', 'fa-plug-circle-xmark', $fleet['silent'], 'red', 'no check-in for a week'],
    ];
    $rm = ($_GET['rm'] ?? '') === '12' ? 12 : 6;
    $mo = [];
    for ($k = $rm - 1; $k >= 0; $k--) {
        $a0 = (new DateTimeImmutable('first day of this month', $tz))->modify("-{$k} months"); $a1 = $a0->modify('+1 month');
        $q = AmetecsBilling::ready() ? $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM ag_payments WHERE status = 'succeeded' AND currency = 'INR' AND created_at >= ? AND created_at < ?") : null;
        $q?->execute([$a0->format('Y-m-d 00:00:00'), $a1->format('Y-m-d 00:00:00')]);
        $mo[$a0->format('M y')] = (float) ($q?->fetchColumn() ?: 0);
    }
    $mx = max(1, max($mo));
    $pays = AmetecsBilling::ready() ? $pdo->query("SELECT p.*, i.number, i.tenant_id, i.description FROM ag_payments p JOIN ag_invoices i ON i.id = p.invoice_id ORDER BY p.id DESC LIMIT 6")->fetchAll(\PDO::FETCH_ASSOC) : [];
    $cloudN = count(array_filter($live, static fn ($r) => $r['deployment'] === 'cloud')); $opN = count($live) - $cloudN;
    $plans = array_count_values(array_map(static fn ($r) => (string) $r['plan'], $live));
    $perApp = []; foreach ($live as $r) { $perApp[$r['app']] = [($perApp[$r['app']][0] ?? 0) + 1, ($perApp[$r['app']][1] ?? 0) + (int) $r['seats']]; }
    $maxSeats = max(1, ...array_values(array_map(static fn ($x) => $x[1], $perApp)) ?: [1]);
    $adopt = [];
    foreach ($plugins as $c => $p) {
        $mine = array_filter($op, static fn ($r) => $r['app'] === $c);
        if ($mine === []) { continue; }
        $newest = null; foreach (AmetecsReleases::all($c) as $r) { if ($r['kind'] !== 'plugin' && AmetecsReleases::stateOf($r) === 'published' && ($newest === null || version_compare($r['version'], $newest, '>'))) { $newest = $r['version']; } }
        $cur = count(array_filter($mine, static fn ($r) => $newest !== null && ($r['app_version'] ?? '') !== '' && version_compare((string) $r['app_version'], $newest, '>=')));
        $adopt[$c] = [$newest, $cur, count($mine)];
    }
    $feed = array_slice(array_values(array_filter(AmetecsData::activity('', 300), static fn ($r) => !in_array($r['what'], ['Signed in', 'Signed out'], true))), 0, 7); ?>
  <section class="hero2">
    <div class="h2t"><span class="eyebrow2"><?= ag_h(date('l, d M Y', (int) strtotime($today))) ?></span>
      <h1><?= $hourNow < 12 ? 'Good morning' : ($hourNow < 17 ? 'Good afternoon' : 'Good evening') ?>, <?= ag_h((string) strtok($myName, ' ')) ?></h1>
      <p><?= count($tenants) ?> clients &middot; <?= $cloudN ?> cloud and <?= $opN ?> on-premise licences &middot; <?= $admActive ?> client administrators &middot; <?= count($plugins) ?> applications</p>
      <div class="row"><a class="btn gold" href="?p=client-new"><i class="fas fa-plus"></i>New client</a><a class="btn hghost" href="?p=licences"><i class="fas fa-key"></i>New licence</a><a class="btn hghost" href="?p=releases"><i class="fas fa-upload"></i>Publish an update</a><a class="btn hghost" href="?p=billing"><i class="fas fa-file-invoice"></i>New invoice</a></div></div>
    <div class="h2k">
      <a href="?p=clients"><i class="fas fa-building"></i><b><?= count($tenants) ?></b><span>Customers<?= $newClients > 0 ? ' &middot; <em class="up">+' . $newClients . ' this month</em>' : '' ?></span></a>
      <a href="?p=licences"><i class="fas fa-arrows-rotate"></i><b><?= ag_h($short($mrr)) ?></b><span>Monthly recurring &middot; <?= ag_h($short($mrr * 12)) ?> a year</span></a>
      <a href="?p=billing"><i class="fas fa-indian-rupee-sign"></i><b><?= ag_h($short($revThis)) ?></b><span>Collected this month<?= $revDelta !== null ? ' &middot; <em class="' . ($revDelta >= 0 ? 'up' : 'down') . '">' . ($revDelta >= 0 ? '▲' : '▼') . abs($revDelta) . '%</em>' : '' ?></span></a>
      <a href="?p=billing&amp;st=pending"><i class="fas fa-hand-holding-dollar"></i><b><?= ag_h($short($owed)) ?></b><span>Outstanding &middot; <?= count($pend) ?> open<?= $overdue > 0 ? ' &middot; <em class="down">' . $overdue . ' overdue</em>' : '' ?></span></a>
    </div>
  </section>
  <section class="actc six"><?php foreach ($tiles as $lab => [$href, $ic, $num, $tone, $sub]): $zero = (int) $num === 0; ?>
    <a class="ac2 <?= $zero ? 'zero' : $tone ?>" href="<?= $href ?>"><span class="ai"><i class="fas <?= $ic ?>"></i></span><span><b><?= (int) $num ?></b><small><?= ag_h($lab) ?></small><small class="s2"><?= $sub ?></small></span></a>
  <?php endforeach; ?></section>

  <div class="dgrid3">
    <div class="box"><div class="hd"><h2><i class="fas fa-chart-column"></i>Revenue collected</h2>
      <span class="vsw sm"><a class="<?= $rm === 6 ? 'on' : '' ?>" href="?p=dashboard">6 months</a><a class="<?= $rm === 12 ? 'on' : '' ?>" href="?p=dashboard&amp;rm=12">12 months</a></span></div>
      <div class="rbars"><?php foreach ($mo as $ml => $mv): ?><div title="<?= ag_h($ml . ': ' . $inr($mv)) ?>"><b><?= $mv > 0 ? ag_h($short($mv)) : '' ?></b><i style="height:<?= max(2, round(100 * $mv / $mx)) ?>%"></i><span><?= ag_h(substr($ml, 0, 3)) ?></span></div><?php endforeach; ?></div>
      <div class="rfoot"><span>Total <?= $rm ?> months <b><?= ag_h($inr(array_sum($mo))) ?></b></span><span>All time <b><?= ag_h($inr(AmetecsBilling::revenue())) ?></b></span><a href="?p=billing">Billing <i class="fas fa-arrow-right"></i></a></div></div>
    <div class="box"><div class="hd"><h2><i class="fas fa-chart-pie"></i>Licence mix</h2><span class="muted sm"><?= count($live) ?> licences</span></div><div class="bd">
      <div class="mixr"><div class="donut2" style="--a:<?= count($live) > 0 ? round(360 * $cloudN / count($live)) : 0 ?>deg"><b><?= count($live) ?></b><small>licences</small></div>
        <ul class="lg2"><li><i style="background:#c9a24c"></i>Cloud<b><?= $cloudN ?></b></li><li><i style="background:#1c1710"></i>On-premise<b><?= $opN ?></b></li></ul></div>
      <div class="plans2"><?php foreach (AmetecsLicences::PLANS as $k => $v): $n = (int) ($plans[$k] ?? 0); ?><div><span><?= ag_h($v) ?></span><span class="pbar"><i style="width:<?= count($live) > 0 ? round(100 * $n / count($live)) : 0 ?>%"></i></span><b><?= $n ?></b></div><?php endforeach; ?></div>
    </div></div>
  </div>

  <div class="dgrid3">
    <div class="box"><div class="hd"><h2><i class="fas fa-calendar-check"></i>Renewals pipeline</h2><span class="muted sm">next 60 days and last 30 &middot; <?= ag_h($inr($renewValue)) ?></span><a href="?p=licences&amp;st=expiring" class="more">All <i class="fas fa-arrow-right"></i></a></div>
      <?php if ($renewals === []): ?><div class="empty"><i class="fas fa-shield-heart"></i>No renewals due in the next 60 days.</div><?php else: ?>
      <table><tr><th>Client</th><th>App</th><th>Ends</th><th>Value</th><th class="right"></th></tr>
      <?php foreach (array_slice($renewals, 0, 7) as $r): $days = (int) floor((strtotime((string) $r['valid_until']) - strtotime($today)) / 86400); ?><tr>
        <td><a href="?p=client&id=<?= (int) $r['tenant_id'] ?>"><b><?= ag_h($names[$r['tenant_id']] ?? '#' . $r['tenant_id']) ?></b></a></td>
        <td><?= $saMark((string) $r['app'], $plugins[$r['app']] ?? ['color' => '#64748B']) ?> <?= ag_h($plugins[$r['app']]['name'] ?? $r['app']) ?></td>
        <td><?= $d($r['valid_until']) ?><br><small class="<?= $days < 0 ? 'tred' : ($days <= 30 ? 'tamb' : 'muted') ?>"><?= $days < 0 ? 'ended ' . -$days . ' days ago' : $days . ' days left' ?></small></td>
        <td><?= (float) ($r['price'] ?? 0) > 0 ? ag_h($r['currency'] . ' ' . number_format((float) $r['price'])) : '<span class="muted">-</span>' ?></td>
        <td class="right" style="white-space:nowrap"><?php if ((float) ($r['price'] ?? 0) > 0 && AmetecsBilling::ready()): ?><form method="post" class="inline"><?= $csrf() ?><input type="hidden" name="do" value="invoice-new"><input type="hidden" name="licence" value="<?= (int) $r['id'] ?>"><button class="btn ghost" title="Raise renewal invoice"><i class="fas fa-file-invoice"></i>Invoice</button></form><?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('Renew this licence by one period now?')"><?= $csrf() ?><input type="hidden" name="do" value="licence-status"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="to" value="renew"><input type="hidden" name="back" value="dashboard"><button class="btn gold"><i class="fas fa-rotate"></i>Renew</button></form></td></tr>
      <?php endforeach; ?></table><?php endif; ?></div>
    <div class="box"><div class="hd"><h2><i class="fas fa-receipt"></i>Recent payments</h2><a href="?p=billing" class="more">All <i class="fas fa-arrow-right"></i></a></div>
      <?php if ($pays === []): ?><div class="empty"><i class="fas fa-receipt"></i>No payments yet.</div><?php else: ?><ul class="pays">
      <?php foreach ($pays as $py): $ref = $py['status'] === 'refunded'; ?><li><span class="pi <?= $ref ? 'r' : '' ?>"><i class="fas fa-<?= $ref ? 'rotate-left' : 'check' ?>"></i></span>
        <div><b><?= ag_h($names[$py['tenant_id']] ?? '#' . $py['tenant_id']) ?></b><small><?= ag_h($py['number']) ?> &middot; <?= ag_h(ucfirst((string) $py['gateway'])) ?> &middot; <?= ag_h(date('d M', (int) strtotime((string) $py['created_at']))) ?></small></div>
        <em class="<?= $ref ? 'r' : '' ?>"><?= $ref ? '−' : '' ?><?= ag_h($py['currency'] . ' ' . number_format((float) $py['amount'])) ?></em></li>
      <?php endforeach; ?></ul><?php endif; ?></div>
  </div>

  <?php // FOUR BOXES REDESIGNED (owner, 19 Nov 2026: "redesign these four"), royal black and gold. ?>
  <div class="dgrid3 d4">
    <div class="box d4mx"><div class="hd"><h2><i class="fas fa-table-cells"></i>Client licences</h2>
        <span class="lg"><em><span class="chk on"><i class="fas fa-check"></i></span>Live</em><em><span class="chk soon"><i class="fas fa-check"></i></span>Arriving soon</em><em><span class="chk off"></span>Not licensed</em><em><span class="chk bad"><i class="fas fa-xmark"></i></span>Expired</em></span>
        <a href="?p=clients" class="more">All clients <i class="fas fa-arrow-right"></i></a></div>
      <div class="mx"><table class="mxt"><thead><tr><th>Client</th><?php foreach ($plugins as $c => $p): ?><th class="ac" title="<?= ag_h($p['name']) ?>"><small><?= ag_h($p['name']) ?></small></th><?php endforeach; ?><th class="ac">Apps</th><th class="ac">Employees</th><th>Status</th></tr></thead><tbody>
      <?php foreach (array_slice($tenants, 0, 8) as $t): $tid = (string) $t['id']; $nOn = 0; foreach ($plugins as $c => $p) { AmetecsAccess::state($tid, $c, $all) === 'on' && $nOn++; } ?><tr>
        <td><a class="cn" href="?p=client&id=<?= (int) $tid ?>"><b><?= ag_h($t['name']) ?></b><small><?= ($t['subdomain'] ?? '') !== '' ? '/c/' . ag_h((string) $t['subdomain']) : 'no sign-in link' ?></small></a></td>
        <?php foreach ($plugins as $c => $p): ?><td class="ac"><?= $chk(AmetecsAccess::state($tid, $c, $all), $p['open'] !== null) ?></td><?php endforeach; ?>
        <td class="ac"><span class="d4n"><?= $nOn ?><small>/<?= count($plugins) ?></small></span></td><td class="ac"><b class="d4emp"><?= (int) ($used[$tid] ?? 0) ?></b></td><td><?= $statusPill((string) $t['status_code']) ?></td></tr>
      <?php endforeach; ?><?php if ($tenants === []): ?><tr><td colspan="<?= count($plugins) + 4 ?>"><div class="empty"><i class="fas fa-building"></i>No clients yet.</div></td></tr><?php endif; ?></tbody></table></div></div>
    <div class="box d4apps"><div class="hd"><h2><i class="fas fa-layer-group"></i>Licences by app</h2><span class="muted sm">by seats</span></div>
      <ol class="d4rank"><?php $ranked = $plugins; uksort($ranked, static fn ($x, $y) => ($perApp[$y][1] ?? 0) <=> ($perApp[$x][1] ?? 0)); $rk = 0;
        foreach ($ranked as $c => $p): [$cn, $sn] = $perApp[$c] ?? [0, 0]; $rk++; ?>
        <li><span class="d4r"><?= $rk ?></span><div class="d4m"><div class="d4t"><b><?= ag_h($p['name']) ?></b><span class="d4s <?= $p['open'] !== null ? 'live' : '' ?>"><?= $p['open'] !== null ? 'Live' : 'Soon' ?></span></div>
          <span class="d4bar"><i style="width:<?= max(2, round(100 * $sn / $maxSeats)) ?>%"></i></span></div>
          <div class="d4v"><b><?= number_format($sn) ?></b><small>seats</small><em><?= $cn ?> client<?= $cn === 1 ? '' : 's' ?></em></div></li>
      <?php endforeach; ?></ol></div>
  </div>

  <div class="dgrid3 d4">
    <div class="box d4op"><div class="hd"><h2><i class="fas fa-server"></i>On-premise servers</h2><span class="muted sm"><?= count($op) ?> active on-premise licences</span></div><div class="bd">
      <div class="d4fleet"><a href="?p=licences&amp;dep=onprem"><span class="fi ok"><i class="fas fa-signal"></i></span><b><?= $fleet['online'] ?></b><small>Checked in this week</small></a>
        <a href="?p=licences&amp;dep=onprem" class="<?= $fleet['silent'] > 0 ? 'warn' : '' ?>"><span class="fi<?= $fleet['silent'] > 0 ? ' bad' : '' ?>"><i class="fas fa-bell-slash"></i></span><b><?= $fleet['silent'] ?></b><small>Silent for a week+</small></a>
        <a href="?p=licences&amp;dep=onprem"><span class="fi"><i class="fas fa-hourglass-half"></i></span><b><?= $fleet['pending'] ?></b><small>Not activated yet</small></a></div>
      <?php if ($adopt !== []): ?><div class="d4ad"><div class="d4adh"><span>Application</span><span>On the newest version</span></div><?php foreach ($adopt as $c => [$nv, $cur, $tot]): $pc = $tot > 0 ? round(100 * $cur / $tot) : 0; ?>
        <div class="d4adr"><span><b><?= ag_h($plugins[$c]['name']) ?></b><small><?= $nv !== null ? 'v' . ag_h($nv) : 'no release yet' ?></small></span><span class="d4bar"><i style="width:<?= $pc ?>%"></i></span><em><?= $cur ?>/<?= $tot ?></em></div><?php endforeach; ?></div>
      <?php else: ?><p class="muted" style="margin:12px 0 0;font-size:13px">No on-premise installations yet.</p><?php endif; ?></div></div>
    <div class="box d4feed"><div class="hd"><h2><i class="fas fa-bolt"></i>Latest changes</h2><a href="?p=activity" class="more">All <i class="fas fa-arrow-right"></i></a></div>
      <?php if ($feed === []): ?><div class="empty"><i class="fas fa-wind"></i>No changes yet.</div><?php else: ?>
      <ul class="d4tl"><?php foreach ($feed as $r): $w = strtolower((string) $r['what']); $fic = str_contains($w, 'payment') ? 'fa-indian-rupee-sign' : (str_contains($w, 'invoice') ? 'fa-file-invoice' : (str_contains($w, 'licen') ? 'fa-key' : (str_contains($w, 'sign') ? 'fa-right-to-bracket' : (str_contains($w, 'release') || str_contains($w, 'update') ? 'fa-cloud-arrow-up' : 'fa-pen')))); ?>
        <li><span class="d4i"><i class="fas <?= $fic ?>"></i></span><div><div class="ft2"><?= ag_h($r['what']) ?><?= ($r['tenant'] ?? '') !== '' ? ' <a class="d4c" href="?p=client&id=' . (int) $r['tenant'] . '">' . ag_h($names[$r['tenant']] ?? '#' . $r['tenant']) . '</a>' : '' ?></div>
          <div class="fm"><?= ag_h($r['who']) ?></div></div><time data-at="<?= ag_h($r['at']) ?>"><?= ag_h($r['at']) ?></time></li>
      <?php endforeach; ?></ul><?php endif; ?></div>
  </div>
<?php elseif ($page === 'clients'):
    $q = strtolower(trim((string) ($_GET['q'] ?? '')));
    $sf = (string) ($_GET['s'] ?? '');
    $list = array_filter($tenants, static fn ($t) => ($q === '' || str_contains(strtolower($t['name'] . ' ' . $t['code'] . ' ' . ($t['subdomain'] ?? '')), $q)) && ($sf === '' || $t['status_code'] === $sf)); ?>
  <h1>Clients</h1><p class="sub">Every company on Ametecs Global. Open one to change its apps, licences and logins.</p>
  <form class="toolbar"><input type="hidden" name="p" value="clients"><span class="si"><i class="fas fa-magnifying-glass"></i><input name="q" data-live="#clients-t" placeholder="Search name, code or sign-in link" value="<?= ag_h($q) ?>" autocomplete="off"></span>
    <select name="s"><option value="">All statuses</option><?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>"<?= $sf === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
    <button class="btn ghost"><i class="fas fa-filter"></i>Filter</button><a class="btn gold" href="?p=client-new" style="margin-left:auto"><i class="fas fa-plus"></i>New client</a></form>
  <div class="box"><table id="clients-t" class="sortable"><tr><th>Client</th><th>Code</th><th>Status</th><th>Apps on</th><th>Employees</th><th>Since</th><th></th></tr>
  <?php foreach ($list as $t): $tid = (string) $t['id']; ?><tr>
    <td><a href="?p=client&id=<?= (int) $tid ?>"><b><?= ag_h($t['name']) ?></b></a><div class="muted" style="font-size:12px"><?= ($t['subdomain'] ?? '') !== '' ? '/c/' . ag_h((string) $t['subdomain']) : 'no sign-in link' ?><?= ($meta[$tid]['contact_name'] ?? '') !== '' ? ' · ' . ag_h($meta[$tid]['contact_name']) : '' ?></div></td>
    <td><?= ag_h($t['code']) ?></td><td><?= $statusPill((string) $t['status_code']) ?></td>
    <td class="chips"><?php foreach ($appsOn($tid) as $c => $st): ?><span class="pill <?= $st === 'on' ? 'green' : ($st === 'suspended' ? 'amber' : 'red') ?>"><?= ag_h($plugins[$c]['name']) ?></span><?php endforeach; ?><?= $appsOn($tid) === [] ? '<span class="muted">none</span>' : '' ?></td>
    <td><?= (int) ($used[$tid] ?? 0) ?></td><td><?= $d($t['onboarded_on'] ?? $t['created_at']) ?></td><td class="right"><a class="btn ghost" href="?p=client&id=<?= (int) $tid ?>">Open</a></td></tr>
  <?php endforeach; ?><?php if ($list === []): ?><tr><td colspan="7" class="muted">No clients match.</td></tr><?php endif; ?></table></div>

<?php elseif ($page === 'client-new'): ?>
  <h1>New client</h1><p class="sub">Creates the company, its first administrator login and its app licences in one step. The administrator signs in at the Ametecs Global front door.</p>
  <form method="post"><?= $csrf() ?><input type="hidden" name="do" value="client-create">
  <div class="box"><div class="hd"><h2><span class="stepn">1</span>Company</h2></div><div class="bd grid2">
    <div><label>Company name <span class="req">*</span></label><input name="name" required value="<?= $f('name') ?>" placeholder="ABC Collections Pvt Ltd"></div>
    <div><label>Client code <span class="req">*</span></label><input name="code" required value="<?= $f('code') ?>" placeholder="ABC" maxlength="20"></div>
    <div><label>Legal name (for payslips &amp; letters)</label><input name="legal_name" value="<?= $f('legal_name') ?>"></div>
    <div><label>Sign-in link name</label><input name="slug" value="<?= $f('slug') ?>" placeholder="made from the name if empty"><span class="muted" style="font-size:12px">their link: <?= ag_h(AmetecsMail::clientLink('name')) ?></span></div>
    <div><label>Time zone</label><select name="timezone"><?php foreach ($timezones as $z): ?><option<?= ($form['timezone'] ?? 'Asia/Kolkata') === $z ? ' selected' : '' ?>><?= $z ?></option><?php endforeach; ?></select></div>
    <div><label>State</label><input name="state" value="<?= $f('state') ?>" placeholder="Telangana"></div>
  </div></div>
  <div class="box"><div class="hd"><h2><span class="stepn">2</span>Contact person</h2></div><div class="bd grid2">
    <div><label>Name</label><input name="contact_name" value="<?= $f('contact_name') ?>"></div>
    <div><label>Phone</label><input name="contact_phone" value="<?= $f('contact_phone') ?>"></div>
    <div><label>Email</label><input type="email" name="contact_email" value="<?= $f('contact_email') ?>"></div>
    <div><label>Notes</label><input name="notes" value="<?= $f('notes') ?>"></div>
  </div></div>
  <div class="box"><div class="hd"><h2><span class="stepn">3</span>Client administrator login</h2></div><div class="bd grid2">
    <div><label>Administrator email <span class="req">*</span></label><input type="email" name="admin_email" required value="<?= $f('admin_email') ?>"></div>
    <div><label>Password</label><input name="admin_password" minlength="<?= Session::PASSWORD_FLOOR ?>" placeholder="leave empty to generate one"></div>
  </div></div>
  <div class="box"><div class="hd"><h2><span class="stepn">4</span>Apps &amp; licences</h2><span class="muted">all apps start ticked - untick what this client does not take; valid till left empty = one year</span></div>
    <?= $licenceRows('', isset($form['p']) ? array_map(static fn ($r) => ['on' => isset($r['on']), 'seats' => (int) ($r['seats'] ?? 0), 'until' => (string) ($r['until'] ?? '')], (array) $form['p']) + array_fill_keys(array_keys($plugins), ['on' => false, 'seats' => 0, 'until' => ''])
        : array_fill_keys(array_keys($plugins), ['on' => true, 'seats' => 25, 'until' => ''])) ?>
    <div class="ft"><a class="btn ghost" href="?p=clients">Cancel</a> <button class="btn gold">Create client</button></div></div>
  </form>

<?php elseif ($page === 'client' && ($t = $tenant((string) ($_GET['id'] ?? ''))) !== null):
    $tid = (string) $t['id'];
    $m = $meta[$tid] ?? [];
    $logins = $admins($tid); ?>
  <div class="phero"><div class="row" style="margin-bottom:6px"><a href="?p=clients" class="muted">← Clients</a></div>
  <div class="row"><h1><?= ag_h($t['name']) ?></h1><?= $statusPill((string) $t['status_code']) ?><?= !empty($m['house']) ? '<span class="pill amber">Ametecs - house client for testing</span>' : '' ?></div>
  <p class="sub">Code <?= ag_h($t['code']) ?> · client #<?= (int) $tid ?> · since <?= $d($t['onboarded_on'] ?? $t['created_at']) ?> · <?= (int) ($used[$tid] ?? 0) ?> active employees</p></div>
  <div class="pg2"><div class="pgm"><form method="post" id="apps"><?= $csrf() ?><input type="hidden" name="do" value="licences-save"><input type="hidden" name="id" value="<?= (int) $tid ?>">
  <div class="box"><div class="hd"><h2><i class="fas fa-layer-group"></i>Apps &amp; licences</h2><span class="muted">what this client can open, how many seats, until when</span></div>
    <?= $licenceRows($tid, (string) ($form['lic_for'] ?? '') === $tid ? array_map(static fn ($r) => ['on' => isset($r['on']), 'seats' => (int) ($r['seats'] ?? 0), 'until' => (string) ($r['until'] ?? '')], (array) ($form['p'] ?? [])) + array_fill_keys(array_keys($plugins), ['on' => false, 'seats' => 0, 'until' => '']) : []) ?>
    <div class="ft"><span class="muted" style="float:left">Valid till left empty = one year from today.</span><button class="btn gold">Save apps &amp; licences</button></div></div></form>

  <div class="box" id="logins"><div class="hd"><h2><i class="fas fa-user-lock"></i>Logins</h2><span class="muted">one login per person works for every app this client has</span></div>
  <table><tr><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th class="right">Actions</th></tr>
  <?php foreach ($logins as $u): $off = trim((string) ($u['disabled_at'] ?? '')) !== ''; ?><tr>
    <td><?= ag_h($u['email']) ?></td><td><?= ag_h(ucfirst((string) $u['status'])) ?></td><td><?= $off ? '<span class="pill red">Deactivated</span>' : '<span class="pill green">Can sign in</span>' ?></td><td><?= $d($u['created_at']) ?></td>
    <td class="right">
      <?php $hid = '<input type="hidden" name="id" value="' . (int) $tid . '"><input type="hidden" name="user" value="' . (int) $u['id'] . '">'; ?>
      <details class="act"><summary class="btn ghost">Reset password ▾</summary><div class="menu">
        <form method="post"><?= $csrf() ?><?= $hid ?><input type="hidden" name="do" value="login-password">
          <b>Set password now</b><span class="muted">type one, or leave empty to generate one (shown once)</span>
          <div class="row"><input name="password" placeholder="new password (or empty)" minlength="<?= Session::PASSWORD_FLOOR ?>"><button class="btn navy">Set now</button></div></form>
        <form method="post"><?= $csrf() ?><?= $hid ?><input type="hidden" name="do" value="login-link">
          <b>Email a reset link</b><span class="muted">they choose their own password; the link works once</span>
          <button class="btn gold"<?= AmetecsMail::box($pdo)->ready() ? '' : ' disabled title="Set up Email settings first"' ?>>Email link to <?= ag_h($u['email']) ?></button></form>
      </div></details>
      <form method="post" class="inline" onsubmit="return confirm('<?= $off ? 'Reactivate' : 'Deactivate' ?> <?= ag_h(addslashes((string) $u['email'])) ?>?')"><?= $csrf() ?><?= $hid ?><input type="hidden" name="do" value="login-toggle"><button class="btn <?= $off ? 'navy' : 'danger' ?>"><?= $off ? 'Reactivate' : 'Deactivate' ?></button></form></td></tr>
  <?php endforeach; ?><?php if ($logins === []): ?><tr><td colspan="5" class="muted">No logins yet — create the administrator below.</td></tr><?php endif; ?></table>
  <form method="post" class="addadm"><?= $csrf() ?><input type="hidden" name="do" value="admin-create"><input type="hidden" name="id" value="<?= (int) $tid ?>">
    <b><i class="fas fa-user-plus"></i>Add an administrator</b>
    <label>Email<input type="email" name="email" placeholder="name@company.com" required></label>
    <label>Password<input name="password" placeholder="leave empty to generate one"></label>
    <button class="btn gold"><i class="fas fa-plus"></i>Create</button></form>
  <div class="bd muted" style="font-size:13px">The client's administrator adds their own employees' logins inside SmartPRS 3 (Users).</div></div>

  <div class="box"><div class="hd"><h2><i class="fas fa-clock-rotate-left"></i>Activity for this client</h2></div><table><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr>
  <?php foreach (AmetecsData::activity($tid, 50) as $r): ?><tr><td><?= ag_h($r['at']) ?></td><td><?= ag_h($r['who']) ?></td><td><?= ag_h($r['what']) ?></td><td class="muted"><?= ag_h($r['detail']) ?></td></tr><?php endforeach; ?></table></div>

</div><aside class="pgs"><div class="box"><div class="bd row"><b>Sign-in link</b>
    <?php if (($t['subdomain'] ?? '') !== ''): $cl = AmetecsMail::clientLink((string) $t['subdomain']); ?>
      <code id="cl"><?= ag_h($cl) ?></code><button type="button" class="btn ghost" onclick="navigator.clipboard.writeText(document.getElementById('cl').textContent);this.textContent='Copied'">Copy</button>
      <a class="btn ghost" href="<?= ag_h($cl) ?>" target="_blank">Open ↗</a><span class="muted">Give this to the client: it shows their company name and only accepts their logins.</span>
    <?php else: ?><span class="muted">No sign-in link name yet - set one under Details below.</span><?php endif; ?></div></div>

  <div class="box"><div class="hd"><h2><i class="fas fa-toggle-on"></i>Status</h2></div><div class="bd row">
    <span>This client is <b><?= ag_h($statuses[$t['status_code']] ?? $t['status_code']) ?></b>.</span>
    <?php foreach (['active' => ['Reactivate', 'navy'], 'suspended' => ['Suspend', 'danger'], 'closed' => ['Deactivate', 'danger']] as $to => [$label, $cls]): if ($to === $t['status_code']) continue; ?>
      <form method="post" class="inline" onsubmit="return confirm('<?= $label ?> <?= ag_h(addslashes($t['name'])) ?>?')"><?= $csrf() ?><input type="hidden" name="do" value="client-status"><input type="hidden" name="id" value="<?= (int) $tid ?>"><input type="hidden" name="to" value="<?= $to ?>"><button class="btn <?= $cls ?>"><?= $label ?></button></form>
    <?php endforeach; ?>
    <span class="muted"><b>Suspend</b> = a temporary stop (e.g. unpaid). <b>Deactivate</b> = the client has left. Either way nobody at this client can sign in to any app, and no data is deleted; Reactivate undoes both.</span>
  </div></div>

  <form method="post"><?= $csrf() ?><input type="hidden" name="do" value="client-save"><input type="hidden" name="id" value="<?= (int) $tid ?>">
  <div class="box"><div class="hd"><h2><i class="fas fa-pen-to-square"></i>Details</h2></div><div class="bd grid2">
    <div><label>Company name</label><input name="name" required value="<?= ag_h($t['name']) ?>"></div>
    <div><label>Client code</label><input name="code" required value="<?= ag_h($t['code']) ?>"></div>
    <div><label>Sign-in link name</label><input name="slug" value="<?= ag_h((string) ($t['subdomain'] ?? '')) ?>"></div>
    <div><label>Time zone</label><select name="timezone"><?php foreach (array_unique(array_merge([$t['business_timezone']], $timezones)) as $z): ?><option<?= $t['business_timezone'] === $z ? ' selected' : '' ?>><?= ag_h($z) ?></option><?php endforeach; ?></select></div>
    <div><label>State</label><input name="state" value="<?= ag_h((string) ($t['state'] ?? '')) ?>"></div>
    <div><label>Contact name</label><input name="contact_name" value="<?= ag_h($m['contact_name'] ?? '') ?>"></div>
    <div><label>Contact phone</label><input name="contact_phone" value="<?= ag_h($m['contact_phone'] ?? '') ?>"></div>
    <div><label>Contact email</label><input type="email" name="contact_email" value="<?= ag_h($m['contact_email'] ?? '') ?>"></div>
    <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= ag_h($m['notes'] ?? '') ?></textarea></div>
  </div><div class="ft"><button class="btn navy">Save details</button></div></div></form>

  </aside></div>

<?php elseif ($page === 'licences'):
    // PHASE 7 (owner, 18 Nov 2026: "redesign this page"): summary cards and status tabs that filter, a by-client view
    // (one card per client, one row per app) or a flat list, validity shown as days left, quick renew / suspend.
    $names = array_column($tenants, 'name', 'id');
    $fa = (string) ($_GET['app'] ?? ''); $fd = (string) ($_GET['dep'] ?? ''); $fs = (string) ($_GET['st'] ?? ''); $view2 = ($_GET['view'] ?? '') === 'list' ? 'list' : 'client';
    $rowsAll = [];
    foreach (AmetecsLicences::rows() as $ltid => $lapps) { foreach ($lapps as $lr) { $rowsAll[] = $lr; } }
    $stOf = static fn (array $r): string => $r['status'] === 'cancelled' ? 'off' : (AmetecsLicences::stateOf($r) === 'on' && ($r['valid_until'] ?? '') !== '' && $r['valid_until'] <= $soon ? 'expiring' : AmetecsLicences::stateOf($r));
    $count = static fn (string $k) => count(array_filter($rowsAll, static fn ($r) => $k === '' ? $r['status'] !== 'cancelled' : ($k === 'on' ? in_array($stOf($r), ['on', 'expiring'], true) : $stOf($r) === $k)));
    $list = array_filter($rowsAll, static fn ($r) => ($fa === '' || $r['app'] === $fa) && ($fd === '' || $r['deployment'] === $fd)
        && ($fs === '' ? $r['status'] !== 'cancelled' : ($fs === 'on' ? in_array($stOf($r), ['on', 'expiring'], true) : $stOf($r) === $fs)));
    usort($list, static fn ($a, $b) => strcmp((string) ($names[$a['tenant_id']] ?? ''), (string) ($names[$b['tenant_id']] ?? '')) ?: strcmp((string) $a['app'], (string) $b['app']));
    $byClient = [];
    foreach ($list as $r) { $byClient[(string) $r['tenant_id']][] = $r; }
    $licIcons = ['smartprs3' => 'fa-people-group', 'smartept' => 'fa-chart-line', 'smartdcm' => 'fa-hand-holding-dollar', 'leadger' => 'fa-bullseye', 'rovexy' => 'fa-route', 'qronex' => 'fa-qrcode'];
    $mark = static fn (string $c) => '<span class="lmk" style="--c:' . ag_h((string) ($plugins[$c]['color'] ?? '#64748B')) . '"><i class="fas ' . ($licIcons[$c] ?? 'fa-cube') . '"></i></span>';
    $tabs = ['' => ['All', 'fa-layer-group'], 'on' => ['Active', 'fa-circle-check'], 'expiring' => ['Expiring in 30 days', 'fa-hourglass-half'], 'suspended' => ['Suspended', 'fa-pause'], 'expired' => ['Expired', 'fa-hourglass-end'], 'off' => ['Cancelled', 'fa-ban']];
    $url = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['p' => 'licences', 'app' => $fa, 'dep' => $fd, 'st' => $fs, 'view' => $view2 === 'list' ? 'list' : ''], $over), static fn ($v) => $v !== ''));
    $validity = static function (array $r) use ($today, $d): string {
        $u = (string) ($r['valid_until'] ?? '');
        if ($u === '') { return '<div class="vl"><b>No end date</b><small>' . ($r['plan'] === 'perpetual' ? 'perpetual' : '') . '</small></div>'; }
        $days = (int) floor((strtotime($u) - strtotime($today)) / 86400);
        $total = max(1, (int) floor((strtotime($u) - strtotime((string) (($r['starts_on'] ?? '') ?: date('Y-m-d', (int) strtotime($u . ' -1 year'))))) / 86400));
        $pct = max(0, min(100, (int) round(100 * $days / $total)));
        $tone = $days < 0 ? 'red' : ($days <= 30 ? 'amber' : 'green');
        return '<div class="vl"><b>' . $d($u) . '</b><small class="t-' . $tone . '">' . ($days < 0 ? 'ended ' . -$days . ' days ago' : ($days === 0 ? 'ends today' : $days . ' days left')) . '</small><span class="vbar"><i class="t-' . $tone . '" style="width:' . ($days < 0 ? 100 : $pct) . '%"></i></span></div>';
    };
    $quick = static function (array $r) use ($csrf): string {
        $f = static fn (string $to, string $label, string $ic, string $ask = '') => '<form method="post"' . ($ask !== '' ? ' onsubmit="return confirm(\'' . ag_h($ask) . '\')"' : '') . '>' . $csrf() . '<input type="hidden" name="do" value="licence-status"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="to" value="' . $to . '"><input type="hidden" name="back" value="licences"><button><i class="fas ' . $ic . '"></i>' . $label . '</button></form>';
        return '<details class="qa"><summary title="Quick actions"><i class="fas fa-ellipsis"></i></summary><div class="qm">'
            . '<a href="?p=licence&id=' . (int) $r['id'] . '"><i class="fas fa-up-right-from-square"></i>Open licence</a>'
            . ($r['plan'] !== 'perpetual' && $r['status'] !== 'cancelled' ? $f('renew', 'Renew one period', 'fa-rotate', 'Extend this licence by one plan period?') : '')
            . ($r['status'] === 'active' ? $f('suspended', 'Suspend', 'fa-pause', 'Suspend this licence? The app stops opening for this client; nothing is deleted.') : $f('active', 'Activate', 'fa-play'))
            . '<a href="?p=client&id=' . (int) $r['tenant_id'] . '"><i class="fas fa-building"></i>Open client</a></div></details>';
    };
    $seatCell = static function (array $r) use ($used, $plugins): string {
        $cap = (int) $r['seats'];
        $u = $r['app'] === 'smartprs3' ? (int) ($used[(string) $r['tenant_id']] ?? 0) : null;
        $pct = $u !== null && $cap > 0 ? min(100, (int) round(100 * $u / $cap)) : null;
        return '<div class="vl"><b>' . ($u !== null ? $u . ' / ' : '') . ($cap > 0 ? $cap : '&infin;') . '</b><small>' . ag_h((string) ($plugins[$r['app']]['unit'] ?? 'users')) . ($u !== null ? ' in use' : '') . '</small>'
            . ($pct !== null ? '<span class="vbar"><i class="t-' . ($pct >= 90 ? 'amber' : 'blue') . '" style="width:' . max(2, $pct) . '%"></i></span>' : '') . '</div>';
    };
    $server = static fn (array $r): string => $r['deployment'] === 'onprem'
        ? (($r['fingerprint'] ?? '') !== '' ? '<span class="srv srv-ok"><i class="fas fa-server"></i>' . ag_h((string) ($r['server_name'] ?: 'bound')) . ' · v' . ag_h((string) ($r['app_version'] ?: '?')) . '</span>'
            : '<span class="srv srv-warn"><i class="fas fa-key"></i>' . (($r['key_hash'] ?? '') !== '' ? 'Key issued, not activated' : 'No key yet') . '</span>')
        : '<span class="srv"><i class="fas fa-cloud"></i>Ametecs cloud</span>';
    // EXPANDED ROW (owner, 18 Nov 2026: "expand option"): the licence's details and recent history without leaving the list.
    $detail = static function (array $r) use ($d, $plugins): string {
        $kv = static fn (string $k, string $v): string => '<div><dt>' . $k . '</dt><dd>' . $v . '</dd></div>';
        $op = $r['deployment'] === 'onprem';
        $pl = AmetecsPlugins::ready() ? AmetecsPlugins::catalogue((string) $r['app']) : [];
        $held = array_filter($pl, static fn ($p) => AmetecsPlugins::authorised(AmetecsPlugins::state((string) $r['tenant_id'], $p['latest'])));
        $h = '<div class="ldet"><dl>'
            . $kv('Plan', ag_h(AmetecsLicences::PLANS[$r['plan']] ?? $r['plan']) . ((float) ($r['price'] ?? 0) > 0 ? ' &middot; ' . ag_h($r['currency'] . ' ' . number_format((float) $r['price'], 2)) . ' per period' : ' &middot; no price set'))
            . $kv('Period', $d($r['starts_on'] ?? null) . ' &rarr; ' . $d($r['valid_until'] ?? null))
            . $kv('Renews', !empty($r['auto_renew']) ? 'Automatically' : 'When renewed or paid')
            . $kv('Deployment', $op ? 'On-premise' : 'Ametecs cloud')
            . ($op ? $kv('Licence key', trim((string) ($r['key_hash'] ?? '')) !== '' ? 'Issued' : '<span class="t-amber">Not issued</span>')
                . $kv('Server', ($r['fingerprint'] ?? '') !== '' ? ag_h((string) ($r['server_name'] ?: 'bound')) . ' <small>' . ag_h((string) ($r['server_os'] ?? '')) . '</small>' : 'Not activated')
                . $kv('Activated', ag_h((string) (($r['activated_at'] ?? '') ?: '—')))
                . $kv('Last check-in', ag_h((string) (($r['last_seen_at'] ?? '') ?: '—')) . (($r['app_version'] ?? '') !== '' ? ' &middot; v' . ag_h((string) $r['app_version']) : '')) : '')
            . $kv('Plugins', $pl === [] ? 'none offered' : count($held) . ' of ' . count($pl) . ' held')
            . (trim((string) ($r['notes'] ?? '')) !== '' ? $kv('Notes', nl2br(ag_h((string) $r['notes']))) : '')
            . '</dl><div class="lhist"><b>Recent changes</b>';
        $ev = AmetecsLicences::events((string) $r['id'], null, 4);
        foreach ($ev as $e) {
            $h .= '<p><span>' . ag_h(date('d M Y H:i', (int) strtotime((string) $e['created_at']))) . '</span>' . ag_h((string) $e['action']) . '<small>' . ag_h((string) $e['by_who']) . '</small></p>';
        }
        return $h . ($ev === [] ? '<p><small>Nothing yet.</small></p>' : '') . '<a href="?p=licence&id=' . (int) $r['id'] . '">Full licence page &rarr;</a></div></div>';
    }; ?>
  <div class="lph"><div><h1>Licences</h1><p class="sub">Every licence of every app, per client: deployment, plan, seats and how long it has left. Open one for keys, .lic files, plugins and history.</p></div>
    <button type="button" class="btn gold" onclick="document.getElementById('newlic').showModal()"><i class="fas fa-plus"></i>New licence</button></div>
  <?php if (!AmetecsLicences::ready()): ?><div class="msg bad"><i class="fas fa-circle-exclamation"></i><span>The licence tables are not on this PC yet. They arrive with the next sync; until then licences work as before.</span></div><?php else: ?>
  <section class="lsum">
    <?php foreach (['on' => ['Active', 'fa-circle-check', 'green'], 'expiring' => ['Expiring in 30 days', 'fa-hourglass-half', 'amber'], 'suspended' => ['Suspended', 'fa-pause', 'amber'], 'expired' => ['Expired', 'fa-hourglass-end', 'red']] as $k => [$lab, $ic, $tone]): $n = $count($k); ?>
      <a class="ls <?= $n === 0 ? 'zero' : $tone ?><?= $fs === $k ? ' sel' : '' ?>" href="<?= ag_h($url(['st' => $fs === $k ? '' : $k])) ?>"><span class="li"><i class="fas <?= $ic ?>"></i></span><span><b><?= $n ?></b><small><?= $lab ?></small></span></a>
    <?php endforeach; ?>
    <div class="ls split"><span class="li"><i class="fas fa-cloud"></i></span><span><b><?= count(array_filter($rowsAll, static fn ($r) => $r['status'] !== 'cancelled' && $r['deployment'] === 'cloud')) ?> <em>/</em> <?= count(array_filter($rowsAll, static fn ($r) => $r['status'] !== 'cancelled' && $r['deployment'] === 'onprem')) ?></b><small>Cloud / on-premise</small></span></div>
  </section>
  <div class="ltabs"><?php foreach ($tabs as $k => [$lab, $ic]): ?><a class="<?= $fs === $k ? 'on' : '' ?>" href="<?= ag_h($url(['st' => $k])) ?>"><i class="fas <?= $ic ?>"></i><?= $lab ?><em><?= $count($k) ?></em></a><?php endforeach; ?></div>
  <form class="ltool" onchange="this.submit()"><input type="hidden" name="p" value="licences"><input type="hidden" name="st" value="<?= ag_h($fs) ?>"><?= $view2 === 'list' ? '<input type="hidden" name="view" value="list">' : '' ?>
    <span class="si"><i class="fas fa-magnifying-glass"></i><input data-lfind placeholder="Search client, app or server…" autocomplete="off" onchange="event.stopPropagation()"></span>
    <select name="app"><option value="">All apps</option><?php foreach ($plugins as $c => $p): ?><option value="<?= ag_h($c) ?>"<?= $fa === $c ? ' selected' : '' ?>><?= ag_h($p['name']) ?></option><?php endforeach; ?></select>
    <select name="dep"><option value="">Cloud &amp; on-premise</option><?php foreach (AmetecsLicences::DEPLOYMENTS as $k => $v): ?><option value="<?= $k ?>"<?= $fd === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
    <span class="xall"><button type="button" data-xall="1"><i class="fas fa-angles-down"></i>Expand all</button><button type="button" data-xall="0"><i class="fas fa-angles-up"></i>Collapse all</button></span>
    <span class="vsw"><a class="<?= $view2 === 'client' ? 'on' : '' ?>" href="<?= ag_h($url(['view' => ''])) ?>" title="Grouped by client"><i class="fas fa-building"></i>By client</a><a class="<?= $view2 === 'list' ? 'on' : '' ?>" href="<?= ag_h($url(['view' => 'list'])) ?>" title="One list"><i class="fas fa-list"></i>List</a></span></form>

  <?php if ($list === []): ?><div class="box"><div class="empty"><i class="fas fa-key"></i>No licences match these filters. <a href="?p=licences">Show all</a></div></div>
  <?php elseif ($view2 === 'client'): ?>
  <div class="lgrp"><?php foreach ($byClient as $tid => $rs): $tn = (string) ($names[$tid] ?? '#' . $tid); $next = min(array_map(static fn ($r) => (string) (($r['valid_until'] ?? '') ?: '9999-12-31'), $rs)); ?>
    <details class="lcl" open data-cl="<?= (int) $tid ?>" data-lf="<?= ag_h(strtolower($tn . ' ' . implode(' ', array_map(static fn ($r) => ($plugins[$r['app']]['name'] ?? $r['app']) . ' ' . ($r['server_name'] ?? ''), $rs)))) ?>">
      <summary><i class="fas fa-chevron-down car"></i><div><a href="?p=client&id=<?= (int) $tid ?>"><b><?= ag_h($tn) ?></b></a><small><?= count($rs) ?> licence<?= count($rs) === 1 ? '' : 's' ?><?= $next !== '9999-12-31' ? ' &middot; next renewal ' . $d($next) : '' ?></small></div>
        <span class="cpills"><?php foreach ($rs as $r): ?><span title="<?= ag_h($plugins[$r['app']]['name'] ?? $r['app']) ?>"><?= $mark((string) $r['app']) ?></span><?php endforeach; ?></span>
        <a class="btn ghost" href="?p=client&id=<?= (int) $tid ?>#apps">Client</a></summary>
      <div class="lrows"><?php foreach ($rs as $r): $st = $stOf($r); [$sl, $sc] = $st === 'expiring' ? ['Expiring', 'amber'] : AmetecsLicences::STATES[$st === 'off' ? 'off' : $st]; ?>
        <div class="lr" data-lf="<?= ag_h(strtolower($tn . ' ' . ($plugins[$r['app']]['name'] ?? $r['app']) . ' ' . ($r['server_name'] ?? ''))) ?>">
          <div class="lapp"><?= $mark((string) $r['app']) ?><div><b><?= ag_h($plugins[$r['app']]['name'] ?? $r['app']) ?></b><small><?= ag_h(AmetecsLicences::PLANS[$r['plan']] ?? $r['plan']) ?><?= (float) ($r['price'] ?? 0) > 0 ? ' &middot; ' . ag_h($r['currency'] . ' ' . number_format((float) $r['price'])) : '' ?></small></div></div>
          <div><span class="pill <?= $sc ?>"><?= $r['status'] === 'cancelled' ? 'Cancelled' : ag_h($sl) ?></span></div>
          <div><?= $server($r) ?></div>
          <?= $seatCell($r) ?>
          <?= $validity($r) ?>
          <div class="lact"><button type="button" class="xr" title="Show details" aria-expanded="false"><i class="fas fa-chevron-down"></i></button><a class="btn ghost" href="?p=licence&id=<?= (int) $r['id'] ?>">Open</a><?= $quick($r) ?></div>
          <?= $detail($r) ?></div>
      <?php endforeach; ?></div>
    </details>
  <?php endforeach; ?></div>
  <?php else: ?>
  <div class="box"><table id="lic-t" class="sortable"><tr><th>Client</th><th>App</th><th>Status</th><th>Where</th><th>Seats</th><th>Valid till</th><th></th></tr>
  <?php foreach ($list as $r): $st = $stOf($r); [$sl, $sc] = $st === 'expiring' ? ['Expiring', 'amber'] : AmetecsLicences::STATES[$st]; $tn = (string) ($names[$r['tenant_id']] ?? '#' . $r['tenant_id']); ?>
    <tr data-lf="<?= ag_h(strtolower($tn . ' ' . ($plugins[$r['app']]['name'] ?? $r['app']) . ' ' . ($r['server_name'] ?? ''))) ?>">
    <td><a href="?p=client&id=<?= (int) $r['tenant_id'] ?>"><b><?= ag_h($tn) ?></b></a></td>
    <td><div class="lapp"><?= $mark((string) $r['app']) ?><div><b><?= ag_h($plugins[$r['app']]['name'] ?? $r['app']) ?></b><small><?= ag_h(AmetecsLicences::PLANS[$r['plan']] ?? $r['plan']) ?></small></div></div></td>
    <td><span class="pill <?= $sc ?>"><?= $r['status'] === 'cancelled' ? 'Cancelled' : ag_h($sl) ?></span></td>
    <td><?= $server($r) ?></td><td><?= $seatCell($r) ?></td><td><?= $validity($r) ?></td>
    <td class="right"><div class="lact"><button type="button" class="xr" title="Show details" aria-expanded="false"><i class="fas fa-chevron-down"></i></button><a class="btn ghost" href="?p=licence&id=<?= (int) $r['id'] ?>">Open</a><?= $quick($r) ?></div></td></tr>
    <tr class="ldrow" hidden><td colspan="7"><?= $detail($r) ?></td></tr>
  <?php endforeach; ?></table></div>
  <?php endif; ?>
  <dialog id="newlic" class="ldlg"><form method="post"><?= $csrf() ?><input type="hidden" name="do" value="licence-new">
    <header><h2><i class="fas fa-key"></i>New licence</h2><button type="button" class="x" onclick="this.closest('dialog').close()">&times;</button></header>
    <div class="grid2">
      <div class="full"><label>Client</label><select name="tenant" required><option value="">Choose a client…</option><?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>"><?= ag_h($t['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>App</label><select name="app" required><?php foreach ($plugins as $c => $p): ?><option value="<?= ag_h($c) ?>"><?= ag_h($p['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Deployment</label><select name="deployment"><?php foreach (AmetecsLicences::DEPLOYMENTS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>Plan</label><select name="plan"><?php foreach (AmetecsLicences::PLANS as $k => $v): ?><option value="<?= $k ?>"<?= $k === 'yearly' ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>Seats</label><input type="number" name="seats" min="1" value="25"></div>
      <div class="full"><label>Valid till <span class="muted">(empty = one plan period from today)</span></label><input type="date" name="valid_until"></div>
    </div>
    <footer><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn gold"><i class="fas fa-check"></i>Create licence</button></footer></form></dialog>
  <script>
  (function () {   // expand / collapse: one licence (chevron), one client (its header), or all; remembered on this browser
    var key = 'agLicClosed', closed = {};
    try { closed = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
    document.querySelectorAll('.lcl[data-cl]').forEach(function (c) {
      if (closed[c.dataset.cl]) c.open = false;
      c.addEventListener('toggle', function () { closed[c.dataset.cl] = !c.open; try { localStorage.setItem(key, JSON.stringify(closed)); } catch (e) {} });
    });
    function row(btn, show) {
      var r = btn.closest('.lr'), panel = r ? r.querySelector('.ldet') : btn.closest('tr').nextElementSibling;
      show = show === undefined ? btn.getAttribute('aria-expanded') !== 'true' : show;
      btn.setAttribute('aria-expanded', show ? 'true' : 'false');
      if (r) r.classList.toggle('open', show); else panel.hidden = !show;
    }
    document.addEventListener('click', function (e) {
      var b = e.target.closest('.xr'); if (b) { row(b); return; }
      var a = e.target.closest('[data-xall]'); if (!a) return;
      var on = a.dataset.xall === '1';
      document.querySelectorAll('.lcl').forEach(function (c) { c.open = on; });
      document.querySelectorAll('.xr').forEach(function (x) { row(x, on); });
    });
  })();
  (function () {   // live search across the client cards / list rows
    var inp = document.querySelector('[data-lfind]'); if (!inp) return;
    inp.addEventListener('input', function () { var t = inp.value.toLowerCase().trim();
      document.querySelectorAll('.lr[data-lf], tr[data-lf]').forEach(function (r) { r.hidden = t !== '' && r.dataset.lf.indexOf(t) < 0; });
      document.querySelectorAll('.lcl').forEach(function (c) { c.hidden = t !== '' && !c.querySelector('.lr:not([hidden])'); }); });
  })();
  </script>
  <?php endif; ?>

<?php elseif ($page === 'licence' && ($lc = AmetecsLicences::byId((string) ($_GET['id'] ?? ''))) !== null):
    $lt = $tenant((string) $lc['tenant_id']); $lp = $plugins[$lc['app']] ?? ['name' => $lc['app'], 'unit' => 'users'];
    $lst = AmetecsLicences::stateOf($lc); [$sl, $sc] = AmetecsLicences::STATES[$lst];
    $rk = $_SESSION['reveal_key'] ?? null; unset($_SESSION['reveal_key']);
    $lid = (int) $lc['id'];
    $btn = static fn (string $to, string $label, string $cls, string $ask = '') => '<form method="post" class="inline"' . ($ask !== '' ? ' onsubmit="return confirm(\'' . ag_h($ask) . '\')"' : '') . '>' . $csrf()
        . '<input type="hidden" name="do" value="licence-status"><input type="hidden" name="id" value="' . $lid . '"><input type="hidden" name="to" value="' . $to . '"><button class="btn ' . $cls . '">' . $label . '</button></form>'; ?>
  <div class="phero"><div class="row" style="margin-bottom:6px"><a href="?p=licences" class="muted">← Licences</a><?php if ($lt !== null): ?><span class="muted">·</span><a href="?p=client&id=<?= (int) $lt['id'] ?>" class="muted"><?= ag_h($lt['name']) ?></a><?php endif; ?></div>
  <div class="row"><h1><?= ag_h($lp['name']) ?> · <?= ag_h($lt['name'] ?? '#' . $lc['tenant_id']) ?></h1><span class="pill <?= $sc ?>"><?= $lc['status'] === 'cancelled' ? 'Cancelled' : $sl ?></span>
    <span class="pill grey"><?= $lc['deployment'] === 'onprem' ? '<i class="fas fa-server"></i> On-premise' : '<i class="fas fa-cloud"></i> Cloud' ?></span></div>
  <p class="sub"><?= ag_h(AmetecsLicences::PLANS[$lc['plan']] ?? '') ?> plan · <?= (int) $lc['seats'] ?> <?= ag_h($lp['unit']) ?> · valid till <?= $d($lc['valid_until'] ?? null) ?><?= ($lc['price'] ?? null) !== null ? ' · ' . ag_h($lc['currency']) . ' ' . number_format((float) $lc['price'], 2) : '' ?><?= $lc['deployment'] === 'cloud' ? (AmetecsLicences::installed($lc) ? ' · <i class="fas fa-circle-check" style="color:#15803d"></i> installed by the client' . (!empty($lc['activated_at']) ? ' ' . ag_h(date('d M Y', (int) strtotime((string) $lc['activated_at']))) : '') : ' · <i class="fas fa-download" style="color:#b45309"></i> waiting for the client to download') : '' ?></p></div>

  <div class="pg2"><div class="pgm"><form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="licence-save"><input type="hidden" name="id" value="<?= $lid ?>">
    <div class="hd"><h2><i class="fas fa-file-signature"></i>Licence terms</h2></div><div class="bd grid2">
    <div><label>Deployment</label><select name="deployment"><?php foreach (AmetecsLicences::DEPLOYMENTS as $k => $v): ?><option value="<?= $k ?>"<?= $lc['deployment'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div><label>Plan</label><select name="plan"><?php foreach (AmetecsLicences::PLANS as $k => $v): ?><option value="<?= $k ?>"<?= $lc['plan'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div><label>Seats (<?= ag_h($lp['unit']) ?>)</label><input type="number" name="seats" min="1" value="<?= (int) $lc['seats'] ?>"></div>
    <div><label>Starts on</label><input type="date" name="starts_on" value="<?= ag_h((string) ($lc['starts_on'] ?? '')) ?>"></div>
    <div><label>Valid till <span class="muted">(empty only for perpetual)</span></label><input type="date" name="valid_until" value="<?= ag_h((string) ($lc['valid_until'] ?? '')) ?>"></div>
    <div><label>Price per period</label><div class="row"><select name="currency" style="width:90px"><?php foreach (['INR', 'USD', 'AED', 'SAR', 'EUR', 'GBP'] as $cu): ?><option<?= $lc['currency'] === $cu ? ' selected' : '' ?>><?= $cu ?></option><?php endforeach; ?></select><input name="price" inputmode="decimal" value="<?= ag_h((string) ($lc['price'] ?? '')) ?>" placeholder="0.00" style="flex:1"></div></div>
    <div><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="checkbox" name="auto_renew"<?= !empty($lc['auto_renew']) ? ' checked' : '' ?>> Renews automatically (billing arrives with the payments phase)</label></div>
    <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= ag_h((string) ($lc['notes'] ?? '')) ?></textarea></div>
    </div><div class="ft"><button class="btn navy">Save terms</button></div></form>

  <?php $lpl = AmetecsPlugins::ready() ? AmetecsPlugins::catalogue((string) $lc['app']) : []; if ($lpl !== []): $ltid = (string) $lc['tenant_id'];
      $pbtn = static fn (string $code, string $act, string $label, string $cls = 'ghost') => '<form method="post" class="inline">' . $csrf() . '<input type="hidden" name="do" value="licence-plugin"><input type="hidden" name="id" value="' . $lid . '"><input type="hidden" name="plugin" value="' . ag_h($code) . '"><input type="hidden" name="act" value="' . $act . '"><button class="btn ' . $cls . '" style="padding:4px 10px;font-size:12.5px">' . $label . '</button></form>'; ?>
  <div class="box" id="plugins"><div class="hd"><h2><i class="fas fa-puzzle-piece"></i>Plugins for this client</h2><span class="muted">Give free = no payment needed &middot; Block = switched off for this client whatever they paid &middot; the price is set on the plugin's release</span></div>
    <table><tr><th>Plugin</th><th>Latest</th><th>Price</th><th>For this client</th><th>Client switch</th><th>Version on their server</th><th class="right"></th></tr>
    <?php foreach ($lpl as $pc => $pp): $prow = AmetecsPlugins::row($ltid, (string) $lc['app'], $pc); $pst = AmetecsPlugins::state($ltid, $pp['latest']); [$psl, $psc] = AmetecsPlugins::STATES[$pst]; $dlOn = (bool) ($prow['download'] ?? true); ?>
      <tr><td><b><?= ag_h($pp['title']) ?></b><br><span class="muted" style="font-size:12px"><?= ag_h($pc) ?></span></td><td><a href="?p=release&id=<?= (int) $pp['latest']['id'] ?>"><?= ag_h((string) $pp['latest']['version']) ?></a></td>
        <td><?= (float) ($pp['latest']['price'] ?? 0) > 0 ? ag_h($pp['latest']['currency'] . ' ' . number_format((float) $pp['latest']['price'], 2)) : 'Free' ?></td>
        <td><span class="pill <?= $psc ?>"><?= ag_h($psl) ?></span><?= !$dlOn ? ' <span class="pill grey">no downloads</span>' : '' ?></td>
        <td><?= AmetecsPlugins::authorised($pst) ? (($prow['enabled'] ?? 1) ? 'On' : 'Off') : '<span class="muted">-</span>' ?></td>
        <td><?= ag_h((string) (($prow['installed_version'] ?? '') ?: (($prow['downloaded_version'] ?? '') !== '' && $prow['downloaded_version'] !== null ? 'downloaded ' . $prow['downloaded_version'] : '-'))) ?></td>
        <td class="right" style="white-space:nowrap"><?= (float) ($pp['latest']['price'] ?? 0) > 0 ? (!empty($prow['granted']) ? $pbtn($pc, 'ungrant', 'Take back free') : $pbtn($pc, 'grant', 'Give free', 'gold')) : '' ?>
          <?= !empty($prow['blocked']) ? $pbtn($pc, 'unblock', 'Unblock', 'navy') : $pbtn($pc, 'block', 'Block', 'danger') ?>
          <?= $lc['deployment'] === 'onprem' ? ($dlOn ? $pbtn($pc, 'dl-off', 'Stop downloads') : $pbtn($pc, 'dl-on', 'Allow downloads')) : '' ?></td></tr>
    <?php endforeach; ?></table></div>
  <?php endif; ?>

  <div class="box"><div class="hd"><h2><i class="fas fa-clock-rotate-left"></i>Licence history</h2></div><table><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr>
  <?php foreach (AmetecsLicences::events((string) $lid) as $e): ?><tr><td style="white-space:nowrap"><?= ag_h($e['created_at']) ?></td><td><?= ag_h($e['by_who']) ?></td><td><?= ag_h($e['action']) ?></td><td class="muted" style="font-size:12.5px"><?= ag_h($e['detail']) ?><?php if (preg_match('/paid |bought online/i', (string) $e['action'])): ?> <form method="post" class="inline" onsubmit="return confirm('Put this licence back to how it was before this payment?')"><?= $csrf() ?><input type="hidden" name="do" value="licence-undo"><input type="hidden" name="id" value="<?= $lid ?>"><input type="hidden" name="event" value="<?= (int) $e['id'] ?>"><button class="btn ghost" style="padding:2px 8px;font-size:12px"><i class="fas fa-rotate-left"></i> Undo</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>

</div><aside class="pgs"><div class="box"><div class="hd"><h2><i class="fas fa-bolt"></i>Actions</h2></div><div class="bd row">
    <?= $lc['status'] !== 'active' ? $btn('active', '<i class="fas fa-play"></i> Activate', 'navy') : '' ?>
    <?= $lc['plan'] !== 'perpetual' ? $btn('renew', '<i class="fas fa-rotate"></i> Renew ' . ['monthly' => '1 month', 'yearly' => '1 year', 'trial' => '14 days'][$lc['plan']], 'gold', 'Extend this licence by one period?') : '' ?>
    <?= $lc['status'] === 'active' ? $btn('suspended', '<i class="fas fa-pause"></i> Suspend', 'danger', 'Suspend this licence? The app stops opening for this client; nothing is deleted.') : '' ?>
    <?= $lc['deployment'] === 'cloud' && AmetecsLicences::installed($lc) ? $btn('uninstall', '<i class="fas fa-box-archive"></i> Uninstall', 'ghost', 'Uninstall this app for the client? They see Download again; their data is kept.') : '' ?>
    <?= $lc['status'] !== 'cancelled' ? $btn('cancelled', '<i class="fas fa-ban"></i> Cancel', 'danger', 'Cancel this licence? The client loses the app; history and data are kept.') : '' ?>
    <?php if ((float) ($lc['price'] ?? 0) > 0 && $lc['plan'] !== 'perpetual' && AmetecsBilling::ready()): ?><form method="post" class="inline"><?= $csrf() ?><input type="hidden" name="do" value="invoice-new"><input type="hidden" name="licence" value="<?= $lid ?>"><button class="btn ghost"><i class="fas fa-file-invoice"></i> Raise renewal invoice</button></form><?php endif; ?>
    <span class="muted"><b>Suspend</b> = temporary stop (e.g. unpaid). <b>Cancel</b> = the client no longer takes this app. Neither deletes anything; Activate undoes both.</span></div></div>

  <?php $rc = $_SESSION['reveal_code'] ?? null; unset($_SESSION['reveal_code']); $codesL = AmetecsLicences::codesOf((string) $lc['tenant_id'], (string) $lc['app']); $nu = AmetecsLicences::nextUntil($lc) ?? date('Y-m-d', strtotime('+1 year')); ?>
  <div class="box" id="codes"><div class="hd"><h2><i class="fas fa-ticket"></i>Licence codes (LC)</h2></div><div class="bd">
    <?php if (is_array($rc) && (string) $rc['id'] === (string) $lid): ?><div class="reveal"><b><i class="fas fa-key"></i> Shown once — copy it now.</b> Licence code: <code><?= ag_h($rc['code']) ?></code></div><?php endif; ?>
    <form method="post" class="row"><?= $csrf() ?><input type="hidden" name="do" value="licence-code"><input type="hidden" name="id" value="<?= $lid ?>">
      <label>Plan <select name="plan"><?php foreach (AmetecsLicences::PLANS as $pk => $pn): ?><option value="<?= $pk ?>"<?= $pk === $lc['plan'] ? ' selected' : '' ?>><?= $pn ?></option><?php endforeach; ?></select></label>
      <label>Seats <input type="number" name="seats" min="1" value="<?= max(1, (int) $lc['seats']) ?>" style="width:90px"></label>
      <label>Valid till <input type="date" name="valid_until" value="<?= ag_h($nu) ?>"></label>
      <label>Code works for <input type="number" name="days" min="1" max="365" value="30" style="width:70px"> days</label>
      <button class="btn gold"><i class="fas fa-ticket"></i>Issue licence code</button></form>
    <p class="muted" style="font-size:12.5px;margin:10px 0">The client enters the code on Licences &amp; plans; these terms apply at once and the app opens again. One use only. Suspending or cancelling the licence withdraws unused codes.</p>
    <?php if ($codesL !== []): ?><table class="tbl"><tr><th>Code</th><th>Terms</th><th>Issued</th><th>State</th><th></th></tr><?php foreach (array_slice($codesL, 0, 12) as $cc): ?>
      <tr><td><code>LC-…<?= ag_h($cc['tail']) ?></code></td><td><?= ag_h(($cc['terms']['plan'] ?? '') . ' · ' . ($cc['terms']['seats'] ?? '') . ' seats · till ' . ($cc['terms']['valid_until'] ?? '')) ?></td>
        <td><?= ag_h(substr((string) $cc['issued_at'], 0, 16)) ?> <span class="muted"><?= ag_h((string) $cc['issued_by']) ?></span></td>
        <td><span class="pill <?= ['waiting' => 'amber', 'used' => 'green', 'revoked' => 'grey', 'lapsed' => 'grey'][$cc['state']] ?>"><?= ag_h(ucfirst($cc['state'])) ?></span><?= $cc['state'] === 'used' ? ' <span class="muted">' . ag_h(substr((string) $cc['used_at'], 0, 16) . ' ' . $cc['used_by']) . '</span>' : ($cc['state'] === 'waiting' ? ' <span class="muted">use by ' . ag_h($cc['use_by']) . '</span>' : '') ?></td>
        <td><?php if ($cc['state'] === 'waiting'): ?><form method="post" class="inline" onsubmit="return confirm('Withdraw this code?')"><?= $csrf() ?><input type="hidden" name="do" value="licence-code-revoke"><input type="hidden" name="id" value="<?= $lid ?>"><input type="hidden" name="tail" value="<?= ag_h($cc['tail']) ?>"><button class="btn ghost">Withdraw</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div></div>

  <?php if ($lc['deployment'] === 'onprem'): $haveKey = trim((string) ($lc['key_hash'] ?? '')) !== ''; ?>
  <div class="box"><div class="hd"><h2><i class="fas fa-server"></i>On-premise installation</h2></div><div class="bd">
    <?php if (is_array($rk) && (string) $rk['id'] === (string) $lid): ?><div class="reveal"><b><i class="fas fa-key"></i> Shown once — copy it now.</b> Licence key: <code><?= ag_h($rk['key']) ?></code></div><?php endif; ?>
    <div class="grid2">
      <div><label>Licence key</label><div><?= $haveKey ? '<span class="pill green">Issued</span> <span class="muted">stored encrypted; issue a new one if it was lost</span>' : '<span class="pill grey">Not issued yet</span>' ?></div></div>
      <div><label>Bound server</label><div><?= ($lc['fingerprint'] ?? '') !== '' ? '<b>' . ag_h((string) ($lc['server_name'] ?: 'unnamed server')) . '</b> <span class="muted">' . ag_h((string) ($lc['server_os'] ?? '')) . ' · id ' . ag_h(substr((string) $lc['fingerprint'], 0, 16)) . '…</span>' : '<span class="muted">Not activated yet</span>' ?></div></div>
      <div><label>Activated</label><div><?= ag_h((string) ($lc['activated_at'] ?? '—')) ?></div></div>
      <div><label>Last check-in · version</label><div><?= ag_h((string) ($lc['last_seen_at'] ?? '—')) ?> · <?= ag_h((string) ($lc['app_version'] ?: '—')) ?></div></div>
    </div>
    <div class="row" style="margin-top:14px">
      <form method="post" class="inline" onsubmit="return <?= $haveKey ? "confirm('Issue a new key? The current key stops working.')" : 'true' ?>"><?= $csrf() ?><input type="hidden" name="do" value="licence-key"><input type="hidden" name="id" value="<?= $lid ?>"><button class="btn gold"><i class="fas fa-key"></i><?= $haveKey ? 'Issue a new key' : 'Issue licence key' ?></button></form>
      <?= ($lc['fingerprint'] ?? '') !== '' ? $btn('unbind', '<i class="fas fa-link-slash"></i> Release from server', 'ghost', 'Release this licence so it can be activated on a different server?') : '' ?>
    </div>
    <form method="post" class="row" style="margin-top:14px"><?= $csrf() ?><input type="hidden" name="do" value="licence-lic"><input type="hidden" name="id" value="<?= $lid ?>">
      <span><b>Offline licence file (.lic)</b> <span class="muted">for servers without internet; locked to one server id</span></span>
      <input name="fingerprint" value="<?= ag_h((string) ($lc['fingerprint'] ?? '')) ?>" placeholder="server id from the app's activation screen" style="flex:1;min-width:260px">
      <button class="btn navy"<?= $haveKey && AmetecsLicences::canSign() ? '' : ' disabled title="' . ($haveKey ? 'The signing key lives only on the live licence server' : 'Issue a licence key first') . '"' ?>><i class="fas fa-file-arrow-down"></i>Download .lic</button></form>
    <p class="muted" style="font-size:12.5px;margin:12px 0 0">Online activation: the installed app sends this key to <code><?= ag_h(AmetecsMail::base()) ?>licence-api.php</code>; the first server to activate is bound, others are refused until you release it.</p>
  </div></div>
  <?php endif; ?>

  </aside></div>

<?php elseif ($page === 'releases'):
    $fa = (string) ($_GET['app'] ?? '');
    $rels = AmetecsReleases::all($fa);
    $inc = AmetecsReleases::ready() ? AmetecsReleases::incoming() : []; ?>
  <?php
    // PHASE 7 (owner, 18 Nov 2026: "redesign this page also"): figures, one card per app (cloud server version against
    // the newest release), status tabs, release cards; uploading moves into a pop-up.
    $rst = in_array($_GET['st'] ?? '', array_keys(AmetecsReleases::STATUSES), true) ? (string) $_GET['st'] : '';
    $allRels = AmetecsReleases::ready() ? AmetecsReleases::all('') : [];
    $byState = array_count_values(array_map(static fn ($r) => AmetecsReleases::stateOf($r), $allRels));
    $shown = array_values(array_filter($rels, static fn ($r) => $rst === '' || AmetecsReleases::stateOf($r) === $rst));
    $qsr = static fn (array $o) => '?' . http_build_query(array_filter($o + ['p' => 'releases', 'app' => $fa, 'st' => $rst]));
    $mk = static fn (string $c, array $p) => '<span class="am" style="--c:' . ag_h((string) ($p['color'] ?? '#64748B')) . '">' . ag_h(mb_substr((string) $p['name'], 0, 1)) . '</span>'; ?>
  <div class="lph"><div><h1>Updates &amp; releases</h1><p class="sub">Every release, patch and plugin for every app. Upload a package, choose who gets it, publish now or on a date. Cloud clients run Ametecs' server: install a release on it (with automatic backup and roll-back).</p></div>
    <?php if (AmetecsReleases::ready()): ?><div class="row"><?php if ($inc !== []): ?><button type="button" class="btn ghost" onclick="document.getElementById('relinc').showModal()"><i class="fas fa-folder-open"></i>From incoming folder <em class="cnt2"><?= count($inc) ?></em></button><?php endif; ?>
    <button type="button" class="btn gold" onclick="document.getElementById('relup').showModal()"><i class="fas fa-upload"></i>Upload a package</button></div><?php endif; ?></div>
  <?php if (!AmetecsReleases::ready()): ?><div class="msg bad"><i class="fas fa-circle-exclamation"></i><span>The update tables are not on this PC yet. They arrive with the next sync.</span></div><?php else: ?>
  <div class="lsum five">
    <div class="ls"><span class="li"><i class="fas fa-box"></i></span><div><b><?= count($allRels) ?></b><small>Releases</small></div></div>
    <div class="ls"><span class="li t-green"><i class="fas fa-circle-check"></i></span><div><b><?= (int) ($byState['published'] ?? 0) ?></b><small>Published</small></div></div>
    <div class="ls"><span class="li"><i class="fas fa-clock"></i></span><div><b><?= (int) ($byState['scheduled'] ?? 0) + (int) ($byState['draft'] ?? 0) ?></b><small>Scheduled &amp; drafts</small></div></div>
    <div class="ls"><span class="li"><i class="fas fa-download"></i></span><div><b><?= AmetecsReleases::countEvents('installed') ?></b><small>Installs, 30 days</small></div></div>
    <div class="ls"><span class="li"><i class="fas fa-triangle-exclamation"></i></span><div><b><?= AmetecsReleases::countEvents('failed') ?></b><small>Failures, 30 days</small></div></div>
  </div>

  <h2 class="sech"><i class="fas fa-server"></i>Ametecs cloud server <small>the version each app runs for cloud clients</small></h2>
  <div class="vgrid"><?php foreach ($plugins as $c => $p):
        $pv = AmetecsReleases::platformVersion($c);
        $newest = null; foreach (AmetecsReleases::all($c) as $r) { if ($r['kind'] !== 'plugin' && AmetecsReleases::stateOf($r) === 'published' && ($newest === null || version_compare($r['version'], $newest['version'], '>'))) { $newest = $r; } }
        $behind = 0; foreach (AmetecsLicences::rows() as $ltid => $la) { $l = $la[$c] ?? null; if ($l !== null && $l['deployment'] === 'onprem' && AmetecsLicences::stateOf($l) === 'on' && AmetecsReleases::offerFor((string) $ltid, $c, (string) ($l['app_version'] ?? ''))['updates'] !== []) { $behind++; } }
        $hasBackup = ($root = AmetecsReleases::pluginRoot($c)) !== null && is_file($root . '/storage/updates/history.json');
        $state = $pv === '' ? ['off', 'Not on this server'] : ($newest !== null && version_compare($newest['version'], $pv, '>') ? ['amber', 'Update available'] : ['green', 'Up to date']); ?>
    <div class="vc" style="--c:<?= ag_h((string) $p['color']) ?>"><div class="vh"><?= $mk($c, $p) ?><b><?= ag_h($p['name']) ?></b><span class="vs <?= $state[0] ?>"><?= $state[1] ?></span></div>
      <div class="vv"><div><small>Running</small><b><?= $pv !== '' ? ag_h($pv) : '—' ?></b></div><i class="fas fa-arrow-right"></i><div><small>Newest</small><b><?= $newest !== null ? '<a href="?p=release&id=' . (int) $newest['id'] . '">' . ag_h($newest['version']) . '</a>' : ($pv !== '' ? ag_h($pv) : '—') ?></b></div></div>
      <div class="vf"><span><i class="fas fa-server"></i><?= $behind ?> on-premise behind</span><?php if ($hasBackup): ?><form method="post" class="inline" onsubmit="return confirm('Roll back the last install of <?= ag_h($p['name']) ?> on the cloud server?')"><?= $csrf() ?><input type="hidden" name="do" value="release-rollback"><input type="hidden" name="app" value="<?= ag_h($c) ?>"><button class="lnk"><i class="fas fa-rotate-left"></i>Roll back</button></form><?php endif; ?></div></div>
  <?php endforeach; ?></div>

  <h2 class="sech"><i class="fas fa-boxes-stacked"></i>Releases</h2>
  <div class="ltabs"><a class="<?= $rst === '' ? 'on' : '' ?>" href="<?= ag_h($qsr(['st' => ''])) ?>"><i class="fas fa-layer-group"></i>All<em><?= count($allRels) ?></em></a>
    <?php foreach (AmetecsReleases::STATUSES as $k => [$lab]): if (empty($byState[$k]) && $rst !== $k) { continue; } ?><a class="<?= $rst === $k ? 'on' : '' ?>" href="<?= ag_h($qsr(['st' => $k])) ?>"><?= ag_h($lab) ?><em><?= (int) ($byState[$k] ?? 0) ?></em></a><?php endforeach; ?></div>
  <form class="toolbar"><input type="hidden" name="p" value="releases"><?php if ($rst !== ''): ?><input type="hidden" name="st" value="<?= ag_h($rst) ?>"><?php endif; ?><span class="si"><i class="fas fa-magnifying-glass"></i><input data-live="#rel-t" placeholder="Search version, title…" autocomplete="off"></span>
    <select name="app" onchange="this.form.submit()"><option value="">All apps</option><?php foreach ($plugins as $c => $p): ?><option value="<?= ag_h($c) ?>"<?= $fa === $c ? ' selected' : '' ?>><?= ag_h($p['name']) ?></option><?php endforeach; ?></select></form>
  <div class="box"><table id="rel-t" class="sortable"><tr><th>Release</th><th>Type</th><th>Status</th><th>Goes to</th><th>Price</th><th class="ac">Downloads</th><th class="ac">Installs</th><th class="ac">Failures</th><th></th></tr>
  <?php foreach ($shown as $r): [$sl, $sc] = AmetecsReleases::STATUSES[AmetecsReleases::stateOf($r)]; $rp = $plugins[$r['app']] ?? ['name' => $r['app'], 'color' => '#64748B']; $fl = AmetecsReleases::countEvents('failed', 3650, (string) $r['id']); ?><tr>
    <td><div class="rn"><?= $mk((string) $r['app'], $rp) ?><div><a href="?p=release&id=<?= (int) $r['id'] ?>"><b><?= ag_h($rp['name']) ?> <?= ag_h($r['version']) ?></b></a><?= $r['mandatory'] ? ' <span class="pill red">Mandatory</span>' : '' ?><?= trim((string) $r['signature']) === '' ? ' <span class="pill grey" title="No signing key on this server">Unsigned</span>' : '' ?><small><?= ag_h((string) ($r['title'] ?: '')) ?></small></div></div></td>
    <td><span class="kt k-<?= ag_h((string) $r['kind']) ?>"><?= ag_h(AmetecsReleases::KINDS[$r['kind']] ?? $r['kind']) ?></span></td>
    <td><span class="pill <?= $sc ?>"><?= $sl ?></span><?= AmetecsReleases::stateOf($r) === 'scheduled' ? '<div class="muted" style="font-size:12px">' . ag_h(date('d M Y H:i', (int) strtotime((string) $r['publish_at']))) . '</div>' : '' ?></td>
    <td><?= $r['audience'] === 'all' ? '<i class="fas fa-users muted"></i> Everyone with the app' : '<i class="fas fa-user-check muted"></i> ' . count(AmetecsReleases::access((string) $r['id'])) . ' selected' ?></td>
    <td><?= ($r['price'] ?? null) !== null ? '<b>' . ag_h($r['currency']) . ' ' . number_format((float) $r['price'], 2) . '</b>' : '<span class="pill green">Free</span>' ?></td>
    <td class="ac"><?= AmetecsReleases::countEvents('downloaded', 3650, (string) $r['id']) ?></td><td class="ac"><?= AmetecsReleases::countEvents('installed', 3650, (string) $r['id']) ?></td><td class="ac"><?= $fl > 0 ? '<b class="tred">' . $fl . '</b>' : '0' ?></td>
    <td class="right"><a class="btn ghost" href="?p=release&id=<?= (int) $r['id'] ?>">Open <i class="fas fa-arrow-right"></i></a></td></tr>
  <?php endforeach; ?><?php if ($shown === []): ?><tr><td colspan="9"><div class="empty"><i class="fas fa-box-open"></i>No releases here yet. <a href="#" onclick="document.getElementById('relup').showModal();return false">Upload a package</a>.</div></td></tr><?php endif; ?></table></div>

  <dialog id="relup" class="ldlg wide"><form method="post" enctype="multipart/form-data"><?= $csrf() ?><input type="hidden" name="do" value="release-upload">
    <header><h2><i class="fas fa-upload"></i>Upload a package</h2><button type="button" class="x" onclick="this.closest('dialog').close()">&times;</button></header><p class="muted dsub2">a .zip from the app's release builder, with release.json at the top &middot; this server accepts up to <?= ag_h((string) ini_get('upload_max_filesize')) ?></p>
    <div class="grid2">
      <div><label>App <span class="req">*</span></label><select name="app" required><?php foreach ($plugins as $c => $p): ?><option value="<?= ag_h($c) ?>"<?= $fa === $c ? ' selected' : '' ?>><?= ag_h($p['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Type <span class="req">*</span></label><select name="kind"><?php foreach (AmetecsReleases::KINDS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>Package (.zip) <span class="req">*</span></label><input type="file" name="package" accept=".zip" required></div>
      <div><label>Version <span class="muted">(empty = from release.json)</span></label><input name="version" placeholder="2.4.0"></div>
      <div><label>Title</label><input name="title" placeholder="e.g. GST return filing"></div>
      <div><label>Plugin code <span class="muted">(plugins only: the same code for every version, e.g. gst-returns; empty = from the title)</span></label><input name="plugin" placeholder="gst-returns" pattern="[A-Za-z0-9 _-]*"></div>
      <div><label>Requires version <span class="muted">(installed version must be at least)</span></label><input name="requires_version" placeholder="2.3.0"></div>
      <div class="full"><label>Release notes <span class="muted">(shown to clients; empty = from release.json)</span></label><textarea name="notes" rows="3"></textarea></div>
      <div><label>Upgrade price <span class="muted">(empty = free)</span></label><input name="price" inputmode="decimal" placeholder="0.00"></div>
      <div><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink);margin-top:26px"><input type="checkbox" name="mandatory"> Mandatory update</label></div>
    </div><footer><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn gold"><i class="fas fa-upload"></i>Upload &amp; check</button></footer></form></dialog>
  <?php if ($inc !== []): ?><dialog id="relinc" class="ldlg">
  <form method="post"><?= $csrf() ?><input type="hidden" name="do" value="release-import">
    <header><h2><i class="fas fa-folder-open"></i>Add from the incoming folder</h2><button type="button" class="x" onclick="this.closest('dialog').close()">&times;</button></header><p class="muted dsub2">for packages bigger than the upload limit: copy them into core\data\releases\incoming</span></div>
    <div class="grid2 incg"><select name="incoming"><?php foreach ($inc as $z): ?><option><?= ag_h($z) ?></option><?php endforeach; ?></select>
      <select name="app"><?php foreach ($plugins as $c => $p): ?><option value="<?= ag_h($c) ?>"><?= ag_h($p['name']) ?></option><?php endforeach; ?></select>
      <select name="kind"><?php foreach (AmetecsReleases::KINDS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
      </div><footer><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn gold">Check &amp; add</button></footer></form></dialog>
  <?php endif; ?>

  <?php endif; ?>

<?php elseif ($page === 'release' && ($rl = AmetecsReleases::byId((string) ($_GET['id'] ?? ''))) !== null):
    $rp = $plugins[$rl['app']] ?? ['name' => $rl['app']]; $rid = (int) $rl['id']; $rst = AmetecsReleases::stateOf($rl); [$sl, $sc] = AmetecsReleases::STATUSES[$rst];
    $acc = AmetecsReleases::access((string) $rid); $pv = AmetecsReleases::platformVersion((string) $rl['app']);
    $ilog = $_SESSION['install_log'] ?? null; unset($_SESSION['install_log']);
    $holders = array_filter($tenants, static fn ($t) => AmetecsLicences::row((string) $t['id'], (string) $rl['app']) !== null && AmetecsLicences::row((string) $t['id'], (string) $rl['app'])['status'] !== 'cancelled');
    $sbtn = static fn (string $to, string $label, string $cls, string $ask = '') => '<form method="post" class="inline"' . ($ask !== '' ? ' onsubmit="return confirm(\'' . ag_h($ask) . '\')"' : '') . '>' . $csrf()
        . '<input type="hidden" name="do" value="release-status"><input type="hidden" name="id" value="' . $rid . '"><input type="hidden" name="to" value="' . $to . '"><button class="btn ' . $cls . '">' . $label . '</button></form>'; ?>
  <div class="phero"><div class="row" style="margin-bottom:6px"><a href="?p=releases" class="muted">← Updates &amp; releases</a></div>
  <div class="row"><h1><?= ag_h($rp['name']) ?> <?= ag_h($rl['version']) ?></h1><span class="pill <?= $sc ?>"><?= $sl ?></span><span class="pill grey"><?= ag_h(AmetecsReleases::KINDS[$rl['kind']]) ?></span><?= $rl['mandatory'] ? '<span class="pill red">Mandatory</span>' : '' ?></div>
  <p class="sub"><?= ag_h((string) ($rl['title'] ?? '')) ?> · <?= (int) $rl['size'] >= 1048576 ? round((int) $rl['size'] / 1048576, 1) . ' MB' : max(1, (int) round((int) $rl['size'] / 1024)) . ' KB' ?> · SHA-256 <code><?= ag_h(substr((string) $rl['sha256'], 0, 20)) ?>…</code> · <?= trim((string) $rl['signature']) !== '' ? '<span class="pill green"><i class="fas fa-shield-halved"></i> Signed by Ametecs</span>' : '<span class="pill grey">Unsigned (no signing key on this server) - test only</span>' ?></p></div>

  <div class="pg2"><div class="pgm"><form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="release-save"><input type="hidden" name="id" value="<?= $rid ?>">
    <div class="hd"><h2><i class="fas fa-pen-to-square"></i>Details</h2></div><div class="bd grid2">
    <div><label>Title</label><input name="title" value="<?= ag_h((string) ($rl['title'] ?? '')) ?>"></div>
    <div><label>Requires version (at least)</label><input name="requires_version" value="<?= ag_h((string) ($rl['requires_version'] ?? '')) ?>" placeholder="any"></div>
    <div class="full"><label>Release notes (clients read these)</label><textarea name="notes" rows="4"><?= ag_h((string) ($rl['notes'] ?? '')) ?></textarea></div>
    <div><label>Minimum PHP</label><input name="min_php" value="<?= ag_h((string) ($rl['min_php'] ?? '')) ?>" placeholder="8.2"></div>
    <div><label>Upgrade price per client</label><div class="row"><select name="currency" style="width:90px"><?php foreach (['INR', 'USD', 'AED', 'SAR', 'EUR', 'GBP'] as $cu): ?><option<?= $rl['currency'] === $cu ? ' selected' : '' ?>><?= $cu ?></option><?php endforeach; ?></select><input name="price" value="<?= ag_h((string) ($rl['price'] ?? '')) ?>" placeholder="free" style="flex:1"></div></div>
    <div><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="checkbox" name="mandatory"<?= $rl['mandatory'] ? ' checked' : '' ?>> Mandatory update</label></div>
    <div><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="checkbox" name="downloadable"<?= $rl['downloadable'] ? ' checked' : '' ?>> Clients may download the package from their panel</label></div>
    </div><div class="ft"><button class="btn navy">Save details</button></div></form>

  <form method="post" class="box" id="who"><?= $csrf() ?><input type="hidden" name="do" value="release-audience"><input type="hidden" name="id" value="<?= $rid ?>">
    <div class="hd"><h2><i class="fas fa-users"></i>Who gets it</h2><span class="muted">only clients holding <?= ag_h($rp['name']) ?> are listed</span></div>
    <div class="bd"><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="radio" name="audience" value="all"<?= $rl['audience'] === 'all' ? ' checked' : '' ?>> Every client with <?= ag_h($rp['name']) ?></label>
      &nbsp; <label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="radio" name="audience" value="selected"<?= $rl['audience'] === 'selected' ? ' checked' : '' ?>> Only the clients ticked below</label>
      <p class="muted" style="font-size:12.5px;margin:8px 0 0">"Free" lets that client take a paid upgrade without paying (works with either choice).</p></div>
    <table><tr><th>Client</th><th>Deployment</th><th>Running</th><th>Gets it</th><th>Free</th></tr>
    <?php foreach ($holders as $t): $tl = AmetecsLicences::row((string) $t['id'], (string) $rl['app']); $tid = (string) $t['id']; ?><tr>
      <td><?= ag_h($t['name']) ?></td><td><?= $tl['deployment'] === 'onprem' ? 'On-premise' : 'Cloud' ?></td><td><?= ag_h($tl['deployment'] === 'onprem' ? ((string) ($tl['app_version'] ?: '—')) : ($pv ?: '—')) ?></td>
      <td><input type="checkbox" name="t[<?= (int) $tid ?>][on]"<?= array_key_exists($tid, $acc) ? ' checked' : '' ?>></td><td><input type="checkbox" name="t[<?= (int) $tid ?>][free]"<?= !empty($acc[$tid]) ? ' checked' : '' ?>></td></tr>
    <?php endforeach; ?><?php if ($holders === []): ?><tr><td colspan="5" class="muted">No client holds <?= ag_h($rp['name']) ?> yet.</td></tr><?php endif; ?></table>
    <div class="ft"><button class="btn navy">Save who gets it</button></div></form>

  <div class="box"><div class="hd"><h2><i class="fas fa-clock-rotate-left"></i>Downloads, installs &amp; history</h2></div><table><tr><th>When</th><th>Where</th><th>What</th><th>From → to</th><th>Who</th><th>Detail</th></tr>
  <?php $tn = array_column($tenants, 'name', 'id'); foreach (AmetecsReleases::events(['release_id' => $rid]) as $e): ?><tr><td style="white-space:nowrap"><?= ag_h($e['created_at']) ?></td><td><?= (int) $e['tenant_id'] === 0 ? 'Ametecs' : ag_h($tn[$e['tenant_id']] ?? '#' . $e['tenant_id']) ?></td>
    <td><?= ag_h(ucfirst(str_replace('_', ' ', (string) $e['action']))) ?></td><td><?= ag_h(trim(($e['from_version'] ?? '') . ' → ' . ($e['to_version'] ?? ''), ' →')) ?></td><td><?= ag_h($e['by_who']) ?></td>
    <td class="muted" style="font-size:12px;max-width:420px;white-space:pre-wrap"><?= ag_h(mb_substr((string) $e['detail'], 0, 400)) ?></td></tr><?php endforeach; ?></table></div>

</div><aside class="pgs"><div class="box"><div class="hd"><h2><i class="fas fa-rocket"></i>Publishing</h2></div><div class="bd row">
    <?php if ($rl['status'] !== 'withdrawn'): ?>
      <?= $rst !== 'published' ? $sbtn('publish', '<i class="fas fa-rocket"></i> Publish now', 'gold', 'Publish ' . $rp['name'] . ' ' . $rl['version'] . ' to the clients it goes to?') : '' ?>
      <form method="post" class="inline row" style="gap:6px"><?= $csrf() ?><input type="hidden" name="do" value="release-status"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="to" value="schedule">
        <input type="datetime-local" name="publish_at" value="<?= ag_h($rst === 'scheduled' ? str_replace(' ', 'T', substr((string) $rl['publish_at'], 0, 16)) : '') ?>"><button class="btn ghost"><i class="far fa-calendar"></i> Schedule</button></form>
      <?= in_array($rst, ['published', 'scheduled'], true) ? $sbtn('disable', '<i class="fas fa-pause"></i> Disable', 'ghost') : '' ?>
      <?= $rst !== 'draft' ? $sbtn('draft', 'Back to draft', 'ghost') : '' ?>
      <?= $sbtn('withdraw', '<i class="fas fa-ban"></i> Withdraw', 'danger', 'Withdraw this release for good? Clients running it will be offered the previous stable version.') ?>
    <?php else: ?><span>Withdrawn: never offered again. Clients running it are offered the newest older full release.</span><?php endif; ?>
    <form method="post" class="inline" style="margin-left:auto"><?= $csrf() ?><input type="hidden" name="do" value="release-file"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn ghost"><i class="fas fa-download"></i> Package</button></form>
  </div></div>

  <div class="box"><div class="hd"><h2><i class="fas fa-server"></i>Ametecs cloud server</h2><span class="muted">cloud clients run this copy of <?= ag_h($rp['name']) ?></span></div><div class="bd row">
    <span>Running: <b><?= $pv !== '' ? ag_h($pv) : 'unknown' ?></b></span>
    <?php if ($rl['kind'] !== 'plugin' && AmetecsReleases::pluginRoot((string) $rl['app']) !== null && ($pv === '' || version_compare((string) $rl['version'], $pv, '>'))): ?>
      <form method="post" class="inline" onsubmit="return confirm('Install <?= ag_h($rp['name'] . ' ' . $rl['version']) ?> on the cloud server now? Every replaced file is backed up first.')"><?= $csrf() ?><input type="hidden" name="do" value="release-install"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn navy"><i class="fas fa-download"></i> Install on cloud server</button></form>
      <span class="muted">Verifies the package, backs up every file it replaces, installs, runs database changes; on any failure everything is put back.</span>
    <?php elseif (AmetecsReleases::pluginRoot((string) $rl['app']) === null): ?><span class="muted"><?= ag_h($rp['name']) ?> is not on this server yet.</span>
    <?php else: ?><span class="muted">Nothing to install here: the server already runs this version or newer<?= $rl['kind'] === 'plugin' ? ' (plugins install on the client\'s own server)' : '' ?>.</span><?php endif; ?>
  </div><?php if (is_string($ilog) && $ilog !== ''): ?><pre style="margin:0 16px 16px;max-height:260px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px"><?= ag_h($ilog) ?></pre><?php endif; ?></div>

  </aside></div>

<?php elseif ($page === 'billing'):
    $fs = (string) ($_GET['st'] ?? ''); $ft = (string) ($_GET['c'] ?? '');
    $invs = AmetecsBilling::invoices($ft, $fs); $names = array_column($tenants, 'name', 'id');
    $pend = AmetecsBilling::invoices('', 'pending'); $gwOn = AmetecsBilling::gateway() !== null;
    $month = (new DateTimeImmutable('first day of this month', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d 00:00:00'); ?>
  <h1>Payments &amp; invoices</h1><p class="sub">Upgrade charges, licence renewals and other invoices for every client. Clients pay online with Razorpay from their panel; a payment counts only once the server has verified it.</p>
  <?php if (!AmetecsBilling::ready()): ?><div class="msg bad"><i class="fas fa-circle-exclamation"></i><span>The billing tables are not on this PC yet. They arrive with the next sync.</span></div><?php else: ?>
  <?php if (!$gwOn): ?><div class="msg bad"><i class="fas fa-credit-card"></i><span>Online payment is off. Clients see "contact Ametecs" until Razorpay is set up in <a href="?p=gateway">Payment settings</a>.</span></div><?php endif; ?>
  <section class="kstrip" style="grid-template-columns:repeat(4,1fr)">
    <div><span class="ki k4"><i class="fas fa-indian-rupee-sign"></i></span><div><b>₹<?= number_format(AmetecsBilling::revenue()) ?></b><span>Received (net of refunds)</span></div></div>
    <div><span class="ki k2"><i class="fas fa-calendar"></i></span><div><b>₹<?= number_format(AmetecsBilling::revenue($month)) ?></b><span>This month</span></div></div>
    <a href="?p=billing&amp;st=pending"><span class="ki k5"><i class="fas fa-file-invoice"></i></span><div><b><?= count($pend) ?></b><span>Pending · ₹<?= number_format(array_sum(array_map(static fn ($i) => $i['currency'] === 'INR' ? (float) $i['total'] : 0, $pend))) ?></span></div></a>
    <a href="?p=billing&amp;st=refunded"><span class="ki k3"><i class="fas fa-rotate-left"></i></span><div><b><?= count(AmetecsBilling::invoices('', 'refunded')) ?></b><span>Refunded</span></div></a>
  </section>
  <form class="toolbar"><input type="hidden" name="p" value="billing"><span class="si"><i class="fas fa-magnifying-glass"></i><input data-live="#inv-t" placeholder="Search number, client, description…" autocomplete="off"></span>
    <select name="c"><option value="">All clients</option><?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $ft === (string) $t['id'] ? ' selected' : '' ?>><?= ag_h($t['name']) ?></option><?php endforeach; ?></select>
    <select name="st"><option value="">Any status</option><?php foreach (AmetecsBilling::STATUSES as $k => [$v]): ?><option value="<?= $k ?>"<?= $fs === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
    <button class="btn ghost"><i class="fas fa-filter"></i>Filter</button>
    <details class="act" style="margin-left:auto"><summary class="btn gold"><i class="fas fa-plus"></i>New invoice</summary><div class="menu" style="right:0;min-width:340px">
      <form method="post" class="stack"><?= $csrf() ?><input type="hidden" name="do" value="invoice-new">
        <label>Client</label><select name="tenant" required><option value="">Choose…</option><?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>"><?= ag_h($t['name']) ?></option><?php endforeach; ?></select>
        <label>Description</label><input name="description" required placeholder="e.g. Training, 2 days">
        <label>Amount (before GST)</label><div class="row"><select name="currency" style="width:90px"><?php foreach (['INR', 'USD', 'AED', 'SAR', 'EUR', 'GBP'] as $cu): ?><option><?= $cu ?></option><?php endforeach; ?></select><input name="amount" inputmode="decimal" required style="flex:1"></div>
        <span class="muted" style="font-size:12px">For a licence renewal use "Raise renewal invoice" on the licence instead: paying it renews the licence automatically.</span>
        <button class="btn gold" style="margin-top:10px">Raise invoice</button></form></div></details></form>
  <div class="box"><table id="inv-t" class="sortable"><tr><th>Number</th><th>Client</th><th>For</th><th>Total</th><th>Status</th><th>Issued</th><th>Paid</th><th></th></tr>
  <?php foreach ($invs as $i): [$sl, $sc] = AmetecsBilling::STATUSES[$i['status']] ?? [$i['status'], 'grey']; ?><tr>
    <td><b><?= ag_h($i['number']) ?></b></td><td><?= ag_h($names[$i['tenant_id']] ?? '#' . $i['tenant_id']) ?></td>
    <td><?= ag_h(AmetecsBilling::PURPOSES[$i['purpose']] ?? $i['purpose']) ?><div class="muted" style="font-size:12px"><?= ag_h($i['description']) ?></div></td>
    <td style="white-space:nowrap"><?= ag_h($i['currency']) ?> <?= number_format((float) $i['total'], 2) ?></td><td><span class="pill <?= $sc ?>"><?= $sl ?></span></td>
    <td><?= $d($i['issued_on']) ?></td><td><?= $i['paid_at'] ? $d(substr((string) $i['paid_at'], 0, 10)) : '—' ?></td>
    <td class="right"><a class="btn ghost" href="?p=invoice&id=<?= (int) $i['id'] ?>">Open</a></td></tr>
  <?php endforeach; ?><?php if ($invs === []): ?><tr><td colspan="8"><div class="empty"><i class="fas fa-file-invoice"></i>No invoices yet.</div></td></tr><?php endif; ?></table></div>
  <?php endif; ?>

<?php elseif ($page === 'invoice' && ($iv = AmetecsBilling::byId((string) ($_GET['id'] ?? ''))) !== null):
    [$sl, $sc] = AmetecsBilling::STATUSES[$iv['status']] ?? [$iv['status'], 'grey']; $it = $tenant((string) $iv['tenant_id']); $ivid = (int) $iv['id'];
    $ipays = AmetecsBilling::payments((string) $ivid); ?>
  <div class="phero"><div class="row" style="margin-bottom:6px"><a href="?p=billing" class="muted">← Payments &amp; invoices</a></div>
  <div class="row"><h1>Invoice <?= ag_h($iv['number']) ?></h1><span class="pill <?= $sc ?>"><?= $sl ?></span><a class="btn ghost" href="?p=invoice-print&id=<?= $ivid ?>" target="_blank" style="margin-left:auto"><i class="fas fa-print"></i>Print / PDF</a></div>
  <p class="sub"><?= ag_h($it['name'] ?? '#' . $iv['tenant_id']) ?> · <?= ag_h(AmetecsBilling::PURPOSES[$iv['purpose']] ?? '') ?> · issued <?= $d($iv['issued_on']) ?><?= $iv['status'] === 'pending' ? ' · due ' . $d($iv['due_on']) : '' ?></p></div>
  <div class="box"><table><tr><th>Description</th><th class="right">Amount</th></tr>
    <tr><td><?= ag_h($iv['description']) ?><?= $iv['release_id'] ? ' · <a href="?p=release&id=' . (int) $iv['release_id'] . '">release</a>' : '' ?><?= $iv['licence_id'] ? ' · <a href="?p=licence&id=' . (int) $iv['licence_id'] . '">licence</a>' : '' ?></td><td class="right"><?= ag_h($iv['currency']) ?> <?= number_format((float) $iv['amount'], 2) ?></td></tr>
    <?php if ((float) $iv['tax'] > 0): ?><tr><td>GST <?= ag_h(rtrim(rtrim((string) $iv['tax_percent'], '0'), '.')) ?>%</td><td class="right"><?= ag_h($iv['currency']) ?> <?= number_format((float) $iv['tax'], 2) ?></td></tr><?php endif; ?>
    <tr><td><b>Total</b></td><td class="right"><b><?= ag_h($iv['currency']) ?> <?= number_format((float) $iv['total'], 2) ?></b></td></tr></table></div>
  <?php if ($iv['status'] === 'pending'): ?>
  <div class="box"><div class="hd"><h2><i class="fas fa-hand-holding-dollar"></i>Collect</h2><span class="muted">Razorpay order: <?= $iv['gateway_order_id'] ? '<code>' . ag_h($iv['gateway_order_id']) . '</code>' : 'not started yet' ?></span></div><div class="bd row">
    <form method="post" class="inline"><?= $csrf() ?><input type="hidden" name="do" value="invoice-reconcile"><input type="hidden" name="id" value="<?= $ivid ?>"><button class="btn navy"<?= $iv['gateway_order_id'] ? '' : ' disabled' ?>><i class="fas fa-magnifying-glass-dollar"></i>Check with Razorpay</button></form>
    <span class="muted">If the client paid but closed the page before it finished, this asks Razorpay and applies the payment.</span></div>
    <form method="post" class="ft row" style="justify-content:flex-start"><?= $csrf() ?><input type="hidden" name="do" value="invoice-paid"><input type="hidden" name="id" value="<?= $ivid ?>">
      <b>Paid offline:</b><select name="method"><option>bank transfer</option><option>cheque</option><option>cash</option><option>UPI</option></select><input name="reference" placeholder="UTR / cheque no. / reference" required style="flex:1;min-width:220px">
      <button class="btn gold" onclick="return confirm('Record the full amount as received? This unlocks what the invoice is for.')">Mark paid</button></form>
    <form method="post" class="ft row" style="justify-content:flex-start"><?= $csrf() ?><input type="hidden" name="do" value="invoice-cancel"><input type="hidden" name="id" value="<?= $ivid ?>">
      <b>Cancel:</b><input name="why" placeholder="reason" style="flex:1;min-width:220px"><button class="btn danger">Cancel invoice</button></form></div>
  <?php endif; ?>
  <div class="box"><div class="hd"><h2><i class="fas fa-receipt"></i>Payments</h2></div><table><tr><th>When</th><th>Via</th><th>Reference</th><th>Amount</th><th>Status</th><th>Detail</th><th class="right"></th></tr>
  <?php foreach ($ipays as $py): ?><tr><td style="white-space:nowrap"><?= ag_h($py['created_at']) ?></td><td><?= ag_h(ucfirst((string) $py['gateway'])) ?><?= $py['method'] ? ' · ' . ag_h($py['method']) : '' ?></td><td><code><?= ag_h((string) $py['gateway_payment_id']) ?></code></td>
    <td><?= ag_h($py['currency']) ?> <?= number_format((float) $py['amount'], 2) ?></td><td><?= $py['status'] === 'refunded' ? '<span class="pill red">Refunded</span>' : '<span class="pill green">Received</span>' ?></td>
    <td class="muted" style="font-size:12px;white-space:pre-wrap"><?= ag_h((string) $py['detail']) ?></td>
    <td class="right"><?php if ($py['status'] === 'succeeded'): ?><details class="act"><summary class="btn ghost">Refund ▾</summary><div class="menu"><form method="post"><?= $csrf() ?><input type="hidden" name="do" value="payment-refund"><input type="hidden" name="id" value="<?= (int) $py['id'] ?>">
      <b>Refund the full amount</b><span class="muted"><?= $py['gateway'] === 'razorpay' ? 'sent back through Razorpay' : 'recorded as an offline refund (send the money yourself)' ?>; what it paid for is taken back</span>
      <div class="row"><input name="why" placeholder="reason" required><button class="btn danger" onclick="return confirm('Refund this payment?')">Refund</button></div></form></div></details><?php endif; ?></td></tr>
  <?php endforeach; ?><?php if ($ipays === []): ?><tr><td colspan="7" class="muted">No payment yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($page === 'gateway'): $gr = AmetecsBilling::gatewayRow() ?? []; $bs = AmetecsBilling::settings(); ?>
  <h1>Payment settings</h1><p class="sub">Razorpay keys for online payment, and the details printed on every invoice. The same Razorpay keys are used by SmartPRS 3's console.</p>
  <div class="msg <?= AmetecsBilling::gateway() !== null ? 'ok' : 'bad' ?>"><?= AmetecsBilling::gateway() !== null ? 'Online payment is ON (' . ag_h(ucfirst((string) ($gr['mode'] ?? 'test'))) . ' mode).' : 'Online payment is OFF: clients are told to contact Ametecs.' ?></div>
  <form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="gateway-save">
    <div class="hd"><h2><i class="fas fa-credit-card"></i>Razorpay</h2><span class="muted">dashboard.razorpay.com → Account &amp; Settings → API Keys</span></div><div class="bd grid2">
      <div class="full"><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="checkbox" name="enabled"<?= in_array(strtolower((string) ($gr['status'] ?? '')), ['configured', 'live', 'active'], true) ? ' checked' : '' ?>> Accept online payments</label></div>
      <div><label>Mode</label><select name="mode"><option value="test"<?= ($gr['mode'] ?? '') !== 'live' ? ' selected' : '' ?>>Test (rzp_test_ keys, no real money)</option><option value="live"<?= ($gr['mode'] ?? '') === 'live' ? ' selected' : '' ?>>Live</option></select></div>
      <div><label>Key ID</label><input name="key_id" value="<?= ag_h((string) ($gr['key_id'] ?? '')) ?>" placeholder="rzp_test_…" autocomplete="off"></div>
      <div><label>Key Secret</label><input type="password" name="secret" placeholder="<?= trim((string) ($gr['secret'] ?? '')) !== '' ? 'saved - leave empty to keep' : '' ?>" autocomplete="new-password"></div>
    </div>
    <div class="hd"><h2><i class="fas fa-file-invoice"></i>On every invoice</h2></div><div class="bd grid2">
      <div><label>Seller name</label><input name="seller" value="<?= ag_h((string) $bs['seller']) ?>"></div>
      <div><label>GSTIN</label><input name="gstin" value="<?= ag_h((string) $bs['gstin']) ?>" maxlength="15" placeholder="36XXXXX0000X1Z5"></div>
      <div class="full"><label>Address</label><textarea name="address" rows="2"><?= ag_h((string) $bs['address']) ?></textarea></div>
      <div><label>Billing email</label><input type="email" name="email" value="<?= ag_h((string) $bs['email']) ?>"></div>
      <div><label>GST % (INR invoices)</label><input name="gst_percent" value="<?= ag_h((string) $bs['gst_percent']) ?>" inputmode="decimal"></div>
      <div><label>Payment due in (days)</label><input type="number" min="0" name="due_days" value="<?= (int) $bs['due_days'] ?>"></div>
    </div>
    <div class="hd"><h2><i class="fas fa-life-ring"></i>Support contact</h2><span class="muted">shown to clients under Support &amp; help; messages they send go to the support email</span></div><div class="bd grid2">
      <div><label>Support email</label><input type="email" name="support_email" value="<?= ag_h((string) ($bs['support_email'] ?? '')) ?>" placeholder="support@ametecsindia.com"></div>
      <div><label>Support phone</label><input name="support_phone" value="<?= ag_h((string) ($bs['support_phone'] ?? '')) ?>" placeholder="+91 …"></div>
      <div class="full"><label>Support hours</label><input name="support_hours" value="<?= ag_h((string) ($bs['support_hours'] ?? '')) ?>" placeholder="Mon-Sat, 10 am - 7 pm IST"></div>
    </div><div class="ft"><button class="btn navy">Save</button></div></form>
  <p class="muted" style="font-size:12px">The Key Secret is stored encrypted and never shown again. Payments are confirmed on the server with Razorpay's signature (or by asking Razorpay directly), never by the browser alone.</p>
  <?php $prs = AmetecsBilling::prices(); ?>
  <form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="prices-save">
    <div class="hd"><h2><i class="fas fa-tags"></i>Self-service prices</h2><span class="muted">INR per seat, before GST. Clients buy an application or change plan and seats themselves under Licences &amp; plans, and pay online. Leave empty to keep a plan off sale.</span></div>
    <div class="bd"><table><tr><th>Application</th><th>Monthly, per seat</th><th>Yearly, per seat</th></tr>
    <?php foreach ($plugins as $c => $p): ?><tr><td><b><?= ag_h($p['name']) ?></b> <small class="muted">per <?= ag_h(rtrim((string) $p['unit'], 's')) ?></small></td>
      <?php foreach (['monthly', 'yearly'] as $k): ?><td><input name="price[<?= ag_h($c) ?>][<?= $k ?>]" value="<?= isset($prs[$c][$k]) ? ag_h((string) $prs[$c][$k]) : '' ?>" inputmode="decimal" placeholder="not sold online" style="max-width:180px"></td><?php endforeach; ?></tr>
    <?php endforeach; ?></table>
    <p class="muted" style="font-size:12px;margin:10px 0 0">A plan change starts a new period on payment; the unused part of the current paid plan is taken off. A smaller plan than the current one is not sold online (it waits for renewal).</p></div>
    <div class="ft"><button class="btn navy">Save prices</button></div></form>

<?php elseif ($page === 'system'):
    $ok = static fn (string $s, string $t, string $d = '') => '<li><span class="s ' . $s . '"><i class="fas fa-' . ['ok' => 'check', 'warn' => 'exclamation', 'bad' => 'xmark'][$s] . '"></i></span><span><b>' . ag_h($t) . '</b>' . ($d !== '' ? '<small>' . $d . '</small>' : '') . '</span></li>';
    $tables = ['ag_licences', 'ag_licence_events', 'ag_releases', 'ag_release_access', 'ag_release_events', 'ag_invoices', 'ag_payments'];
    $missing = [];
    foreach ($tables as $tb) { try { $pdo->query("SELECT 1 FROM {$tb} LIMIT 1"); } catch (\Throwable) { $missing[] = $tb; } }
    $exts = array_filter(['pdo_mysql', 'openssl', 'zip', 'curl', 'mbstring'], static fn ($e) => !extension_loaded($e));
    $free = @disk_free_space(__DIR__); $dataW = is_writable(__DIR__ . '/data');
    $env = \SmartPRS\Surface\Database::env(AG_BASE . '/.env'); $prod = strtolower((string) ($env['APP_ENV'] ?? 'production')) === 'production';
    $houseQ = $pdo->prepare('SELECT password FROM users WHERE LOWER(email) = ? LIMIT 1'); $houseQ->execute([AmetecsAccess::HOUSE_LOGIN]);
    $houseDefault = ($hp = $houseQ->fetchColumn()) !== false && $hasher->verify(AmetecsAccess::HOUSE_PASSWORD, (string) $hp);
    $gw = AmetecsBilling::ready() ? AmetecsBilling::gateway() : null;
    $staffAll = $pdo->query('SELECT name, email, disabled_at, last_login_at FROM platform_users ORDER BY last_login_at DESC')->fetchAll(\PDO::FETCH_ASSOC);
    $backups = [];
    foreach (glob(__DIR__ . '/../plugins/*/storage/updates/backup-*.zip') ?: [] as $bf) { $backups[] = [basename(dirname($bf, 3)), basename($bf), filesize($bf), filemtime($bf)]; }
    usort($backups, static fn ($a, $b) => $b[3] <=> $a[3]);
    $relBytes = 0; foreach (glob(__DIR__ . '/data/releases/*/*.zip') ?: [] as $rf) { $relBytes += filesize($rf); }
    $logF = __DIR__ . '/../sync-log.txt'; $logTail = is_file($logF) ? implode("\n", array_slice(file($logF, FILE_IGNORE_NEW_LINES) ?: [], -40)) : '';
    $mb = static fn ($b) => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(0, (int) round($b / 1024)) . ' KB'); ?>
  <h1>System &amp; health</h1><p class="sub">Is this Ametecs Global server healthy, safe and backed up? Checked live every time you open this page.</p>
  <div class="dgrid2">
    <div class="dcol2">
      <div class="box"><div class="hd"><h2><i class="fas fa-heart-pulse"></i>Health checks</h2></div><ul class="chk-l">
        <?= $ok('ok', 'Database connected', ag_h((string) ($env['DB_DATABASE'] ?? '')) . ' on ' . ag_h((string) ($env['DB_HOST'] ?? '')) ) ?>
        <?= $missing === [] ? $ok('ok', 'All Ametecs Global tables present', count($tables) . ' tables: licences, releases, billing') : $ok('bad', 'Tables missing: ' . implode(', ', $missing), 'Run SYNC.bat (or wait for AUTO-SYNC) so the database changes are applied.') ?>
        <?= version_compare(PHP_VERSION, '8.2', '>=') ? $ok('ok', 'PHP ' . PHP_VERSION) : $ok('bad', 'PHP ' . PHP_VERSION, 'Ametecs Global needs PHP 8.2 or newer.') ?>
        <?= $exts === [] ? $ok('ok', 'PHP extensions', 'pdo_mysql, openssl, zip, curl, mbstring') : $ok('bad', 'Missing PHP extensions: ' . implode(', ', $exts), 'Enable them in php.ini.') ?>
        <?= $ok('ok', 'Upload limit ' . ini_get('upload_max_filesize') . ' (post ' . ini_get('post_max_size') . ')', 'Bigger release packages go through core\data\releases\incoming.') ?>
        <?= $dataW ? $ok('ok', 'core\data is writable', 'release packages: ' . $mb($relBytes)) : $ok('bad', 'core\data is NOT writable', 'Uploads, activity log and settings cannot be saved.') ?>
        <?= $free === false ? $ok('warn', 'Disk space unknown') : ($free < 2 * 1073741824 ? $ok('warn', 'Low disk space: ' . $mb($free) . ' free') : $ok('ok', $mb($free) . ' free disk space')) ?>
        <?= AmetecsLicences::canSign() ? $ok('ok', 'Licence signing key present', 'releases and .lic files are signed here') : $ok($prod ? 'bad' : 'warn', 'No licence signing key on this server', $prod ? 'This production server cannot sign releases or .lic files.' : 'Fine on a development PC: uploads stay unsigned and test-only.') ?>
        <?= $gw !== null ? $ok($gw['mode'] === 'live' ? 'ok' : 'warn', 'Razorpay on (' . $gw['mode'] . ' mode)', $gw['mode'] === 'live' ? 'clients pay real money' : 'test keys: no real money moves') : $ok('warn', 'Online payment is off', '<a href="?p=gateway">Payment settings</a>') ?>
        <?= AmetecsMail::box($pdo)->ready() ? $ok('ok', 'Email is on') : $ok('warn', 'Email is off', 'Password resets, support messages and alerts need it: <a href="?p=email">Email settings</a>') ?>
      </ul></div>
      <div class="box"><div class="hd"><h2><i class="fas fa-shield-halved"></i>Security</h2></div><ul class="chk-l">
        <?= $prod ? $ok('ok', 'Running as production', 'test logins are never created') : $ok('warn', 'Running as a development / test server (APP_ENV=' . ag_h((string) ($env['APP_ENV'] ?? '')) . ')', 'Never use this mode for real clients.') ?>
        <?= $houseDefault ? $ok($prod ? 'bad' : 'warn', 'The test login ' . AmetecsAccess::HOUSE_LOGIN . ' still has the default password', 'The repository is public: change it under Clients → Ametecs India → Logins.') : $ok('ok', 'No default test password in use') ?>
        <?= $ok('ok', count(array_filter($staffAll, static fn ($u) => trim((string) $u['disabled_at']) === '')) . ' active Super Admin' . (count($staffAll) === 1 ? '' : 's'), 'sign-in locks for 5 minutes after 5 wrong passwords') ?>
        <?= $ok('ok', 'Secrets stored encrypted', 'Razorpay secret, SMTP password, licence keys (with this server\'s APP_KEY)') ?>
      </ul>
      <table><tr><th>Super Admin</th><th>Status</th><th>Last sign-in</th></tr><?php foreach ($staffAll as $u): ?><tr><td><?= ag_h((string) ($u['name'] ?: $u['email'])) ?><div class="muted" style="font-size:12px"><?= ag_h($u['email']) ?></div></td><td><?= trim((string) $u['disabled_at']) === '' ? '<span class="pill green">Active</span>' : '<span class="pill red">Disabled</span>' ?></td><td><?= ag_h((string) ($u['last_login_at'] ?? '—')) ?></td></tr><?php endforeach; ?></table></div>
    </div>
    <div class="dcol2">
      <div class="box"><div class="hd"><h2><i class="fas fa-cubes"></i>Apps on this server</h2></div><table><tr><th>App</th><th>Folder</th><th>Version</th></tr>
        <?php foreach ($plugins as $c => $p): $root = AmetecsReleases::pluginRoot($c); ?><tr><td><?= ag_h($p['name']) ?></td><td><?= $root !== null ? '<span class="pill green">plugins/' . ag_h($c) . '</span>' : '<span class="pill grey">not here yet</span>' ?></td><td><?= ag_h(AmetecsReleases::platformVersion($c) ?: '—') ?></td></tr><?php endforeach; ?></table></div>
      <div class="box"><div class="hd"><h2><i class="fas fa-box-archive"></i>Install backups</h2><span class="muted">made automatically before every install on this server</span></div><table><tr><th>App</th><th>Backup</th><th>Size</th><th>Made</th></tr>
        <?php foreach (array_slice($backups, 0, 12) as [$ba, $bn, $bs2, $bt]): ?><tr><td><?= ag_h($ba) ?></td><td style="font-size:12px"><code><?= ag_h($bn) ?></code></td><td><?= $mb($bs2) ?></td><td><?= ag_h(date('d M Y H:i', $bt)) ?></td></tr><?php endforeach; ?>
        <?php if ($backups === []): ?><tr><td colspan="4"><div class="empty"><i class="fas fa-box-archive"></i>No installs on this server yet.</div></td></tr><?php endif; ?></table>
        <div class="bd muted" style="font-size:12.5px">Database: each PC's MySQL holds its own data. Back it up with Laragon (Menu → MySQL → Backup) or <code>mysqldump</code> before big changes; on the live server use the hosting provider's daily backups.</div></div>
      <?php $smp = AmetecsSample::present(); $houseT = array_values(array_filter($tenants, static fn ($t) => ($t['subdomain'] ?? '') === 'ametecs'))[0]['id'] ?? ($tenants[0]['id'] ?? ''); ?>
      <div class="box"><div class="hd"><h2><i class="fas fa-flask"></i>Sample data</h2><span class="muted">for demos and testing on this PC only: 100 employees in Ametecs India Pvt Ltd (4 managers, 12 team leads) with 30 days of attendance, and 20 demo clients with licences and invoices</span></div><div class="bd">
        <?php if ($prod): ?><p class="muted" style="margin:0">Switched off: this server runs as production (APP_ENV).</p>
        <?php else: ?><div class="row" style="gap:10px;flex-wrap:wrap;align-items:center">
          <form method="post" class="row" style="gap:8px" onsubmit="return confirm('Add 100 sample employees in Ametecs India Pvt Ltd and 20 demo clients to this PC\'s database?')"><?= $csrf() ?><input type="hidden" name="do" value="sample-add">
            <label class="muted" style="font-size:12.5px">Employees go to</label><select name="tenant"><?php foreach ($tenants as $t): if (str_starts_with((string) $t['code'], 'DEMO')) { continue; } ?><option value="<?= (int) $t['id'] ?>"<?= (string) $t['id'] === (string) $houseT ? ' selected' : '' ?>><?= ag_h($t['name']) ?></option><?php endforeach; ?></select>
            <button class="btn gold"><i class="fas fa-wand-magic-sparkles"></i>Add sample data</button></form>
          <?php if ($smp !== []): ?><form method="post" onsubmit="return confirm('Remove all sample employees, attendance, demo clients and their invoices?')"><?= $csrf() ?><input type="hidden" name="do" value="sample-remove"><button class="btn danger"><i class="fas fa-trash"></i>Remove sample data</button></form>
          <span class="pill amber"><?= count($smp['tenants'] ?? []) ?> demo clients &middot; <?= count(array_filter($smp['rows'] ?? [], static fn ($r) => $r[0] === 'employees')) ?> sample employees present</span><?php endif; ?>
        </div><?php endif; ?></div></div>
      <div class="box"><div class="hd"><h2><i class="fas fa-arrows-rotate"></i>Sync with GitHub (last 40 lines)</h2></div><?= $logTail !== '' ? '<pre class="log">' . ag_h($logTail) . '</pre>' : '<div class="empty"><i class="fas fa-arrows-rotate"></i>No sync log on this PC.</div>' ?></div>
    </div>
  </div>

<?php elseif ($page === 'apps'):
    // PHASE 7 (owner, 18 Nov 2026: "redesign this page"): one card per app - logo, status, clients, seats, online
    // price - with editing in a pop-up instead of a wide table of inputs.
    $cat = AmetecsAccess::plugins(true); $prs = AmetecsBilling::ready() ? AmetecsBilling::prices() : [];
    $builtIn = ['smartprs3' => 'assets/logos/smartprs3.png', 'smartept' => 'assets/logos/smartept.png', 'leadger' => 'https://rovexy.app/brand/products/leadger.png inv', 'rovexy' => 'https://rovexy.app/brand/rovexy-lockup.svg'];
    $logoImg = static function (string $c, array $p) use ($builtIn): string {
        [$lg, $inv] = array_pad(explode(' ', trim((string) ($p['logo'] ?? '')) ?: ($builtIn[$c] ?? ''), 2), 2, '');
        return $lg !== '' ? '<img src="' . ag_h($lg) . '" alt="' . ag_h($p['name']) . '"' . ($inv === 'inv' ? ' style="filter:invert(1) hue-rotate(180deg)"' : '') . ' onerror="this.remove()">' : '<span class="amark">' . ag_h(mb_substr((string) $p['name'], 0, 1)) . '</span>';
    };
    $stat = []; $tc = 0; $ts = 0;
    foreach ($cat as $c => $p) {
        $n = 0; $seats = 0;
        foreach ($tenants as $t) { if (AmetecsAccess::state((string) $t['id'], $c, $all) === 'on') { $n++; $seats += (int) AmetecsAccess::get((string) $t['id'], $c, $all)['seats']; } }
        $stat[$c] = [$n, $seats];
    }
    $liveN = count(array_filter($cat, static fn ($p) => empty($p['retired']) && $p['open'] !== null));
    $retN = count(array_filter($cat, static fn ($p) => !empty($p['retired'])));
    $field = static fn (string $fid, array $p, string $c = ''): string => '<div class="grid2">'
        . '<div><label>App name <span class="req">*</span></label><input name="name" value="' . ag_h((string) ($p['name'] ?? '')) . '" required placeholder="e.g. SmartVisit"></div>'
        . '<div><label>Seats are counted in</label><input name="unit" value="' . ag_h((string) ($p['unit'] ?? 'users')) . '" placeholder="users, employees, agents…"></div>'
        . '<div class="full"><label>What it does</label><input name="what" value="' . ag_h((string) ($p['what'] ?? '')) . '" placeholder="one line clients will read"></div>'
        . '<div class="full"><label>Opens at <small class="muted">empty = not live yet</small></label><input name="open" value="' . ag_h((string) ($p['open'] ?? '')) . '" placeholder="../plugins/myapp/  or  https://myapp.example.com"></div>'
        . '<div class="full"><label>Logo <small class="muted">web address or assets/logos/file.png; add " inv" for a white-only logo; empty = built-in</small></label><input name="logo" value="' . ag_h((string) ($p['logo'] ?? '')) . '" placeholder="built-in"></div>'
        . '<div><label>Colour</label><input type="color" name="color" value="' . ag_h((string) ($p['color'] ?? '#1F3A5F')) . '" style="height:40px;padding:3px;width:100%"></div>'
        . ($c !== '' ? '<div><label>Status</label><label class="sw" style="text-transform:none;font-size:13.5px;color:var(--ink);margin-top:8px"><input type="checkbox" name="retired"' . (!empty($p['retired']) ? ' checked' : '') . '> Retired (clients stop seeing it; licences kept)</label></div>' : '')
        . '</div>'; ?>
  <div class="lph"><div><h1>Applications</h1><p class="sub">The apps every client sees. A change here reaches every PC through GitHub within minutes. An app with an "Opens at" link is <b>Live</b>; without one it can be licensed and shows as arriving soon.</p></div>
    <button type="button" class="btn gold" onclick="document.getElementById('appnew').showModal()"><i class="fas fa-plus"></i>Add app</button></div>
  <div class="lsum four">
    <div class="ls"><span class="li"><i class="fas fa-layer-group"></i></span><div><b><?= count($cat) ?></b><small>Applications</small></div></div>
    <div class="ls"><span class="li t-green"><i class="fas fa-circle-play"></i></span><div><b><?= $liveN ?></b><small>Live</small></div></div>
    <div class="ls"><span class="li"><i class="fas fa-hourglass-half"></i></span><div><b><?= count($cat) - $liveN - $retN ?></b><small>Arriving soon</small></div></div>
    <div class="ls"><span class="li"><i class="fas fa-key"></i></span><div><b><?= array_sum(array_column($stat, 0)) ?> · <?= array_sum(array_column($stat, 1)) ?></b><small>Licences · seats</small></div></div>
  </div>
  <div class="appg"><?php foreach ($cat as $c => $p): [$n, $seats] = $stat[$c]; $ret = !empty($p['retired']); $pr = $prs[$c] ?? []; ?>
    <article class="appc<?= $ret ? ' ret' : '' ?>" style="--c:<?= ag_h((string) $p['color']) ?>">
      <header><span class="alogo"><?= $logoImg($c, $p) ?></span><?= $ret ? '<span class="pill red">Retired</span>' : ($p['open'] !== null ? '<span class="pill green">Live</span>' : '<span class="pill grey">Arriving soon</span>') ?></header>
      <h3><?= ag_h($p['name']) ?> <small><?= ag_h($c) ?></small></h3>
      <p><?= ag_h((string) ($p['what'] ?: 'No description yet.')) ?></p>
      <dl><div><dt>Clients</dt><dd><?= $n ?></dd></div><div><dt>Seats</dt><dd><?= $seats ?> <small><?= ag_h((string) $p['unit']) ?></small></dd></div>
        <div><dt>Online price</dt><dd><?= $pr !== [] ? implode(' · ', array_map(static fn ($k, $v) => '₹' . number_format((float) $v) . '<small>/' . ($k === 'yearly' ? 'yr' : 'mo') . '</small>', array_keys($pr), $pr)) : '<a href="?p=gateway">Set</a>' ?></dd></div></dl>
      <footer><button type="button" class="btn ghost" onclick="document.getElementById('app-<?= ag_h($c) ?>').showModal()"><i class="fas fa-pen"></i>Edit</button>
        <a class="btn ghost" href="?p=clients"><i class="fas fa-building"></i>Clients</a>
        <?php if ($p['open'] !== null && !$ret): ?><a class="btn ghost" href="<?= ag_h((string) $p['open']) ?>" target="_blank" rel="noopener" title="Open"><i class="fas fa-arrow-up-right-from-square"></i></a><?php endif; ?></footer>
    </article>
    <dialog id="app-<?= ag_h($c) ?>" class="ldlg"><form method="post"><?= $csrf() ?><input type="hidden" name="do" value="app-save"><input type="hidden" name="code" value="<?= ag_h($c) ?>">
      <header><h2><i class="fas fa-pen"></i>Edit <?= ag_h($p['name']) ?></h2><button type="button" class="x" onclick="this.closest('dialog').close()">&times;</button></header>
      <?= $field('app-' . $c, $p, $c) ?>
      <footer><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn gold"><i class="fas fa-check"></i>Save</button></footer></form></dialog>
  <?php endforeach; ?>
    <button type="button" class="appc addc" onclick="document.getElementById('appnew').showModal()"><i class="fas fa-plus"></i><b>Add a new app</b><small>It appears for every client straight away, switched off until you licence it.</small></button>
  </div>
  <dialog id="appnew" class="ldlg"><form method="post"><?= $csrf() ?><input type="hidden" name="do" value="app-save">
    <header><h2><i class="fas fa-circle-plus"></i>Add a new app</h2><button type="button" class="x" onclick="this.closest('dialog').close()">&times;</button></header>
    <?= $field('new', ['color' => '#1F3A5F']) ?>
    <footer><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn gold"><i class="fas fa-plus"></i>Add app</button></footer></form></dialog>

<?php elseif ($page === 'activity'):
    // PHASE 7 (owner, 18 Nov 2026: "re design this page"): the same timeline as the client panel - kinds as clickable
    // totals, period / person / client filters, day groups, search and a CSV of what is shown. Sign-ins are a kind.
    $names = array_column($tenants, 'name', 'id');
    $kindOf = static function (string $what): string {
        $w = strtolower($what);
        return match (true) {
            in_array($what, ['Signed in', 'Signed out'], true) => 'signin',
            str_contains($w, 'invoice') || str_contains($w, 'payment') || str_contains($w, 'refund') || str_contains($w, 'price') => 'billing',
            str_contains($w, 'release') || str_contains($w, 'update') || str_contains($w, 'install') || str_contains($w, 'roll') || str_contains($w, 'plugin') || str_contains($w, ' app ') || str_starts_with($w, 'added app') || str_starts_with($w, 'changed app ') => 'update',
            str_contains($w, 'licen') || str_contains($w, 'plan') || str_contains($w, 'seat') || str_contains($w, 'apps &') => 'licence',
            str_contains($w, 'client') || str_contains($w, 'administrator') || str_contains($w, 'login') || str_contains($w, 'tenant') => 'client',
            default => 'settings' };
    };
    $kinds = ['client' => ['Clients & logins', 'fa-building'], 'licence' => ['Licences & plans', 'fa-key'], 'billing' => ['Billing', 'fa-indian-rupee-sign'],
        'update' => ['Apps & releases', 'fa-cloud-arrow-up'], 'settings' => ['Settings & staff', 'fa-sliders'], 'signin' => ['Sign-ins', 'fa-right-to-bracket']];
    $feedA = array_map(static fn ($r) => $r + ['k' => $kindOf((string) $r['what'])], AmetecsData::activity('', 3000));
    $fk = isset($kinds[$_GET['k'] ?? '']) ? (string) $_GET['k'] : '';
    $periods = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '' => 'All time'];
    $fp = isset($periods[$_GET['pd'] ?? '']) ? (string) ($_GET['pd'] ?? '') : '';
    $since = ['today' => $today, '7d' => date('Y-m-d', strtotime('-6 days')), '30d' => date('Y-m-d', strtotime('-29 days')), '90d' => date('Y-m-d', strtotime('-89 days'))][$fp] ?? '';
    $whoL = array_values(array_unique(array_filter(array_map(static fn ($r) => (string) $r['who'], $feedA)))); sort($whoL);
    $fw = in_array($_GET['who'] ?? '', $whoL, true) ? (string) $_GET['who'] : '';
    $fc = isset($names[$_GET['c'] ?? '']) ? (string) $_GET['c'] : '';
    $inP = array_values(array_filter($feedA, static fn ($r) => $since === '' || substr((string) $r['at'], 0, 10) >= $since));
    $byK = array_count_values(array_column($inP, 'k'));
    $rowsA = array_values(array_filter($inP, static fn ($r) => ($fk === '' ? $r['k'] !== 'signin' : $r['k'] === $fk) && ($fw === '' || $r['who'] === $fw) && ($fc === '' || (string) $r['tenant'] === $fc)));
    $lim = min(count($rowsA), max(150, (int) ($_GET['n'] ?? 150)));
    $aq = static fn (array $o) => '?' . http_build_query(array_filter($o + ['p' => 'activity', 'k' => $fk, 'pd' => $fp, 'who' => $fw, 'c' => $fc], static fn ($v) => $v !== ''));
    $dayName = static fn (string $d) => $d === $today ? 'Today' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('l, d M Y', (int) strtotime($d)));
    $kcol = ['client' => 'k-access', 'licence' => 'k-licence', 'billing' => 'k-billing', 'update' => 'k-update', 'settings' => 'k-settings', 'signin' => 'k-settings']; ?>
  <div class="lph"><div><h1>Activity log</h1><p class="sub">Every change made in Ametecs Global, by Ametecs staff and by clients, newest first. Entries are never edited or removed.</p></div>
    <button type="button" class="btn ghost" data-csv="#satl" data-name="ametecsglobal-activity-<?= ag_h($today) ?>.csv"><i class="fas fa-file-arrow-down"></i>Download CSV</button></div>
  <section class="akpis seven">
    <a class="<?= $fk === '' ? 'on' : '' ?>" href="<?= ag_h($aq(['k' => ''])) ?>"><i class="fas fa-layer-group"></i><b><?= count($inP) - (int) ($byK['signin'] ?? 0) ?></b><span>All changes</span></a>
    <?php foreach ($kinds as $k => [$lab, $ic]): ?><a class="<?= $kcol[$k] ?><?= $fk === $k ? ' on' : '' ?>" href="<?= ag_h($aq(['k' => $k])) ?>"><i class="fas <?= $ic ?>"></i><b><?= (int) ($byK[$k] ?? 0) ?></b><span><?= ag_h($lab) ?></span></a><?php endforeach; ?>
  </section>
  <form class="afilt" method="get"><input type="hidden" name="p" value="activity"><?php if ($fk !== ''): ?><input type="hidden" name="k" value="<?= ag_h($fk) ?>"><?php endif; ?>
    <label class="fsearch"><i class="fas fa-magnifying-glass"></i><input data-live-tl="#satl" placeholder="Search what, who, client or detail…" autocomplete="off"></label>
    <select name="pd" onchange="this.form.submit()"><?php foreach ($periods as $k => $v2): ?><option value="<?= $k ?>"<?= $fp === $k ? ' selected' : '' ?>><?= $v2 ?></option><?php endforeach; ?></select>
    <select name="c" onchange="this.form.submit()"><option value="">All clients</option><?php foreach ($names as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= $fc === (string) $cid ? ' selected' : '' ?>><?= ag_h((string) $cn) ?></option><?php endforeach; ?></select>
    <select name="who" onchange="this.form.submit()"><option value="">Everyone</option><?php foreach ($whoL as $w2): ?><option<?= $fw === $w2 ? ' selected' : '' ?>><?= ag_h($w2) ?></option><?php endforeach; ?></select>
    <?php if ($fk !== '' || $fp !== '' || $fw !== '' || $fc !== ''): ?><a class="btn ghost" href="?p=activity"><i class="fas fa-xmark"></i>Clear</a><?php endif; ?>
    <span class="acnt"><?= count($rowsA) ?> <?= count($rowsA) === 1 ? 'entry' : 'entries' ?></span></form>
  <div class="tl" id="satl"><?php $lastDay = ''; foreach (array_slice($rowsA, 0, $lim) as $r): $day = substr((string) $r['at'], 0, 10); $k = $r['k']; $cn = $r['tenant'] !== '' ? ($names[$r['tenant']] ?? '#' . $r['tenant']) : '';
      if ($day !== $lastDay): $lastDay = $day; ?><div class="tlday"><?= ag_h($dayName($day)) ?></div><?php endif; ?>
    <div class="tli <?= $kcol[$k] ?>" data-csv-row="<?= ag_h((string) json_encode([date('Y-m-d H:i', (int) strtotime((string) $r['at'])), $kinds[$k][0], $r['what'], $r['who'], $cn, (string) $r['detail']])) ?>"><span class="tlic"><i class="fas <?= $kinds[$k][1] ?>"></i></span>
      <div class="tlb"><div class="tlh"><b><?= ag_h((string) $r['what']) ?><?= $cn !== '' ? ' <a class="tlc" href="?p=client&id=' . (int) $r['tenant'] . '">' . ag_h($cn) . '</a>' : '' ?></b><time><?= ag_h(date('h:i A', (int) strtotime((string) $r['at']))) ?></time></div>
        <?= trim((string) $r['detail']) !== '' ? '<p>' . ag_h((string) $r['detail']) . '</p>' : '' ?><small><span class="av"><?= ag_h(mb_strtoupper(mb_substr((string) $r['who'] ?: '?', 0, 1))) ?></span><?= ag_h((string) $r['who'] ?: 'system') ?> &middot; <?= ag_h($kinds[$k][0]) ?></small></div></div>
  <?php endforeach; ?>
  <?php if ($rowsA === []): ?><div class="box"><div class="empty"><i class="fas fa-wind"></i>Nothing matches these filters.</div></div><?php endif; ?></div>
  <?php if ($lim < count($rowsA)): ?><div style="text-align:center;margin:14px 0"><a class="btn ghost" href="<?= ag_h($aq(['n' => $lim + 150])) ?>">Show more (<?= count($rowsA) - $lim ?> older)</a></div><?php endif; ?>

<?php elseif ($page === 'staff'):
    // PHASE 7 (owner, 18 Nov 2026: "redesign this page"): member cards, figures, add in a pop-up, reset a password.
    $staff = $pdo->query('SELECT * FROM platform_users ORDER BY disabled_at IS NOT NULL, name')->fetchAll(\PDO::FETCH_ASSOC);
    $nowTs = time();
    $ago = static function (?string $at) use ($nowTs): string {
        if (($at ?? '') === '') { return 'Never'; }
        $s2 = max(0, $nowTs - (int) strtotime((string) $at));
        return $s2 < 90 ? 'just now' : ($s2 < 3600 ? round($s2 / 60) . ' min ago' : ($s2 < 86400 ? round($s2 / 3600) . ' h ago' : ($s2 < 86400 * 30 ? round($s2 / 86400) . ' days ago' : date('d M Y', (int) strtotime((string) $at)))));
    };
    $ini = static fn (string $n): string => strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s._@-]+/', trim($n)) ?: ['S'], 0, 2))));
    $active = array_filter($staff, static fn ($u) => trim((string) ($u['disabled_at'] ?? '')) === '');
    $recent = array_filter($staff, static fn ($u) => ($u['last_login_at'] ?? '') !== '' && strtotime((string) $u['last_login_at']) > $nowTs - 7 * 86400);
    $emails = array_map(static fn ($u) => strtolower((string) $u['email']), $staff);
    $feed = array_slice(array_values(array_filter(AmetecsData::activity('', 400), static fn ($r) => in_array(strtolower((string) $r['who']), $emails, true) && $r['what'] !== 'Signed in')), 0, 8); ?>
  <div class="lph"><div><h1>Super Admins</h1><p class="sub">Ametecs staff who run this console: clients, licences, releases and billing for every company on Ametecs Global.</p></div>
    <button type="button" class="btn gold" onclick="document.getElementById('addsa').showModal()"><i class="fas fa-user-plus"></i>Add Super Admin</button></div>
  <section class="lsum" style="grid-template-columns:repeat(4,1fr)">
    <div class="ls split"><span class="li"><i class="fas fa-user-shield"></i></span><span><b><?= count($staff) ?></b><small>Super Admins</small></span></div>
    <div class="ls green"><span class="li"><i class="fas fa-circle-check"></i></span><span><b><?= count($active) ?></b><small>Active</small></span></div>
    <div class="ls <?= count($recent) > 0 ? 'green' : 'zero' ?>"><span class="li"><i class="fas fa-right-to-bracket"></i></span><span><b><?= count($recent) ?></b><small>Signed in, last 7 days</small></span></div>
    <div class="ls <?= count($staff) - count($active) > 0 ? 'red' : 'zero' ?>"><span class="li"><i class="fas fa-user-slash"></i></span><span><b><?= count($staff) - count($active) ?></b><small>Disabled</small></span></div>
  </section>
  <div class="pg2"><div class="pgm">
    <div class="sacards"><?php foreach ($staff as $u): $off = trim((string) ($u['disabled_at'] ?? '')) !== ''; $self = strcasecmp((string) $u['email'], $meEmail) === 0; ?>
      <article class="sac<?= $off ? ' off' : '' ?><?= $self ? ' me' : '' ?>">
        <div class="sat"><span class="saav"><?= ag_h($ini((string) ($u['name'] ?: $u['email']))) ?><i class="<?= $off ? 'off' : (($u['last_login_at'] ?? '') !== '' && strtotime((string) $u['last_login_at']) > $nowTs - 86400 ? 'on' : '') ?>"></i></span>
          <div><b><?= ag_h((string) $u['name']) ?><?= $self ? ' <span class="pill grey">you</span>' : '' ?></b><a href="mailto:<?= ag_h((string) $u['email']) ?>"><?= ag_h((string) $u['email']) ?></a></div>
          <span class="pill <?= $off ? 'red' : 'green' ?>"><?= $off ? 'Disabled' : 'Active' ?></span></div>
        <dl><div><dt>Role</dt><dd>Super Admin</dd></div><div><dt>Last sign-in</dt><dd title="<?= ag_h((string) ($u['last_login_at'] ?? '')) ?>"><?= ag_h($ago($u['last_login_at'] ?? null)) ?></dd></div><div><dt>Added</dt><dd><?= $d(substr((string) ($u['created_at'] ?? ''), 0, 10)) ?></dd></div></dl>
        <footer><?php if ($self): ?><a class="btn ghost" href="?p=account"><i class="fas fa-lock"></i>Change my password</a>
          <?php else: ?><form method="post" onsubmit="return confirm('Set a new password for <?= ag_h(addslashes((string) $u['email'])) ?>? Their current password stops working; the new one is shown once.')"><?= $csrf() ?><input type="hidden" name="do" value="staff-password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn ghost"><i class="fas fa-key"></i>New password</button></form>
            <form method="post" onsubmit="return confirm('<?= $off ? 'Enable' : 'Disable' ?> <?= ag_h(addslashes((string) $u['email'])) ?>?')"><?= $csrf() ?><input type="hidden" name="do" value="staff-toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn <?= $off ? 'navy' : 'danger' ?>"><i class="fas fa-<?= $off ? 'user-check' : 'user-slash' ?>"></i><?= $off ? 'Enable' : 'Disable' ?></button></form><?php endif; ?></footer>
      </article>
    <?php endforeach; ?>
      <button type="button" class="sac add" onclick="document.getElementById('addsa').showModal()"><i class="fas fa-plus"></i><b>Add a Super Admin</b><small>Name and email; a password is generated and shown once</small></button>
    </div>
  </div><aside class="pgs">
    <div class="box"><div class="hd"><h2><i class="fas fa-shield-halved"></i>Security</h2></div><ul class="sasec">
      <li><i class="fas fa-lock"></i><span><b>Sign-in lock</b>5 wrong passwords lock the account for 5 minutes.</span></li>
      <li><i class="fas fa-eye-slash"></i><span><b>Passwords shown once</b>New and reset passwords appear one time only; nobody can look one up later.</span></li>
      <li><i class="fas fa-user-slash"></i><span><b>Disable, never delete</b>A disabled Super Admin cannot sign in; their history stays.</span></li>
      <li><i class="fas fa-terminal"></i><span><b>Locked out?</b><code>php plugins\smartprs3\tools\make-super-admin.php email "Name"</code></span></li></ul></div>
    <div class="box"><div class="hd"><h2><i class="fas fa-clock-rotate-left"></i>Recent activity</h2><a href="?p=activity" class="more">All <i class="fas fa-arrow-right"></i></a></div>
      <?php if ($feed === []): ?><div class="empty">Nothing yet.</div><?php else: ?><ul class="feed"><?php foreach ($feed as $r): ?><li><span class="fd"></span><div><div class="ft2"><?= ag_h($r['what']) ?></div><div class="fm"><?= ag_h($r['who']) ?> &middot; <?= ag_h($ago($r['at'])) ?></div></div></li><?php endforeach; ?></ul><?php endif; ?></div>
  </aside></div>
  <dialog id="addsa" class="ldlg"><form method="post"><?= $csrf() ?><input type="hidden" name="do" value="staff-add">
    <header><h2><i class="fas fa-user-plus"></i>Add a Super Admin</h2><button type="button" class="x" onclick="this.closest('dialog').close()">&times;</button></header>
    <div class="grid2"><div class="full"><label>Full name</label><input name="name" required placeholder="e.g. Ravi Teja" autocomplete="off"></div>
      <div class="full"><label>Work email</label><input type="email" name="email" required placeholder="name@ametecsindia.com" autocomplete="off"></div>
      <div class="full"><p class="muted" style="margin:0;font-size:12.5px"><i class="fas fa-circle-info"></i> A password is generated and shown to you once. Give it to them privately; they change it under My account. They get full access to every client.</p></div></div>
    <footer><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button><button class="btn gold"><i class="fas fa-check"></i>Add Super Admin</button></footer></form></dialog>

<?php elseif ($page === 'email'): $mc = AmetecsMail::box($pdo)->config(); ?>
  <h1>Email settings</h1><p class="sub">The mailbox Ametecs Global sends from: password reset links for clients. Use your company mail server (SMTP) details.</p>
  <div class="msg <?= AmetecsMail::box($pdo)->ready() ? 'ok' : 'bad' ?>"><?= AmetecsMail::box($pdo)->ready() ? 'Email is ON.' : 'Email is OFF: "Email reset link" and "Forgot password?" will not work until this is set up and switched on.' ?></div>
  <form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="email-save">
    <div class="hd"><h2><i class="fas fa-server"></i>Mail server (SMTP)</h2></div><div class="bd grid2">
      <div class="full"><label class="sw" style="text-transform:none;font-size:14px;color:var(--ink)"><input type="checkbox" name="enabled"<?= $mc['enabled'] ? ' checked' : '' ?>> Send emails</label></div>
      <div><label>Server (host)</label><input name="host" value="<?= ag_h((string) $mc['host']) ?>" placeholder="smtp.gmail.com"></div>
      <div><label>Port</label><input type="number" name="port" value="<?= (int) $mc['port'] ?>"></div>
      <div><label>Security</label><select name="encryption"><?php foreach (['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $k => $v): ?><option value="<?= $k ?>"<?= $mc['encryption'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>Username</label><input name="username" value="<?= ag_h((string) $mc['username']) ?>" autocomplete="off"></div>
      <div><label>Password</label><input type="password" name="password" placeholder="<?= $mc['has_password'] ? 'saved - leave empty to keep' : '' ?>" autocomplete="new-password"></div>
      <div><label>From email</label><input type="email" name="from_email" value="<?= ag_h((string) $mc['from_email']) ?>" placeholder="noreply@ametecsindia.com"></div>
      <div><label>From name</label><input name="from_name" value="<?= ag_h((string) $mc['from_name'] === 'SmartPRS' ? 'Ametecs Global' : (string) $mc['from_name']) ?>"></div>
      <div><label>Reply-to (optional)</label><input type="email" name="reply_to" value="<?= ag_h((string) $mc['reply_to']) ?>"></div>
    </div><div class="ft"><button class="btn navy">Save</button></div></form>
  <form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="email-test">
    <div class="hd"><h2><i class="fas fa-paper-plane"></i>Send a test email</h2></div>
    <div class="bd row"><input type="email" name="to" required value="<?= ag_h($meEmail) ?>" placeholder="any email address" style="flex:1;min-width:240px">
      <button class="btn gold"<?= AmetecsMail::box($pdo)->ready() ? '' : ' disabled title="Save the mail server and switch on Send emails first"' ?>><i class="fas fa-paper-plane"></i>Send test</button></div>
    <?php if (!AmetecsMail::box($pdo)->ready()): ?><div class="bd muted" style="padding-top:0;font-size:12.5px">Save the mail server above with <b>Send emails</b> switched on, then send a test.</div><?php endif; ?></form>
  <p class="muted" style="font-size:12px">The password is stored encrypted. These are the same mail settings SmartPRS 3's console uses, so there is one mailbox to look after.</p>

<?php elseif ($page === 'account'): ?>
  <h1>My account</h1><p class="sub"><?= ag_h($meEmail) ?></p>
  <form method="post" class="box"><?= $csrf() ?><input type="hidden" name="do" value="my-password">
    <div class="hd"><h2><i class="fas fa-lock"></i>Change password</h2></div><div class="bd stack">
    <label>Current password</label><input type="password" name="current" required autocomplete="current-password"><br><br>
    <label>New password (<?= Session::PASSWORD_FLOOR ?>+ characters)</label><input type="password" name="new" required minlength="<?= Session::PASSWORD_FLOOR ?>" autocomplete="new-password"><br><br>
    <label>New password again</label><input type="password" name="again" required autocomplete="new-password"></div>
    <div class="ft"><button class="btn navy">Change password</button></div></form>

<?php else: ?>
  <h1>Not found</h1><p class="sub">That page or client does not exist. <a href="?p=clients">Back to clients</a></p>
<?php endif; ?>
</main></div></div>
<div class="pal" id="pal" onclick="if(event.target===this)pal(false)"><div class="pb"><input id="palq" placeholder="Type a client or page name…" autocomplete="off"><ul id="pall"></ul></div></div>
<div class="tip" id="tip"></div>
<script>
(function(){
try{if(localStorage.getItem('agMini')==='1')document.body.classList.add('mini')}catch(e){}
// count-up KPIs
document.querySelectorAll('[data-count]').forEach(function(el){var n=+el.dataset.count,t0=null;function f(t){t0=t0||t;var k=Math.min(1,(t-t0)/700);el.textContent=Math.round(n*(1-Math.pow(1-k,3))).toLocaleString('en-IN');if(k<1)requestAnimationFrame(f)}requestAnimationFrame(f)});
// bars + donut grow in
requestAnimationFrame(function(){setTimeout(function(){document.querySelectorAll('[data-h]').forEach(function(i){i.style.height=Math.max(+i.dataset.h>0?3:0,+i.dataset.h)+'%'});document.querySelectorAll('[data-w]').forEach(function(i){i.style.width=Math.max(i.dataset.w>0?3:0,+i.dataset.w)+'%'});document.querySelectorAll('[data-da]').forEach(function(c){c.setAttribute('stroke-dasharray',c.dataset.da)})},60)});
// tooltips
var tip=document.getElementById('tip');document.querySelectorAll('[data-tip]').forEach(function(el){el.addEventListener('mousemove',function(e){tip.textContent=el.dataset.tip;tip.style.opacity=1;tip.style.left=(e.clientX+14)+'px';tip.style.top=(e.clientY+14)+'px'});el.addEventListener('mouseleave',function(){tip.style.opacity=0})});
// toasts fade away
document.querySelectorAll('.toast[data-auto]').forEach(function(t){setTimeout(function(){t.style.transition='opacity .4s';t.style.opacity=0;setTimeout(function(){t.remove()},400)},5000)});
// relative times
document.querySelectorAll('time[data-at]').forEach(function(el){var d=new Date(el.dataset.at.replace(' ','T')+'+05:30'),s=(Date.now()-d)/1000;if(isNaN(s))return;el.title=el.dataset.at;
  el.textContent=s<60?'just now':s<3600?Math.floor(s/60)+' min ago':s<86400?Math.floor(s/3600)+' h ago':s<604800?Math.floor(s/86400)+' d ago':el.dataset.at.slice(0,10)});
// live filter
document.querySelectorAll('input[data-live]').forEach(function(inp){var t=document.querySelector(inp.dataset.live);if(!t)return;function go(){var q=inp.value.toLowerCase().trim();t.querySelectorAll('tr').forEach(function(r,i){if(r.querySelector('th'))return;r.style.display=!q||r.textContent.toLowerCase().indexOf(q)>=0?'':'none'})}inp.addEventListener('input',go);go()});
// sortable tables
document.querySelectorAll('table.sortable').forEach(function(t){var head=t.querySelector('tr');head.querySelectorAll('th').forEach(function(th,ix){if(!th.textContent.trim())return;th.classList.add('sort');th.addEventListener('click',function(){var asc=!th.classList.contains('asc');head.querySelectorAll('th').forEach(function(h){h.classList.remove('asc','desc')});th.classList.add(asc?'asc':'desc');
  var rows=[].slice.call(t.querySelectorAll('tr')).filter(function(r){return !r.querySelector('th')});rows.sort(function(a,b){var x=(a.children[ix]||{}).textContent||'',y=(b.children[ix]||{}).textContent||'',nx=parseFloat(x),ny=parseFloat(y);var c=!isNaN(nx)&&!isNaN(ny)?nx-ny:x.localeCompare(y,undefined,{numeric:true});return asc?c:-c});rows.forEach(function(r){r.parentNode.appendChild(r)})})})});
// command palette
var items=<?= json_encode(array_merge(
    array_map(static fn ($k, $v) => ['t' => $v[0], 'h' => '?p=' . $k, 'i' => $v[1], 's' => 'Page'], array_keys(array_merge(...array_values($navItems))), array_values(array_merge(...array_values($navItems)))),
    array_map(static fn ($t) => ['t' => (string) $t['name'], 'h' => '?p=client&id=' . (int) $t['id'], 'i' => 'fa-building', 's' => (string) ($t['code'] ?? '')], $tenants)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var P=document.getElementById('pal'),Q=document.getElementById('palq'),L=document.getElementById('pall'),sel=0,cur=[];
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function draw(){var q=Q.value.toLowerCase();cur=items.filter(function(x){return !q||(x.t+' '+x.s).toLowerCase().indexOf(q)>=0}).slice(0,12);sel=Math.min(sel,Math.max(0,cur.length-1));
  L.innerHTML=cur.map(function(x,i){return '<li'+(i===sel?' class="sel"':'')+'><a href="'+esc(x.h)+'"><i class="fas '+esc(x.i)+'"></i>'+esc(x.t)+'<small>'+esc(x.s)+'</small></a></li>'}).join('')||'<li style="padding:14px;color:#64748b">Nothing matches.</li>'}
window.pal=function(o){P.classList.toggle('open',o);if(o){Q.value='';sel=0;draw();setTimeout(function(){Q.focus()},10)}};
Q.addEventListener('input',function(){sel=0;draw()});
Q.addEventListener('keydown',function(e){if(e.key==='ArrowDown'){sel=Math.min(sel+1,cur.length-1);draw();e.preventDefault()}else if(e.key==='ArrowUp'){sel=Math.max(sel-1,0);draw();e.preventDefault()}else if(e.key==='Enter'&&cur[sel]){location.href=cur[sel].h}});
document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();pal(!P.classList.contains('open'))}else if(e.key==='Escape')pal(false)});
// close open menus on outside click
document.addEventListener('click',function(e){document.querySelectorAll('details.me[open],details.act[open]').forEach(function(d){if(!d.contains(e.target))d.removeAttribute('open')})});
})();
</script>
<script src="assets/ui.js?v=14"></script>
<?php endif; ?>
</body></html>
