<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\InstallationLicense;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * AMETECSGLOBAL BRIDGE (19 Nov 2026, owner: "add SmartEPT like ROVEXY"). Inside Ametecs Global (plugins/smartept):
 *  - one sign-in: Ametecs Global signs a short-lived hand-over (core/data/ag-sso.key); this turns it into the console's own
 *    ?sso= ticket, so the console signs in exactly as it always did from SmartEPT Central;
 *  - each Ametecs Global client is one SmartEPT company (companies.external_tenant_id "ag-<client id>", AMETECS_SAAS);
 *  - the licence (on / expired / suspended, seats = PCs, end date) comes live from Ametecs Global Super Admin, written as that
 *    company's own licence row (bundle.source "ametecsglobal") - SmartEPT Central is not used;
 *  - branches, teams and employees are copied one way (employees.smartprs_employee_id "ag-<id>"); edit them in Ametecs Global;
 *  - every agent log-in / log-out punch also goes to Ametecs Global attendance (core/attendance/public/smartept.php).
 */
final class AgBridge
{
    public static function agRoot(): string
    {
        return dirname(base_path(), 2);
    }

    public static function managed(): bool
    {
        return is_file(self::agRoot() . '/core/open.php');
    }

    public static function isAg(?Company $c): bool
    {
        return $c !== null && str_starts_with((string) $c->external_tenant_id, 'ag-') && self::managed();
    }

    /** Ametecs Global's address as seen from this request: /AmetecsGlobal/plugins/smartept/public -> /AmetecsGlobal */
    public static function agUrl(string $path = ''): string
    {
        return request()->getSchemeAndHttpHost() . rtrim(dirname(request()->getBasePath(), 3), '/\\') . '/' . ltrim($path, '/');
    }

    public static function lockUrl(): string
    {
        return self::agUrl('core/?v=licences&app=smartept&lock=1#lp');
    }

