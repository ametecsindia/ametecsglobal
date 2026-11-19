<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeLoginSession;
use App\Services\PolicyResolver;
use App\Support\ResolvesLocalNow;
use App\Services\StatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Post-shift auto logout (Ejaz, 19-Aug-2026).
 *
 * The nightly `smartept:mark-attendance` already closes sessions left open across a day
 * boundary, but it runs at 00:15 and is retrospective — so between shift end and midnight
 * an employee who never signed out stays "logged in", and whatever stale instant happened
 * to sit in check_out_at is what the productivity report divides by. That is precisely the
 * shape of the 596% row (AI0043, 14-Aug: logout stamped 10:21 while activity ran to ~17:00).
 *
 * This command closes the loop in near-real time: every 5 minutes it finds still-open
 * sessions whose shift ended more than N minutes ago and signs them out AT
 * (shift end + N) — not at "now", so an employee whose PC sat idle overnight is not
 * credited the extra hours.
 *
 * N = the employee's shift post_shift_auto_logout_minutes (09-Oct-2026, Ejaz: the shift is
 * the ONLY place — the Attendance-policy fallback is gone). NULL = disabled.
 *
 * What a close does, in order:
 *   1. employee_login_sessions  — logout_at, duration_seconds (16h cap), reason POST_SHIFT_AUTO
 *   2. employee_attendance_logs — check_out_at / final_logout_at advanced (never rewound)
 *   3. StatusService::closeAll  — end any open break / meeting segment so the live board clears
 *   4. employee_devices         — session_status FORCE_LOGOUT + the device's Sanctum token
 *                                 revoked, so the agent 401s on its next heartbeat and
 *                                 returns to the login screen instead of tracking all night
 */
class AutoLogoutPostShift extends Command
{
    use ResolvesLocalNow;

    protected $signature = 'smartept:auto-logout
        {--dry-run : List what would be closed, change nothing}
        {--explain : Print EVERY open session with the reason it was or was not signed out}
        {--now= : Treat this instant as "now" (Y-m-d H:i:s) — for testing}
        {--employee= : Only this employee id (the agent heartbeat runs it per device — 23-Sep-2026)}';

    protected $description = 'Sign out agents that never signed out, N minutes after their shift ends';

    /** Same cap the nightly job uses: an agent that died on Friday must not credit the weekend. */
    private const MAX_AUTO_SESSION_SECONDS = 16 * 3600;

    /** employee_id => resolved minutes|null, so the policy chain is walked once per employee. */
    private array $minutesCache = [];

