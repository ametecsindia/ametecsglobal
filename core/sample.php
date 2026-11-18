<?php
/**
 * SAMPLE DATA (owner, 18 Nov 2026: "add sample data 20 employee and 10 client on super admin").
 * Super Admin -> System & health -> Sample data. Adds, on THIS PC's database only (never through a migration, so it
 * cannot reach a live server by sync):
 *   - 20 employees in one client (default: the house client), with branches / departments / teams if it has none,
 *     and 30 days of punches (some late, a few absent) so every dashboard chart has something to show;
 *   - 10 demo clients (codes DEMO01..DEMO10) with mixed cloud / on-premise licences, plans and prices, and paid
 *     invoices spread over the last 6 months for the revenue chart.
 * Everything added is listed in core/data/sample.json, and "Remove sample data" takes exactly that away again.
 */
declare(strict_types=1);

final class AmetecsSample
{
    private const FILE = __DIR__ . '/data/sample.json';

    public static function present(): array
    {
        $v = is_file(self::FILE) ? json_decode((string) file_get_contents(self::FILE), true) : null;
        return is_array($v) ? $v : [];
    }

    private static function remember(array $log): void
    {
        @mkdir(dirname(self::FILE), 0775, true);
        file_put_contents(self::FILE, json_encode($log, JSON_PRETTY_PRINT));
    }

