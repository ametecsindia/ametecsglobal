<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Services\GateService;
use App\Services\OutboundPusher;
use App\Services\StatusService;
use App\Services\WorkCalendar;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    use ResolvesAgentContext;

    /**
     * POST /api/agent/attendance-event
     * Handles LOGIN / LOGOUT / LOCK / UNLOCK from the client, maintaining the login
     * session and the day's attendance record (with late / early-logout minutes).
     */
    /**
     * GET /api/agent/gate-status — Gate-to-PC (USP). Tells the agent whether it
     * may start a work session: open only when a door/biometric IN punch has
     * reached SmartEPT for the day (or no gate policy is set).
     */
    public function gateStatus(Request $request, GateService $gate): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        return response()->json($gate->statusFor($employee, $this->agentDevice($request, $employee)));
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid' => ['required', 'string'],
            'event_type'  => ['required', 'in:LOGIN,LOGOUT,LOCK,UNLOCK'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $device = $this->agentDevice($request, $employee);

        // GATE-TO-PC (USP): a LOGIN/UNLOCK is refused until the door punch arrives.
        // The work clock therefore only ever starts on real, verified arrival — unless a
        // standing exemption covers this device / person / team / department / branch.
        if (in_array($data['event_type'], ['LOGIN', 'UNLOCK'], true)
            && ! app(GateService::class)->isOpen($employee, $device)) {
            return response()->json([
                'error' => ['code' => 'GATE_CLOSED',
                    'message' => 'No door punch received yet. Punch IN at the entrance to start your session.'],
            ], 423);
        }

        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $workDate = $at->toDateString();

        // 09-Nov-2026 (Ejaz: app Attendance showed check-in 2:31 AM, the QR IN was 8:51 AM): the gate above judges the
        // state NOW, but the event carries its own time. A sign-in stamped BEFORE that day's first gate IN — a PC on the
        // wrong time zone at start-up, or an event queued while offline and sent after the scan — never becomes the
        // check-in or a session; neither does a lock / sign-out from before arrival with no session open.
        if (app(GateService::class)->statusFor($employee, $device)['gate_required']) {
            $firstIn = \App\Models\BiometricLog::withoutGlobalScopes()->where('employee_id', $employee->id)
                ->whereIn('punch_type', ['IN', 'BREAK_IN'])->whereDate('punched_at', $workDate)->min('punched_at');
            $beforeArrival = ! $firstIn || $at->lessThan(Carbon::parse($firstIn));
            $noSession = ! EmployeeLoginSession::where('employee_id', $employee->id)->whereNull('logout_at')->exists();
            if ($beforeArrival && (in_array($data['event_type'], ['LOGIN', 'UNLOCK'], true) || $noSession)) {
                return response()->json(['ok' => true, 'ignored' => 'BEFORE_GATE_IN']);
            }
        }

        // whereDate lookup: portable across MySQL and sqlite for the date-cast
        // work_date column (a plain firstOrCreate() where-match misses on sqlite).
        // Matches ANY source so a biometric-created row is reused, not duplicated.
        $attendance = EmployeeAttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)->first()
            ?? EmployeeAttendanceLog::create([
                'employee_id' => $employee->id, 'work_date' => $workDate, 'source' => 'CLIENT',
                'company_id' => $employee->company_id, 'device_uuid' => $data['device_uuid'], 'status' => 'PRESENT',
            ]);

        match ($data['event_type']) {
            'LOGIN', 'UNLOCK' => $this->handleLogin($employee, $data['device_uuid'], $at, $attendance, $request->ip()),
            'LOGOUT', 'LOCK'  => $this->handleLogout($employee, $at, $attendance, $data['event_type']),
        };

        // QA Phase 1 (dual-write): mirror the session event into the authoritative
        // status timeline. LOGIN opens ACTIVE; UNLOCK resumes work; LOCK forces IDLE
        // (respecting an open manual break); LOGOUT closes the day. Wrapped so a
        // timeline hiccup can never fail the primary attendance write.
        try {
            $status = app(StatusService::class);
            $opts = ['device_uuid' => $data['device_uuid']];
            match ($data['event_type']) {
                // A fresh LOGIN resumes work and CLOSES any stale open break/meeting left
                // from a prior session (agent killed mid-break → a 16-hour "On break" ghost
                // on the live board). resume bypasses the ambient guard so the stale segment
                // is closed and ACTIVE opens cleanly.
                'LOGIN'  => $status->resumeActive($employee, $at, $opts),
                'UNLOCK' => $status->resumeActive($employee, $at, $opts),
                'LOCK'   => $status->forceIdle($employee, $at, 'LOCK', $opts),
                'LOGOUT' => $status->closeAll($employee, $at),
            };
        } catch (\Throwable $e) {
            Log::warning('StatusService mirror failed on attendance event', ['e' => $e->getMessage()]);
        }

        // R4 item 7: the live dashboard must flip THE INSTANT a session event lands —
        // never wait for the heartbeat window to expire.
        EmployeeDevice::where('device_uuid', $data['device_uuid'])->update([
            'last_sync_at'   => now(),
            'current_status' => match ($data['event_type']) {
                'LOGIN', 'UNLOCK' => 'ONLINE',
                'LOCK'            => 'AWAY',
                'LOGOUT'          => 'OFFLINE',
            },
        ]);

        // Biometric-style real-time relay (Ejaz 17-Jul): forward this login/logout
        // to any outbound target subscribed to "attendance.punch" (e.g. SmartPRS),
        // so SmartEPT acts like a punch device. LOGIN/UNLOCK = IN, LOGOUT/LOCK = OUT.
        // Best-effort: OutboundPusher swallows target failures, never breaks the agent.
        try {
            $punch = in_array($data['event_type'], ['LOGIN', 'UNLOCK'], true) ? 'IN' : 'OUT';
            app(OutboundPusher::class)->relayPunch(
                $employee->company_id, $employee, $punch, $at, $data['device_uuid'], 'AGENT'
            );
        } catch (\Throwable $e) { /* relay is never allowed to fail the punch */ }
        // AmetecsGlobal: the same punch into its attendance (SmartPRS 3 payroll, late marks), after the agent's answer
        try {
            \App\Services\AgBridge::punch($employee->company ?? \App\Models\Company::find($employee->company_id), $employee, $punch, $at, $data['device_uuid']);
        } catch (\Throwable $e) { /* never fails the punch */ }

        return response()->json(['ok' => true], 201);
    }

    private function handleLogin($employee, string $uuid, Carbon $at, EmployeeAttendanceLog $attendance, ?string $ip): void
    {
        // Open a session only if there is no currently-open one.
        $open = EmployeeLoginSession::where('employee_id', $employee->id)
            ->whereNull('logout_at')->latest('login_at')->first();

        if (! $open) {
            EmployeeLoginSession::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'device_uuid' => $uuid, 'session_type' => 'CLIENT',
                'login_at' => $at, 'login_ip' => $ip,
            ]);
        }

        $updates = ['last_activity_at' => $at];
        if (! $attendance->check_in_at) {
            $updates['check_in_at'] = $at;
            $updates['check_in_source'] = 'AGENT';
            $updates['first_activity_at'] = $at;
        }

        // QA Phase 3 (B8/D2) + late-capture fix (Admin #6): the late formula lives in
        // AttendanceDerivation so agent login, biometric-only and nightly recompute all
        // agree. It is WRITE-ONCE, but must be set even when a biometric door punch created
        // the attendance row (and stamped check_in_at) BEFORE the employee signed in —
        // otherwise those employees never got late_minutes (captured for one emp, missing
        // for another). arrival_source_used is null until late is derived, so it is the
        // reliable "not computed yet" flag (late_minutes defaults to 0, so can't be).
        if (empty($attendance->arrival_source_used)) {
            $bioInRaw = \App\Models\BiometricLog::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->where('punch_type', 'IN')
                ->whereDate('punched_at', $at->toDateString())
                ->min('punched_at');
            $bioIn = $bioInRaw ? Carbon::parse($bioInRaw) : null;

            $late = app(\App\Services\AttendanceDerivation::class)
                ->lateFor($employee, $at->toDateString(), $at, $bioIn, $at);
            if ($late) {
                $updates['late_minutes'] = $late['minutes'];
                $updates['arrival_source_used'] = $late['used'];
            }
        }
        // QA Phase 2 (A2): first_login_at is WRITE-ONCE for the day; last_login_at always
        // tracks the most recent login/unlock. /today reads first_login_at so the shown
        // login time is stable across re-logins.
        if (! $attendance->first_login_at) {
            $updates['first_login_at'] = $at;
        }
        $updates['last_login_at'] = $at;
        // 12-Oct-2026 (Ejaz: report said "Logged out 10:00 now" at 10:35): back at work after a
        // LOCK / LOGOUT / auto sign-out - that earlier logout is no longer the day's end. Left in
        // place it froze today's logout at the old time. The next real logout stamps it again.
        if ($attendance->check_out_at && $attendance->check_out_at->lessThanOrEqualTo($at)) {
            $updates['check_out_at'] = null;
            $updates['final_logout_at'] = null;
            $updates['early_logout_minutes'] = 0;
        }
        $attendance->update($updates);
    }

    private function handleLogout($employee, Carbon $at, EmployeeAttendanceLog $attendance, string $reason): void
    {
        $open = EmployeeLoginSession::where('employee_id', $employee->id)
            ->whereNull('logout_at')->latest('login_at')->first();

        if ($open) {
            $open->update([
                'logout_at' => $at,
                'duration_seconds' => $open->login_at ? (int) $at->diffInSeconds($open->login_at, true) : null,
                'logout_reason' => $reason === 'LOCK' ? 'LOCK' : 'USER',
            ]);
        }

        $updates = [
            'check_out_at' => $at,
            'last_activity_at' => $at,
            'early_logout_minutes' => $this->earlyLogoutMinutes($employee, $at),
        ];
        // QA Phase 2 (A2): a real LOGOUT closes the working day; a LOCK is temporary and
        // must NOT stamp the final logout.
        if ($reason === 'LOGOUT') {
            $updates['final_logout_at'] = $at;
        }
        $attendance->update($updates);
    }

    private function earlyLogoutMinutes($employee, Carbon $at): int
    {
        $shift = $employee->shiftOn($at); // 13-Oct-2026: that day's own end time
        // No early-logout penalty on weekly offs / holidays.
        if (! $shift || ! app(WorkCalendar::class)->isWorkingDay($employee, $at->toDateString())) {
            return 0;
        }
        $end = Carbon::parse($at->toDateString() . ' ' . $shift->end_time);
        return $at->lessThan($end) ? (int) $end->diffInMinutes($at, true) : 0;
    }
}