    public function handle(PolicyResolver $resolver, StatusService $status): int
    {
        // Wall-clock now, not UTC now — the agent stores local times. `--now=` is already a
        // local clock face. See ResolvesLocalNow; the per-company value is resolved below,
        // this one only bounds the "last 3 days" query.
        $now = $this->option('now') ? Carbon::parse($this->option('now')) : $this->localNow(null);
        $dry = (bool) $this->option('dry-run');
        $explain = (bool) $this->option('explain');
        $closed = 0;
        $open = 0;
        // 26-Aug-2026: the old counters said only "N not configured", which is the same
        // number whether the feature is switched off, the employee has no shift, or the
        // attendance policy was never ASSIGNED to anyone. Those need different fixes, so
        // the reasons are counted separately and --explain prints them per session.
        $reasons = [];

        EmployeeLoginSession::withoutGlobalScopes()
            ->with('employee.shift')
            ->whereNull('logout_at')
            ->whereNotNull('login_at')
            // Nothing older than 3 days: those belong to the nightly sweep, and reaching
            // further back would rewrite months of settled history on first deploy.
            ->where('login_at', '>=', $now->copy()->subDays(3))
            ->when($this->option('employee'), fn ($q, $id) => $q->where('employee_id', (int) $id))
            ->chunkById(200, function ($sessions) use ($resolver, $status, $now, $dry, $explain, &$closed, &$open, &$reasons) {
                foreach ($sessions as $session) {
                    $open++;
                    $employee = $session->employee;
                    $who = $employee
                        ? $employee->employee_code . ' (' . trim($employee->first_name . ' ' . $employee->last_name) . ')'
                        : 'session #' . $session->id;
                    $since = $session->login_at->format('Y-m-d H:i');

                    $note = function (string $reason) use (&$reasons, $explain, $who, $since) {
                        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                        if ($explain) {
                            $this->line(sprintf('  <fg=yellow>skip</> %-34s open since %s — %s', $who, $since, $reason));
                        }
                    };

                    if (! $employee) {
                        $note('session has no employee record');
                        continue;
                    }

                    $minutes = $this->autoLogoutMinutes($employee, $resolver);
                    if ($minutes === null) {
                        // 09-Oct-2026: the minutes live on the Shift only — the old wording sent people to an Attendance policy.
                        $note($employee->shift_id
                            ? 'auto sign-out minutes not set on the shift'
                            : 'employee has no shift assigned (the auto sign-out minutes live on the Shift)');
                        continue;
                    }

                    $cutoff = $this->cutoffFor($session, $employee, $minutes);
                    if (! $cutoff) {
                        $note('no shift end and no login day to measure from');
                        continue;
                    }
                    // The employee's OWN wall clock decides whether their shift has ended.
                    $empNow = $this->option('now') ? $now : $this->localNow($employee->company_id);
                    $shiftEnd = $cutoff->copy()->subMinutes($minutes);

                    // 13-Oct-2026 (Ejaz): auto sign-out (end + N) is for people who LEFT without signing
                    // out. 20-Oct-2026 (Ejaz): "Still working?" is asked only on the shift's Nth minute of
                    // continuous Idle beyond shift end — a fixed every-N-minutes popup could be answered once
                    // and the session kept open for nothing. Working = no popup. No answer → signed out.
                    if ($empNow->lessThan($cutoff)) {
                        $sw = $empNow->greaterThanOrEqualTo($shiftEnd)
                            ? $this->stillWorking($employee, $shiftEnd, $empNow, $dry, $session->login_at->copy(), $cutoff) : null;
                        if (! $sw || empty($sw['noanswer'])) {
                            $note($sw && $sw['wait'] ? $sw['why'] : 'shift ends ' . $cutoff->format('Y-m-d H:i') . ' — not due yet');
                            continue;
                        }
                    } else {
                        // 14-Oct-2026 (Ejaz; Nandini, 13-Oct): auto signed out at 20:00, signed in again at
                        // 21:15, and "Yes, still working" kept that session open overnight and was asked
                        // again in the middle of the NEXT day's shift. Two limits on "Still working?":
                        //  - someone NOT allowed outside shift hours who signs in after their auto sign-out
                        //    time is signed out straight away (no question) — the 26-Aug rule;
                        //  - once the next day's shift has started, the question is never asked for an old
                        //    session: it is closed at the last real activity before that start.
                        $nextStart = $this->nextShiftStart($employee, $session->login_at->toDateString(), $cutoff);
                        if (! $employee->work_outside_shift && $session->login_at->greaterThanOrEqualTo($cutoff)) {
                            $sw = ['wait' => false, 'why' => '', 'at' => null];
                        } elseif ($empNow->greaterThanOrEqualTo($nextStart)) {
                            $sw = ['wait' => false, 'why' => '', 'at' => $this->lastActiveBefore($employee, $session->login_at, $nextStart, $cutoff)];
                        } else {
                            // 10-Oct-2026 (Ejaz): a PC still in use at the auto sign-out moment stays signed in;
                            // 20-Oct-2026: it is asked "Still working?" on the Nth minute Idle, no answer within a
                            // minute signs them out. A PC that has gone quiet is signed out at the cutoff.
                            $sw = $this->stillWorking($employee, $shiftEnd, $empNow, $dry, $session->login_at->copy(), $cutoff);
                            if ($sw['wait']) {
                                $note($sw['why']);
                                continue;
                            }
                        }
                    }
                    if ($sw['at']) {
                        $cutoff = $sw['at'];
                    }
                    $minutes = (int) $shiftEnd->diffInMinutes($cutoff, true); // what the sign-out really is: shift end + this

                    $this->line(sprintf(
                        '  <fg=green>close</> %-34s open since %s → sign out at %s (+%dm)',
                        $who, $since, $cutoff->format('Y-m-d H:i'), $minutes
                    ));

                    if (! $dry) {
                        $this->closeSession($session, $employee, $cutoff, $minutes, $status);
                    }
                    $closed++;
                }
            });

        $orphans = $this->settleOrphanDevices($resolver, $now, $dry);

        $this->info(($dry ? '[dry-run] ' : '') . "Post-shift auto logout: {$closed} of {$open} open sessions signed out"
            . ($orphans ? ", {$orphans} PC(s) left 'signed in' after a lock signed out" : '') . '.');

        foreach ($reasons as $reason => $count) {
            $this->line("  <fg=yellow>{$count}</> left open — {$reason}");
        }
        if ($reasons && ! $explain) {
            $this->line('  Run with --explain to see which employees.');
        }

        return self::SUCCESS;
    }