    /** @return string what was added */
    public static function add(\PDO $pdo, \SmartPRS\Surface\SqlStore $platform, string $tenant, string $actor): string
    {
        $log = self::present() + ['rows' => [], 'tenants' => [], 'licences' => [], 'invoices' => []];
        $keep = static function (string $table, array $row) use (&$log): array { $log['rows'][] = [$table, (string) $row['id']]; return $row; };
        $store = $platform->forTenant($tenant);
        $done = [];

        // --- 20 employees ------------------------------------------------------------------------------------------
        $have = static fn (string $t) => array_values(array_filter($store->all($t), static fn ($r) => ($r['deleted_at'] ?? null) === null));
        $companies = $have('companies');
        $company = (string) ($companies[0]['id'] ?? $keep('companies', $store->put('companies', ['name' => 'Sample Company', 'timezone' => 'Asia/Kolkata']))['id']);
        $mk = static function (string $table, array $names) use ($have, $store, $keep, $company): array {
            $ids = array_column($have($table), 'id', 'name');
            foreach ($names as $n) {
                $ids[$n] ??= $keep($table, $store->put($table, ['name' => $n] + ($table === 'branches' || $table === 'departments' || $table === 'teams' ? ['employer_company_id' => $company] : [])))['id'];
            }
            return array_map('strval', $ids);
        };
        try { $branches = $mk('branches', ['Hyderabad - Kondapur', 'Bengaluru']); } catch (\Throwable) { $branches = []; }
        try { $depts = $mk('departments', ['Collections', 'Operations', 'Sales', 'HR & Admin']); } catch (\Throwable) { $depts = []; }
        try { $teams = $mk('teams', ['Team Alpha', 'Team Beta']); } catch (\Throwable) { $teams = []; }
        $people = [['Aarav Sharma', 'M'], ['Priya Reddy', 'F'], ['Mohammed Irfan', 'M'], ['Sneha Patil', 'F'], ['Rahul Verma', 'M'], ['Ananya Iyer', 'F'], ['Vikram Singh', 'M'],
            ['Fatima Khan', 'F'], ['Karthik Nair', 'M'], ['Divya Menon', 'F'], ['Arjun Kapoor', 'M'], ['Lakshmi Rao', 'F'], ['Sameer Ali', 'M'], ['Pooja Gupta', 'F'],
            ['Naveen Kumar', 'M'], ['Meera Joshi', 'F'], ['Rohit Das', 'M'], ['Kavya Shetty', 'F'], ['Imran Sheikh', 'M'], ['Swathi Varma', 'F']];
        $today = AmetecsAccess::today();
        $used = array_flip(array_map(static fn ($e) => (string) ($e['emp_code'] ?? ''), $store->all('employees')));
        $codes = [];
        foreach ($people as $i => [$name, $g]) {
            $code = 'SMP' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            if (isset($used[$code])) { continue; }
            $doj = date('Y-m-d', strtotime($today . ' -' . (15 + $i * 47) . ' days'));
            $dob = date('Y-m-d', strtotime((1985 + $i % 14) . '-' . substr($today, 5, 5) . ' +' . ($i * 3 - 6) . ' days'));   // a few birthdays this month
            $keep('employees', $store->put('employees', ['emp_code' => $code, 'name' => $name, 'gender' => $g === 'M' ? 'Male' : 'Female', 'dob' => $dob, 'doj' => $doj,
                'type' => $i % 3 === 0 ? 'Office' : 'Field', 'employer_company_id' => $company, 'status' => 'active',
                'branch_id' => $branches === [] ? null : array_values($branches)[$i % count($branches)],
                'department_id' => $depts === [] ? null : array_values($depts)[$i % count($depts)],
                'team_id' => $teams === [] ? null : array_values($teams)[$i % count($teams)],
                'email' => strtolower(str_replace(' ', '.', $name)) . '@sample.test', 'mobile' => '9' . str_pad((string) (100000000 + $i * 7919), 9, '0'),
                'probation_end' => $i === 3 ? date('Y-m-d', strtotime($today . ' +12 days')) : null]));
            $codes[] = $code;
        }
        // 30 days of punches: weekdays, most on time, some late, a few absent
        foreach ($codes as $n => $code) {
            for ($d = 29; $d >= 0; $d--) {
                $day = date('Y-m-d', strtotime($today . " -{$d} days"));
                if ((int) date('N', strtotime($day)) >= 7 || (($n * 7 + $d) % 13 === 0)) { continue; }   // Sundays off, ~1 in 13 absent
                $late = ($n + $d) % 6 === 0 ? 25 + ($n % 4) * 10 : ($n % 5);
                $in = $day . ' ' . date('H:i:s', strtotime('09:00 +' . $late . ' minutes'));
                $keep('attendance_logs', $store->put('attendance_logs', ['emp_code' => $code, 'log_date' => $day, 'punch_at' => $in, 'direction' => 'in', 'source' => 'sample']));
                if ($d > 0 || (int) date('G') >= 18) {
                    $out = $day . ' ' . (($n + $d) % 9 === 0 ? '16:40:00' : '18:' . str_pad((string) (5 + $n % 40), 2, '0', STR_PAD_LEFT) . ':00');
                    $keep('attendance_logs', $store->put('attendance_logs', ['emp_code' => $code, 'log_date' => $day, 'punch_at' => $out, 'direction' => 'out', 'source' => 'sample']));
                }
            }
        }
        $done[] = count($codes) . ' employees with 30 days of attendance';

        // --- 10 demo clients ----------------------------------------------------------------------------------------
        $names = ['Sunrise Recoveries', 'Apex Collections', 'BlueOcean Finserv', 'Trident Agencies', 'Nova Credit Services', 'Pinnacle Debt Solutions',
            'Metro Field Force', 'Zenith Telecollect', 'Greenline Microfinance', 'Royal Business Services'];
        $apps = array_keys(AmetecsAccess::plugins());
        $made = 0;
        foreach ($names as $i => $name) {
            $code = 'DEMO' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $q = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE code = ?');
            $q->execute([$code]);
            if ((int) $q->fetchColumn() > 0) { continue; }
            $t = $platform->put('tenants', ['code' => $code, 'name' => $name, 'status_code' => $i === 8 ? 'suspended' : 'active', 'business_timezone' => 'Asia/Kolkata',
                'subdomain' => 'demo' . ($i + 1), 'onboarded_on' => date('Y-m-d', strtotime($today . ' -' . ($i * 17) . ' days')), 'provisioned_at' => date('Y-m-d H:i:s')]);
            $tid = (string) $t['id'];
            $log['tenants'][] = $tid;
            $platform->forTenant($tid)->put('companies', ['name' => $name . ' Pvt Ltd', 'timezone' => 'Asia/Kolkata']);
            $pdo->prepare('UPDATE tenants SET created_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime($today . ' -' . ($i * 17) . ' days')), $tid]);
            foreach (array_slice($apps, $i % 3, 1 + $i % 3) as $j => $app) {
                $plan = ($i + $j) % 3 === 0 ? 'monthly' : 'yearly';
                $seats = [25, 50, 100, 150, 200][($i + $j) % 5];
                AmetecsLicences::put($tid, $app, ['deployment' => $i % 4 === 3 ? 'onprem' : 'cloud', 'plan' => $plan, 'status' => 'active', 'seats' => $seats,
                    'price' => number_format($seats * ($plan === 'monthly' ? 60 : 600), 2, '.', ''), 'currency' => 'INR',
                    'valid_until' => date('Y-m-d', strtotime($today . ' +' . ([12, 25, 45, 90, 200, -5, 330, 18, 60, 150][$i]) . ' days'))], 'Sample data');
                $log['licences'][] = [$tid, $app];
            }
            if (AmetecsBilling::ready()) {   // paid invoices spread over the last 6 months
                $inv = AmetecsBilling::create($tid, 'other', 'Sample: subscription', 5000 + $i * 2750, 'INR');
                AmetecsBilling::markPaid($inv, (float) $inv['total'], 'offline', 'SAMPLE-' . $code . '-' . time(), 'sample data');
                $when = date('Y-m-d H:i:s', strtotime($today . ' 11:00 -' . (($i % 6) * 30 + 3) . ' days'));
                $pdo->prepare('UPDATE ag_payments SET created_at = ? WHERE invoice_id = ?')->execute([$when, $inv['id']]);
                $pdo->prepare('UPDATE ag_invoices SET issued_on = ?, paid_at = ? WHERE id = ?')->execute([substr($when, 0, 10), $when, $inv['id']]);
                $log['invoices'][] = (string) $inv['id'];
                if ($i % 4 === 1) {   // and a few still open
                    $log['invoices'][] = (string) AmetecsBilling::create($tid, 'other', 'Sample: add-on seats', 3500, 'INR')['id'];
                }
            }
            $made++;
        }
        $done[] = $made . ' demo clients with licences and invoices';
        self::remember($log);
        AmetecsData::log($actor, 'Added sample data', $tenant, implode('; ', $done));
        return implode(', ', $done);
    }

    /** Takes away exactly what add() put in. */
    public static function remove(\PDO $pdo, string $actor): string
    {
        $log = self::present();
        $n = 0;
        foreach (array_reverse($log['rows'] ?? []) as [$table, $id]) {
            if (preg_match('/^[a-z_]+$/', (string) $table) === 1) { $n += $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$id]) ? 1 : 0; }
        }
        $inv = array_map('intval', $log['invoices'] ?? []);
        foreach ($inv as $id) {
            $pdo->prepare('DELETE FROM ag_payments WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ag_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($log['tenants'] ?? [] as $tid) {
            foreach (['ag_licence_events', 'ag_licences', 'companies'] as $t) {
                try { $pdo->prepare("DELETE FROM `{$t}` WHERE tenant_id = ?")->execute([$tid]); } catch (\Throwable) {}
            }
            $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tid]);
        }
        @unlink(self::FILE);
        AmetecsData::log($actor, 'Removed sample data', '', $n . ' rows, ' . count($log['tenants'] ?? []) . ' demo clients, ' . count($inv) . ' invoices');
        return 'Sample data removed: ' . count($log['tenants'] ?? []) . ' demo clients and ' . $n . ' sample rows.';
    }
}
