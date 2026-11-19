<?php

namespace App\Demo;

use Ametecs\Qronex\Models\Device as QxDevice;
use Ametecs\Qronex\Models\Employee as QxEmployee;
use Ametecs\Qronex\Models\Gate;
use Ametecs\Qronex\Models\Office;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\EmployeeSync;
use App\Http\Controllers\Api\BiometricController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeDevice;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\AttendanceDerivation;
use App\Services\ScoringService;
use App\Services\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 10-Nov-2026 (Ejaz): Explore Demo. Builds a ready-to-use demo company — 10 employees in the visitor's city, an office with
 * two QR gates, approved phones — and simulates their last two months exactly as the agent and the QR app would have
 * reported them (QR IN/OUT, PC sessions, active/idle, breaks, apps, websites). The console then derives attendance,
 * productivity and summaries from those raw records with its own code, so every screen shows real calculations.
 *
 * Only the sample employees are simulated. Everything the visitor creates (employees, offices, gates) is live.
 * Simulated rows carry the sample PC's device_uuid ("demo-…") or a "QXD…" QR ref, so they are never confused with real data.
 */
class DemoSeeder
{
    public const DAYS_BACK = 60;

    /**
     * 11-Nov-2026 (Ejaz): the employee a visitor or the Google Play reviewer activates on their own phone. Nothing is ever
     * simulated for EMP001 — no sample PC, no sample phone, no past days, no today — so the first activation starts
     * "Not checked in today" with an empty history, and every punch it ever shows is the person's own.
     * EMP002–EMP010 carry the two months of sample history (use EMP002 to see a phone with past attendance).
     */
    public const OWN_PHONE_EMPLOYEE = 'EMP001';
    private const SHIFT_START = '09:30';
    private const SHIFT_END = '18:30';

    private const DEPARTMENTS = [
        ['Operations', 'OPS', ['Team Leader', 'Senior Executive', 'Executive', 'Executive']],
        ['Collections', 'COL', ['Team Leader', 'Tele-caller', 'Tele-caller', 'Tele-caller']],
        ['Customer Support', 'SUP', ['Support Executive', 'Support Executive']],
    ];

    // [app name, process, category, window title]
    private const APPS = [
        ['Google Chrome', 'chrome.exe', 'NEUTRAL', null],
        ['SmartDCM Dialer', 'SmartDCM.exe', 'PRODUCTIVE', 'SmartDCM - Call queue'],
        ['Microsoft Excel', 'EXCEL.EXE', 'PRODUCTIVE', 'Daily_Tracker.xlsx - Excel'],
        ['Microsoft Outlook', 'OUTLOOK.EXE', 'COMMUNICATION', 'Inbox - Outlook'],
        ['Microsoft Teams', 'ms-teams.exe', 'COMMUNICATION', 'Chat | Microsoft Teams'],
        ['Microsoft Word', 'WINWORD.EXE', 'PRODUCTIVE', 'Customer_Letter.docx - Word'],
        ['WhatsApp', 'WhatsApp.exe', 'NON_PRODUCTIVE', 'WhatsApp'],
        ['File Explorer', 'explorer.exe', 'SYSTEM', 'Downloads'],
    ];
    private const APP_WEIGHTS = [30, 22, 14, 8, 8, 5, 6, 7];

    // [domain, category, page title]
    private const SITES = [
        ['crm.smartdcm.com', 'BANKING_CRM', 'SmartDCM CRM - Accounts'],
        ['mail.google.com', 'COMMUNICATION', 'Inbox - Gmail'],
        ['docs.google.com', 'PRODUCTIVE', 'Weekly report - Google Sheets'],
        ['www.google.com', 'NEUTRAL', 'Google Search'],
        ['www.linkedin.com', 'NEUTRAL', 'Feed | LinkedIn'],
        ['www.youtube.com', 'NON_PRODUCTIVE', 'YouTube'],
        ['web.whatsapp.com', 'COMMUNICATION', 'WhatsApp Web'],
        ['news.google.com', 'NON_PRODUCTIVE', 'Google News'],
    ];
    private const SITE_WEIGHTS = [34, 14, 14, 12, 7, 8, 6, 5];