    private static function agPdo(): \PDO
    {
        $e = \Dotenv\Dotenv::parse((string) file_get_contents(self::agRoot() . '/plugins/smartprs3/.env'));
        return new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4',
            (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
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
        if (!is_array($p) || (int) ($p['exp'] ?? 0) < time() || !Cache::add('agsso:' . ($p['jti'] ?? ''), 1, 300)) {
            return null;
        }
        return $p;
    }

    /**
     * THE LICENCE, LIVE FROM AMETECSGLOBAL SUPER ADMIN, written onto the company's own licence row (what every SmartEPT
     * screen, the agents and EnsureLicensed read). Re-read at most once a minute. Fail-soft: if Ametecs Global can't be
     * read, the last copy stands.
     */
    public static function licence(Company $c, bool $now = false): void
    {
        if (!self::isAg($c) || (!$now && !Cache::add('ag-licence:' . $c->id, 1, 60))) {
            return;
        }
        try {
            $q = self::agPdo()->prepare("SELECT l.status, l.seats, l.valid_until, l.plan, t.status_code FROM tenants t
                LEFT JOIN ag_licences l ON l.tenant_id = t.id AND l.app = 'smartept' WHERE t.id = ?");
            $q->execute([substr((string) $c->external_tenant_id, 3)]);
            $r = $q->fetch();
        } catch (\Throwable $x) {
            report($x);
            return;
        }
        $until = $r !== false ? (string) ($r['valid_until'] ?? '') : '';
        $status = match (true) {
            $r === false || $r['status'] === null || $r['status'] === 'cancelled' => 'revoked',
            $r['status'] === 'suspended' || in_array((string) $r['status_code'], ['suspended', 'closed'], true) => 'suspended',
            $until !== '' && $until < now('Asia/Kolkata')->toDateString() => 'expired',
            default => 'active',
        };
        InstallationLicense::forCompany($c->id)->forceFill([
            'license_key' => 'AG-' . substr((string) $c->external_tenant_id, 3) . '-SMARTEPT', 'status' => $status, 'last_checked_at' => now(),
            'bundle' => ['source' => 'ametecsglobal', 'company' => $c->name, 'plan' => 'Ametecs Global ' . ucfirst((string) ($r['plan'] ?? '')),
                'expires_at' => $until ?: null, 'grace_days' => 0, 'device_limit' => (int) ($r['seats'] ?? 0) ?: null],
            'last_error' => null, 'unreachable_since' => null, 'key_saved_at' => now(),
        ])->save();
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

    /** The client's SmartEPT company, made the first time someone from that client opens SmartEPT. */
    public static function workspace(array $p): Company
    {
        $ext = 'ag-' . preg_replace('/[^0-9]/', '', (string) $p['tid']);
        $c = Company::withoutGlobalScopes()->where('external_tenant_id', $ext)->first();
        $taken = static fn (string $s) => Company::withoutGlobalScopes()->where('slug', $s)->where('id', '!=', $c?->id ?? 0)->exists();
        if ($c !== null) {
            $c->name !== (string) $p['tname'] && $c->forceFill(['name' => (string) $p['tname']])->save();
            if (str_starts_with((string) $c->slug, 'ag-') && ($s = self::commonSlug($p, $taken, 30)) !== $c->slug) {
                $c->forceFill(['slug' => $s])->save();   // made before the common name: renamed once
            }
            return $c;
        }
        Role::query()->where('slug', 'COMPANY_ADMIN')->exists() || \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => \Database\Seeders\RolePermissionSeeder::class, '--force' => true]);
        $slug = self::commonSlug($p, $taken, 30);
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $p['tname']), 0, 6)) . preg_replace('/[^0-9]/', '', (string) $p['tid']);
        while (Company::withoutGlobalScopes()->where('code', $code)->exists()) {
            $code .= random_int(0, 9);
        }
        return Company::create(['name' => (string) $p['tname'], 'code' => $code, 'slug' => $slug, 'external_tenant_id' => $ext,
            'timezone' => 'Asia/Kolkata', 'deployment_model' => 'AMETECS_SAAS', 'status' => 'ACTIVE']);
    }

    /**
     * One-way copy of branches, teams and employees. $off = employee ids App access has switched off for SmartEPT.
     * All active employees are copied; SmartEPT itself holds the seat (PC) limit when agents activate.
     * ponytail: runs at each sign-in (after the page is sent) and `php artisan ag:sync`.
     */
    public static function sync(Company $c, array $off = []): array
    {
        $ag = self::agPdo();
        $tid = substr((string) $c->external_tenant_id, 3);
        $rows = static function (string $sql) use ($ag, $tid): array { $q = $ag->prepare($sql); $q->execute([$tid]); return $q->fetchAll(); };

        $branch = [];
        foreach ($rows('SELECT id, name, city FROM branches WHERE tenant_id = ? AND deleted_at IS NULL') as $b) {
            $nm = mb_substr((string) ($b['name'] ?: 'Branch ' . $b['id']), 0, 190);
            $q = static fn () => Branch::withoutGlobalScopes()->where('company_id', $c->id);
            $row = $q()->where('code', 'AG' . $b['id'])->first() ?? $q()->where('name', $nm)->where('code', 'like', 'AG%')->first() ?? new Branch();   // re-made in Ametecs Global (new id, same name, e.g. fresh sample data): take over the old one instead of a duplicate
            $row->forceFill(['company_id' => $c->id, 'code' => 'AG' . $b['id'], 'name' => $nm, 'city' => $b['city'] ?: null])->save();
            $branch[$b['id']] = $row->id;
        }
        $team = [];
        foreach ($rows('SELECT id, name FROM teams WHERE tenant_id = ? AND deleted_at IS NULL') as $t) {
            $nm = mb_substr((string) ($t['name'] ?: 'Team ' . $t['id']), 0, 190);
            $q = static fn () => Team::withoutGlobalScopes()->where('company_id', $c->id);
            $row = $q()->where('code', 'AG' . $t['id'])->first() ?? $q()->where('name', $nm)->where('code', 'like', 'AG%')->first() ?? new Team();   // re-made in Ametecs Global (new id, same name, e.g. fresh sample data): take over the old one instead of a duplicate
            $row->forceFill(['company_id' => $c->id, 'code' => 'AG' . $t['id'], 'name' => $nm, 'status' => 'ACTIVE'])->save();
            $team[$t['id']] = $row->id;
        }
        $off = array_flip(array_map('intval', $off));
        $seen = [];
        foreach ($rows('SELECT id, emp_code, name, email, mobile, status, team_id, branch_id FROM employees WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id') as $e) {
            $ref = 'ag-' . $e['id'];
            $emp = Employee::withoutGlobalScopes()->withTrashed()->firstOrNew(['company_id' => $c->id, 'smartprs_employee_id' => $ref]);
            $code = mb_substr(trim((string) $e['emp_code']), 0, 60) ?: $ref;
            if (Employee::withoutGlobalScopes()->withTrashed()->where('company_id', $c->id)->where('employee_code', $code)->whereKeyNot($emp->id ?? 0)->exists()) {
                $code = $ref;   // two Ametecs Global employees with one code: keep both, apart
            }
            [$first, $last] = array_pad(explode(' ', trim((string) ($e['name'] ?: $code)), 2), 2, null);
            $want = strtolower((string) ($e['status'] ?? 'active')) === 'active' && !isset($off[(int) $e['id']]);
            $emp->fill(['employee_code' => $code, 'first_name' => mb_substr($first, 0, 190), 'last_name' => $last ? mb_substr($last, 0, 190) : null,
                'email' => filter_var(trim((string) $e['email']), FILTER_VALIDATE_EMAIL) ? strtolower(trim((string) $e['email'])) : null,
                'mobile' => $e['mobile'] ? mb_substr((string) $e['mobile'], 0, 20) : null,
                'employment_status' => $want ? 'ACTIVE' : 'RELIEVED', 'branch_id' => $branch[$e['branch_id']] ?? null, 'team_id' => $team[$e['team_id']] ?? null]);
            $emp->deleted_at = null;
            $emp->save();
            $seen[] = $emp->id;
        }
        // Gone from Ametecs Global: relieved here too (screenshots, usage and history stay).
        Employee::withoutGlobalScopes()->where('company_id', $c->id)->where('smartprs_employee_id', 'like', 'ag-%')->whereNotIn('id', $seen)
            ->where('employment_status', '!=', 'RELIEVED')->update(['employment_status' => 'RELIEVED']);
        return [count($branch), count($team), count($seen)];
    }

    /**
     * The Ametecs Global person signing in (ONE LOGIN, 19 Nov 2026). Ametecs Global decides the role: Admin = Company Admin
     * (or the admin role already given here), Manager = MANAGER, Team Lead = TEAM_LEADER, Employee = EMPLOYEE (their own
     * data only). Their people come from Ametecs Global's reporting line, written as SmartEPT's own reporting links, so
     * SmartEPT's HierarchyService scopes them exactly as it scopes its own managers.
     */
    public static function admin(Company $c, array $p): User
    {
        $email = strtolower((string) $p['email']);
        $level = (string) ($p['role'] ?? 'admin');
        $emp = (string) ($p['emp'] ?? '');
        $u = User::query()->firstWhere('email', $email);
        if ($u !== null && $u->company_id !== null && (int) $u->company_id !== (int) $c->id) {
            $email = 'ag' . substr((string) $c->external_tenant_id, 3) . '+' . $email;   // one login per client company
            $u = User::query()->firstWhere('email', $email);
        }
        $u ??= new User(['email' => $email, 'password' => Str::password(32)]);
        $slug = ['manager' => 'MANAGER', 'team_lead' => 'TEAM_LEADER', 'employee' => 'EMPLOYEE'][$level] ?? null;
        $admins = Role::query()->whereIn('slug', ['SUPER_ADMIN', 'COMPANY_ADMIN'])->pluck('id')->all();
        $role = $slug !== null ? Role::query()->where('slug', $slug)->value('id')
            : ($u->exists && in_array($u->role_id, $admins) ? $u->role_id : Role::query()->where('slug', 'COMPANY_ADMIN')->value('id'));
        $u->forceFill(['company_id' => $c->id, 'role_id' => $role, 'name' => (string) ($p['name'] ?: $email), 'status' => 'ACTIVE', 'must_change_password' => false])->save();
        if ($emp !== '') {
            self::linkPeople($c, $u, $emp, $slug !== null && $slug !== 'EMPLOYEE');
        }
        return $u;
    }

    /** The person's own employee row is theirs; a lead's people (any depth) report up to a SmartEPT login through them. */
    private static function linkPeople(Company $c, User $u, string $emp, bool $lead): void
    {
        $rows = Employee::withoutGlobalScopes()->where('company_id', $c->id)->where('smartprs_employee_id', 'like', 'ag-%');
        (clone $rows)->where('smartprs_employee_id', 'ag-' . $emp)->update(['user_id' => $u->id]);
        if (!$lead) {
            return;
        }
        $q = self::agPdo()->prepare('SELECT id, reporting_manager_id FROM employees WHERE tenant_id = ? AND deleted_at IS NULL');
        $q->execute([substr((string) $c->external_tenant_id, 3)]);
        $kids = [];
        $boss = [];
        foreach ($q->fetchAll() as $e) {
            $kids[(string) $e['reporting_manager_id']][] = (string) $e['id'];
            $boss[(string) $e['id']] = (string) $e['reporting_manager_id'];
        }
        $seen = [$emp => true];
        for ($todo = [$emp]; $todo !== [];) {   // guarded against loops in bad data
            foreach ($kids[array_pop($todo)] ?? [] as $k) {
                if (!isset($seen[$k])) { $seen[$k] = true; $todo[] = $k; }
            }
        }
        unset($seen[$emp]);
        $login = (clone $rows)->whereNotNull('user_id')->pluck('user_id', 'smartprs_employee_id')->all();   // 'ag-<id>' => user id
        foreach (array_keys($seen) as $id) {
            // reports to this lead, or to someone under them who has never signed in to SmartEPT (then this lead is the
            // nearest login above them); someone who has signed in keeps their own people
            $over = $boss[(string) $id] ?? '';
            $to = $over === $emp ? $u->id : ($login['ag-' . $over] ?? $u->id);
            (clone $rows)->where('smartprs_employee_id', 'ag-' . $id)->update(['reporting_manager_user_id' => $to]);
        }
    }

    /** The console's own SSO ticket (the one SmartEPT Central used to send), signed with this server's SSO secret. */
    public static function ticket(User $u): string
    {
        $secret = (string) config('services.sso.secret');
        $body = rtrim(strtr(base64_encode((string) json_encode(['email' => $u->email, 'exp' => time() + 60])), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, $secret);
    }

    /** An agent log-in / log-out: into Ametecs Global attendance too, after the agent has its answer. */
    public static function punch(Company $c, $employee, string $type, \DateTimeInterface $at, string $deviceUuid): void
    {
        if (!self::isAg($c) || app()->runningInConsole()) {
            return;
        }
        $body = (string) json_encode(['tenant' => substr((string) $c->external_tenant_id, 3), 'event' => 'attendance.punch', 'payload' => [
            'company_id' => $c->id, 'device_id' => 'SMARTEPT-' . substr($deviceUuid, 0, 12), 'employee_code' => $employee->employee_code ?? null,
            'biometric_employee_id' => $employee->employee_code ?? null, 'punch_type' => $type, 'punched_at' => $at->format(\DateTimeInterface::ATOM),
            'verification_mode' => 'SYSTEM']]);
        $sig = hash_hmac('sha256', $body, trim((string) @file_get_contents(self::agRoot() . '/core/data/ag-sso.key')));
        $url = self::agUrl('core/attendance/public/smartept.php');
        app()->terminating(static function () use ($url, $body, $sig): void {
            rescue(static fn () => \Illuminate\Support\Facades\Http::timeout(5)->withHeaders(['X-AG-Signature' => $sig])->withBody($body, 'application/json')->post($url), null, false);
        });
    }

    /** ONE LOGIN: the Ametecs Global session the console came from is still the browser's (signed out there = out here). */
    public static function sameSession(?string $sid): bool
    {
        return $sid === null || hash_equals($sid, hash('sha256', (string) ($_COOKIE['AMETECSGLOBAL'] ?? '')));
    }
}
