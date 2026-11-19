<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeIdleLog;
use App\Models\EmployeeLoginSession;
use App\Services\StatusService;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentStatusController extends Controller
{
    use ResolvesAgentContext;

    /**
     * GET /api/agent/gate-status — Biometric Gate (Doc 11 v1.1).
     * The agent polls this (and reads the same block off every heartbeat) to know
     * whether the door punch has lifted the gate. Deliberately OUTSIDE the consent
     * wall: it is a pre-work check, and it exposes only the caller's own punch state.
     */
    public function gateStatus(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $gate = app(\App\Services\GateService::class);

        // Resolved OUTSIDE the try: a device/token mismatch must surface as its own 403,
        // not be swallowed by the fail-closed catch below and reported as SERVER_ERROR.
        // Null when the caller sends no device_uuid (the agent's bare poll) — the
        // exclusion walk then simply starts at EMPLOYEE.
        $device = $this->agentDevice($request, $employee);

        // 10-Nov-2026 (Ejaz): the punch-in screen sends no heartbeats, so its 10-minute limit is checked on this poll too
        // (same command the heartbeat and the scheduler run, once a minute per employee). Revoked → the next poll is a 401.
        if (\Illuminate\Support\Facades\Cache::add('autologout-hb:' . $employee->id, 1, 60)) {
            try {
                \Illuminate\Support\Facades\Artisan::call('smartept:auto-logout', ['--employee' => $employee->id]);
            } catch (\Throwable $e) {
                report($e); // never fail the gate poll over this
            }
        }

        try {
            // QA Phase 2 (A3): return BOTH shapes. The nested `gate` block keeps the
            // existing console/heartbeat consumers working; the TOP-LEVEL
            // {gate_required, open, message, reason} is what the agent actually reads
            // in ensureGateThenBegin (it was reading top-level keys that never existed —
            // the gate silently never blocked). Now they exist and are correct.
            $status = $gate->statusFor($employee, $device);      // gate_required, open, message, reason
            $status['gate'] = $gate->stateFor($employee, $device); // backward-compatible nested block

            return response()->json($status);
        } catch (\Throwable $e) {
            // Fail CLOSED for the USP: if we cannot compute the gate, tell the agent it
            // is required and not open, with a diagnosable reason.
            return response()->json([
                'gate_required' => true,
                'open' => false,
                'message' => 'Gate status is temporarily unavailable — please wait.',
                'reason' => 'SERVER_ERROR',
                'gate' => ['enabled' => true, 'state' => 'OUT', 'arrived' => false, 'last_punch_at' => null,
                    'message' => 'Gate status is temporarily unavailable — please wait.'],
            ], 200);
        }
    }

    /**
     * GET /api/agent/today
     * Server-side truth for the employee's visible dashboard: today's active / idle /
     * break / meeting totals and login time. The agent renders these so the numbers
     * can't drift.
     *
     * QA Phase 1: active / idle / break stay on the per-event legacy sums (which carry the
     * agent's precise reported durations and must not regress), while the authoritative
     * status_timeline ADDITIVELY contributes what those sums never carried — meeting time
     * (productive, reported separately) and the Tea/Lunch/Other break split — so the agent
     * can show them and they agree with the live console. Full read-switch of active/idle
     * onto the timeline waits until the agent emits server-time segments (D6, later phase).
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $today = now()->toDateString();

        // 26-Oct-2026 (Ejaz): the agent's card shows the SAME figures as the Productivity report — the one
        // day ledger (DayTimeline) for the shift-day running now (a night shift stays one day past midnight).
        // Each second has one status, so break / meeting time is never also Active or Idle.
        $today = \App\Services\DayTimeline::currentDate($employee, $today);
        $L = \App\Services\DayTimeline::ledger($employee, $today);
        $sec = $L['sec'] ?? array_fill_keys(\App\Services\DayTimeline::ORDER, 0);
        $split = ['TEA' => 0, 'LUNCH' => 0, 'OTHER' => 0];
        foreach ($L['parts'] ?? [] as $p) {
            if ($p[0] === 'break') {
                $type = strtoupper((string) ($L['raw']['brk'][(int) substr($p[1], 1)]['type'] ?? ''));
                $split[isset($split[$type]) ? $type : 'OTHER'] += $p[3];
            }
        }

        // QA Phase 2 (A1/A2): the shown login time must never be blank right after a
        // login. Prefer the write-once first_login_at, then the earliest login session,
        // then the attendance check-in — so a materialisation race can't render "—".
        $attendance = EmployeeAttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $today)->first();
        // 23-Sep-2026 (Ejaz): the check_in_at fallback was the DOOR punch on a biometric site, so
        // the agent showed the gate time first and then flipped to the sign-in time. Two separate
        // values now: Sign IN never falls back to a door punch, and Gate IN is its own field.
        $firstLogin = $attendance?->first_login_at
            ?? EmployeeLoginSession::where('employee_id', $employee->id)->whereDate('login_at', $today)->min('login_at')
            ?? (($attendance?->check_in_source ?? null) === 'BIOMETRIC' ? null : $attendance?->check_in_at);
        // First door IN of the day; null where the company has no biometric reader.
        $gateIn = \App\Models\BiometricLog::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->whereDate('punched_at', $today)->whereIn('punch_type', ['IN', 'BREAK_IN'])
            // 10-Nov-2026: on QR Attendance a reader punch is not a gate IN (it showed "Gate IN 8:37 AM" with no QR scan).
            ->when(\App\Models\BiometricLog::qrOnly((int) $employee->company_id), fn ($q) => $q->where('external_id', 'like', \App\Models\BiometricLog::QR_PREFIX . '%'))
            ->min('punched_at');
        if ($L) {   // night shift: the shift-day's own first sign-in / door IN
            $firstLogin = $L['pc_in'] !== null ? date('Y-m-d H:i:s', $L['pc_in']) : $firstLogin;
            $gateIn = $L['gate_in'] !== null ? date('Y-m-d H:i:s', $L['gate_in']) : $gateIn;
        }


        return response()->json([
            'employee'            => ['id' => $employee->id, 'name' => $employee->fullName()],
            'date'                => $today,
            'logged_in_at'        => $firstLogin,
            'gate_in_at'          => $gateIn,
            'active_seconds'      => $sec['work'],
            'idle_seconds'        => $sec['idle'],
            'break_seconds'       => $sec['break'],
            'break_tea_seconds'   => $split['TEA'],
            'break_lunch_seconds' => $split['LUNCH'],
            'break_other_seconds' => $split['OTHER'],
            'meeting_seconds'     => $sec['meeting'],
            'away_seconds'        => $sec['away'],
            'server_time'         => now()->toIso8601String(),
            'timezone'            => config('app.timezone'), // 09-Nov-2026: Organisation time zone
        ]);
    }
}