    /**
     * Effective minutes-after-shift-end for one employee. Per-shift wins; the Attendance
     * policy is the fallback default. null = the feature is off for this employee.
     */
    private function autoLogoutMinutes(Employee $employee, PolicyResolver $resolver): ?int
    {
        if (array_key_exists($employee->id, $this->minutesCache)) {
            return $this->minutesCache[$employee->id];
        }

        $minutes = $employee->shift?->post_shift_auto_logout_minutes; // shift only (09-Oct-2026)

        return $this->minutesCache[$employee->id] = $minutes === null ? null : max(0, (int) $minutes);
    }

    /**
     * The instant to stamp as the sign-out: the shift end that belongs to THIS session's
     * login, plus the configured minutes. Returns null when the employee has no shift times
     * (there is no "shift end" to measure from, so the nightly sweep keeps ownership).
     */
    private function cutoffFor(EmployeeLoginSession $session, Employee $employee, int $minutes): ?Carbon
    {
        return $this->cutoffForDay($session->login_at->toDateString(), $employee, $minutes);
    }

    /**
     * 08-Nov-2026 (Ejaz): would a sign-in right now be signed straight back out? Someone NOT allowed outside shift
     * hours who signs in after the day's shift end + N is closed on the very next pass (the 26-Aug rule above). The
     * agent then showed the QR wall, took the scan, and only then dropped to the login screen. The sign-in itself is
     * now refused with this cutoff, by the SAME rule, so the two can never disagree. Null = allowed.
     */
    public static function refusedAfterShiftAt(Employee $employee, Carbon $now): ?Carbon
    {
        $minutes = $employee->shift?->post_shift_auto_logout_minutes;
        if ($employee->work_outside_shift || $minutes === null) {
            return null;
        }
        $cutoff = self::cutoffForDay($now->toDateString(), $employee, max(0, (int) $minutes));

        return $now->greaterThanOrEqualTo($cutoff) ? $cutoff : null;
    }

    private static function cutoffForDay(string $loginDay, Employee $employee, int $minutes): Carbon
    {
        $shift = $employee->shiftOn($loginDay); // 13-Oct-2026: that day's own end time

        // 26-Aug-2026: an employee with no shift, or a shift saved without times, used to
        // return null here and could therefore NEVER be signed out by this command — while
        // the nightly sweep would not reach them until the following night either, so their
        // agent tracked idle time straight through. Fall back to the end of the login day,
        // which is exactly the boundary `smartept:mark-attendance` already uses for them.
        if (! $shift || ! $shift->end_time) {
            return Carbon::parse($loginDay . ' 23:59:59')->addMinutes($minutes);
        }

        $end = Carbon::parse($loginDay . ' ' . $shift->end_time);

        // Night shift: the shift's end belongs to the NEXT calendar day.
        if ($shift->crosses_midnight
            || ($shift->start_time && $end->lessThanOrEqualTo(Carbon::parse($loginDay . ' ' . $shift->start_time)))) {
            $end->addDay();
        }

        // A login AFTER the shift end is NOT given a fresh window (Ejaz, 26-Aug-2026:
        // "irrespective of the re-login ... it should consider the post shift logout time and
        // sign out the agent"). This used to `addDay()`, which handed such a session the whole
        // of the next day. The cutoff stays at THIS login day's shift end + N, so signing back
        // in after the shift is over is closed on the very next pass. A genuine night shift is
        // already moved forward by the crosses_midnight branch above and is unaffected.

        return $end->addMinutes($minutes);
    }

