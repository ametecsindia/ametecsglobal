<?php
/**
 * ONE ROLE MAP (owner, 19 Nov 2026 - unified ecosystem, Phase 2): Admin, Manager, Team Lead, Employee for every app.
 * Admin = an Ametecs Global login that is not an employee login. For people, the hierarchy SmartPRS already uses decides:
 * reporting_manager_id (Employee -> Team Lead -> Manager -> Director). Someone with direct reports is a Team Lead; someone
 * whose reports have reports of their own is a Manager. No designation is read - titles differ per company.
 */
declare(strict_types=1);

final class AmetecsRoles
{
    public const LABELS = ['admin' => 'Admin', 'manager' => 'Manager', 'team_lead' => 'Team Lead', 'employee' => 'Employee'];
    public const SELF_SERVICE = ['smartept'];   // apps with a web view of an employee's own data (SmartEPT's employee portal)

    /**
     * Who administers this client (20 Nov 2026 fix): the Administrator role, or a login that is no employee at all.
     * An employee's own login carries SmartPRS's designation role (often "viewer" or "manager") - that is NOT an
     * administrator; their level comes from the reporting line. Before this, any role other than "employee" counted as
     * an administrator, so a viewer login saw the company dashboard.
     */
    public static function isAdmin(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        return $role === 'administrator' || ((string) ($user['employee_id'] ?? '') === '' && $role !== 'employee' && $role !== 'viewer');
    }

    /** Admin for an administrator (isAdmin); otherwise the reporting line decides. */
    public static function level(\PDO $pdo, array $user): string
    {
        $emp = (string) ($user['employee_id'] ?? '');
        if (self::isAdmin($user)) {
            return 'admin';
        }
        return $emp === '' ? 'employee' : (self::levels((new \SmartPRS\Surface\SqlStore($pdo))->forTenant((string) $user['tenant_id'])->all('employees'))[$emp] ?? 'employee');
    }

    /**
     * ONE APP ON THE PHONE (Phase 4, 20 Nov 2026): the apps this login may open, by the same rules as core/open.php -
     * enabled and installed for the client, not switched off for this person (App access), and employees only where
     * the app has a self-service view. SmartPRS (payslips, leave, attendance) is always there: it is Ametecs Global's base.
     * @return list<array{app: string, name: string}>
     */
    public static function apps(\PDO $pdo, array $user): array
    {
        $tid = (string) $user['tenant_id'];
        $emp = (string) ($user['employee_id'] ?? '');
        $level = self::level($pdo, $user);
        $out = [['app' => 'smartprs3', 'name' => 'SmartPRS']];
        foreach (\AmetecsAccess::plugins() as $app => $p) {
            if ($app === 'smartprs3' || \AmetecsAccess::state($tid, $app) !== 'on'
                || !\AmetecsLicences::installed(\AmetecsLicences::row($tid, $app)) || \AmetecsLicences::maintenance($tid, $app)
                || ($level === 'employee' && !in_array($app, self::SELF_SERVICE, true))
                || ($level !== 'admin' && $emp !== '' && in_array((int) $emp, array_map('intval', \AmetecsAppAccess::hidden($pdo, $tid, $app)), true))) {
                continue;
            }
            $out[] = ['app' => (string) $app, 'name' => (string) ($p['name'] ?? $app)];
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $employees  @return array<string, string> employee id => manager|team_lead (others are employees) */
    public static function levels(array $employees): array
    {
        $kids = [];
        foreach ($employees as $e) {
            $m = (string) ($e['reporting_manager_id'] ?? '');
            $m !== '' && $m !== (string) $e['id'] && $kids[$m][] = (string) $e['id'];
        }
        $out = [];
        foreach ($kids as $m => $ks) {
            $out[$m] = array_filter($ks, static fn ($k) => isset($kids[$k])) !== [] ? 'manager' : 'team_lead';
        }
        return $out;
    }

    /** Everyone reporting to $lead, at any depth, and $lead. @return array<string, true> */
    public static function team(array $employees, string $lead): array
    {
        $kids = [];
        foreach ($employees as $e) {
            $kids[(string) ($e['reporting_manager_id'] ?? '')][] = (string) $e['id'];
        }
        $seen = [$lead => true];
        for ($todo = [$lead]; $todo !== [];) {   // ponytail: breadth walk, guarded against loops in bad data
            foreach ($kids[array_pop($todo)] ?? [] as $k) {
                if (!isset($seen[$k])) { $seen[$k] = true; $todo[] = $k; }
            }
        }
        return $seen;
    }
}
