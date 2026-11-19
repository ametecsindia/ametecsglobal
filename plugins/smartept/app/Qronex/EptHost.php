<?php

namespace App\Qronex;

use Ametecs\Qronex\Contracts\Host;
use Ametecs\Qronex\Models\AttendanceEvent;
use App\Http\Controllers\Api\BiometricController;
use App\Models\BiometricLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\InstallationLicense;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * SmartEPT adapter for the shared QRONEX attendance module (packages/qronex-module).
 * Tenant = company. Employees = `employees` (employee_code). Verified QR punches are written to biometric_logs and run
 * through the same fan-out as every other punch source (merge → gate → derive), so attendance, late marks, the door gate
 * and the nightly job treat them exactly like biometric punches. Licence: Central plan feature `qr_attendance`.
 */
class EptHost implements Host
{
    /**
     * EPT role (slug or base_slug of a custom role) → module abilities. The module works company-wide (no branch/team
     * scoping yet), so only company-level roles get in. ponytail: add BRANCH_ADMIN / MANAGER / TEAM_LEADER once
     * approvals are scoped to their branch or team.
     */
    private const ROLES = [
        'SUPER_ADMIN' => ['view', 'approve', 'manage', 'configure'],
        'COMPANY_ADMIN' => ['view', 'approve', 'manage', 'configure'],
        'HR_ADMIN' => ['view', 'approve', 'manage'],
        'AUDITOR' => ['view'],
        'COMPLIANCE_OFFICER' => ['view'],
    ];

    public function name(): string
    {
        return 'SmartEPT';
    }

    /** 07-Nov-2026: the module's on/off lives in ONE place in SmartEPT — the Organisation → Attendance source card. */
    public function switchNote(): string
    {
        return 'Turned on and off in SmartEPT → Organisation → Attendance source: choose "QR Attendance".';
    }

    /** 07-Nov-2026 (Ejaz): photos employees set in the app → QR Attendance › Employees thumbnails. [host employee id => signed URL] */
    public function photoUrls(array $hostEmployeeIds): array
    {
        return Employee::withoutGlobalScope('company')->whereIn('id', $hostEmployeeIds)->whereNotNull('photo_path')
            ->get(['id', 'photo_path', 'updated_at'])->mapWithKeys(fn ($e) => [$e->id => PhotoController::url($e)])->all();
    }

    /** Name of the employee phone app in emails (07-Nov-2026). */
    public function appName(): string
    {
        return 'EPT QR Attendance';
    }

    public function homeUrl(): string
    {
        return url('/admin');
    }

    public function tenantIdFor(Authenticatable $user): ?int
    {
        return $user->isSuperAdmin() || ! $user->company_id ? null : (int) $user->company_id;
    }