    /**
     * 14-Oct-2026 (Ejaz: "logged in yesterday, still shows signed in with Force logout, not on the live
     * dashboard"). A LOCK — PC locked or put to sleep before leaving — closes the login session but
     * leaves the PC row ACTIVE. The sweep above only sees OPEN sessions, so that PC was never signed
     * out and sat "stale" forever. Settle it once that day's shift end + N has passed.
     * ponytail: the day is the last heartbeat's day — a night shift seen after midnight is settled a
     * day late, never early.
     */
    private function settleOrphanDevices(PolicyResolver $resolver, Carbon $now, bool $dry): int
    {
        $n = 0;
        EmployeeDevice::withoutGlobalScopes()->with('employee.shift')
            ->where('session_status', 'ACTIVE')->whereNull('unbound_at')->whereNotNull('employee_id')
            ->when($this->option('employee'), fn ($q, $id) => $q->where('employee_id', (int) $id))
            ->get()
            ->each(function (EmployeeDevice $device) use ($resolver, $now, $dry, &$n) {
                $employee = $device->employee;
                $minutes = $employee ? $this->autoLogoutMinutes($employee, $resolver) : null;
                $last = $device->last_heartbeat_at ?? $device->updated_at;
                if (! $employee || ! $last) {
                    return;
                }
                // An open session belongs to the sweep above (and to "Still working?").
                if (EmployeeLoginSession::withoutGlobalScopes()->where('employee_id', $employee->id)->whereNull('logout_at')->exists()) {
                    return;
                }
                // 10-Nov-2026 (Ejaz): "the agent might be walking after punching in and depositing the phone in the locker —
                // keep this max 10 minutes and force logout; do not keep the window waiting for punch in". A sign-in whose
                // work session never started (still on the QR / door wall) is given WALL_MINUTES, then signed out — at any
                // hour. This replaces the 08-Nov gate exemption, which kept a PC locked after shift signed in for 12 h.
                $loginAt = $device->last_login_at ? Carbon::parse($device->last_login_at) : null;
                $started = $loginAt && EmployeeLoginSession::withoutGlobalScopes()->where('employee_id', $employee->id)
                    ->where('login_at', '>=', $loginAt->copy()->subMinutes(2))->exists();
                if ($loginAt && ! $started) {
                    if ($loginAt->greaterThan(now()->subMinutes(self::WALL_MINUTES))) {
                        return; // walking to the scanner
                    }
                    if (app(\App\Services\GateService::class)->stateFor($employee, $device)['enabled']) {
                        $this->signOutDevice($device, $employee, $last, $dry, 'never got past the punch-in screen');
                        $n++;

                        return;
                    }
                }
                if ($minutes === null) {
                    return; // no shift auto sign-out set
                }
                $cutoff = $this->cutoffForDay($last->toDateString(), $employee, $minutes);
                $empNow = $this->option('now') ? $now : $this->localNow($employee->company_id);
                if ($empNow->lessThan($cutoff)) {
                    return; // locked for lunch etc. — the day is not over
                }
                $this->signOutDevice($device, $employee, $last, $dry, 'signed in with no open session');
                $n++;
            });

        return $n;
    }

    private function signOutDevice(EmployeeDevice $device, Employee $employee, Carbon $last, bool $dry, string $why): void
    {
        $this->line(sprintf('  <fg=green>close</> %-34s PC %s %s (last seen %s)',
            $employee->employee_code, $device->computer_name ?? $device->device_uuid, $why, $last->format('Y-m-d H:i')));
        if (! $dry) {
            self::closeDoorAway($employee->id, $last);
            $device->revokeAgentToken();
            $device->update([
                'session_status'  => 'FORCE_LOGOUT',
                'force_logout_at' => $this->localNow($employee->company_id),
                'current_status'  => 'OFFLINE',
            ] + (self::stillWorkingReady() ? ['still_working_asked_at' => null, 'still_working_until' => null] : []));
        }
    }

