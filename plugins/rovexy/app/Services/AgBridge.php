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

    /** The client's workspace, made the first time someone from that client opens ROVEXY. */
    public static function workspace(array $p): Company
    {
        $ctx = app(CurrentCompany::class);
        $c = $ctx->withoutScope(fn () => Company::query()->get()->first(fn (Company $c) => (string) $c->setting('ag_tenant') === (string) $p['tid']));
        if ($c === null) {
            $slug = 'ag-' . trim(substr(Str::slug((string) ($p['slug'] ?: $p['tname'])), 0, 30), '-');
            while (Company::query()->where('slug', $slug)->exists()) {
                $slug = substr($slug, 0, 34) . '-' . random_int(10, 99);
            }
            $c = Workspaces::create($slug, (string) $p['tname'], (string) $p['email'], (string) $p['name'], Str::random(40));
            $c->settings = ['ag_tenant' => (string) $p['tid']] + ($c->settings ?? []);
            $c->save();
        }
        return $c;
    }

    /** Seats and expiry from AmetecsGlobal. Call inside the company. */
    public static function licence(Company $c, array $p): void
    {
        $status = ($p['state'] ?? 'on') === 'on' || ($p['state'] ?? '') === 'expired' ? 'active' : 'suspended';
        Licence::query()->updateOrCreate(['company_id' => $c->id, 'notes' => 'AmetecsGlobal'], [
            'key' => Licence::query()->where('company_id', $c->id)->where('notes', 'AmetecsGlobal')->value('key') ?? 'AG-' . strtoupper(Str::random(20)),
            'holder_name' => (string) $p['tname'], 'kind' => 'package', 'deployment' => 'cloud', 'status' => $status,
            'max_users' => max(0, (int) ($p['seats'] ?? 0)), 'features' => array_keys(Licensing::FEATURES), 'grace_days' => 0,
            'expires_at' => ($p['until'] ?? '') !== '' ? $p['until'] . ' 23:59:59' : null,
        ]);
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
            $u->teams()->sync(isset($team[$e['team_id']]) ? [$team[$e['team_id']] => ['company_id' => $c->id]] : []);
            $seen[] = $u->id;
        }
        // Gone from AmetecsGlobal: switched off here too (nothing deleted - calls and history stay).
        User::query()->where('employee_code', 'like', 'ag-%')->whereNotIn('id', $seen)->update(['status' => 'left']);
        app(Licensing::class)->forget();
        return [count($branch), count($team), count($seen)];
    }

    /** The AmetecsGlobal user signing in: their ROVEXY login (matched by email), an administrator. */
    public static function admin(Company $c, array $p): User
    {
        $email = strtolower((string) $p['email']);
        $u = User::query()->firstWhere('email', $email)
            ?? User::query()->create(['employee_code' => 'agu-' . substr(md5($email), 0, 10), 'email' => $email, 'name' => (string) ($p['name'] ?: $email), 'password' => Str::random(40), 'status' => 'active']);
        $u->status !== 'active' && $u->update(['status' => 'active']);
        if (!$u->isAdminUser()) {
            $u->roles()->syncWithoutDetaching([Role::query()->where('key', 'company_admin')->value('id') => ['company_id' => $c->id]]);
            $u->unsetRelation('roles');
        }
        return $u;
    }
}
