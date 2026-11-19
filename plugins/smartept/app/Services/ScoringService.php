<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDailySummary;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeScreenshotLog;
use Illuminate\Support\Carbon;

/**
 * Computes the productivity and compliance scores (0–100) for an employee/day and writes
 * the daily summary. Server-side so scores cannot be manipulated on the endpoint.
 *
 * Productivity = the Productivity report's own figure for the day (DayTimeline::figures).
 * Compliance   = 100 − penalties per violation class.
 */
class ScoringService
{
    public function buildSummary(Employee $employee, string $date): EmployeeDailySummary
    {
        // Historical bucketing fix: every source for the day is bounded by the COMPANY-LOCAL
        // calendar day, not the raw storage/app timezone. Bounds are built in the company's
        // timezone then converted to the app (storage) timezone so the range matches the
        // stored wall-clock values. A cross-midnight event therefore lands on the local date
        // its instant belongs to, and when company tz == app tz (the single-tenant default)
        // this is byte-for-byte the old whereDate() behaviour.
        [$dayStart, $dayEnd] = $this->localDayBounds($employee, $date);

        // 26-Oct-2026 (Ejaz): the day's times come from the ONE day ledger the Productivity report uses
        // (DayTimeline) — no second formula, nothing counted twice, night shifts as one shift-day.
        $L = DayTimeline::ledger($employee, $date);
        $f = $L ? DayTimeline::figures($employee, $L) : null;

        $productive = (int) EmployeeAppUsageLog::where('employee_id', $employee->id)
            ->whereBetween('start_at', [$dayStart, $dayEnd])
            ->whereIn('category', ['PRODUCTIVE', 'CLIENT_REQUIRED', 'COMMUNICATION'])
            ->sum('duration_seconds');
        $nonProductive = (int) EmployeeAppUsageLog::where('employee_id', $employee->id)
            ->whereBetween('start_at', [$dayStart, $dayEnd])
            ->whereIn('category', ['NON_PRODUCTIVE', 'BLOCKED', 'RESTRICTED'])
            ->sum('duration_seconds');

        $violations = EmployeeComplianceEvent::where('employee_id', $employee->id)->whereBetween('started_at', [$dayStart, $dayEnd])->count();
        $shots = EmployeeScreenshotLog::where('employee_id', $employee->id)->whereBetween('captured_at', [$dayStart, $dayEnd])->count();

        $attendance = EmployeeAttendanceLog::where('employee_id', $employee->id)->whereDate('work_date', $date)->first();

        $at = fn ($ts) => $ts === null ? null : date('Y-m-d H:i:s', $ts);
        $early = (int) ($attendance->early_logout_minutes ?? 0);
        $compliance = $this->complianceScore($employee, $date);

        // whereDate: work_date is a date-cast column; a plain where() misses on
        // sqlite ('Y-m-d' vs stored 'Y-m-d 00:00:00') and re-runs then violate the
        // unique index. This lookup is portable across MySQL and sqlite.
        $summary = EmployeeDailySummary::withoutGlobalScopes()
            ->where('employee_id', $employee->id)->whereDate('work_date', $date)->first()
            ?? new EmployeeDailySummary(['employee_id' => $employee->id, 'work_date' => $date]);

        $summary->fill([
                'company_id'             => $employee->company_id,
                'present_seconds'        => $f['present'] ?? 0,
                'active_seconds'         => $f['work'] ?? 0,
                'idle_seconds'           => $f['idle'] ?? 0,
                'away_seconds'           => $f['away'] ?? 0,
                'break_seconds'          => $f['break'] ?? 0,
                'productive_app_seconds' => $productive,
                'non_productive_seconds' => $nonProductive,
                'first_login_at'         => $L ? $at($L['start']) : null,
                'last_logout_at'         => $L ? $at($L['live'] ? null : ($L['last_out'] ?? $L['end'])) : null,
                'late_minutes'           => $f['late'] ?? 0,
                'early_logout_minutes'   => $early,
                'violation_count'        => $violations,
                'screenshot_count'       => $shots,
                'productivity_score'     => $f['productivity'] ?? 0,
                'compliance_score'       => $compliance,
            ])->save();

        return $summary;
    }

    private function complianceScore(Employee $employee, string $date): float
    {
        $events = EmployeeComplianceEvent::where('employee_id', $employee->id)->whereDate('started_at', $date)->get();
        $weights = [
            'BLOCKED_APP_OPENED' => 5, 'BLOCKED_WEBSITE_OPENED' => 5, 'UNAUTHORIZED_NETWORK' => 8,
            'USB_VIOLATION' => 6, 'AGENT_TAMPER' => 15, 'CAMERA_BLOCKED' => 3,
            'SCREENSHOT_DISABLED' => 8, 'VPN_PROXY_DETECTED' => 6,
        ];
        $sevMult = ['LOW' => 0.5, 'MEDIUM' => 1, 'HIGH' => 1.5, 'CRITICAL' => 2];

        $penalty = 0;
        foreach ($events as $e) {
            $w = $weights[$e->event_type] ?? 3;
            $penalty += $w * ($sevMult[$e->severity] ?? 1);
        }

        return round(max(0, min(100, 100 - $penalty)), 2);
    }

    /** Scheduled shift length in seconds (default 8h when no shift is set). */
    public static function shiftExpectedSeconds($shift): int
    {
        if (! $shift || ! $shift->start_time || ! $shift->end_time) {
            return 8 * 3600;
        }
        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return max(3600, (int) $end->diffInSeconds($start, true));
    }

    /** Allotted break for the day: the shift's break_minutes_allowed (fallback: 1 hour per 9-hour
     *  shift), PRO-RATED to how much of the scheduled shift the employee was actually present. */
    public static function allottedBreakSeconds($shift, int $present): int
    {
        $expected = self::shiftExpectedSeconds($shift);
        $full = ($shift && $shift->break_minutes_allowed !== null)
            ? (int) $shift->break_minutes_allowed * 60
            : (int) round($expected / 9);
        $fraction = $expected > 0 ? min(1.0, max(0.0, $present / $expected)) : 1.0;

        return (int) round($full * $fraction);
    }

    /**
     * [start, end] instants of the employee's COMPANY-LOCAL calendar day, expressed in the
     * app (storage) timezone so a whereBetween() over datetime columns matches the stored
     * wall-clock values. The company timezone falls back to the app default, so single-tz
     * installs are unaffected (bounds equal the plain local day).
     */
    private function localDayBounds(Employee $employee, string $date): array
    {
        $appTz = config('app.timezone', 'UTC');
        $companyTz = ($employee->company?->timezone ?: null) ?: $appTz;

        $start = Carbon::parse($date, $companyTz)->startOfDay()->setTimezone($appTz);
        $end   = Carbon::parse($date, $companyTz)->endOfDay()->setTimezone($appTz);

        return [$start, $end];
    }

    private function sum(string $model, int $employeeId, string $col, string $date, array $where = []): int
    {
        return (int) $model::where('employee_id', $employeeId)->whereDate($col, $date)->where($where)->sum('duration_seconds');
    }

    private function sumRaw(string $table, int $employeeId, string $col, string $date): int
    {
        return (int) \DB::table($table)->where('employee_id', $employeeId)->whereDate($col, $date)->sum('duration_seconds');
    }
}
