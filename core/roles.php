<?php
/**
 * ONE ROLE MAP (owner, 19 Nov 2026 - unified ecosystem, Phase 2): Admin, Manager, Team Lead, Employee for every app.
 * Admin = an AmetecsGlobal login that is not an employee login. For people, the hierarchy SmartPRS already uses decides:
 * reporting_manager_id (Employee -> Team Lead -> Manager -> Director). Someone with direct reports is a Team Lead; someone
 * whose reports have reports of their own is a Manager. No designation is read - titles differ per company.
 */
declare(strict_types=1);

final class AmetecsRoles
{
    public const LABELS = ['admin' => 'Admin', 'manager' => 'Manager', 'team_lead' => 'Team Lead', 'employee' => 'Employee'];

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
