<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\Licensing;
use App\Services\Platform\Workspaces;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMETECSGLOBAL BRIDGE (19 Nov 2026, "Connect first"). Inside Ametecs Global (plugins/rovexy) ROVEXY is one of the apps:
 *  - one sign-in: Ametecs Global signs a short-lived hand-over with the shared key core/data/ag-sso.key (core/open.php);
 *  - each Ametecs Global client is one ROVEXY workspace (companies.settings.ag_tenant), made on first open;
 *  - seats and expiry come from the Ametecs Global licence (Super Admin), written as this workspace's ROVEXY licence;
 *  - branches, teams and employees are copied one way from Ametecs Global (codes "ag-<id>"); edit them in Ametecs Global.
 */
final class AgBridge
{
    /** Ametecs Global's own folder (this plugin lives in <AG>/plugins/rovexy). */
    public static function agRoot(): string
    {
        return dirname(base_path(), 2);
    }

    /** True when this ROVEXY runs inside Ametecs Global: then every licence comes from Ametecs Global Super Admin only. */
    public static function managed(): bool
    {
        return is_file(self::agRoot() . '/core/open.php');
    }

    /**
     * THE LICENCE, LIVE FROM AMETECSGLOBAL SUPER ADMIN (ag_licences + the client's status), in the shape Licensing reads.
     * Suspend, cancel, renew or change seats in Super Admin and ROVEXY follows within a minute - console and phones alike.
     * Null when this workspace is not an Ametecs Global client, or Ametecs Global's database cannot be read just now
     * (then the copy saved at the last sign-in applies).
     */
    public static function liveLicence(Company $c): ?array
    {
        $tid = (string) $c->setting('ag_tenant');
        if ($tid === '') {
            return null;
        }
        // ponytail: 60-second cache per workspace; a Super Admin change reaches ROVEXY within a minute
        return \Illuminate\Support\Facades\Cache::remember('ag-licence:' . $c->id, 60, static function () use ($tid): ?array {
            try {
                $ag = self::agPdo();
                $q = $ag->prepare("SELECT l.status, l.seats, l.valid_until, l.plan, t.status_code FROM tenants t
                    LEFT JOIN ag_licences l ON l.tenant_id = t.id AND l.app = 'rovexy' WHERE t.id = ?");
                $q->execute([$tid]);
                $r = $q->fetch();
            } catch (\Throwable $x) {
                report($x);
                return null;
            }
            $status = match (true) {
                $r === false || $r['status'] === null || $r['status'] === 'cancelled' => 'licence_revoked',
                $r['status'] === 'suspended' || in_array((string) $r['status_code'], ['suspended', 'closed'], true) => 'licence_suspended',
                default => 'active',
            };
            return ['key' => 'AG-' . $tid, 'kind' => 'package', 'plan' => ucfirst((string) ($r['plan'] ?? '') ?: 'Ametecs Global'), 'max_users' => (int) ($r['seats'] ?? 0),
                'features' => array_keys(Licensing::FEATURES), 'c2c_users' => null, 'maps' => true, 'status' => $status,
                'expires_at' => ($r['valid_until'] ?? '') ? (new \DateTimeImmutable($r['valid_until'] . ' 23:59:59', new \DateTimeZone('Asia/Kolkata')))->format(DATE_ATOM) : null,
                'grace_days' => 0];
        });
    }

    /** The signed hand-over, or null if it is forged, old or already used. */
    public static function verify(string $token): ?array
    {
        $key = trim((string) @file_get_contents(self::agRoot() . '/core/data/ag-sso.key'));
        [$body, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if (!$key || strlen($key) < 32 || !hash_equals(hash_hmac('sha256', $body, $key), $sig)) {
            return null;
        }
        $p = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (!is_array($p) || (int) ($p['exp'] ?? 0) < time() || !\Illuminate\Support\Facades\Cache::add('agsso:' . ($p['jti'] ?? ''), 1, 300)) {
            return null;
        }
        return $p;
    }

    /**
     * ONE ADDRESS NAME (owner, 19 Nov 2026: "common slug everywhere"): the workspace uses the company's Ametecs Global
     * address name (core/c/<slug>), not "ag-<slug>". Only if another company here already has it, or it is a word the app
     * keeps for itself, does it fall back to "ag-<slug>".
     */
    private static function commonSlug(array $p, callable $taken, int $max): string
    {
        $want = trim(substr(Str::slug((string) ($p['slug'] ?: $p['tname'])), 0, $max), '-') ?: 'company-' . preg_replace('/[^0-9]/', '', (string) $p['tid']);
        $reserved = ['admin', 'api', 'login', 'logout', 'sso', 'ag', 'w', 'storage', 'assets', 'build', 'register', 'pricing', 'contact', 'home', 'core', 'plugins', 'public', 'img', 'brand', 'up'];
        foreach ([$want, 'ag-' . $want] as $s) {
            if (!in_array($s, $reserved, true) && !$taken($s)) {
                return $s;
            }
        }
        do { $s = 'ag-' . $want . '-' . random_int(10, 99); } while ($taken($s));
        return $s;
    }

    /** The client's workspace, made the first time someone from that client opens ROVEXY. */
    public static function workspace(array $p): Company
    {
        $ctx = app(CurrentCompany::class);
        $c = $ctx->withoutScope(fn () => Company::query()->get()->first(fn (Company $c) => (string) $c->setting('ag_tenant') === (string) $p['tid']));
        $taken = static fn (string $s) => Company::query()->where('slug', $s)->where('id', '!=', $c?->id ?? 0)->exists();
        if ($c !== null && str_starts_with((string) $c->slug, 'ag-') && ($s = self::commonSlug($p, $taken, 30)) !== $c->slug) {
            $c->forceFill(['slug' => $s])->save();   // made before the common name: renamed once
        }
        if ($c === null) {
            $slug = self::commonSlug($p, $taken, 30);
            $c = Workspaces::create($slug, (string) $p['tname'], (string) $p['email'], (string) $p['name'], Str::random(40));
            $c->settings = ['ag_tenant' => (string) $p['tid']] + ($c->settings ?? []);
            $c->save();
        }
        return $c;
    }

    /** Seats and expiry from Ametecs Global, saved as the fallback copy (liveLicence is what applies). Call inside the company. */
    public static function licence(Company $c, array $p): void
    {
        $status = ($p['state'] ?? 'on') === 'on' || ($p['state'] ?? '') === 'expired' ? 'active' : 'suspended';
        Licence::query()->updateOrCreate(['company_id' => $c->id, 'notes' => 'AmetecsGlobal'], [
            'key' => Licence::query()->where('company_id', $c->id)->where('notes', 'AmetecsGlobal')->value('key') ?? 'AG-' . strtoupper(Str::random(20)),
            'holder_name' => (string) $p['tname'], 'kind' => 'package', 'deployment' => 'cloud', 'status' => $status,
            'max_users' => max(0, (int) ($p['seats'] ?? 0)), 'features' => array_keys(Licensing::FEATURES), 'grace_days' => 0,
            'expires_at' => ($p['until'] ?? '') !== '' ? $p['until'] . ' 23:59:59' : null,
        ]);
        \Illuminate\Support\Facades\Cache::forget('ag-licence:' . $c->id);
        app(Licensing::class)->forget();
    }

    /** Ametecs Global's database (its .env sits in plugins/smartprs3). */
    private static function agPdo(): \PDO
    {
        $e = \Dotenv\Dotenv::parse((string) file_get_contents(self::agRoot() . '/plugins/smartprs3/.env'));
        return new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4',
            (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    /**
     * One-way copy of branches, teams and employees. $off = employee ids App access has switched off for ROVEXY.
     * Call inside the company. Returns [branches, teams, users] counts.
     * ponytail: runs at each sign-in (and `php artisan ag:sync`); a scheduled run if clients want changes without opening ROVEXY.
     */
    public static function sync(Company $c, ?\PDO $ag = null, array $off = []): array
    {
        $ag ??= self::agPdo();
        $tid = (string) $c->setting('ag_tenant');
        $rows = static function (string $sql) use ($ag, $tid): array { $q = $ag->prepare($sql); $q->execute([$tid]); return $q->fetchAll(); };

        $branch = [];
        foreach ($rows('SELECT id, name FROM branches WHERE tenant_id = ? AND deleted_at IS NULL') as $b) {
            $nm = mb_substr((string) ($b['name'] ?: 'Branch ' . $b['id']), 0, 100);
            $row = Branch::query()->firstWhere('code', 'ag-' . $b['id']) ?? Branch::query()->firstWhere('name', $nm) ?? new Branch();   // re-made in Ametecs Global (new id, same name, e.g. fresh sample data): take over the old one instead of a duplicate
            $row->forceFill(['code' => 'ag-' . $b['id'], 'name' => $nm])->save();
            $branch[$b['id']] = $row->id;
        }
        $team = [];
        foreach ($rows('SELECT id, name FROM teams WHERE tenant_id = ? AND deleted_at IS NULL') as $t) {
            $nm = mb_substr((string) ($t['name'] ?: 'Team ' . $t['id']), 0, 100);
            $row = Team::query()->firstWhere('code', 'ag-' . $t['id']) ?? Team::query()->firstWhere('name', $nm) ?? new Team();   // re-made in Ametecs Global (new id, same name, e.g. fresh sample data): take over the old one instead of a duplicate
            $row->forceFill(['code' => 'ag-' . $t['id'], 'name' => $nm])->save();
            $team[$t['id']] = $row->id;
        }
        $off = array_flip(array_map('intval', $off));
        $lic = app(Licensing::class)->current();
        $seats = (int) $lic['max_users'];
        $roleId = Role::query()->where('key', 'employee')->value('id');
        $seen = [];
        $active = User::query()->where('status', 'active')->where('employee_code', 'not like', 'ag-%')->count();   // admins and ROVEXY-only users keep their seats
        foreach ($rows('SELECT id, emp_code, name, email, mobile, status, team_id, branch_id FROM employees WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id') as $e) {
            $code = 'ag-' . $e['id'];
            $want = strtolower((string) ($e['status'] ?? 'active')) === 'active' && !isset($off[(int) $e['id']]) && ($seats === 0 || $active < $seats);
            $u = User::query()->firstWhere('employee_code', $code) ?? new User(['employee_code' => $code, 'password' => Str::random(40)]);
            $email = strtolower(trim((string) $e['email']));
            $u->fill([
                'name' => mb_substr((string) ($e['name'] ?: $e['emp_code'] ?: $code), 0, 150),
                'phone' => $e['mobile'] ? mb_substr((string) $e['mobile'], 0, 20) : null,
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) && !User::query()->where('email', $email)->whereKeyNot($u->id ?? 0)->exists() ? $email : null,
                'status' => $want ? 'active' : 'suspended',
            ])->save();
            $want && $active++;
            $u->wasRecentlyCreated && $roleId && $u->roles()->attach($roleId, ['company_id' => $c->id]);
            // member of their Ametecs Global team; supervisor rows (Team Lead / Manager, set at sign-in) are left alone
            $mine = isset($team[$e['team_id']]) ? [$team[$e['team_id']]] : [];
            DB::table('team_user')->where('user_id', $u->id)->where('membership', 'member')->whereNotIn('team_id', $mine)->delete();
            foreach ($mine as $tm) {
                DB::table('team_user')->where('user_id', $u->id)->where('team_id', $tm)->exists()
                    || DB::table('team_user')->insert(['team_id' => $tm, 'user_id' => $u->id, 'company_id' => $c->id, 'membership' => 'member']);
            }
            $seen[] = $u->id;
        }
        // Gone from Ametecs Global: switched off here too (nothing deleted - calls and history stay).
        User::query()->where('employee_code', 'like', 'ag-%')->whereNotIn('id', $seen)->update(['status' => 'left']);
        app(Licensing::class)->forget();
        return [count($branch), count($team), count($seen)];
    }

    /**
     * The Ametecs Global person signing in (ONE LOGIN, 19 Nov 2026). Ametecs Global decides the role: an Admin is a Company Admin
     * here; a Manager or Team Lead is a Supervisor of the ROVEXY teams their people (reporting line, any depth) belong to.
     * ponytail: ROVEXY scopes by team, so a lead sees everyone in those teams; per-person scope if leads share teams.
     */
    public static function admin(Company $c, array $p): User
    {
        $email = strtolower((string) $p['email']);
        $role = (string) ($p['role'] ?? 'admin');
        $emp = (string) ($p['emp'] ?? '');
        $u = ($role !== 'admin' && $emp !== '' ? User::query()->firstWhere('employee_code', 'ag-' . $emp) : null)
            ?? User::query()->firstWhere('email', $email)
            ?? User::query()->create(['employee_code' => 'agu-' . substr(md5($email), 0, 10), 'email' => $email, 'name' => (string) ($p['name'] ?: $email), 'password' => Str::random(40), 'status' => 'active']);
        $u->status !== 'active' && $u->update(['status' => 'active']);
        $rid = static fn (string $k) => Role::query()->where('key', $k)->value('id');
        if ($role === 'admin') {
            if (!$u->isAdminUser()) {
                $u->roles()->syncWithoutDetaching([$rid('company_admin') => ['company_id' => $c->id]]);
            }
        } else {
            $u->roles()->detach($rid('company_admin'));   // Ametecs Global says this person is not an administrator
            $u->roles()->syncWithoutDetaching([$rid('supervisor') => ['company_id' => $c->id]]);
            $codes = array_map(static fn ($i) => 'ag-' . $i, self::teamOf((string) $c->setting('ag_tenant'), $emp));
            $teams = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->where('team_user.company_id', $c->id)
                ->whereIn('users.employee_code', $codes)->where('team_user.membership', 'member')->distinct()->pluck('team_user.team_id')->all();
            DB::table('team_user')->where('user_id', $u->id)->where('membership', 'supervisor')->whereNotIn('team_id', $teams)->delete();
            foreach ($teams as $tm) {
                DB::table('team_user')->updateOrInsert(['team_id' => $tm, 'user_id' => $u->id], ['company_id' => $c->id, 'membership' => 'supervisor']);
            }
        }
        $u->unsetRelation('roles');
        return $u;
    }

    /** Ametecs Global employee ids reporting to $emp at any depth (the reporting line, as core/roles.php reads it). @return list<string> */
    public static function teamOf(string $tid, string $emp): array
    {
        $q = self::agPdo()->prepare('SELECT id, reporting_manager_id FROM employees WHERE tenant_id = ? AND deleted_at IS NULL');
        $q->execute([$tid]);
        $kids = [];
        foreach ($q->fetchAll() as $e) {
            $kids[(string) $e['reporting_manager_id']][] = (string) $e['id'];
        }
        $seen = [$emp => true];
        for ($todo = [$emp]; $todo !== [];) {   // guarded against loops in bad data
            foreach ($kids[array_pop($todo)] ?? [] as $k) {
                if (!isset($seen[$k])) { $seen[$k] = true; $todo[] = $k; }
            }
        }
        unset($seen[$emp]);
        return array_map('strval', array_keys($seen));
    }

    /** ONE LOGIN: the Ametecs Global session this app session came from is still the browser's (signed out there = out here). */
    public static function sameSession(?string $sid): bool
    {
        return $sid === null || hash_equals($sid, hash('sha256', (string) ($_COOKIE['AMETECSGLOBAL'] ?? '')));
    }

    /**
     * ONE LOGIN ON THE PHONE (Phase 4, 20 Nov 2026): the ROVEXY app takes the Ametecs Global sign-in - the employee code
     * (e.g. SMP005) or email, and the Ametecs Global password. Returns the Ametecs Global employee id whose ROVEXY account
     * ("ag-<id>") signs in, or null (then ROVEXY's own accounts are checked as before). Same rules as Ametecs Global's own
     * sign-in: the login must belong to this client and not be disabled. The password is only checked, never stored here.
     */
    public static function agLogin(string $who, #[\SensitiveParameter] string $password): ?string
    {
        if (!self::managed()) {
            return null;
        }
        try {
            $tid = (string) app(CurrentCompany::class)->get()->setting('ag_tenant');
            if ($tid === '') {
                return null;
            }
            $q = self::agPdo()->prepare('SELECT u.*, e.id AS emp_id FROM users u LEFT JOIN employees e ON e.id = u.employee_id AND e.tenant_id = u.tenant_id AND e.deleted_at IS NULL
                WHERE u.tenant_id = ? AND (LOWER(u.email) = LOWER(?) OR e.emp_code = ?)');
            $q->execute([$tid, trim($who), trim($who)]);
            foreach ($q->fetchAll() as $u) {
                $hash = (string) ($u['password'] ?? $u['password_hash'] ?? '');
                if ($hash !== '' && trim((string) ($u['disabled_at'] ?? '')) === '' && (string) $u['emp_id'] !== '' && password_verify($password, $hash)) {
                    return (string) $u['emp_id'];
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /** The Ametecs Global login behind a ROVEXY account made by sync ("ag-<employee id>"), or null. */
    private static function agUser(User $u): ?array
    {
        if (!self::managed() || !str_starts_with((string) $u->employee_code, 'ag-')) {
            return null;
        }
        $tid = (string) app(CurrentCompany::class)->get()->setting('ag_tenant');
        $q = self::agPdo()->prepare('SELECT * FROM users WHERE tenant_id = ? AND employee_id = ? AND disabled_at IS NULL LIMIT 1');
        $q->execute([$tid, substr((string) $u->employee_code, 3)]);
        return $q->fetch() ?: null;
    }

    /**
     * ONE APP ON THE PHONE (Phase 4, 20 Nov 2026): the Ametecs Global apps this employee may open, by the same rules as
     * Ametecs Global's own menu (core/roles.php AmetecsRoles::apps). Null when this is not an Ametecs Global workspace.
     * @return list<array{app: string, name: string}>|null
     */
    public static function apps(User $u): ?array
    {
        try {
            if (($au = self::agUser($u)) === null) {
                return null;
            }
            require_once self::agRoot() . '/core/access.php';
            $tid = (string) $au['tenant_id'];
            $apps = [['app' => 'me', 'name' => 'My space']];
            // QR attendance scanning is every employee's where QRONEX is on and not switched off for them (core/scan.php checks again)
            if (\AmetecsAccess::state($tid, 'qronex') === 'on' && \AmetecsLicences::installed(\AmetecsLicences::row($tid, 'qronex'))
                && !in_array((int) $au['employee_id'], array_map('intval', \AmetecsAppAccess::hidden(self::agPdo(), $tid, 'qronex')), true)) {
                $apps[] = ['app' => 'scan', 'name' => 'Scan QR attendance'];
            }
            $apps = array_merge($apps, \AmetecsRoles::apps(self::agPdo(), $au));
            // this phone app is ROVEXY's field app: field work is every employee's here (the web console stays for leads and admins)
            if (!in_array('rovexy', array_column($apps, 'app'), true) && !in_array((int) $au['employee_id'], array_map('intval', \AmetecsAppAccess::hidden(self::agPdo(), $tid, 'rovexy')), true)) {
                $apps[] = ['app' => 'rovexy', 'name' => 'ROVEXY'];
            }
            return $apps;
        } catch (\Throwable $x) {
            report($x);
            return null;
        }
    }

    public const WEB = ['payslips' => 'payslips', 'leave' => 'leave', 'attendance' => 'my-attendance', 'smartprs3' => 'dashboard',
        'qronex' => 'open-qronex', 'smartept' => 'open-smartept', 'me' => 'open-me', 'scan' => 'open-scan'];

    /**
     * A one-time link (2 minutes, this phone only) that signs the phone's web view in to Ametecs Global as this employee
     * and opens $what - a SmartPRS page or another app. The phone's ROVEXY approval stands in for a second approval.
     */
    public static function openLink(User $u, \App\Models\ManagedDevice $d, string $what, string $base): ?string
    {
        if (!isset(self::WEB[$what]) || ($au = self::agUser($u)) === null) {
            return null;
        }
        $app = in_array($what, ['payslips', 'leave', 'attendance', 'me'], true) ? 'smartprs3' : $what;
        if ($what === 'scan') {   // every employee may scan where QR attendance is on; core/scan.php checks licence and App access
            $app = 'smartprs3';
        }
        if (!in_array($app, array_column(self::apps($u) ?? [], 'app'), true)) {
            return null;   // switched off for this person since the home screen loaded
        }
        require_once self::agRoot() . '/core/access.php';
        $dev = 'rx-' . $d->id;
        $tok = \SmartPRS\Surface\MobileActivation::load(self::agRoot() . '/plugins/smartprs3')->mintLogin((string) $au['id'], (string) $au['tenant_id'], $dev, time());
        return rtrim($base, '/') . '/plugins/smartprs3/public/app.php?' . http_build_query(['applogin' => $tok, 'device' => $dev, 'r' => self::WEB[$what]]);
    }

    /** Today's attendance for the phone's home card, from every source Ametecs Global records (biometric, QR, PC, app). */
    public static function today(User $u): ?array
    {
        try {
            if (($au = self::agUser($u)) === null) {
                return null;
            }
            $q = self::agPdo()->prepare("SELECT MIN(CASE WHEN l.direction = 'in' THEN l.punch_at END) fi, MAX(CASE WHEN l.direction = 'out' THEN l.punch_at END) lo,
                (SELECT l2.direction FROM attendance_logs l2 WHERE l2.tenant_id = l.tenant_id AND l2.emp_code = l.emp_code AND l2.log_date = l.log_date ORDER BY l2.punch_at DESC LIMIT 1) last
                FROM attendance_logs l JOIN employees e ON e.emp_code = l.emp_code AND e.tenant_id = l.tenant_id
                WHERE l.tenant_id = ? AND e.id = ? AND l.log_date = ? GROUP BY l.tenant_id, l.emp_code, l.log_date");
            $q->execute([(string) $au['tenant_id'], (string) $au['employee_id'], now('Asia/Kolkata')->toDateString()]);
            $r = $q->fetch() ?: [];
            return ['status' => ($r['last'] ?? null) ?: 'none', 'in' => isset($r['fi']) ? substr((string) $r['fi'], 11, 5) : null, 'out' => isset($r['lo']) ? substr((string) $r['lo'], 11, 5) : null];
        } catch (\Throwable $x) {
            report($x);
            return null;
        }
    }
}