    public function tenants(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Company code in the phone app = the company's slug (or code); a single-company install accepts any code. */
    public function tenantBySlug(string $slug): ?int
    {
        $id = Company::query()->where('slug', $slug)->orWhere('code', $slug)->value('id');
        if (! $id && Company::query()->count() === 1) {
            $id = Company::query()->value('id');
        }

        return $id ? (int) $id : null;
    }

    public function tenant(int $tenantId): array
    {
        $c = Company::query()->find($tenantId);

        // 09-Nov-2026 (Ejaz): the phone app shows every time in the Organisation time zone, not the phone's.
        $tz = $c?->timezone && in_array($c->timezone, timezone_identifiers_list(), true) ? $c->timezone : config('app.timezone');

        return ['name' => $c->name ?? 'SmartEPT', 'slug' => $c->slug ?: ($c->code ?? (string) $tenantId), 'timezone' => $tz];
    }

    public function allows(Authenticatable $user, string $ability): bool
    {
        if (isset($user->status) && strtoupper((string) $user->status) !== 'ACTIVE') {
            return false;
        }
        if (! $user->company_id && ! $user->isSuperAdmin()) {
            return false; // no company = platform super admin only
        }
        $role = $user->role;
        foreach (array_filter([$role?->slug, $role?->base_slug]) as $slug) {
            if (in_array($ability, self::ROLES[$slug] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function licensed(int $tenantId): bool
    {
        return InstallationLicense::governing(Company::query()->find($tenantId))->hasFeature('qr_attendance');
    }

    public function employees(int $tenantId): iterable
    {
        $q = Employee::withoutGlobalScope('company')->with('department:id,name', 'designation:id,name')->where('company_id', $tenantId)->orderBy('id');
        foreach ($q->cursor() as $e) {
            if (blank($e->employee_code)) {
                continue;
            }
            yield ['id' => $e->id, 'code' => $e->employee_code, 'name' => trim($e->first_name.' '.$e->last_name), 'email' => $e->email, 'mobile' => $e->mobile,
                'department' => $e->department?->name, 'designation' => $e->designation?->name, 'active' => $e->employment_status !== 'RELIEVED'];
        }
    }

    public function recordPunch(AttendanceEvent $event): void
    {
        $cid = (int) $event->tenant_id;
        $emp = Employee::withoutGlobalScope('company')->where('company_id', $cid)->find($event->employee->host_employee_id);
        if (! $emp) {
            throw new RuntimeException('Employee no longer exists in SmartEPT.');
        }
        $external = 'qx:'.$event->ref;
        $row = [
            'company_id' => $cid,
            'biometric_device_id' => null,
            'biometric_employee_id' => (string) ($emp->biometric_id ?: $emp->employee_code),
            'employee_id' => $emp->id,
            'punch_type' => $event->type,
            'punched_at' => $event->recorded_at->copy()->setTimezone(config('app.timezone')),
            'verification_mode' => null, // enum has no QR value; the method is in metadata
            'external_id' => $external,
            'metadata' => json_encode(array_filter([
                'ingested_via' => 'QRONEX', 'method' => 'QR', 'mode' => $event->mode, 'office' => $event->office?->name, 'gate' => $event->gate?->name,
                'lat' => $event->lat, 'lng' => $event->lng, 'accuracy_m' => $event->accuracy_m, 'distance_m' => $event->distance_m, 'adjusted' => $event->adjusted ?: null,
            ], fn ($v) => $v !== null)),
            'processed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        // One transaction: if the fan-out fails the raw row is rolled back too, so the hourly retry redoes the whole punch.
        $inserted = false;
        DB::transaction(function () use ($cid, $row, &$inserted) {
            if (BiometricLog::insertOrIgnore([$row]) === 0) {
                return; // already recorded (unique company_id + external_id) — retries and races never duplicate
            }
            $inserted = true;
            // The same fan-out every punch source uses (push API, CSV, cloud sync, public API).
            BiometricController::mergeIntoAttendance($cid, [$row]);
            BiometricController::processGatePunches($cid, [$row]);
            BiometricController::deriveAttendance($cid, [$row]);
        });
        // Punched OUT → the app's portal dashboard closes (PortalSessionController issues it only while IN).
        if ($event->type === 'OUT') {
            PortalSessionController::revoke($emp->user);
            // 10-Nov-2026 (Ejaz): punch OUT = sign out — the agent is signed out (and SmartDCM with it, whose session
            // follows the agent's). Only for a fresh punch: an hourly retry of an old OUT must never end a newer session.
            // An OUT written BY a sign-out (AGENT_SIGNOUT) has nothing left to sign out.
            if ($inserted && $event->mode !== 'AGENT_SIGNOUT' && $event->recorded_at->greaterThan(now()->subMinutes(15))) {
                \App\Models\EmployeeDevice::withoutGlobalScopes()->where('employee_id', $emp->id)
                    ->where('session_status', 'ACTIVE')->get()->each->endSession();
            }
        }
    }

    /** QR module emails go out like every other SmartEPT email: the company's SMTP (console mail settings), recorded in the mail log. */
    public function sendMail(string $to, string $subject, string $body, ?int $tenantId): void
    {
        \App\Services\MailService::send($to, $subject, $body, null, $tenantId);
    }

    /** 12-Nov-2026 (Ejaz): the phone app signs in with the employee's SmartEPT login — the same e-mail and password as the agent (or the employee code). */
    public function loginEmployeeId(int $tenantId, string $login, string $password): ?int
    {
        $user = str_contains($login, '@')
            ? User::query()->where('email', $login)->first()
            : Employee::withoutGlobalScope('company')->where('company_id', $tenantId)->where('employee_code', $login)->first()?->user;
        if (! $user || (int) $user->company_id !== $tenantId || strtoupper((string) $user->status) !== 'ACTIVE' || ! Hash::check($password, (string) $user->password)) {
            return null;
        }
        $id = Employee::withoutGlobalScope('company')->where('company_id', $tenantId)->where('user_id', $user->id)->value('id');

        return $id ? (int) $id : null;
    }

    public function approverEmails(int $tenantId): array
    {
        return User::query()->where('company_id', $tenantId)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['COMPANY_ADMIN', 'HR_ADMIN'])->orWhereIn('base_slug', ['COMPANY_ADMIN', 'HR_ADMIN']))
            ->pluck('email')->filter()->unique()->values()->all();
    }
}
