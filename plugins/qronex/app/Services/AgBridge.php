<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\Office;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * AMETECSGLOBAL BRIDGE (19 Nov 2026, owner: "add QRONEX like ROVEXY"). Inside Ametecs Global (plugins/qronex):
 *  - one sign-in: Ametecs Global signs a short-lived hand-over with core/data/ag-sso.key (core/open.php?app=qronex);
 *  - each Ametecs Global client is one QRONEX company (tenants.ag_tenant), made on first open;
 *  - the licence (on / expired / suspended, seats, end date) is read live from Ametecs Global Super Admin;
 *  - branches become offices and employees are copied one way (hrms_ref "ag-<id>"); edit them in Ametecs Global;
 *  - verified punches go into Ametecs Global attendance (core/attendance/public/qronex.php pulls them).
 */
final class AgBridge
{
    /** Ametecs Global's own folder (this plugin lives in <AG>/plugins/qronex). */
    public static function agRoot(): string
    {
        return dirname(base_path(), 2);
    }

    public static function managed(): bool
    {
        return is_file(self::agRoot() . '/core/open.php');
    }

    /** Ametecs Global's address as seen from this request: /AmetecsGlobal/plugins/qronex/public -> /AmetecsGlobal */
    public static function agUrl(string $path = ''): string
    {
        $base = rtrim(dirname(request()->getBasePath(), 3), '/\\');
        return request()->getSchemeAndHttpHost() . $base . '/' . ltrim($path, '/');
    }

    /** The licence page in Ametecs Global where a locked QRONEX is renewed (licence code, .lic file or payment). */
    public static function lockUrl(): string
    {
        return self::agUrl('core/?v=licences&app=qronex&lock=1#lp');
    }