    // ------------------------------------------------------------------ set-up

    /** Org, shift, 10 employees with logins and PCs, QR office + gates + approved phones. Idempotent per company. */
    public static function setup(Company $company, string $cityKey, string $password): DemoAccount
    {
        $city = DemoCities::get($cityKey);
        $cid = (int) $company->id;

        $existing = DemoAccount::where('company_id', $cid)->first();
        if ($existing && $existing->employee_ids) {
            return $existing;
        }

        return DB::transaction(function () use ($company, $city, $password, $cid) {
            $company->forceFill([
                'attendance_mode' => 'QR',
                'timezone' => $company->timezone ?: 'Asia/Kolkata',
                'weekly_off' => ['SUN' => [1, 2, 3, 4, 5], 'SAT' => [2, 4]],
            ])->save();

            $branch = Branch::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'code' => 'HO'],
                ['name' => $city['name'] . ' Office', 'city' => $city['name'], 'state' => $city['state'], 'country' => 'India', 'address' => $city['areas'][0] . ', ' . $city['name']]);
            $shift = Shift::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'name' => 'General Shift'], [
                'code' => 'GEN', 'start_time' => self::SHIFT_START . ':00', 'end_time' => self::SHIFT_END . ':00', 'grace_minutes' => 10,
                'post_shift_auto_logout_minutes' => 30, 'break_lunch_min' => 30, 'break_tea_min' => 10, 'break_other_min' => 10, 'status' => 'ACTIVE',
            ]);
            $roleId = Role::where('slug', 'EMPLOYEE')->whereNull('company_id')->value('id');

            $rng = new Randomizer(new Mt19937(crc32('demo-names|' . $cid)));
            [$firsts, $lasts] = $city['names'];
            $firsts = $rng->shuffleArray($firsts);

            $ids = [];
            $n = 0;
            foreach (self::DEPARTMENTS as [$deptName, $deptCode, $roles]) {
                $dept = Department::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'code' => $deptCode], ['name' => $deptName, 'branch_id' => $branch->id]);
                $team = Team::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'code' => $deptCode . '-A'], ['name' => $deptName . ' Team', 'department_id' => $dept->id]);
                foreach ($roles as $roleName) {
                    $desig = Designation::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'name' => $roleName], ['code' => strtoupper(Str::slug($roleName, '_'))]);
                    $first = $firsts[$n % count($firsts)];
                    $last = $lasts[$rng->getInt(0, count($lasts) - 1)];
                    $code = sprintf('EMP%03d', ++$n);
                    $email = Str::lower($first . '.' . $last . '.' . $code) . '@' . ($company->slug ?: 'demo') . '.demo';

                    $user = User::create(['name' => "$first $last", 'email' => $email, 'password' => $password, 'company_id' => $cid,
                        'branch_id' => $branch->id, 'role_id' => $roleId, 'status' => 'ACTIVE', 'must_change_password' => false]);
                    $emp = Employee::withoutGlobalScopes()->create([
                        'company_id' => $cid, 'branch_id' => $branch->id, 'department_id' => $dept->id, 'team_id' => $team->id,
                        'designation_id' => $desig->id, 'shift_id' => $shift->id, 'user_id' => $user->id, 'employee_code' => $code,
                        'first_name' => $first, 'last_name' => $last, 'email' => $email, 'mobile' => '9' . $rng->getInt(100000000, 999999999),
                        'employment_status' => 'ACTIVE', 'date_of_joining' => now()->subDays($rng->getInt(120, 900))->toDateString(),
                    ]);
                    if ($code !== self::OWN_PHONE_EMPLOYEE) EmployeeDevice::withoutGlobalScopes()->create([
                        'company_id' => $cid, 'employee_id' => $emp->id, 'device_uuid' => self::deviceUuid($cid, $emp->id),
                        'computer_name' => 'DESKTOP-' . strtoupper(substr(md5($cid . '|' . $emp->id), 0, 7)), 'os_version' => 'Windows 11 Pro 24H2',
                        'windows_username' => Str::lower($first), 'lan_ip' => '192.168.1.' . (20 + $n), 'processor' => 'Intel Core i5-1235U',
                        'ram_gb' => 16, 'disk_gb' => 512, 'app_version' => '0.40.0', 'agent_health' => 'HEALTHY',
                        'current_status' => 'OFFLINE', 'session_status' => 'LOGGED_OUT', 'registered_at' => now()->subDays(self::DAYS_BACK + 5),
                    ]);
                    $ids[] = $emp->id;
                }
            }

            // QR Attendance: on, phones approved automatically, one office in the chosen city with two gates.
            Qronex::settings($cid)->fill(['tenant_id' => $cid, 'enabled' => true, 'require_device_approval' => false])->save();
            $office = Office::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $cid, 'code' => 'HO'], [
                'slug' => Str::slug($city['name']) . '-office', 'name' => $city['name'] . ' Office', 'address' => $city['areas'][0] . ', ' . $city['name'],
                'city' => $city['name'], 'lat' => $city['lat'], 'lng' => $city['lng'], 'geofence_radius_m' => 150, 'is_active' => true, 'skip_geofence' => true,
            ]);
            foreach ([['gate1', 'Main Entrance'], ['gate2', 'Reception']] as [$gc, $gn]) {
                Gate::withoutGlobalScopes()->firstOrCreate(['office_id' => $office->id, 'code' => $gc], ['tenant_id' => $cid, 'name' => $gn]);
            }
            EmployeeSync::run($cid);
            $phones = ['Samsung Galaxy A55', 'Redmi Note 13 Pro', 'vivo T3', 'OnePlus Nord CE4', 'realme 12 Pro', 'Samsung Galaxy M35'];
            foreach (QxEmployee::withoutGlobalScopes()->where('tenant_id', $cid)->whereIn('host_employee_id', $ids)->get() as $i => $qx) {
                $qx->forceFill(['office_id' => $office->id, 'scan_scope' => 'office', 'max_devices' => 50])->save();
                if ((int) $qx->host_employee_id === $ids[0]) {
                    continue; // EMP001: no sample phone — the visitor's own phone is its only one
                }
                QxDevice::withoutGlobalScopes()->create(['tenant_id' => $cid, 'employee_id' => $qx->id, 'platform' => 'android',
                    'model' => $phones[$i % count($phones)], 'os_version' => 'Android 15', 'install_ref' => 'demo-' . $cid . '-' . $qx->id . '-' . Str::random(12),
                    'status' => 'approved', 'approved_at' => now()->subDays(self::DAYS_BACK + 5), 'last_seen_at' => now()->subHours(2)]);
            }

            return DemoAccount::updateOrCreate(['company_id' => $cid], [
                'city' => $city['key'], 'activation_code' => self::activationCode($cid), 'status' => 'building', 'employee_ids' => $ids,
            ]);
        });
    }

    /** setup() + two months of history + today so far, then the console's own derivations. */
    public static function build(Company $company, string $cityKey, string $password): DemoAccount
    {
        @set_time_limit(0);
        $acc = self::setup($company, $cityKey, $password);
        $today = self::today($company);
        self::simulate($acc, Carbon::parse($today)->subDays(self::DAYS_BACK)->toDateString(), $today);
        $acc->update(['status' => 'ready']);
        Log::info('Demo company built', ['company_id' => $company->id, 'city' => $cityKey]);

        return $acc;
    }

    /**
     * 11-Nov-2026: a demo whose first build stopped part-way (status still "building" after 15 minutes) is finished by the
     * 15-minute job: the same two months, then "ready". Rows are cleared per range first, so a rerun never duplicates.
     */
    public static function finish(DemoAccount $acc): void
    {
        $company = Company::withoutGlobalScopes()->find($acc->company_id);
        if (! $company || ! $acc->employee_ids) {
            return;
        }
        @set_time_limit(0);
        $today = self::today($company);
        self::simulate($acc, Carbon::parse($today)->subDays(self::DAYS_BACK)->toDateString(), $today);
        $acc->update(['status' => 'ready']);
        Log::info('Demo company finished by top-up', ['company_id' => $company->id]);
    }

    /** Every 15 minutes: carry the sample employees' today forward (and fill yesterday if it was never simulated). */
    public static function topUp(DemoAccount $acc): void
    {
        $company = Company::withoutGlobalScopes()->find($acc->company_id);
        if (! $company || $company->status !== 'ACTIVE' || ! $acc->employee_ids) {
            return;
        }
        self::purgeOwnPhone($acc, $company);
        $today = self::today($company);
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $from = self::hasRows($acc, $yesterday) ? $today : $yesterday;
        self::simulate($acc, $from, $today);
        self::live($acc);
    }

    /**
     * 16-Nov-2026 (Ejaz): the Live Dashboard of a demo must look like a real office, not all zeros. It counts a PC as
     * Active / Idle only from a heartbeat of the last 3 minutes, so every minute (smartept:demo-live) each sample PC whose
     * employee is still at work today — today's simulated session is still running — "heartbeats": about 4 in 5 Active, the
     * rest Idle, re-chosen every 10 minutes. Everyone else (gone home, absent, EMP001 with no PC) reads Offline.
     * Demo companies' own demo-… PCs only.
     */
    public static function live(DemoAccount $acc): void
    {
        $cid = (int) $acc->company_id;
        $now = now();
        $working = DB::table('employee_login_sessions')->where('company_id', $cid)->where('device_uuid', 'like', 'demo-%')
            ->where('logout_reason', 'LOCK')                 // still running when the top-up last ran (clipped at that moment)
            ->where('login_at', '<=', $now)->where('logout_at', '>=', $now->copy()->subMinutes(20))
            ->pluck('device_uuid')->flip();
        $slot = intdiv($now->getTimestamp(), 600);
        foreach (DB::table('employee_devices')->where('company_id', $cid)->where('device_uuid', 'like', 'demo-%')->get(['id', 'device_uuid']) as $d) {
            $idle = crc32($d->device_uuid . '|' . $slot) % 100 < 20;
            DB::table('employee_devices')->where('id', $d->id)->update(isset($working[$d->device_uuid])
                ? ['current_status' => $idle ? 'IDLE' : 'ONLINE', 'session_status' => 'ACTIVE', 'last_heartbeat_at' => $now]
                : ['current_status' => 'OFFLINE', 'session_status' => 'LOGGED_OUT']);
        }
    }

    // ------------------------------------------------------------------ simulation

    /** Re-generate [from, to] for the sample employees (clipped at now), then derive attendance and summaries. */
    public static function simulate(DemoAccount $acc, string $from, string $to): void
    {
        $cid = (int) $acc->company_id;
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        $tz = $company->timezone ?: 'Asia/Kolkata';
        $now = now()->getTimestamp();
        $employees = Employee::withoutGlobalScopes()->with('shift')->whereIn('id', $acc->employee_ids)->orderBy('id')->get()
            ->reject(fn ($e) => $e->employee_code === self::OWN_PHONE_EMPLOYEE); // keys kept, so EMP002–EMP010 keep their profiles
        $qx = QxEmployee::withoutGlobalScopes()->where('tenant_id', $cid)->whereIn('host_employee_id', $acc->employee_ids)->get()->keyBy('host_employee_id');
        $gates = Gate::withoutGlobalScopes()->where('tenant_id', $cid)->orderBy('id')->get();
        $office = Office::withoutGlobalScopes()->where('tenant_id', $cid)->where('code', 'HO')->first();
        $phones = QxDevice::withoutGlobalScopes()->where('tenant_id', $cid)->where('status', 'approved')->orderBy('id')->get()->groupBy('employee_id');
        $calendar = new WorkCalendar();

        self::clear($acc, $from, $to);

        $dates = [];
        for ($d = $from; $d <= $to; $d = Carbon::parse($d)->addDay()->toDateString()) {
            $dates[] = $d;
        }
        $punches = [];
        foreach ($dates as $d) {
            if ($calendar->isCompanyOff($cid, $d)) {
                continue;
            }
            $rows = ['sess' => [], 'act' => [], 'brk' => [], 'app' => [], 'web' => [], 'qx' => [], 'bio' => []];
            foreach ($employees as $i => $emp) {
                $q = $qx->get($emp->id);
                if ($q) {
                    self::day($rows, $cid, $emp, $i, $q, $phones->get($q->id)?->first()?->id, $gates, $office, $d, $tz, $now);
                }
            }
            foreach (['sess' => 'employee_login_sessions', 'act' => 'employee_activity_events', 'brk' => 'employee_break_logs',
                'app' => 'employee_app_usage_logs', 'web' => 'employee_website_usage_logs', 'qx' => 'qx_attendance_events', 'bio' => 'biometric_logs'] as $k => $table) {
                foreach (array_chunk($rows[$k], 400) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
            foreach ($rows['bio'] as $b) {
                $punches[] = ['employee_id' => $b['employee_id'], 'punch_type' => $b['punch_type'], 'punched_at' => Carbon::parse($b['punched_at'])];
            }
        }
        if ($punches) {
            BiometricController::mergeIntoAttendance($cid, $punches);
        }
        DB::table('employee_devices')->where('company_id', $cid)->where('device_uuid', 'like', 'demo-%')->update([
            'last_heartbeat_at' => DB::table('employee_login_sessions')->where('company_id', $cid)->max('logout_at'),
        ]);

        // The console's own nightly logic, per sample employee only (never the whole server).
        $derive = app(AttendanceDerivation::class);
        $scoring = app(ScoringService::class);
        $today = self::today($company);
        foreach ($dates as $d) {
            foreach ($employees as $emp) {
                try {
                    $working = $calendar->isWorkingDay($emp, $d);
                    $row = $derive->deriveDay($emp, $d);
                    if (! $row && $working && $d < $today) {
                        EmployeeAttendanceLog::create(['company_id' => $cid, 'employee_id' => $emp->id, 'work_date' => $d,
                            'source' => 'MANUAL', 'status' => 'ABSENT', 'notes' => 'Auto-marked absent']);
                    }
                    if ($d < $today && ($working || $row)) {
                        $scoring->buildSummary($emp, $d);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Demo derive failed', ['employee_id' => $emp->id, 'date' => $d, 'e' => $e->getMessage()]);
                }
            }
        }
    }

    /** One simulated working day for one employee, appended to $rows. */
    private static function day(array &$rows, int $cid, Employee $emp, int $i, QxEmployee $q, ?int $phoneId, $gates, ?Office $office, string $d, string $tz, int $now): void
    {
        $r = new Randomizer(new Mt19937(crc32("demo|$cid|{$emp->id}|$d")));
        $p = self::profile($i);
        if ($r->getInt(1, 100) <= 3) {
            return; // absent
        }
        $dev = self::deviceUuid($cid, $emp->id);
        $at = fn (string $hm, int $sec = 0) => Carbon::parse("$d $hm", $tz)->getTimestamp() + $sec;
        $ts = fn (int $t) => Carbon::createFromTimestamp($t)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $stamp = $ts($now);
        $base = ['company_id' => $cid, 'employee_id' => $emp->id];

        $late = $r->getInt(1, 100) <= $p['late'] ? $r->getInt(12, 48) : $r->getInt(-14, 7);
        $qrIn = $at(self::SHIFT_START, $late * 60 + $r->getInt(0, 59));
        if ($qrIn > $now) {
            return;
        }
        $login = $qrIn + ($r->getInt(1, 100) <= 3 ? $r->getInt(660, 840) : $r->getInt(60, 360));
        $end = $r->getInt(1, 100) <= 4 ? $at('17:20', $r->getInt(0, 1800)) : $at(self::SHIFT_END, $r->getInt(-300, 2400));
        $gateOut = $r->getInt(1, 100) <= 65;
        $logout = $gateOut ? $end + $r->getInt(10, 30) : $end;
        $breaks = [
            ['TEA', $at('11:05', $r->getInt(0, 1500)), $r->getInt(7, 14) * 60, 10],
            ['LUNCH', $at('13:15', $r->getInt(0, 1800)), ($r->getInt(1, 100) <= $p['longLunch'] ? $r->getInt(36, 52) : $r->getInt(24, 32)) * 60, 30],
            ['TEA', $at('16:05', $r->getInt(0, 1500)), $r->getInt(7, 15) * 60, 10],
        ];

        // QR punches: IN at a gate; OUT at a gate, or written by the agent sign-out ("Auto Punch Out due to Agent Sign out").
        $gate = $gates[$r->getInt(1, 100) <= 80 ? 0 : min(1, count($gates) - 1)] ?? null;
        $punch = function (string $type, int $t, ?object $g, string $mode) use (&$rows, $cid, $emp, $q, $phoneId, $office, $r, $ts, $stamp, $d) {
            $ref = 'QXD' . strtoupper(substr(md5("$cid|{$emp->id}|$d|$type"), 0, 14));
            $lat = $office && $g ? round($office->lat + $r->getInt(-30, 30) / 100000, 7) : null;
            $lng = $office && $g ? round($office->lng + $r->getInt(-30, 30) / 100000, 7) : null;
            $rows['qx'][] = ['ref' => $ref, 'tenant_id' => $cid, 'employee_id' => $q->id, 'device_id' => $g ? $phoneId : null, 'office_id' => $office?->id,
                'gate_id' => $g?->id, 'type' => $type, 'mode' => $mode, 'adjusted' => false, 'qr_window' => $g ? intdiv($t, 3) : null,
                'lat' => $lat, 'lng' => $lng, 'accuracy_m' => $g ? $r->getInt(6, 24) : null, 'distance_m' => $g ? $r->getInt(4, 45) : null,
                'recorded_at' => $ts($t), 'host_synced_at' => $ts($t), 'created_at' => $ts($t)];
            $rows['bio'][] = ['company_id' => $cid, 'biometric_device_id' => null, 'biometric_employee_id' => (string) ($emp->biometric_id ?: $emp->employee_code),
                'employee_id' => $emp->id, 'punch_type' => $type, 'punched_at' => $ts($t), 'verification_mode' => null, 'external_id' => 'qx:' . $ref,
                'metadata' => json_encode(array_filter(['ingested_via' => 'QRONEX', 'method' => 'QR', 'mode' => $mode, 'office' => $office?->name, 'gate' => $g?->name, 'demo' => true])),
                'processed' => true, 'created_at' => $stamp, 'updated_at' => $stamp];
        };
        $punch('IN', $qrIn, $gate, 'OFFICE_QR');
        if ($end <= $now) {
            $punch('OUT', $end, $gateOut ? $gate : null, $gateOut ? 'OFFICE_QR' : 'AGENT_SIGNOUT');
        }

        if ($login > $now) {
            return;
        }
        $out = min($logout, $now);
        $workEnd = min($end, $now); // a QR OUT signs the agent out ~20 s later; nothing is tracked after the OUT
        $rows['sess'][] = $base + ['device_uuid' => $dev, 'session_type' => 'CLIENT', 'login_at' => $ts($login), 'logout_at' => $ts($out),
            'duration_seconds' => $out - $login, 'login_ip' => '192.168.1.' . (21 + $i), 'logout_reason' => $logout <= $now ? 'USER' : 'LOCK',
            'created_at' => $stamp, 'updated_at' => $stamp];

        // Breaks, then active/idle spells in the gaps between them.
        $cursor = $login;
        $spans = [];
        foreach ($breaks as [$type, $bs, $dur, $limit]) {
            if ($bs <= $cursor || $bs >= $workEnd) {
                continue;
            }
            $spans[] = [$cursor, $bs];
            $be = min($bs + $dur, $workEnd);
            $rows['brk'][] = $base + ['device_uuid' => $dev, 'break_type' => $type, 'source' => 'MANUAL', 'start_at' => $ts($bs), 'end_at' => $ts($be),
                'duration_seconds' => $be - $bs, 'permitted_seconds' => $limit * 60, 'excess_seconds' => max(0, $be - $bs - $limit * 60),
                'approval_status' => 'NOT_REQUIRED', 'review_status' => 'NONE', 'created_at' => $stamp, 'updated_at' => $stamp];
            $cursor = $be;
        }
        $spans[] = [$cursor, $workEnd];

        foreach ($spans as [$s, $e]) {
            $t = $s;
            while ($t < $e - 30) {
                $len = min($e - $t, $r->getInt(5 * 60, 28 * 60));
                $app = self::APPS[self::pick($r, self::APP_WEIGHTS)];
                $site = $app[0] === 'Google Chrome' ? self::SITES[self::pick($r, $p['sites'])] : null;
                $title = $site ? $site[2] . ' - Google Chrome' : $app[3];
                // 11-Nov-2026: keyboard/mouse_activity are booleans (MySQL tinyint, max 127). Counts of 40–1,200 were refused on MySQL
                // and stopped the whole build; getInt() still runs so every demo keeps the same random day.
                $rows['act'][] = $base + ['device_uuid' => $dev, 'event_type' => 'ACTIVE', 'started_at' => $ts($t), 'ended_at' => $ts($t + $len),
                    'duration_seconds' => $len, 'active_app' => $app[0], 'window_title' => $title,
                    'keyboard_activity' => $r->getInt(40, 900) > 0, 'mouse_activity' => $r->getInt(60, 1200) > 0, 'metadata' => '{"demo":true}', 'created_at' => $stamp, 'updated_at' => $stamp];
                $rows['app'][] = $base + ['device_uuid' => $dev, 'app_name' => $app[0], 'process_name' => $app[1], 'window_title' => $title,
                    'start_at' => $ts($t), 'end_at' => $ts($t + $len), 'duration_seconds' => $len, 'category' => $app[2], 'compliance_status' => 'OK',
                    'created_at' => $stamp, 'updated_at' => $stamp];
                if ($site) {
                    $rows['web'][] = $base + ['device_uuid' => $dev, 'browser' => 'Chrome', 'domain' => $site[0], 'full_url' => 'https://' . $site[0] . '/',
                        'page_title' => $site[2], 'start_at' => $ts($t), 'end_at' => $ts($t + $len), 'duration_seconds' => $len, 'category' => $site[1],
                        'compliance_status' => 'OK', 'created_at' => $stamp, 'updated_at' => $stamp];
                }
                $t += $len;
                if ($t < $e - 60 && $r->getInt(1, 100) <= $p['idle']) {
                    $idle = min($e - $t, $r->getInt(60, $p['idleMax'] * 60));
                    $rows['act'][] = $base + ['device_uuid' => $dev, 'event_type' => 'IDLE', 'started_at' => $ts($t), 'ended_at' => $ts($t + $idle),
                        'duration_seconds' => $idle, 'active_app' => null, 'window_title' => null, 'keyboard_activity' => 0, 'mouse_activity' => 0,
                        'metadata' => '{"demo":true}', 'created_at' => $stamp, 'updated_at' => $stamp];
                    $t += $idle;
                }
            }
        }
    }

    /** Ten distinct work habits, so the reports show a real spread (punctual/late, focused/idle, short/long lunch). */
    private static function profile(int $i): array
    {
        $late = [5, 25, 8, 12, 35, 6, 15, 10, 20, 4];
        $idle = [35, 55, 30, 45, 65, 25, 50, 40, 60, 30];
        $idleMax = [6, 12, 5, 9, 15, 4, 10, 8, 12, 5];
        $long = [5, 20, 4, 10, 30, 3, 12, 8, 18, 6];
        $sites = $i % 3 === 1 ? [20, 12, 8, 14, 10, 20, 9, 7] : self::SITE_WEIGHTS;

        return ['late' => $late[$i % 10], 'idle' => $idle[$i % 10], 'idleMax' => $idleMax[$i % 10], 'longLunch' => $long[$i % 10], 'sites' => $sites];
    }

    private static function pick(Randomizer $r, array $weights): int
    {
        $x = $r->getInt(1, array_sum($weights));
        foreach ($weights as $k => $w) {
            if (($x -= $w) <= 0) {
                return $k;
            }
        }

        return 0;
    }

    /** Remove the simulated rows for [from, to] (only the sample PCs' rows and QXD… punches — never anything real). */
    private static function clear(DemoAccount $acc, string $from, string $to): void
    {
        $cid = (int) $acc->company_id;
        $tz = Company::withoutGlobalScopes()->whereKey($cid)->value('timezone') ?: 'Asia/Kolkata';
        $lo = Carbon::parse("$from 00:00:00", $tz)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $hi = Carbon::parse("$to 23:59:59", $tz)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        foreach (['employee_login_sessions' => 'login_at', 'employee_activity_events' => 'started_at', 'employee_break_logs' => 'start_at',
            'employee_app_usage_logs' => 'start_at', 'employee_website_usage_logs' => 'start_at'] as $table => $col) {
            DB::table($table)->where('company_id', $cid)->where('device_uuid', 'like', 'demo-%')->whereBetween($col, [$lo, $hi])->delete();
        }
        DB::table('qx_attendance_events')->where('tenant_id', $cid)->where('ref', 'like', 'QXD%')->whereBetween('recorded_at', [$lo, $hi])->delete();
        DB::table('biometric_logs')->where('company_id', $cid)->where('external_id', 'like', 'qx:QXD%')->whereBetween('punched_at', [$lo, $hi])->delete();
    }

    /**
     * Demos built before 11-Nov-2026 simulated EMP001 too. Wipe EMP001 once to a first-activation state: sample PC + its
     * activity, every punch (the simulated ones and any test scans mixed in with them — a half-simulated day only confuses),
     * the sample phone, attendance and summaries. Phones a person activated stay activated.
     * Runs only while such rows exist, so afterwards it is one cheap query per top-up.
     */
    public static function purgeOwnPhone(DemoAccount $acc, Company $company): void
    {
        $cid = (int) $acc->company_id;
        $emp = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $acc->employee_ids ?? [])
            ->where('employee_code', self::OWN_PHONE_EMPLOYEE)->first();
        $qx = $emp ? QxEmployee::withoutGlobalScopes()->where('tenant_id', $cid)->where('host_employee_id', $emp->id)->first() : null;
        if (! $emp || ! $qx) {
            return;
        }
        $uuid = self::deviceUuid($cid, $emp->id);
        $simulated = DB::table('qx_attendance_events')->where('employee_id', $qx->id)->where('ref', 'like', 'QXD%')->exists()
            || DB::table('employee_devices')->where('device_uuid', $uuid)->exists();
        if (! $simulated) {
            return;
        }
        DB::transaction(function () use ($cid, $emp, $qx, $uuid) {
            foreach (['employee_login_sessions', 'employee_activity_events', 'employee_break_logs', 'employee_app_usage_logs', 'employee_website_usage_logs', 'employee_devices'] as $t) {
                DB::table($t)->where('company_id', $cid)->where('device_uuid', $uuid)->delete();
            }
            DB::table('qx_attendance_events')->where('employee_id', $qx->id)->delete();
            DB::table('biometric_logs')->where('company_id', $cid)->where('employee_id', $emp->id)->delete();
            QxDevice::withoutGlobalScopes()->where('employee_id', $qx->id)->where('install_ref', 'like', 'demo-%')->delete();
            EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $emp->id)->delete();
            EmployeeDailySummary::withoutGlobalScopes()->where('employee_id', $emp->id)->delete();
        });
        Log::info('Demo EMP001 sample data removed', ['company_id' => $cid]);
    }

    private static function hasRows(DemoAccount $acc, string $date): bool
    {
        return DB::table('qx_attendance_events')->where('tenant_id', $acc->company_id)->where('ref', 'like', 'QXD%')
            ->whereDate('recorded_at', $date)->exists();
    }

    public static function deviceUuid(int $cid, int $empId): string
    {
        return 'demo-' . $cid . '-' . $empId;
    }

    /** The one QR app activation code every demo employee accepts (re-armed after each use — see DemoServiceProvider). */
    public static function activationCode(int $cid): string
    {
        return 'DEMO' . strtoupper(substr(md5('demo-activation|' . $cid . '|' . config('app.key')), 0, 4));
    }

    private static function today(Company $company): string
    {
        return now()->setTimezone($company->timezone ?: 'Asia/Kolkata')->toDateString();
    }
}
