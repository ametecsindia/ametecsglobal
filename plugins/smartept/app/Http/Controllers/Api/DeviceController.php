<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Support\ResolvesLocalNow;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeviceController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesLocalNow;

    /** GET /api/devices — admin/manager view of registered endpoints (tenant-scoped). */
    public function index(Request $request): JsonResponse
    {
        $visible = $this->visibleEmployeeIds($request->user());
        $devices = EmployeeDevice::query()
            ->with('employee:id,first_name,last_name,employee_code,team_id')
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->when($request->status, fn ($q, $v) => $q->where('current_status', $v))
            ->latest('last_heartbeat_at')
            ->paginate((int) $request->integer('per_page', 25));

        // 26-Aug-2026: a PC that is switched off sends no "goodbye", it just stops
        // heartbeating, so `current_status` kept whatever the last heartbeat wrote and the
        // screen showed employees ONLINE indefinitely.
        //
        // Fixed at READ time, not by a scheduled job that writes OFFLINE into the column.
        // A writer fights the heartbeat for the same field — 30s apart, opposite directions —
        // and whichever ran last wins, which is how a live agent ends up displayed as offline.
        // The Live Dashboard already decides this at read time (3-minute window), and
        // deviceHealth() below already overrides agent_health the same way. One convention.
        $stale = now()->subMinutes(10);
        $devices->getCollection()->each(function ($d) use ($stale) {
            if (! $d->last_heartbeat_at || $d->last_heartbeat_at->lt($stale)) {
                $d->current_status = 'OFFLINE';
            }
        });

        return response()->json($devices);
    }

    /**
     * POST /api/agent/register-device
     * Binds the calling employee's account to a hardware device_uuid and returns a
     * device-scoped token the agent uses for all subsequent agent endpoints.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid'          => ['required', 'string', 'max:191'],
            'computer_name'        => ['nullable', 'string', 'max:191'],
            'os_version'           => ['nullable', 'string', 'max:191'],
            'windows_username'     => ['nullable', 'string', 'max:191'],
            'lan_ip'               => ['nullable', 'string', 'max:64'],
            'public_ip'            => ['nullable', 'string', 'max:64'],
            'mac_address'          => ['nullable', 'string', 'max:64'],
            'wifi_ssid'            => ['nullable', 'string', 'max:191'],
            'processor'            => ['nullable', 'string', 'max:191'],
            'ram_gb'               => ['nullable', 'integer'],
            'disk_gb'              => ['nullable', 'integer'],
            'camera_available'     => ['nullable', 'boolean'],
            'microphone_available' => ['nullable', 'boolean'],
            'app_version'          => ['nullable', 'string', 'max:32'],
            'service_version'      => ['nullable', 'string', 'max:32'],
        ]);

        $employee = $this->resolveEmployee($request);

        // R2-1 seat enforcement: a brand-new device claims a licence seat on Central.
        // Re-registration of a known device never re-claims. Central down → allowed
        // (offline-tolerant); the daily validate reconciles seats.
        // UNSCOPED lookup (Ejaz, 12-Aug-2026): device_uuid is globally unique, but the
        // BelongsToCompany scope hid rows registered under ANOTHER company — the insert
        // then hit the unique index (1062 → HTTP 500) whenever a PC that once signed in
        // for one company signed in for a different one. A physical PC is looked up
        // globally; registration below re-points it to the current employee's company.
        $existing = EmployeeDevice::withoutGlobalScopes()
            ->where('device_uuid', $data['device_uuid'])->first();

        // 28-Sep-2026: "device was unbound … approve a re-bind" while the PC was NOT on the
        // Devices screen, so there was nothing to approve. The lookup above is unscoped but
        // the Devices screen is company-scoped AND narrowed to the viewer's visible employees,
        // so the blocking row was invisible whenever it (a) belonged to ANOTHER company, or
        // (b) still pointed at an employee outside the viewer's scope — a relieved/deleted
        // previous user of a shared PC, or someone in another branch.
        $crossTenant = $existing && $existing->company_id !== $employee->company_id;

        // (a) Another company's unbind is that company's decision about ITS seat (already
        // released at unbind time). It cannot bind this company, whose admin can never see or
        // re-bind that row. For this company the PC is simply a new device: it claims a seat
        // below and the row is re-pointed and un-blocked by the updateOrCreate.
        //
        // (b) Same company: the block stands (R2-3), but the row is re-pointed to the employee
        // now at this PC, so it appears on the Devices screen for exactly the admins who manage
        // that employee, under their name, with "Approve re-bind". Audited with the previous
        // owner, so nothing is lost.
        if ($existing && $existing->unbound_at && ! $crossTenant) {
            $previousEmployeeId = $existing->employee_id;
            $existing->forceFill(array_filter([
                'employee_id'   => $employee->id,
                'computer_name' => $data['computer_name'] ?? null,
            ], fn ($v) => $v !== null))->save();

            $this->audit($request, 'LOGIN_DENIED_DEVICE_UNBOUND', EmployeeDevice::class, $existing->id, [
                'device_uuid'           => $data['device_uuid'],
                'previous_employee_id'  => $previousEmployeeId,
                'attempted_employee_id' => $employee->id,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'DEVICE_UNBOUND',
                    'message' => 'This device (' . ($existing->computer_name ?: 'this PC')
                        . ') was unbound by an administrator. Ask them to approve a re-bind on the Devices screen.',
                ],
            ], 409);
        }

        // Shift-bounded sign-in (Ejaz, 26-Aug-2026): outside the employee's shift the agent is
        // refused. Enforced HERE and nowhere else on purpose — /auth/login is shared with the
        // admin console, so gating that would lock admins out of the console after hours,
        // while register-device is only ever called by the agent.
        //
        // Off unless the shift opts in, so an upgrade changes nothing until an admin says so.
        // 10-Oct-2026 (Ejaz): employees allowed to work outside shift hours are not refused.
        $shift = $employee->shift;
        if ($shift?->restrict_login_to_shift && ! $employee->work_outside_shift
            && ! $shift->coversSignInAt($this->localNow($employee->company_id))) {
            return response()->json([
                'error' => [
                    'code' => 'OUTSIDE_SHIFT',
                    'message' => 'Sign-in is only allowed during your shift (' . $shift->windowLabel()
                        . '). Please sign in during those hours, or ask your administrator.',
                ],
            ], 403);
        }

        // 08-Nov-2026 (Ejaz): not allowed outside shift hours and past today's shift end + auto sign-out minutes → refuse
        // here, with the reason, instead of letting the agent through the QR wall only to be signed out after the scan.
        if ($cutoff = \App\Console\Commands\AutoLogoutPostShift::refusedAfterShiftAt($employee, $this->localNow($employee->company_id))) {
            return response()->json([
                'error' => [
                    'code' => 'OUTSIDE_SHIFT',
                    'message' => 'Your shift has ended (auto sign-out at ' . $cutoff->format('g:i A')
                        . '). Sign-in outside shift hours is not allowed — ask your administrator to allow work outside shift.',
                ],
            ], 403);
        }

        // A PC arriving from another company is new to THIS company's licence: claim a seat here.
        $isNewDevice = $existing === null || $crossTenant;

        // Section 10: server-enforced single active session per employee. Serialise
        // concurrent logins for this employee with a short lock so two PCs racing to
        // sign in cannot BOTH win, then deny if another PC already holds a live session.
        $staleMinutes = (int) config('smartept.session_stale_minutes', 10);
        $lock = Cache::lock('agent-login:emp:' . $employee->id, 10);
        try {
            $lock->block(5);
        } catch (\Throwable $e) {
            return response()->json(['error' => ['code' => 'LOGIN_BUSY', 'message' => 'Another sign-in is in progress — please try again.']], 409);
        }

        try {
            $activeOther = EmployeeDevice::where('employee_id', $employee->id)
                ->where('device_uuid', '!=', $data['device_uuid'])
                ->where('session_status', 'ACTIVE')
                ->whereNull('unbound_at')
                ->where('last_heartbeat_at', '>=', now()->subMinutes($staleMinutes))
                ->first();

            if ($activeOther) {
                $this->audit($request, 'LOGIN_DENIED_CONCURRENT', EmployeeDevice::class, $activeOther->id, [
                    'attempted_device' => $data['device_uuid'],
                    'active_device'     => $activeOther->device_uuid,
                ]);

                return response()->json([
                    'error' => [
                        'code' => 'SINGLE_SESSION_ACTIVE',
                        'message' => 'Your SmartEPT account is already active on another computer ('
                            . ($activeOther->computer_name ?: 'another PC') . '). Please log out from the other computer or contact your administrator.',
                    ],
                ], 409);
            }

            if ($isNewDevice) {
                // Ejaz, 14-Aug-2026 (finding 1.4): check the cap HERE first. The
                // Central call below deliberately fails open when Central is
                // unreachable, which meant an offline server could bind PCs
                // forever. This local count cannot be dodged that way.
                if ($why = app(\App\Services\LicenceSeats::class)->blockedReason($employee->company_id, 'device')) {
                    return response()->json([
                        'error' => ['code' => 'LICENSE_SEAT_BLOCKED', 'reason' => 'device_limit_reached', 'message' => $why],
                    ], 409);
                }

                $seat = app(\App\Services\LicenseClient::class)
                    ->activateDevice($data['device_uuid'], $data['computer_name'] ?? null, $employee->company_id);

                if (! $seat['ok']) {
                    return response()->json([
                        'error' => [
                            'code' => 'LICENSE_SEAT_BLOCKED',
                            'reason' => $seat['reason'],
                            'message' => $seat['reason'] === 'device_limit_reached'
                                ? 'All licensed device seats are in use. Free a seat (offboard/unbind a device) or upgrade the licence.'
                                : 'This device cannot be activated on the current licence (' . $seat['reason'] . ').',
                        ],
                    ], 409);
                }
            }

            // Device moved between companies (test PC / reassigned laptop) — record it
            // before the row is re-pointed so the transfer is auditable.
            if ($existing && $existing->company_id !== $employee->company_id) {
                $this->audit($request, 'DEVICE_COMPANY_REASSIGNED', EmployeeDevice::class, $existing->id, [
                    'device_uuid'     => $data['device_uuid'],
                    'from_company_id' => $existing->company_id,
                    'to_company_id'   => $employee->company_id,
                ]);
            }

            // A machine crossing tenants must NOT carry the previous company's policy
            // overrides with it: gate_mode EXCLUDED set by client A's admin would silently
            // lift Gate-to-PC for client B's employee, and device_uuid is agent-supplied,
            // so this is reachable on purpose as well as by accident. Reset to inherit.
            $crossTenantReset = ($existing && $existing->company_id !== $employee->company_id)
                ? ['gate_mode' => null, 'gate_mode_from' => null, 'gate_mode_until' => null,
                    'gate_mode_reason' => null, 'gate_mode_by_user_id' => null, 'tracking_mode' => null,
                    'unbound_at' => null] // the other company's unbind does not follow the PC
                : [];

            // The previous company still holds a seat for a PC it no longer has — release it.
            if ($crossTenant && ! $existing->unbound_at) {
                app(\App\Services\LicenseClient::class)->deactivateDevice($existing->device_uuid, $existing->company_id);
            }

            // Unscoped for the same reason as the lookup above: the row may currently
            // belong to another company; updateOrCreate must SEE it to update, not insert.
            $device = EmployeeDevice::withoutGlobalScopes()->updateOrCreate(
                ['device_uuid' => $data['device_uuid']],
                array_merge($data, $crossTenantReset, [
                    'company_id'      => $employee->company_id,
                    'employee_id'     => $employee->id,
                    'current_status'  => 'ONLINE',
                    'agent_health'    => 'HEALTHY',
                    'registered_at'   => now(),
                    'last_heartbeat_at' => now(),
                    'session_status'  => 'ACTIVE',
                    'last_login_at'   => now(),
                    'force_logout_at' => null,
                ])
            );

            // Issue a device-scoped token (ability 'agent') on the employee's user account.
            $user = $request->user();
            $ttl = config('smartept.agent_token_ttl');
            $token = $user->createToken(
                'device:' . $data['device_uuid'],
                ['agent'],
                $ttl ? now()->addMinutes($ttl) : null
            )->plainTextToken;

            $device->forceFill(['device_token_hash' => hash('sha256', $token)])->save();
            // 08-Nov-2026 (Ejaz: "first sign-in goes back to the login screen"): a fresh sign-in starts with no Idle.
            // The Idle-since mark from before the sign-in was read by "Still working?" as hours of Idle beyond shift,
            // so an after-hours sign-in was asked at once and signed out a minute later.
            Cache::forget('sw-idle-since:' . $device->id);

            // 29-Sep-2026: a new sign-in starts a new chat — nothing from an earlier session.
            \App\Models\EmployeeChatMessage::clearFor($employee->id);

            // Section 10: this device is now THE session — retire every OTHER of the
            // employee's sessions (revoke their agent tokens so a resuming stale PC gets
            // 401 → login), so exactly one PC is ever active.
            $retired = EmployeeDevice::where('employee_id', $employee->id)
                ->where('device_uuid', '!=', $data['device_uuid'])
                ->where('session_status', 'ACTIVE')
                ->get();
            foreach ($retired as $o) {
                $o->revokeAgentToken();
                $o->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);
            }
            if ($retired->isNotEmpty()) {
                $this->audit($request, 'SESSION_TAKEOVER', EmployeeDevice::class, $device->id, [
                    'retired' => $retired->pluck('device_uuid'),
                ]);
            }

            $this->audit($request, 'REGISTER_DEVICE', EmployeeDevice::class, $device->id, ['device_uuid' => $device->device_uuid]);

            return response()->json([
                'timezone'     => config('app.timezone'), // 09-Nov-2026: Organisation time zone, known from the first sign-in
                'device_token' => $token,
                'device'       => $device,
                'employee'     => [
                    'id'   => $employee->id,
                    'name' => $employee->fullName(),
                    'code' => $employee->employee_code,
                ],
            ], 201);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * POST /api/agent/heartbeat
     * 30-second liveness ping. Requires the 'agent' token ability.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');

        $data = $request->validate([
            'device_uuid'    => ['required', 'string'],
            'status'         => ['nullable', 'in:ONLINE,ACTIVE,IDLE,AWAY,OFFLINE'],
            'app_version'    => ['nullable', 'string', 'max:32'],
            'service_version' => ['nullable', 'string', 'max:32'],
            'sync_pending'   => ['nullable', 'integer', 'min:0'],
            'client_time'    => ['nullable', 'date'],
            // LiveView multi-screen (14-Sep-2026): screen.getAllDisplays().length, so the
            // admin's monitor picker can offer "Desktop 1..N" for what this PC actually has.
            'monitor_count'  => ['nullable', 'integer', 'min:1', 'max:16'],
        ]);

        $device = EmployeeDevice::where('device_uuid', $data['device_uuid'])->firstOrFail();

        // 26-Aug-2026: the heartbeat is how the agent LEARNS it has been signed out — its
        // handleSessionRevoked() fires on a 401 here. Until now the only thing that produced
        // that 401 was the token being deleted, so any close that missed the token left the
        // agent running happily while every screen said the employee was signed out. The
        // device row now answers directly, so an agent can never outlive its session by more
        // than one heartbeat (~30s) whatever else went wrong.
        // 23-Sep-2026: post-shift auto sign-out used to depend ONLY on the background scheduler
        // (cron / scheduled task). Where that is not running — production, 22/23-Sep — nobody
        // was ever signed out and the agent tracked all evening. The agent heartbeats every
        // ~30s, so run the same per-employee close right here: no scheduler needed. It is the
        // SAME command the scheduler runs (one rule, not two), throttled to once a minute per
        // employee. If it closes the session it revokes the device, and the check below 401s,
        // which is what makes the agent drop to its login screen.
        \App\Console\Commands\AutoLogoutPostShift::noteIdle($device, $data['status'] ?? null); // 20-Oct-2026: Idle since
        if ($device->employee_id && $device->session_status === 'ACTIVE'
            && Cache::add('autologout-hb:' . $device->employee_id, 1, 60)) {
            try {
                \Illuminate\Support\Facades\Artisan::call('smartept:auto-logout', ['--employee' => $device->employee_id]);
                $device->refresh();
            } catch (\Throwable $e) {
                report($e); // never fail a heartbeat over this
            }
        }

        // 23-Sep-2026: "Block agent sign-in outside these hours" also ends a RUNNING agent once
        // the window (start .. end + N) has passed. Auto sign-out above works from the login-
        // session row; this works from the device itself, so an agent with no open session row
        // (or one resumed from a stored token) cannot keep tracking outside the shift either.
        // 10-Oct-2026 (Ejaz): not for someone allowed outside shift hours, nor while a "Still working?"
        // question is open or its "Yes" still runs (auto sign-out above decides those).
        $shift = $device->employee?->shift;
        if ($device->session_status === 'ACTIVE' && $shift?->restrict_login_to_shift
            && ! $device->employee->work_outside_shift && ! $this->stillWorkingHold($device)
            && ! $shift->coversSignInAt($this->localNow($device->company_id))) {
            $device->revokeAgentToken();
            $device->update(['session_status' => 'FORCE_LOGOUT', 'force_logout_at' => $this->localNow($device->company_id), 'current_status' => 'OFFLINE']);
        }

        // 29-Oct-2026 (Altaf): SmartDCM Sign on — an employee who walked out of the door without
        // clicking Break (Away) is signed out of the agent, and SmartDCM follows within 30 s.
        if ($device->session_status === 'ACTIVE' && ! $device->unbound_at && $this->awayAtDoor($device)) {
            $device->endSession();
            \App\Models\AuditLog::create([
                'company_id' => $device->company_id, 'user_id' => $request->user()->id, 'action' => 'LOGOUT',
                'subject_type' => EmployeeDevice::class, 'subject_id' => $device->id,
                'changes' => ['note' => 'signed out — punched out at the door (Away)', 'device_uuid' => $device->device_uuid],
            ]);
        }

        if ($device->unbound_at || $device->session_status !== 'ACTIVE') {
            return response()->json([
                'error' => ['code' => 'SESSION_ENDED', 'message' => 'This device session has ended. Please sign in again.'],
            ], 401);
        }

        // EPT-25: clock-skew tamper check. The agent stamps its local time; a large
        // gap from server time usually means the PC clock was changed to fake hours.
        $skew = isset($data['client_time'])
            ? (int) abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($data['client_time'])))
            : null;
        $health = ($skew !== null && $skew > 180) ? 'DEGRADED' : 'HEALTHY';

        // 14-Oct-2026 (Ejaz): keep how far the clock is off (+ = PC ahead) and since / until when,
        // so DEGRADED is explained on screen. Inert until the migration has run.
        $skewCols = [];
        if (\Illuminate\Support\Facades\Cache::remember('schema:devices:clock_skew', 600,
            fn () => \Illuminate\Support\Facades\Schema::hasColumn('employee_devices', 'clock_skew_since'))) {
            if ($health === 'DEGRADED') {
                $skewCols = ['clock_skew_seconds' => \Illuminate\Support\Carbon::parse($data['client_time'])->getTimestamp() - now()->getTimestamp()];
                if (! $device->clock_skew_since || $device->clock_skew_until) { // a new off-period starts
                    $skewCols += ['clock_skew_since' => now(), 'clock_skew_until' => null];
                }
            } elseif ($device->clock_skew_since && ! $device->clock_skew_until) {
                $skewCols = ['clock_skew_until' => now()]; // clock corrected
            }
        }

        $device->update($skewCols + [
            'current_status'    => (($data['status'] ?? 'ONLINE') === 'ACTIVE' ? 'ONLINE' : ($data['status'] ?? 'ONLINE')),
            'app_version'       => $data['app_version'] ?? $device->app_version,
            'service_version'   => $data['service_version'] ?? $device->service_version,
            'sync_pending_count' => $data['sync_pending'] ?? $device->sync_pending_count,
            'agent_health'      => $health,
            'monitor_count'     => $data['monitor_count'] ?? $device->monitor_count,
            'last_heartbeat_at' => now(),
        ]);

        // Biometric Gate: piggyback the live gate state on every heartbeat so the
        // agent syncs continuously (Ejaz's rule — no hourly button, heartbeat-style).
        $gate = null;
        $gateStatus = null; // QA Phase 2 (A3): the {gate_required, open, reason} the agent enforces on

        if ($device->employee) {
            $svc = app(\App\Services\GateService::class);
            $gate = $svc->stateFor($device->employee, $device);           // backward-compatible nested block
            $gateStatus = $svc->statusFor($device->employee, $device);    // gate_required, open, message, reason
        }

        // Section 2: piggyback the currently-joinable meeting (participant + in window +
        // not cancelled) so the agent shows the Meeting button ONLY when authorised, and
        // hides it within one heartbeat of a cancellation or the scheduled end.
        $meeting = null;
        if ($device->employee) {
            $m = \App\Models\Meeting::currentJoinableFor($device->employee);
            if ($m) {
                $inSession = \App\Models\EmployeeMeetingSession::where('meeting_id', $m->id)
                    ->where('employee_id', $device->employee->id)
                    ->whereNull('actual_end_at')->exists();
                $meeting = [
                    'id'         => $m->id,
                    'title'      => $m->title,
                    'end_at'     => $m->end_at->toIso8601String(),
                    'in_session' => $inSession,
                    'meeting_mode' => $m->meeting_mode,
                    'meeting_link' => $m->meeting_link,
                    'venue'        => $m->venue,
                ];
            }
        }

        // Admin #9: an APPROACHING meeting (reminder lead-time reached, not yet started)
        // so the agent can pop a reminder with a Join button for members + organiser.
        $meetingReminder = null;
        if ($device->employee) {
            $rm = \App\Models\Meeting::reminderDueFor($device->employee);
            if ($rm) {
                $meetingReminder = [
                    'id'                => $rm->id,
                    'title'             => $rm->title,
                    'start_at'          => $rm->start_at->toIso8601String(),
                    'starts_in_seconds' => max(0, (int) now()->diffInSeconds($rm->start_at, false)),
                    'meeting_mode'      => $rm->meeting_mode,
                    'meeting_link'      => $rm->meeting_link,
                    'venue'             => $rm->venue,
                ];
            }
        }

        // 28-Sep-2026 (Ejaz): back from a door punch-out that had no Break → the agent shows,
        // once per break id, how much Non-Productive (Away) time was recorded.
        $awayNotice = null;
        if ($device->employee) {
            // 13-Nov-2026 (Ejaz: "40 hr away" popup at the first sign-in of the day): only an Away that STARTED
            // after this working day's first sign-in is announced — a walk-out from an earlier day (closed only
            // today) is that day closing, never something to tell the employee about this morning.
            $firstSign = \App\Models\EmployeeLoginSession::withoutGlobalScopes()->where('employee_id', $device->employee->id)
                ->where('login_at', '>=', $this->localNow($device->company_id)->subHours(20))->min('login_at');
            $b = $firstSign ? \App\Models\EmployeeBreakLog::withoutGlobalScopes()->unannounced()
                ->where('employee_id', $device->employee->id)->whereNotNull('end_at')
                ->where('end_at', '>=', $this->localNow($device->company_id)->subHours(4))
                ->where('start_at', '>', $firstSign)
                ->latest('end_at')->first() : null;
            if ($b) {
                $awayNotice = ['id' => $b->id, 'minutes' => max(1, (int) round(((int) $b->duration_seconds) / 60)),
                    'from' => $b->start_at->format('g:i A'), 'to' => $b->end_at->format('g:i A'), // 20-Oct-2026: 12-hour (shown as is)
                    // 29-Sep-2026: exact instants, so the agent can show "back at desk" and the
                    // gate → PC walk from the first keyboard/mouse input after the IN punch.
                    'out_at' => $b->start_at->toIso8601String(), 'in_at' => $b->end_at->toIso8601String()];
            }
            // 26-Oct-2026 (Ejaz: "I punched out and back, and got the Idle popup instead of Away"):
            // the door-break row above is only written when every check passes at the moment the OUT
            // punch arrives (session found, previous punch an IN, inside the shift…). When it is not,
            // the punches are still the record: the latest door OUT → IN of 2 min or more in the last
            // 4 h, after today's first sign-in, is the Away — the same rule the reports use.
            $since = $this->localNow($device->company_id)->subHours(4);
            $punches = \App\Models\BiometricLog::withoutGlobalScopes()->where('employee_id', $device->employee->id)
                ->where('punched_at', '>=', $since->copy()->subHours(8))->orderBy('punched_at')->get(['id', 'punch_type', 'punched_at']);
            $pair = null;
            $out = null;
            foreach ($punches as $p) {
                if (in_array($p->punch_type, ['OUT', 'BREAK_OUT'], true)) {
                    $out ??= $p;
                    continue;
                }
                if ($out && $p->punched_at->greaterThanOrEqualTo($since)
                    && $out->punched_at->diffInSeconds($p->punched_at) >= \App\Services\GateService::MERGE_UNDER_MINUTES * 60
                    && $firstSign && $out->punched_at->greaterThan(\Illuminate\Support\Carbon::parse($firstSign))) {
                    $pair = [$out, $p];
                }
                $out = null;
            }
            // A door OUT → IN that any break covers is not Away: a Break the employee started (the door
            // only confirms it) is never reported as away, and a door-break row is already reported above.
            $covered = $pair && \App\Models\EmployeeBreakLog::withoutGlobalScopes()->where('employee_id', $device->employee->id)
                ->where('start_at', '<', $pair[1]->punched_at)
                ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', $pair[0]->punched_at))->exists();
            if ($pair && ! $covered
                && (! $awayNotice || $pair[1]->punched_at->greaterThan(\Illuminate\Support\Carbon::parse($awayNotice['in_at'])))) {
                [$o, $i] = $pair;
                $awayNotice = ['id' => 'P' . $o->id, 'minutes' => max(1, (int) round($o->punched_at->diffInSeconds($i->punched_at) / 60)),
                    'from' => $o->punched_at->format('g:i A'), 'to' => $i->punched_at->format('g:i A'),
                    'out_at' => $o->punched_at->toIso8601String(), 'in_at' => $i->punched_at->toIso8601String()];
            }
        }

        // 26-Oct-2026 (Ejaz: "punched out, but the agent shows the Idle popup"): the employee is out of
        // the door RIGHT NOW (a door punch-out not yet followed by an IN). The agent shows no Idle
        // popup while this is set — that time is Away, not Idle (and the reports count it once, as Away).
        $awayNow = null;
        if ($device->employee) {
            $since = $this->localNow($device->company_id)->subHours(16);
            $open = \App\Models\EmployeeBreakLog::withoutGlobalScopes()->unannounced()
                ->where('employee_id', $device->employee->id)->whereNull('end_at')
                ->where('start_at', '>=', $since)->latest('start_at')->first(['start_at']);
            $lastPunch = \App\Models\BiometricLog::withoutGlobalScopes()->where('employee_id', $device->employee->id)
                ->where('punched_at', '>=', $since)->latest('punched_at')->first(['punch_type', 'punched_at']);
            if ($lastPunch && in_array($lastPunch->punch_type, ['IN', 'BREAK_IN'], true)) {
                $open = null;   // walked back in — a break row not closed yet is not "out" any more
            }
            $out = $open?->start_at
                ?? (($lastPunch && in_array($lastPunch->punch_type, ['OUT', 'BREAK_OUT'], true)) ? $lastPunch->punched_at : null);
            if ($out) {
                $awayNow = ['out_at' => $out->toIso8601String()];
            }
        }

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'timezone'    => config('app.timezone'), // 09-Nov-2026: Organisation time zone (ApplyCompanyTimezone) — the agent stamps and shows times in it
            'away_notice' => $awayNotice,
            'away_now' => $awayNow,
            'clock_skew_seconds' => $skew,
            'gate' => $gate,                 // nested {enabled,state,arrived,...} (unchanged)
            'gate_status' => $gateStatus,    // QA Phase 2 (A3): {gate_required, open, message, reason}
            'meeting' => $meeting,
            'meeting_reminder' => $meetingReminder, // Admin #9: approaching-meeting reminder
            'liveview_requested' => $this->liveviewRequestedFor($device), // Phase 3 POC (12-Sep-2026): plan §7 Option A
            // 04-Oct-2026: PC Audit Log — the agent records click targets only while this is true.
            'pc_audit_clicks' => \App\Services\EndpointSecurity\Entitlement::can(\App\Models\Company::find($device->company_id), 'pc_audit'),
            // 10-Oct-2026 (Ejaz): the "Still working?" question after shift end (null = none open).
            'still_working' => $this->stillWorkingPrompt($device),
        ]);
    }

    /**
     * 10-Oct-2026: an open "Still working?" question, or — 20-Oct-2026 — someone not yet on their Nth
     * minute Idle (they are working; the question decides). +2 min so the once-a-minute sign-out pass
     * can ask before this signs the PC out without a question.
     */
    /**
     * Away = an open door break (punch-out, no Break clicked) — the same rule as the live board.
     * Only for companies with SmartDCM Sign on, and only a walk-out made during THIS sign-in, so
     * signing back in before the late IN punch arrives does not sign the employee straight out again.
     */
    private function awayAtDoor(EmployeeDevice $device): bool
    {
        if (! $device->employee_id || ! \App\Models\Company::withoutGlobalScopes()->whereKey($device->company_id)->value('smartdcm_sso_enabled')) {
            return false;
        }
        $seg = app(\App\Services\StatusService::class)->openStatusMap([$device->employee_id])[$device->employee_id] ?? null;

        return $seg && $seg['state'] === 'OTHER_BREAK' && ($seg['source'] ?? null) === 'BIOMETRIC'
            && (! $device->last_login_at || \Illuminate\Support\Carbon::parse($seg['started_at'])->greaterThanOrEqualTo($device->last_login_at));
    }

    private function stillWorkingHold(EmployeeDevice $device): bool
    {
        if (! \App\Console\Commands\AutoLogoutPostShift::stillWorkingReady()) {
            return false;
        }
        $now = $this->localNow($device->company_id);
        $asked = $device->still_working_asked_at ? \Illuminate\Support\Carbon::parse($device->still_working_asked_at) : null;
        $need = \App\Console\Commands\AutoLogoutPostShift::idleAskMinutes($device->employee) * 60 + 120;

        return ($asked && $asked->greaterThan($now->copy()->subSeconds(90)))
            || \App\Console\Commands\AutoLogoutPostShift::idleSeconds($device, $now) < $need;
    }

    /** 10-Oct-2026: what the agent shows — {id, shift_end, timeout_seconds} while a question is open. */
    private function stillWorkingPrompt(EmployeeDevice $device): ?array
    {
        if (! \App\Console\Commands\AutoLogoutPostShift::stillWorkingReady() || ! $device->still_working_asked_at) {
            return null;
        }
        $asked = \Illuminate\Support\Carbon::parse($device->still_working_asked_at);
        $until = $device->still_working_until ? \Illuminate\Support\Carbon::parse($device->still_working_until) : null;
        if (($until && $until->greaterThanOrEqualTo($asked)) || $this->localNow($device->company_id)->greaterThan($asked->copy()->addSeconds(90))) {
            return null;
        }
        $shift = $device->employee?->shiftOn($this->localNow($device->company_id)); // 13-Oct-2026: today's end time

        return [
            'id' => $asked->format('YmdHis'),
            'shift_end' => $shift?->end_time ? \Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A') : null, // shown as is
            'timeout_seconds' => 60,
            'every_minutes' => \App\Console\Commands\AutoLogoutPostShift::idleAskMinutes($device->employee), // = Idle minutes before asking
        ];
    }

    /**
     * POST /api/agent/still-working {device_uuid} — 10-Oct-2026 (Ejaz): the employee clicked
     * "Yes, still working". 20-Oct-2026: stays signed in; asked again only after another N minutes
     * of continuous Idle counted from this answer (still_working_until = the moment of the answer).
     */
    public function stillWorking(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('agent'), 403, 'Agent token required.');
        $data = $request->validate(['device_uuid' => ['required', 'string']]);
        $device = EmployeeDevice::where('device_uuid', $data['device_uuid'])->firstOrFail();
        if ($device->unbound_at || $device->session_status !== 'ACTIVE') {
            return response()->json(['error' => ['code' => 'SESSION_ENDED', 'message' => 'This device session has ended. Please sign in again.']], 401);
        }
        $every = \App\Console\Commands\AutoLogoutPostShift::idleAskMinutes($device->employee);
        $at = $this->localNow($device->company_id);
        $device->forceFill(['still_working_asked_at' => null, 'still_working_until' => $at])->save();

        return response()->json(['ok' => true, 'until' => $at->format('g:i A'), 'every_minutes' => $every]);
    }

    /**
     * LiveView Phase 3 POC (plan §7, Option A): the Agent has no persistent
     * connection to anything (finding 1.1), so the heartbeat — already round-tripping
     * every ~30s — is the one channel available for "a stream is wanted for you".
     * Mirrors the existing `hb.enforcement` piggyback just above this method: same
     * shape of problem, same solution, no new infrastructure.
     *
     * 15-Sep-2026: this ~30s cadence made every Start (and every restart after a
     * Stop) feel "stuck" for up to 30s — fine for enforcement/meeting sync, not for
     * a UI the admin is staring at. LiveViewController::poll() now gives the Agent a
     * dedicated fast-cadence sibling (LIVEVIEW_POLL_MS) for just this signal; this
     * heartbeat copy stays as the low-cost fallback so a poll hiccup self-heals
     * within one heartbeat instead of hanging forever.
     *
     * Returns one block per 'connecting' LiveViewSession this device currently has
     * (15-Sep-2026: was a single object|null — see LiveViewSession::connectingFor()'s
     * docblock for why that starved every monitor but the newest), each with its own
     * one-time stream token, for main.js's sendHeartbeat() to start capturing.
     */
    private function liveviewRequestedFor(EmployeeDevice $device): array
    {
        $tokens = app(\App\Services\LiveViewTokenService::class);

        return \App\Models\LiveViewSession::connectingFor($device->device_uuid)
            ->map(fn ($session) => $session->toAgentRequest($tokens))
            ->all();
    }

    /**
     * POST /api/devices/{device}/unbind — R2-3: kill the device's agent token,
     * release its licence seat on Central and block re-registration until an
     * admin approves a re-bind.
     */
    public function unbind(Request $request, EmployeeDevice $device): JsonResponse
    {
        // Revoke exactly this device's agent token (named on creation).
        $device->revokeAgentToken();

        app(\App\Services\LicenseClient::class)->deactivateDevice($device->device_uuid, $device->company_id);

        $device->update([
            'unbound_at' => now(),
            'current_status' => 'OFFLINE',
            'agent_health' => 'STOPPED',
        ]);

        $this->audit($request, 'UNBIND_DEVICE', EmployeeDevice::class, $device->id, [
            'device_uuid' => $device->device_uuid,
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * POST /api/devices/{device}/rebind — approve a previously unbound device.
     * Re-claims a licence seat first; if none is free the admin sees why.
     */
    public function rebind(Request $request, EmployeeDevice $device): JsonResponse
    {
        // Local cap first (14-Aug-2026), for the same reason as register(): the
        // Central call below deliberately fails open when Central is unreachable,
        // so on its own it could be used to rebind PCs without limit.
        if ($why = app(\App\Services\LicenceSeats::class)->blockedReason($device->company_id, 'device')) {
            return response()->json([
                'error' => ['code' => 'LICENSE_SEAT_BLOCKED', 'reason' => 'device_limit_reached', 'message' => $why],
            ], 409);
        }

        $seat = app(\App\Services\LicenseClient::class)
            ->activateDevice($device->device_uuid, $device->computer_name, $device->company_id);

        if (! $seat['ok']) {
            return response()->json([
                'error' => [
                    'code' => 'LICENSE_SEAT_BLOCKED',
                    'reason' => $seat['reason'],
                    'message' => $seat['reason'] === 'device_limit_reached'
                        ? 'No free licence seat for this device — unbind another device or upgrade the licence.'
                        : 'Central refused this device (' . $seat['reason'] . ').',
                ],
            ], 409);
        }

        $device->update(['unbound_at' => null]);

        $this->audit($request, 'REBIND_DEVICE', EmployeeDevice::class, $device->id, [
            'device_uuid' => $device->device_uuid,
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * POST /api/devices/{device}/force-logout — Section 10: end this device's agent
     * session now. Revokes its token (so the agent gets 401 on its next heartbeat and
     * returns to the login screen) WITHOUT freeing the licence seat or blocking
     * re-registration — the employee can sign in again on any PC.
     */
    public function forceLogout(Request $request, EmployeeDevice $device): JsonResponse
    {
        // Branch Admin / Manager may only force-logout devices of employees within their scope.
        if ($device->employee_id) {
            $this->assertEmployeeVisible($request, (int) $device->employee_id);
        }

        $device->endSession();

        $this->audit($request, 'FORCE_LOGOUT', EmployeeDevice::class, $device->id, [
            'device_uuid' => $device->device_uuid,
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * POST /api/agent/session-logout — the agent's own explicit sign-out. Marks the
     * session logged out and revokes the current token so another PC can take over
     * immediately (no wait for the stale window).
     */
    public function sessionLogout(Request $request): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('agent'), 403, 'Agent token required.');

        $uuid = (string) $request->input('device_uuid');
        $device = EmployeeDevice::where('device_uuid', $uuid)->first();
        if ($device) {
            $device->update(['session_status' => 'LOGGED_OUT', 'current_status' => 'OFFLINE']);
        }
        // 29-Sep-2026: chat is session-only — the thread ends with the session.
        $employeeId = \App\Models\Employee::where('user_id', $request->user()->id)->value('id');
        if ($employeeId) \App\Models\EmployeeChatMessage::clearFor((int) $employeeId);
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * PUT /api/devices/{device}/tracking-mode — set a per-device override
     * (null = inherit from the employee / team / department / branch / company).
     */
    public function trackingMode(Request $request, EmployeeDevice $device): JsonResponse
    {
        $data = $request->validate([
            'tracking_mode' => ['nullable', 'in:FULL,NO_SCREENSHOTS,PRESENCE_ONLY,EXCLUDED'],
        ]);

        $device->update(['tracking_mode' => $data['tracking_mode'] ?? null]);
        $this->audit($request, 'DEVICE_TRACKING_MODE', EmployeeDevice::class, $device->id, $data);

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * PUT /api/devices/{device}/gate-mode — Gate-to-PC exclusion for ONE machine
     * (a shared kiosk, a visitor laptop, a WFH desktop that never sees the door).
     * null = inherit from the employee / team / department / branch.
     */
    public function gateMode(Request $request, EmployeeDevice $device): JsonResponse
    {
        $data = $request->validate([
            'gate_mode' => ['nullable', 'in:REQUIRED,EXCLUDED'],
            'gate_mode_from' => ['nullable', 'date'],
            'gate_mode_until' => ['nullable', 'date', 'after_or_equal:gate_mode_from'],
            'gate_mode_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $device->update([
            'gate_mode' => $data['gate_mode'] ?? null,
            'gate_mode_from' => $data['gate_mode_from'] ?? null,
            'gate_mode_until' => $data['gate_mode_until'] ?? null,
            'gate_mode_reason' => $data['gate_mode_reason'] ?? null,
            'gate_mode_by_user_id' => ($data['gate_mode'] ?? null) ? $request->user()->id : null,
        ]);
        $this->audit($request, 'DEVICE_GATE_MODE', EmployeeDevice::class, $device->id, $data);

        return response()->json(['data' => $device->fresh()]);
    }

    private function resolveEmployee(Request $request): Employee
    {
        $user = $request->user();

        // Prefer the employee linked to the authenticated user.
        $employee = Employee::where('user_id', $user->id)->first();

        // Admins may register on behalf by passing employee_code.
        if (! $employee && $request->filled('employee_code')) {
            $employee = Employee::where('employee_code', $request->input('employee_code'))->first();
        }

        abort_if(! $employee, 422, 'No employee is linked to this account.');

        return $employee;
    }
}