    private static function agPdo(): \PDO
    {
        $e = \Dotenv\Dotenv::parse((string) file_get_contents(self::agRoot() . '/plugins/smartprs3/.env'));
        return new \PDO('mysql:host=' . ($e['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($e['DB_PORT'] ?? '3306') . ';dbname=' . ($e['DB_DATABASE'] ?? '') . ';charset=utf8mb4',
            (string) ($e['DB_USERNAME'] ?? ''), (string) ($e['DB_PASSWORD'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    /**
     * THE LICENCE, LIVE FROM AMETECSGLOBAL SUPER ADMIN: ['state' => on|expired|suspended|off, 'seats' => n, 'until' => Y-m-d|null].
     * Null when this company is not an Ametecs Global client or Ametecs Global's database can't be read (then QRONEX's own status applies).
     */
    public static function live(Tenant $t): ?array
    {
        if (!self::managed() || (string) $t->ag_tenant === '') {
            return null;
        }
        // ponytail: 60-second cache per company; a Super Admin change reaches QRONEX within a minute
        return Cache::remember('ag-licence:' . $t->id, 60, static function () use ($t): ?array {
            try {
                $q = self::agPdo()->prepare("SELECT l.status, l.seats, l.valid_until, t.status_code FROM tenants t
                    LEFT JOIN ag_licences l ON l.tenant_id = t.id AND l.app = 'qronex' WHERE t.id = ?");
                $q->execute([(string) $t->ag_tenant]);
                $r = $q->fetch();
            } catch (\Throwable $x) {
                report($x);
                return null;
            }
            $until = $r !== false ? (string) ($r['valid_until'] ?? '') : '';
            $state = match (true) {
                $r === false || $r['status'] === null || $r['status'] === 'cancelled' => 'off',
                $r['status'] === 'suspended' || in_array((string) $r['status_code'], ['suspended', 'closed'], true) => 'suspended',
                $until !== '' && $until < now('Asia/Kolkata')->toDateString() => 'expired',
                default => 'on',
            };
            return ['state' => $state, 'seats' => (int) ($r['seats'] ?? 0), 'until' => $until ?: null];
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
        if (!is_array($p) || (int) ($p['exp'] ?? 0) < time() || !Cache::add('agsso:' . ($p['jti'] ?? ''), 1, 300)) {
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

    /** The client's QRONEX company, made the first time someone from that client opens QRONEX. */
    public static function workspace(array $p): Tenant
    {
        $t = Tenant::query()->where('ag_tenant', (string) $p['tid'])->first();
        $taken = static fn (string $s) => in_array($s, Tenant::RESERVED_SLUGS, true) || Tenant::withTrashed()->where('slug', $s)->where('id', '!=', $t?->id ?? 0)->exists();
        if ($t !== null) {
            $t->name !== (string) $p['tname'] && $t->update(['name' => (string) $p['tname']]);
            if (str_starts_with((string) $t->slug, 'ag-') && ($s = self::commonSlug($p, $taken, 40)) !== $t->slug) {
                $t->forceFill(['slug' => $s])->save();   // made before the common name: renamed once
            }
            return $t;
        }
        Plan::query()->exists() || \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => \Database\Seeders\PlatformSeeder::class, '--force' => true]);
        $slug = self::commonSlug($p, $taken, 40);
        $t = Tenant::create(['name' => (string) $p['tname'], 'slug' => $slug, 'status' => 'active', 'customer_type' => 'independent', 'ag_tenant' => (string) $p['tid'],
            'commercial_note' => 'Ametecs Global client #' . $p['tid'] . ' - licence managed in Ametecs Global Super Admin.']);
        $t->settings()->create(TenantSetting::defaults());
        $t->subscriptions()->create(['plan_version_id' => Plan::query()->orderByDesc('max_employees')->first()->currentVersion->id, 'billing_cycle' => 'annual',
            'employee_count' => max(1, (int) ($p['seats'] ?? 1)), 'status' => 'active', 'arrangement' => 'custom',
            'current_period_start' => now()->toDateString(), 'current_period_end' => ($p['until'] ?? '') ?: null]);
        return $t;
    }

    /** Seats and end date from Ametecs Global onto QRONEX's own subscription and company status (what its screens show). */
    public static function licence(Tenant $t): void
    {
        Cache::forget('ag-licence:' . $t->id);
        $l = self::live($t);
        if ($l === null) {
            return;
        }
        $t->update(['status' => $l['state'] === 'on' ? 'active' : 'suspended']);
        $t->subscription?->update(['employee_count' => max(1, $l['seats']), 'status' => $l['state'] === 'on' ? 'active' : 'suspended', 'current_period_end' => $l['until']]);
    }

    /**
     * One-way copy: branches -> offices (code "AG<id>"), employees -> employees (hrms_ref "ag-<id>", code = Ametecs Global's code).
     * $off = employee ids App access has switched off for QRONEX. Seats beyond the licence are left inactive.
     * Location, geofence and gates are QRONEX's own and stay as set here. Returns [offices, employees].
     * ponytail: runs at each sign-in (and `php artisan ag:sync`); a schedule if clients want changes without opening QRONEX.
     */
    public static function sync(Tenant $t, array $off = []): array
    {
        $ag = self::agPdo();
        $rows = static function (string $sql) use ($ag, $t): array { $q = $ag->prepare($sql); $q->execute([(string) $t->ag_tenant]); return $q->fetchAll(); };

        $office = [];
        foreach ($rows('SELECT id, name, city, lat, lng FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id') as $b) {
            $o = Office::withoutGlobalScopes()->firstOrNew(['tenant_id' => $t->id, 'code' => 'AG' . $b['id']]);
            if (!$o->exists) {   // re-made in Ametecs Global (new id, same name, e.g. fresh sample data): take over the old one instead of a duplicate (its gates and history stay)
                $old = Office::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', (string) $b['name'])->where('code', 'like', 'AG%')->first();
                $old !== null && ($o = $old)->forceFill(['code' => 'AG' . $b['id']]);
            }
            $o->fill(['name' => mb_substr((string) ($b['name'] ?: 'Branch ' . $b['id']), 0, 190), 'city' => $b['city'] ?: $o->city]);
            if (!$o->exists) {   // first copy: Ametecs Global's rough location, if any; set the exact spot and gates in QRONEX
                $o->fill(['slug' => 'ag' . $b['id'], 'lat' => (float) ($b['lat'] ?? 0), 'lng' => (float) ($b['lng'] ?? 0), 'geofence_radius_m' => 150]);
            }
            $o->save();
            $o->wasRecentlyCreated && $o->gates()->create(['tenant_id' => $t->id, 'code' => 'gate1', 'name' => 'Main Entrance']);
            $office[$b['id']] = $o->id;
        }

        $off = array_flip(array_map('intval', $off));
        $seats = self::live($t)['seats'] ?? 0;
        $active = 0;
        $seen = [];
        foreach ($rows('SELECT id, emp_code, name, email, mobile, status, branch_id FROM employees WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id') as $e) {
            $ref = 'ag-' . $e['id'];
            $emp = Employee::withoutGlobalScopes()->firstOrNew(['tenant_id' => $t->id, 'hrms_ref' => $ref]);
            $code = mb_substr(trim((string) $e['emp_code']), 0, 40) ?: $ref;
            if (Employee::withoutGlobalScopes()->where('tenant_id', $t->id)->where('employee_code', $code)->whereKeyNot($emp->id ?? 0)->exists()) {
                $code = $ref;   // two Ametecs Global employees with one code: keep both, apart
            }
            $want = strtolower((string) ($e['status'] ?? 'active')) === 'active' && !isset($off[(int) $e['id']]) && ($seats === 0 || $active < $seats);
            $emp->fill([
                'employee_code' => $code, 'name' => mb_substr((string) ($e['name'] ?: $code), 0, 190),
                'email' => filter_var(trim((string) $e['email']), FILTER_VALIDATE_EMAIL) ? strtolower(trim((string) $e['email'])) : null,
                'mobile' => $e['mobile'] ? mb_substr((string) $e['mobile'], 0, 20) : null, 'status' => $want ? 'active' : 'inactive',
            ]);
            $emp->office_id ??= $office[$e['branch_id']] ?? null;
            $emp->save();
            $want && $active++;
            $seen[] = $emp->id;
        }
        // Gone from Ametecs Global: inactive here too, phones revoked (attendance history stays).
        $gone = Employee::withoutGlobalScopes()->where('tenant_id', $t->id)->where('hrms_ref', 'like', 'ag-%')->whereNotIn('id', $seen)->pluck('id');
        Employee::withoutGlobalScopes()->whereIn('id', $gone)->update(['status' => 'inactive']);
        \App\Models\Device::withoutGlobalScopes()->whereIn('employee_id', $gone)->whereIn('status', ['pending', 'approved'])->update(['status' => 'revoked']);
        return [count($office), count($seen)];
    }

    /** The Ametecs Global administrator signing in: their QRONEX login (by email), the company owner role. */
    public static function admin(Tenant $t, array $p): User
    {
        $email = strtolower((string) $p['email']);
        $role = (string) ($p['role'] ?? 'admin');
        $u = User::query()->firstWhere('email', $email);
        if ($u !== null && (int) $u->tenant_id !== (int) $t->id) {
            $email = 'ag' . $t->ag_tenant . '+' . $email;   // the same person in two client companies: one login each
            $u = User::query()->firstWhere('email', $email);
        }
        $u ??= new User(['email' => $email, 'password' => Str::random(40)]);
        // ONE ROLE MAP (19 Nov 2026): Ametecs Global decides. Admin = Company Owner (or the admin role already given here);
        // Manager / Team Lead = Office Manager of the offices their people (reporting line, any depth) work at.
        // ponytail: QRONEX scopes by office, so a lead sees everyone at those offices; per-person scope if that is too wide.
        $keep = $u->exists && in_array($u->role, ['company_owner', 'company_admin'], true) ? $u->role : 'company_owner';
        $u->forceFill(['tenant_id' => $t->id, 'name' => (string) ($p['name'] ?: $email), 'role' => $role === 'admin' ? $keep : 'office_manager', 'is_active' => true, 'last_login_at' => now()])->save();
        if ($role !== 'admin') {
            $refs = array_map(static fn ($i) => 'ag-' . $i, self::teamOf((string) $t->ag_tenant, (string) ($p['emp'] ?? '')));
            $u->offices()->sync(Employee::withoutGlobalScopes()->where('tenant_id', $t->id)->whereIn('hrms_ref', $refs)->whereNotNull('office_id')->distinct()->pluck('office_id')->all());
        }
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

    /** Tell Ametecs Global a punch was recorded: it pulls new punches into its attendance (never blocks the phone). */
    public static function pingPunches(Tenant $t): void
    {
        if (!self::managed() || (string) $t->ag_tenant === '' || app()->runningInConsole()) {
            return;
        }
        $url = self::agUrl('core/attendance/public/qronex.php?tenant=' . rawurlencode((string) $t->ag_tenant));
        app()->terminating(static function () use ($url): void {
            rescue(static fn () => \Illuminate\Support\Facades\Http::timeout(5)->get($url), null, false);
        });
    }
}