    /** 14-Oct-2026: start of the shift on the day AFTER the login day (never before the cutoff). */
    private function nextShiftStart(Employee $employee, string $loginDay, Carbon $cutoff): Carbon
    {
        $next = Carbon::parse($loginDay)->addDay()->toDateString();
        $shift = $employee->shiftOn($next);
        $start = $shift && $shift->start_time ? Carbon::parse($next . ' ' . $shift->start_time) : Carbon::parse($next . ' 00:00:00');

        return $start->greaterThan($cutoff) ? $start : $cutoff->copy()->addHours(12);
    }

    /** 14-Oct-2026: end of the last ACTIVE stretch in [from, before), never earlier than $floor. */
    private function lastActiveBefore(Employee $employee, Carbon $from, Carbon $before, Carbon $floor): Carbon
    {
        $last = null;
        \App\Models\EmployeeActivityEvent::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->where('event_type', 'ACTIVE')->where('started_at', '>=', $from)->where('started_at', '<', $before)
            ->get(['started_at', 'duration_seconds'])
            ->each(function ($e) use (&$last) {
                $end = $e->started_at->copy()->addSeconds((int) $e->duration_seconds);
                $last = (! $last || $end->greaterThan($last)) ? $end : $last;
            });
        $at = $last && $last->lessThan($before) ? $last : $before->copy();

        return $at->greaterThan($floor) ? $at : $floor->copy();
    }

    /** "Still working?" answer window: 60 s on screen + up to one heartbeat (~30 s) to deliver it. */
    private const ANSWER_SECONDS = 90;
    /** 10-Nov-2026 (Ejaz): longest wait on the punch-in screen after agent sign-in. */
    public const WALL_MINUTES = 10;

    private static ?bool $swReady = null;

    public static function stillWorkingReady(): bool
    {
        return self::$swReady ??= \Illuminate\Support\Facades\Schema::hasColumn('employee_devices', 'still_working_asked_at');
    }

    /** 20-Oct-2026: the shift's "ask after N minutes Idle" (blank = 30, never under 5). */
    public static function idleAskMinutes(?Employee $employee): int
    {
        return max(5, (int) ($employee?->shift?->still_working_every_min ?? 30));
    }

    /**
     * 20-Oct-2026 (Ejaz): every heartbeat notes when this PC's current Idle stretch began (agent
     * status IDLE); any other status ends it.
     * ponytail: kept in the cache, not a column — a cache flush only restarts the Idle count.
     */
    public static function noteIdle(EmployeeDevice $device, ?string $status): void
    {
        $key = 'sw-idle-since:' . $device->id;
        $status === 'IDLE' ? \Illuminate\Support\Facades\Cache::add($key, now()->getTimestamp(), 86400) : \Illuminate\Support\Facades\Cache::forget($key);
    }

    /** Seconds of continuous Idle on this PC, counted only after $from (shift end / the last "Yes"). */
    public static function idleSeconds(EmployeeDevice $device, Carbon $now, ?Carbon $from = null): int
    {
        $since = \Illuminate\Support\Facades\Cache::get('sw-idle-since:' . $device->id);
        if (! $since) {
            return 0;
        }
        $idle = max(0, now()->getTimestamp() - (int) $since);
        $until = $device->still_working_until ? Carbon::parse($device->still_working_until) : null; // = the last "Yes"
        foreach ([$from, $until] as $t) {
            if ($t) {
                $idle = min($idle, max(0, (int) $t->diffInSeconds($now, false)));
            }
        }

        return $idle;
    }

