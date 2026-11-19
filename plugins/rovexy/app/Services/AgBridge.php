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
 * AMETECSGLOBAL BRIDGE (19 Nov 2026, "Connect first"). Inside AmetecsGlobal (plugins/rovexy) ROVEXY is one of the apps:
 *  - one sign-in: AmetecsGlobal signs a short-lived hand-over with the shared key core/data/ag-sso.key (core/open.php);
 *  - each AmetecsGlobal client is one ROVEXY workspace (companies.settings.ag_tenant), made on first open;
 *  - seats and expiry come from the AmetecsGlobal licence (Super Admin), written as this workspace's ROVEXY licence;
 *  - branches, teams and employees are copied one way from AmetecsGlobal (codes "ag-<id>"); edit them in AmetecsGlobal.
 */
final class AgBridge
{
    /** AmetecsGlobal's own folder (this plugin lives in <AG>/plugins/rovexy). */
    public static function agRoot(): string
    {
        return dirname(base_path(), 2);
    }

    /** True when this ROVEXY runs inside AmetecsGlobal: then every licence comes from AmetecsGlobal Super Admin only. */
    public static function managed(): bool
    {
        return is_file(self::agRoot() . '/core/open.php');
    }

    /**
     * THE LICENCE, LIVE FROM AMETECSGLOBAL SUPER ADMIN (ag_licences + the client's status), in the shape Licensing reads.
     * Suspend, cancel, renew or change seats in Super Admin and ROVEXY follows within a minute - console and phones alike.
     * Null when this workspace is not an AmetecsGlobal client, or AmetecsGlobal's database cannot be read just now
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
            return ['key' => 'AG-' . $tid, 'kind' => 'package', 'plan' => ucfirst((string) ($r['plan'] ?? '') ?: 'AmetecsGlobal'), 'max_users' => (int) ($r['seats'] ?? 0),
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
     * ONE ADDRESS NAME (owner, 19 Nov 2026: "common slug everywhere"): the workspace uses the company's AmetecsGlobal
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

    /** Seats and expiry from AmetecsGlobal, saved as the fallback copy (liveLicence is what applies). Call inside the company. */
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

    /** AmetecsGlobal's database (its .env sits in plugins/smartprs3). */
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
            $branch[$b['id']] = Branch::query()->updateOrCreate(['code' => 'ag-' . $b['id']], ['name' => mb_substr((string) ($b['name'] ?: 'Branch ' . $b['id']), 0, 100)])->id;
        }
        $team = [];
        foreach ($rows('SELECT id, name FROM teams WHERE tenant_id = ? AND deleted_at IS NULL') as $t) {
            $team[$t['id']] = Team::query()->updateOrCreate(['code' => 'ag-' . $t['id']], ['name' => mb_substr((string) ($t['name'] ?: 'Team ' . $t['id']), 0, 100)])->id;
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
            // member of their AmetecsGlobal team; supervisor rows (Team Lead / Manager, set at sign-in) are left alone
            $mine = isset($team[$e['team_id']]) ? [$team[$e['team_id']]] : [];
            DB::table('team_user')->where('user_id', $u->id)->where('membership', 'member')->whereNotIn('team_id', $mine)->delete();
            foreach ($mine as $tm) {
                DB::table('team_user')->where('user_id', $u->id)->where('team_id', $tm)->exists()
                    || DB::table('team_user')->insert(['team_id' => $tm, 'user_id' => $u->id, 'company_id' => $c->id, 'membership' => 'member']);
            }
            $seen[] = $u->id;
        }
        // Gone from AmetecsGlobal: switched off here too (nothing deleted - calls and history stay).
        User::query()->where('employee_code', 'like', 'ag-%')->whereNotIn('id', $seen)->update(['status' => 'left']);
        app(Licensing::class)->forget();
        return [count($branch), count($team), count($seen)];
    }

    /**
     * The AmetecsGlobal person signing in (ONE LOGIN, 19 Nov 2026). AmetecsGlobal decides the role: an Admin is a Company Admin
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
            $u->roles()->detach($rid('company_admin'));   // AmetecsGlobal says this person is not an administrator
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

    /** AmetecsGlobal employee ids reporting to $emp at any depth (the reporting line, as core/roles.php reads it). @return list<string> */
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

    /** ONE LOGIN: the AmetecsGlobal session this app session came from is still the browser's (signed out there = out here). */
    public static function sameSession(?string $sid): bool
    {
        return $sid === null || hash_equals($sid, hash('sha256', (string) ($_COOKIE['AMETECSGLOBAL'] ?? '')));
    }
}
