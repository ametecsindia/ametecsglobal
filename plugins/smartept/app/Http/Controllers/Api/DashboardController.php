<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ScopesVisibleEmployees;
use App\Support\ResolvesBusinessDay;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\EmployeePresenceEvent;
use App\Models\EmployeeScreenshotLog;
use App\Services\StatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesBusinessDay;
    /** GET /api/dashboard/live-status — cards + employee live table (tenant-scoped). */
    public function liveStatus(Request $request): JsonResponse
    {
        $tz = $this->bizTz($request);
        $today = $this->bizToday($tz);
        $day = $this->dayUtcBounds($today, $tz);
        $onlineWindow = now()->subMinutes(3);
        $visible = $this->scopedEmployeeIds($request);

        $devices = EmployeeDevice::query()->get();

        // 26-Oct-2026: a night-shift break opened before midnight is still open now — any open break of the last 18 h.
        $onBreak = EmployeeBreakLog::whereNull('end_at')->where('start_at', '>=', now()->subHours(18))
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->distinct()->count('employee_id');

        // R4 item 7 + QA Phase 1 B1/B2/B3/B15: employee-centric status, ONE source of truth
        // for cards AND rows so the numbers always agree with the table. Device heartbeat
        // decides ONLINE vs OFFLINE (an instant-logout flip is offline even with a recent
        // heartbeat); the authoritative status_timeline then decides WHICH working state an
        // online employee is in — active / idle / on a specific break / in a meeting — so an
        // employee falls into exactly one category and can never be "active AND on break".
        // An employee can own several device rows — a replaced PC, a second desk, a re-bind,
        // a laptop and a desktop. keyBy() keeps the LAST row for a duplicate key, and the fetch
        // order is simply the table's, so a long-dead device could win over the one the
        // employee is signed in on right now — and a live agent then reads OFFLINE here.
        //
        // 26-Aug-2026 (Ejaz): Enforce02 had DESKTOP-KN8IQRK heartbeating 24 seconds earlier AND
        // DESKTOP-LRCR8LO dead since 24-Aug. The dead one won, so ACTIVE showed 0 while the
        // agent was demonstrably working — screenshots and violations landing all afternoon.
        //
        // Sorting ascending by last heartbeat makes the most recently seen device the one that
        // survives keyBy(), which is the only device that can be the employee's live session.
        $devByEmp = $devices
            ->sortBy(fn ($d) => $d->last_heartbeat_at?->getTimestamp() ?? 0)
            ->keyBy('employee_id');

        $employees = Employee::with(['team:id,name', 'department:id,name', 'shift'])
            ->where('employment_status', 'ACTIVE')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get();

        // 26-Oct-2026: each employee's shift-day running now — a night shift that began yesterday
        // evening is still the current shift after midnight. Active / Idle for the board are that
        // day's figures from the ONE day ledger (break / meeting / away time is never Active or Idle too).
        $cur = [];
        foreach ($employees as $e) {
            $cur[$e->id] = \App\Services\DayTimeline::currentDate($e, $today);
        }
        $activeSecs = [];
        $idleSecs = [];
        if ($cur) {
            foreach (\App\Services\DayTimeline::days($employees, min($cur), max($cur)) as $dd) {
                if ($cur[$dd['emp']->id] === $dd['date']) {
                    $activeSecs[$dd['emp']->id] = $dd['ledger']['sec']['work'];
                    $idleSecs[$dd['emp']->id] = $dd['ledger']['sec']['idle'];
                }
            }
        }

        // Self-heal any stale "ghost" break/meeting left open from a previous shift-day BEFORE we
        // read the live status, so nobody shows as "On break · 16h" (agent killed mid-break).
        // Night shifts: "previous" means before the current shift-day opened, not before midnight.
        foreach ($employees->groupBy(fn ($e) => \App\Services\DayTimeline::window($e, $cur[$e->id])[0]) as $opens => $grp) {
            app(StatusService::class)->closeStaleOpenSegments(
                $grp->pluck('id')->all(),
                \Illuminate\Support\Carbon::parse(date('Y-m-d H:i:s', (int) $opens))
            );
        }

        // Also close any MEETING segment whose meeting has already ended (Completed / Cancelled /
        // Auto-closed / actual_end stamped), so an employee never lingers in "Meeting" on the
        // board after the meeting is over. A still-live (overrunning) meeting is left untouched.
        app(StatusService::class)->closeOrphanedMeetingSegments($employees->pluck('id')->all());

        $openMap = app(StatusService::class)->openStatusMap($employees->pluck('id')->all());
        $now = now();

        $rows = $employees->map(function ($e) use ($devByEmp, $activeSecs, $idleSecs, $onlineWindow, $openMap, $now) {
            $d = $devByEmp->get($e->id);
            $fresh = $d && $d->last_heartbeat_at && $d->last_heartbeat_at->gte($onlineWindow);
            $status = (! $fresh || $d->current_status === 'OFFLINE')
                ? 'OFFLINE'
                : ($d->current_status ?: 'ONLINE');

            // Timeline-derived working status (one category per employee).
            $seg = $openMap[$e->id] ?? null;
            $breakStart = null;
            if ($status === 'OFFLINE') {
                $work = 'OFFLINE';                        // offline never counts as active/idle/break/meeting
            } elseif ($seg && in_array($seg['state'], ['TEA_BREAK', 'LUNCH_BREAK', 'OTHER_BREAK', 'MEETING'], true)) {
                // Breaks and meetings are explicit, one-off transitions — the timeline is right.
                $work = $seg['state'];
                $breakStart = $seg['started_at'];
                // 09-Oct-2026 (Ejaz): door punch-out with no Break clicked is Away, not "Other
                // break" — same rule the Productivity report has used since 28-Sep.
                if ($work === 'OTHER_BREAK' && ($seg['source'] ?? null) === 'BIOMETRIC') {
                    $work = 'AWAY';
                }
            } else {
                // 28-Sep-2026: Active vs Idle comes from the device heartbeat, NOT the timeline.
                // The timeline's ACTIVE/IDLE segments are built from COMPLETED activity stretches
                // the agent flushes once a minute and syncs up to 15s later — so it trails the
                // agent by ~1–2 min, and a retried (older) batch can flip it back to IDLE. The
                // heartbeat carries the agent's own current state every 30s, which is exactly
                // what the agent window shows. Timeline ACTIVE/IDLE is still used for reports.
                $work = match ($status) {
                    'IDLE', 'AWAY' => 'IDLE',
                    default        => 'ACTIVE',
                };
            }

            return [
                'employee_id'    => $e->id,
                'name'           => $e->fullName(),
                'department'     => $e->department?->name,
                'team'           => $e->team?->name,
                'status'         => $status,                       // device status (backward compatible)
                'work_status'    => $work,                         // timeline-derived working state
                'break_started_at' => $breakStart,
                'elapsed'        => $breakStart ? max(0, $now->getTimestamp() - $breakStart->getTimestamp()) : null,
                'last_seen'      => $d?->last_heartbeat_at,
                'active_seconds' => (int) ($activeSecs[$e->id] ?? 0),
                'idle_seconds'   => (int) ($idleSecs[$e->id] ?? 0),
                'compliance_status' => $d?->compliance_status,
            ];
        });

        $byStatus = fn ($s) => $rows->where('status', $s)->count();
        $byWork = fn ($s) => $rows->where('work_status', $s)->count();
        $breakTea = $byWork('TEA_BREAK');
        $breakLunch = $byWork('LUNCH_BREAK');
        $breakOther = $byWork('OTHER_BREAK');

        $cards = [
            'total_employees'   => $rows->count(),
            // Legacy device-status cards (kept so existing consumers don't break).
            'active_now'        => $byStatus('ONLINE'),
            'idle_now'          => $byStatus('IDLE'),
            'away_now'          => $byStatus('AWAY'),
            'offline'           => $byStatus('OFFLINE'),
            'on_break'          => $onBreak,
            // QA Phase 1 timeline-derived cards (one employee → exactly one of these).
            'active'            => $byWork('ACTIVE'),
            'idle'              => $byWork('IDLE'),
            'break_total'       => $breakTea + $breakLunch + $breakOther,
            'break_tea'         => $breakTea,
            'break_lunch'       => $breakLunch,
            'break_other'       => $breakOther,
            'meeting'           => $byWork('MEETING'),
            'away'              => $byWork('AWAY'),
            'offline_count'     => $byWork('OFFLINE'),
            // 19-Oct-2026 (Ejaz): Signed in = Active + Idle + Away + Break + Meeting (everyone not Offline);
            // Present = checked in today as per Attendance.
            'signed_in'         => $rows->where('work_status', '!=', 'OFFLINE')->count(),
            // 26-Oct-2026: against each employee's CURRENT shift-day (a night shift still on after midnight counts).
            'present_today'     => \App\Models\EmployeeAttendanceLog::whereIn('work_date', array_values(array_unique($cur ?: [$today])))
                ->whereIn('employee_id', $rows->pluck('employee_id'))->whereNotNull('check_in_at')
                ->whereNotIn('status', ['ABSENT', 'ON_LEAVE'])->get(['employee_id', 'work_date'])
                ->filter(fn ($a) => ($cur[$a->employee_id] ?? $today) === \Illuminate\Support\Carbon::parse($a->work_date)->toDateString())
                ->unique('employee_id')->count(),
            'camera_blocked'    => EmployeePresenceEvent::whereDate('started_at', $today)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->where('event_type', 'CAMERA_BLOCKED')->distinct()->count('employee_id'),
            'violations_today'  => EmployeeComplianceEvent::whereDate('started_at', $today)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'screenshots_today' => EmployeeScreenshotLog::whereDate('captured_at', $today)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
        ];

        return response()->json(['cards' => $cards, 'employees' => $rows->values()]);
    }

    /** GET /api/dashboard/summary — headline counts for a date. */
    public function summary(Request $request): JsonResponse
    {
        $tz = $this->bizTz($request);
        $date = $request->query('date', $this->bizToday($tz));
        $day = $this->dayUtcBounds($date, $tz);
        $visible = $this->visibleEmployeeIds($request->user());

        return response()->json([
            'date'              => $date,
            'employees'         => Employee::where('employment_status', 'ACTIVE')->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->count(),
            'devices'           => EmployeeDevice::when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'violations'        => EmployeeComplianceEvent::whereDate('started_at', $date)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            'screenshots'       => EmployeeScreenshotLog::whereDate('captured_at', $date)->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))->count(),
            // 26-Oct-2026: Working hours from the one day ledger (never inside a break / meeting / Away; night shifts whole).
            'active_hours_total' => round(array_sum(array_map(fn ($d) => $d['ledger']['sec']['work'], \App\Services\DayTimeline::days(
                Employee::with('shift')->where('employment_status', 'ACTIVE')->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->get(), $date, $date))) / 3600, 1),
        ]);
    }

    /** GET /api/dashboard/device-health — agent + device health overview. */
    public function deviceHealth(Request $request): JsonResponse
    {
        $visible = $this->scopedEmployeeIds($request);
        $devices = EmployeeDevice::with('employee:id,first_name,last_name,employee_code')
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->orderByDesc('last_heartbeat_at')
            ->get(array_merge(['id', 'employee_id', 'device_uuid', 'computer_name', 'os_version', 'app_version', 'agent_health', 'compliance_status', 'current_status', 'sync_pending_count', 'last_heartbeat_at', 'last_sync_at'],
                // 14-Oct-2026: why DEGRADED — clock gap and since / until (after the migration has run)
                \Illuminate\Support\Facades\Schema::hasColumn('employee_devices', 'clock_skew_since') ? ['clock_skew_seconds', 'clock_skew_since', 'clock_skew_until'] : []));

        // EPT-25: agent-stopped alert. A force-killed agent stops sending heartbeats
        // but its stored agent_health stays at the last value ('HEALTHY'). Flip it to
        // STOPPED at read-time when the last heartbeat is stale (>10 min) so the admin
        // sees the gap immediately. (Read-only override — not persisted.)
        $staleAfter = now()->subMinutes(10);
        $devices->each(function ($d) use ($staleAfter) {
            if (! $d->last_heartbeat_at || $d->last_heartbeat_at->lt($staleAfter)) {
                $d->agent_health = 'STOPPED';
            }
        });

        return response()->json(['data' => $devices]);
    }
}