    /**
     * 10-Oct-2026 (Ejaz) / 20-Oct-2026: the "Still working?" check once the shift has ended.
     * wait = leave the session open (working, waiting for the answer, or just asked);
     * at   = sign out at this instant instead of the cutoff (the unanswered question, or the last "Yes").
     * A PC that has stopped heartbeating is left to the cutoff ($cutoff null = before it).
     *
     * @return array{wait: bool, why: string, at: ?Carbon}
     */
    private function stillWorking(Employee $employee, Carbon $shiftEnd, Carbon $now, bool $dry, Carbon $loginAt, ?Carbon $cutoff = null): array
    {
        $cutoff ??= $shiftEnd;
        if (! self::stillWorkingReady()) { // migration not run yet: behave exactly as before
            return ['wait' => false, 'why' => '', 'at' => null];
        }
        $dev =EmployeeDevice::withoutGlobalScopes()->where('employee_id', $employee->id)->whereNull('unbound_at')
            ->where('session_status', 'ACTIVE')->orderByDesc('last_heartbeat_at')->first();
        if (! $dev) {
            return ['wait' => false, 'why' => '', 'at' => null];
        }
        $asked = $dev->still_working_asked_at ? Carbon::parse($dev->still_working_asked_at) : null;
        $until = $dev->still_working_until ? Carbon::parse($dev->still_working_until) : null;
        // Only this sign-in counts: a question or "Yes" left over from an earlier session is ignored.
        if ($asked && $asked->lessThan($loginAt)) {
            $asked = null;
        }
        if ($until && $until->lessThan($loginAt)) {
            $until = null;
        }

        if ($asked && (! $until || $until->lessThan($asked))) {
            if ($now->lessThan($asked->copy()->addSeconds(self::ANSWER_SECONDS))) {
                return ['wait' => true, 'why' => 'asked "Still working?" at ' . $asked->format('H:i') . ' — waiting for the answer', 'at' => null];
            }

            return ['wait' => false, 'why' => '', 'noanswer' => true, 'at' => $asked->greaterThan($cutoff) ? $asked : $cutoff->copy()]; // no answer
        }
        // 10-Nov-2026 (Ejaz): past shift end + N an Idle or locked PC is signed out — no waiting, no question.
        if ($now->greaterThanOrEqualTo($cutoff) && $dev->current_status === 'IDLE') {
            return ['wait' => false, 'why' => '', 'at' => ($until && $until->greaterThan($cutoff)) ? $until : null];
        }
        // 20-Oct-2026: the PC is on (Active or Idle) → working = no popup; on the Nth minute Idle
        // beyond shift end (and beyond the last "Yes") → ask.
        $alive = (in_array($dev->current_status, ['ONLINE', 'IDLE'], true) && $dev->last_heartbeat_at
                && Carbon::parse($dev->last_heartbeat_at)->greaterThan(now()->subMinutes(2)))
            // 08-Nov-2026 (Ejaz): a session that has only just started IS a PC in use — e.g. the gate opened after a long
            // walk to the QR scanner, during which the agent sends no heartbeats. It used to read as "not reporting".
            || $loginAt->greaterThan($now->copy()->subMinutes(2));
        if ($alive) {
            $need = self::idleAskMinutes($employee);
            $idle = self::idleSeconds($dev, $now, $shiftEnd);
            if ($idle < $need * 60) {
                return ['wait' => true, 'why' => 'working beyond shift — Idle ' . intdiv($idle, 60) . ' of ' . $need . ' min before "Still working?"', 'at' => null];
            }
            if (! $dry) {
                $dev->forceFill(['still_working_asked_at' => $now])->save();
            }

            return ['wait' => true, 'why' => 'Idle ' . $need . ' min beyond shift — asked "Still working?"', 'at' => null];
        }
        if ($now->lessThan($cutoff)) {
            return ['wait' => true, 'why' => 'PC not reporting — auto sign-out at ' . $cutoff->format('H:i'), 'at' => null];
        }

        return ['wait' => false, 'why' => '', 'at' => ($until && $until->greaterThan($cutoff)) ? $until : null];
    }

    /** Close the session, advance the attendance sheet, clear live status, and kick the agent. */
    private function closeSession(EmployeeLoginSession $session, Employee $employee, Carbon $cutoff, int $minutes, StatusService $status): void
    {
        // A session that STARTED after the shift end has a cutoff behind its own login. Stamp
        // the login instant instead of a logout that precedes it (which would read as a
        // negative session and, through diffInSeconds' absolute value, a bogus duration).
        $logoutAt = $cutoff->lessThan($session->login_at) ? $session->login_at->copy() : $cutoff;

        $session->update([
            'logout_at'        => $logoutAt,
            'duration_seconds' => min(
                (int) $logoutAt->diffInSeconds($session->login_at, true),
                self::MAX_AUTO_SESSION_SECONDS
            ),
            'logout_reason'    => 'POST_SHIFT_AUTO',
        ]);
        $cutoff = $logoutAt;

        // Attendance sheet: only ever move check-out FORWARD. A real punch-out or a manual
        // HR correction that is already later than the cutoff must win.
        $attendance = EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $session->login_at->toDateString())
            ->first();

        if ($attendance) {
            $updates = [];
            if (! $attendance->check_out_at || $attendance->check_out_at->lessThan($cutoff)) {
                $updates['check_out_at'] = $cutoff;
                $updates['check_out_source'] = 'AUTO_POST_SHIFT';
            }
            if (! $attendance->final_logout_at || $attendance->final_logout_at->lessThan($cutoff)) {
                $updates['final_logout_at'] = $cutoff;
            }
            // Signed out AT shift end + N, so by definition this is not an early logout.
            $updates['early_logout_minutes'] = 0;
            $updates['derivation_note'] = trim((string) $attendance->derivation_note . ' | auto sign-out '
                . $cutoff->format('Y-m-d g:i A') . ' (shift end + ' . $minutes . 'm)', ' |');
            $attendance->update($updates);
        }

        // End any break / meeting segment left open, so the live board stops showing a ghost.
        try {
            self::closeDoorAway($employee->id, $cutoff);
            $status->closeAll($employee, $cutoff);
        } catch (\Throwable $e) {
            $this->warn("  status close failed for {$employee->employee_code}: {$e->getMessage()}");
        }

        // Kick the agent: revoke this device's token so it 401s on the next heartbeat and
        // drops back to the login screen instead of tracking through the night.
        // NOT filtered to session_status = ACTIVE. A device whose row had already moved to
        // LOGGED_OUT / FORCE_LOGOUT was skipped entirely, so its token was never revoked and
        // the agent carried on tracking against a closed session (26-Aug-2026). Revoking a
        // token that is already gone costs one query and is always safe.
        $devices = EmployeeDevice::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNull('unbound_at')
            ->get();

        foreach ($devices as $device) {
            $device->revokeAgentToken();
            $device->update([
                'session_status'  => 'FORCE_LOGOUT',
                'force_logout_at' => $this->localNow($employee->company_id),
                'current_status'  => 'OFFLINE',
            ] + (self::stillWorkingReady() ? ['still_working_asked_at' => null, 'still_working_until' => null] : [])); // 10-Oct-2026: next session starts clean
        }

        // 29-Sep-2026: chat is session-only — auto sign-out ends the thread too.
        \App\Models\EmployeeChatMessage::clearFor($employee->id);
    }

    /**
     * 13-Nov-2026 (Ejaz: "40 hr away" after logging in next morning): a door walk-out (Away, no Break
     * started) still open when the day is closed by auto sign-out ends AT the sign-out — it is the day
     * closing. Left open, the next morning's activity / IN punch closed it and booked the whole night
     * (and the day between) as Away, and popped the "Welcome back" notice for it.
     */
    private static function closeDoorAway(int $employeeId, Carbon $at): void
    {
        \App\Models\EmployeeBreakLog::withoutGlobalScopes()->unannounced()
            ->where('employee_id', $employeeId)->whereNull('end_at')->where('start_at', '<', $at)
            ->get()
            ->each(fn ($b) => $b->update(['end_at' => $at, 'duration_seconds' => max(0, $at->getTimestamp() - $b->start_at->getTimestamp())]));
    }
}
