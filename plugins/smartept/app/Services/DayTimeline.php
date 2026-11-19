<?php

namespace App\Services;

use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeeMeetingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 26-Oct-2026 (Ejaz: "no overlap, no redundant calculation anywhere; break or meeting time must
 * never be counted again as Idle or Working; night shifts too") — THE one place an employee's day
 * is measured. Every screen (Productivity report + export + scheduled emails, the "+" detail row,
 * the Live Dashboard, daily summaries, the monthly summary and Extra Hours) reads it from here.
 *
 * The day = first arrival (door IN or PC sign-in, whichever is earlier) → the day's end. Every
 * second of it is given to exactly ONE status, by precedence (highest first):
 *
 *   Meeting > Break (declared) > Away (out of the door, walk back to the desk) > Gate → PC
 *     > Working (keyboard/mouse) > Idle
 *
 * so a minute that is both "on break" and "PC idle" is Break only, a minute in a meeting is
 * Meeting only, and so on. What no record covers is: signed in → Idle (no data from the agent),
 * not signed in → Away. The statuses therefore always add up to Actual Present exactly — there
 * is nothing left over and nothing counted twice, by construction.
 *
 * Shift-day: a day shift is the calendar day; a night shift (crosses midnight) is ONE day from
 * 6 h before its start to 6 h before the next day's start, so 21:00 → 06:00 is a single row.
 */
class DayTimeline
{
    public const GATE_TO_PC_ALLOWED_SECONDS = 600; // ponytail: Ejaz's fixed 10-min rule (23-Oct-2026); move to the Shift if it ever has to vary
    public const ORDER = ['meeting', 'break', 'away', 'gate_to_pc', 'work', 'idle'];
    private const NIGHT_LEAD = 6 * 3600;      // a night shift's day opens 6 h before the shift starts
    private const WALK_BACK_MAX = 4 * 3600;   // door IN → first keyboard/mouse, at most 4 h
    private const JOIN = 10;                  // idle pieces this close are one spell (detail list)
    private const SPILL_GAP = 300;            // 08-Nov-2026: activity this close to last night's session still belongs to last night
    private const EMPLOYEE_DAYS_PER_LOAD = 120; // ponytail: memory bound per batch of raw rows; raise if reports get slow

    // ---------------------------------------------------------------- shift-day window

    public static function isNight($sh): bool
    {
        return $sh && $sh->start_time && $sh->end_time
            && ($sh->crosses_midnight || strtotime('2000-01-01 ' . $sh->end_time) <= strtotime('2000-01-01 ' . $sh->start_time));
    }

    /** The shift's end on shift-day $d (next day for a night shift); null without an end time. */
    public static function shiftEnd($sh, string $d): ?int
    {
        if (! $sh || ! $sh->end_time) {
            return null;
        }

        return strtotime($d . ' ' . $sh->end_time) + (self::isNight($sh) ? 86400 : 0);
    }

    private static function nextDate(string $d, int $days = 1): string
    {
        return date('Y-m-d', strtotime($d . ' 12:00:00') + 86400 * $days);
    }

    private static function dayOpens(Employee $emp, string $d): int
    {
        $sh = $emp->shiftOn($d);
        $mid = strtotime($d . ' 00:00:00');
        if (self::isNight($sh)) {
            return strtotime($d . ' ' . $sh->start_time) - self::NIGHT_LEAD;
        }
        $prev = $emp->shiftOn(self::nextDate($d, -1));
        if (! self::isNight($prev)) {
            return $mid;
        }
        // Day shift after a night shift: open half-way between last night's end and today's start (≤ 2 h after the end).
        $prevEnd = strtotime($d . ' ' . $prev->end_time);
        $start = ($sh && $sh->start_time) ? strtotime($d . ' ' . $sh->start_time) : $prevEnd + 4 * 3600;

        return max($mid, $prevEnd + min(2 * 3600, intdiv(max(0, $start - $prevEnd), 2)));
    }

    /** [opens, closes) of shift-day $d — consecutive days never overlap and leave no gap. */
    public static function window(Employee $emp, string $d): array
    {
        return [self::dayOpens($emp, $d), self::dayOpens($emp, self::nextDate($d))];
    }

    /** The shift-day running at this moment: today, or yesterday while last night's shift is still on. */
    public static function currentDate(Employee $emp, string $today, ?int $now = null): string
    {
        return ($now ?? now()->getTimestamp()) < self::window($emp, $today)[0] ? self::nextDate($today, -1) : $today;
    }

    // ---------------------------------------------------------------- loading + ledgers

    /**
     * Every employee-day with a footprint in [from, to], keyed "employeeId|Y-m-d":
     * ['emp' => Employee, 'date' => 'Y-m-d', 'ledger' => array]. Raw rows are loaded in batches.
     */
    public static function days($employees, string $from, string $to, ?int $now = null): array
    {
        $now ??= now()->getTimestamp(); // the app clock (tests travel it), never the raw system clock
        $employees = collect($employees)->values();
        $dates = [];
        for ($d = $from; $d <= $to; $d = self::nextDate($d)) {
            $dates[] = $d;
        }
        if (! $dates || $employees->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ($employees->chunk(max(1, intdiv(self::EMPLOYEE_DAYS_PER_LOAD, count($dates)))) as $chunk) {
            $win = [];
            $before = []; // the day before $from: only to see last night's session (spill-over below)
            foreach ($chunk as $e) {
                foreach ($dates as $d) {
                    $win[$e->id][$d] = self::window($e, $d);
                }
                $before[$e->id] = self::window($e, self::nextDate($from, -1));
            }
            $all = array_merge(array_values($before), ...array_map('array_values', array_values($win)));
            $data = self::load($chunk->pluck('id')->all(), min(array_column($all, 0)), max(array_column($all, 1)));
            // 08-Nov-2026 (Ejaz): activity that carries on past midnight from last night's session stays on
            // last night's day. It used to open the new day (e.g. 12:14 AM), so everything up to the first
            // sign-in of that day was counted as Away (19h 30m on the agent card and in the report).
            foreach ($chunk as $e) {
                $prevWs = $before[$e->id][0];
                $prevD = null;
                foreach ($dates as $d) {
                    [$ws, $we] = $win[$e->id][$d];
                    $b = self::spill($data['sess'][$e->id] ?? [], $data['ev'][$e->id] ?? [], $prevWs, $ws, $we);
                    if ($b > $ws) {
                        $win[$e->id][$d][0] = $b;
                        if ($prevD !== null) {
                            $win[$e->id][$prevD][1] = $b;
                        }
                    }
                    $prevWs = $ws;
                    $prevD = $d;
                }
            }
            // The attendance record of each day (check-in / check-out, incl. HR's manual corrections).
            $att = [];
            foreach (EmployeeAttendanceLog::withoutGlobalScopes()->whereIn('employee_id', $chunk->pluck('id')->all())
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
                ->get(['employee_id', 'work_date', 'source', 'check_in_source', 'check_in_at', 'check_out_at']) as $a) {
                $att[$a->employee_id . '|' . Carbon::parse($a->work_date)->toDateString()] = $a;
            }
            foreach ($chunk as $e) {
                foreach ($dates as $d) {
                    [$ws, $we] = $win[$e->id][$d];
                    if ($we <= $ws) {
                        continue;
                    }
                    $x = [];
                    foreach (['sess', 'pun', 'ev', 'brk', 'mtg', 'viol'] as $k) {
                        $x[$k] = self::slice($data[$k][$e->id] ?? [], $ws, $we);
                    }
                    $x['att'] = $att[$e->id . '|' . $d] ?? null;
                    if ($L = self::ledgerFrom($e, $d, $ws, $we, $x, $now)) {
                        $out[$e->id . '|' . $d] = ['emp' => $e, 'date' => $d, 'ledger' => $L];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 08-Nov-2026: where day $ws really starts for this employee. If last night's session (signed in during
     * [prevWs, ws)) ran up to midnight, the agent activity that carries straight on from it (gaps under
     * SPILL_GAP) is last night's — until this day's own first sign-in, and at most 12 h. Otherwise $ws.
     */
    private static function spill(array $sess, array $ev, int $prevWs, int $ws, int $we): int
    {
        $lastEnd = null;
        $cap = min($we, $ws + 12 * 3600);
        foreach ($sess as $s) {
            if ($s['s'] >= $prevWs && $s['s'] < $ws) {
                $lastEnd = max($lastEnd ?? 0, $s['e'] ?? $ws); // still open = reaches midnight; activity decides the rest
            } elseif ($s['s'] >= $ws && $s['s'] < $cap) {
                $cap = $s['s']; // this day's own sign-in starts this day
            }
        }
        if ($lastEnd === null || $lastEnd < $ws - self::SPILL_GAP) {
            return $ws;
        }
        $b = min(max($ws, $lastEnd), $cap);
        foreach ($ev as $x) {
            if ($x['s'] < $ws) {
                continue;
            }
            if ($x['s'] >= $cap || $x['s'] > $b + self::SPILL_GAP) {
                break;
            }
            $b = max($b, min($x['e'], $cap));
        }

        return $b;
    }

    /** One employee-day (null = no footprint). */
    public static function ledger(Employee $emp, string $date): ?array
    {
        return self::days([$emp], $date, $date)[$emp->id . '|' . $date]['ledger'] ?? null;
    }

    /** Raw rows for these employees in [lo, hi), as plain arrays sorted by time, grouped by employee. */
    private static function load(array $ids, int $lo, int $hi): array
    {
        [$a, $b] = [date('Y-m-d H:i:s', $lo), date('Y-m-d H:i:s', $hi)];
        $ts = fn ($v) => ($v === null || $v === '') ? null : strtotime((string) $v);
        $q = fn ($model, $col) => $model::withoutGlobalScopes()->whereIn('employee_id', $ids)
            ->where($col, '>=', $a)->where($col, '<', $b)->orderBy($col)->toBase();
        $group = function ($rows, $map) {
            $g = [];
            foreach ($rows as $r) {
                $g[$r->employee_id][] = $map($r);
            }

            return $g;
        };
        $end = fn ($s, $e, $dur) => ((int) $dur > 0) ? $s + (int) $dur : ($e ?? $s); // measured duration, as every sum always used

        return [
            'sess' => $group($q(EmployeeLoginSession::class, 'login_at')->get(['employee_id', 'login_at', 'logout_at', 'logout_reason']),
                fn ($r) => ['s' => $ts($r->login_at), 'e' => $ts($r->logout_at), 'reason' => $r->logout_reason]),
            'pun' => $group($q(BiometricLog::class, 'punched_at')->whereNotNull('employee_id')->get(['employee_id', 'punch_type', 'punched_at']),
                fn ($r) => ['s' => $ts($r->punched_at), 'in' => in_array($r->punch_type, ['IN', 'BREAK_IN'], true)]),
            'ev' => $group($q(EmployeeActivityEvent::class, 'started_at')->whereIn('event_type', ['ACTIVE', 'IDLE'])
                ->get(['employee_id', 'event_type', 'started_at', 'ended_at', 'duration_seconds']),
                fn ($r) => ['s' => $s = $ts($r->started_at), 'e' => $end($s, $ts($r->ended_at), $r->duration_seconds), 'work' => $r->event_type === 'ACTIVE']),
            'brk' => $group($q(EmployeeBreakLog::class, 'start_at')->get(['employee_id', 'break_type', 'start_at', 'end_at', 'source', 'device_uuid']),
                // A door punch-out with NO Break started (GateService: source BIOMETRIC, no device) is Away, not Break.
                fn ($r) => ['s' => $ts($r->start_at), 'e' => $ts($r->end_at), 'type' => $r->break_type,
                    'declared' => ! ($r->source === 'BIOMETRIC' && $r->device_uuid === null)]),
            'mtg' => $group($q(EmployeeMeetingSession::class, 'actual_start_at')->get(['employee_id', 'actual_start_at', 'actual_end_at', 'duration_seconds']),
                fn ($r) => ['s' => $s = $ts($r->actual_start_at),
                    'e' => $r->actual_end_at ? $ts($r->actual_end_at) : (((int) $r->duration_seconds > 0) ? $s + (int) $r->duration_seconds : null)]),
            'viol' => $group($q(EmployeeComplianceEvent::class, 'started_at')->get(['employee_id', 'started_at']), fn ($r) => ['s' => $ts($r->started_at)]),
        ];
    }

    /** Rows (sorted by 's') with ws ≤ s < we. */
    private static function slice(array $rows, int $ws, int $we): array
    {
        $lo = 0;
        $hi = count($rows);
        while ($lo < $hi) {
            $m = ($lo + $hi) >> 1;
            $rows[$m]['s'] < $ws ? $lo = $m + 1 : $hi = $m;
        }
        $out = [];
        for ($i = $lo, $n = count($rows); $i < $n && $rows[$i]['s'] < $we; $i++) {
            $out[] = $rows[$i];
        }

        return $out;
    }

    private static function ledgerFrom(Employee $emp, string $d, int $ws, int $we, array $x, int $now): ?array
    {
        ['sess' => $sess, 'pun' => $pun, 'ev' => $ev, 'brk' => $brk, 'mtg' => $mtg] = $x;
        $a = $x['att'] ?? null;
        $ts = fn ($v) => $v ? strtotime((string) $v) : null;
        // The attendance record is the authoritative arrival / departure (HR corrections included). A
        // check-in taken from the DOOR is not a PC sign-in, so it never shows as "Logged in" (14-Oct-2026).
        $attIn = $a ? $ts($a->check_in_at) : null;
        $attOut = $a ? $ts($a->check_out_at) : null;
        $attInIsDoor = $a && ($a->source === 'BIOMETRIC' || $a->check_in_source === 'BIOMETRIC');
        $firstLogin = $sess[0]['s'] ?? null;
        $pcIn = ($attIn !== null && ! $attInIsDoor) ? min(array_filter([$firstLogin, $attIn])) : $firstLogin;
        $gateIn = null;
        foreach ($pun as $p) {
            if ($p['in']) {
                $gateIn = $p['s'];
                break;
            }
        }
        // Anything recorded starts the day: arrival, or the first activity / break / meeting.
        $firsts = array_values(array_filter([$gateIn, $pcIn, $attIn, $ev[0]['s'] ?? null, $brk[0]['s'] ?? null, $mtg[0]['s'] ?? null],
            fn ($v) => $v !== null));
        if (! $firsts) {
            return null;
        }
        $start = min($firsts);
        $current = $now >= $ws && $now < $we;
        $last = $sess ? $sess[count($sess) - 1] : null;
        $reSignedIn = $attOut !== null && $sess && max(array_column($sess, 's')) > $attOut;
        $useAttOut = $attOut !== null && ! $reSignedIn;
        $open = $useAttOut ? false : ($sess ? $last['e'] === null : ($attIn !== null && $attOut === null));

        $lastHard = 0;   // last end of work / break / meeting
        $lastAny = 0;    // ... or idle
        foreach ($ev as $e) {
            $lastAny = max($lastAny, $e['e']);
            if ($e['work']) $lastHard = max($lastHard, $e['e']);
        }
        foreach (array_merge($brk, $mtg) as $r) {
            if ($r['e'] !== null) $lastHard = max($lastHard, $r['e']);
        }
        $lastAny = max($lastAny, $lastHard);

        // ---- where the day ends
        $reason = null;
        $gap = 0;
        $lastOut = null;
        if ($open && $current) {
            $end = $now;
            $reason = 'LIVE';
        } elseif ($open) {
            // Past day, never signed out: bound at the moment the server would have signed them out
            // (shift end + post-shift minutes). Work, breaks and meetings are never cut — only the idle tail.
            $sh = $emp->shiftOn($d);
            $cap = self::shiftEnd($sh, $d);
            $cap = $cap === null ? null : $cap + 60 * (int) ($sh->post_shift_auto_logout_minutes ?? 0);
            $end = $lastAny ?: (int) $pcIn;
            $reason = 'NO_SIGN_OUT';
            if ($cap !== null && $cap > $start && $end > $cap) {
                $end = max($cap, $lastHard);
                if ($end < $lastAny) $reason = 'NO_SIGN_OUT_CAPPED';
            }
        } elseif ($useAttOut || $sess) {
            $lastOut = $useAttOut ? $attOut : max(array_column($sess, 'e'));
            if ($last && $last['reason'] === 'POST_SHIFT_AUTO' && $last['e'] !== null && abs($lastOut - $last['e']) <= 120) {
                // 09-Oct-2026: the auto sign-out is not when they left — last door OUT / last activity / shift end.
                $end = self::autoEnd($emp, $d, $start, $last['e'], $pun, $ev);
                $lastOut = $end;
                $reason = 'AUTO';
            } else {
                // Activity recorded after the sign-out (agent kept running) extends the day — flagged.
                $end = max($lastOut, $lastAny);
                $gap = $end - $lastOut;
                if ($gap > 0) $reason = 'ACTIVITY_AFTER_SIGN_OUT';
            }
        } else {
            // No PC sign-in and no attendance record: door punches (and/or agent data) only.
            $lp = $pun ? $pun[count($pun) - 1] : null;
            $end = ($current && $lp && $lp['in']) ? $now : max($lastAny, ($lp && ! $lp['in']) ? $lp['s'] : 0);
        }
        $end = min(max($end, $start), $we, $current ? max($now, $start) : PHP_INT_MAX);

        // ---- the claims, highest precedence first
        $firstWork = function (int $t) use ($ev) {
            foreach ($ev as $e) {
                if ($e['work'] && $e['s'] >= $t) return $e['s'];
            }

            return null;
        };
        $L = [];
        foreach ($mtg as $i => $m) {
            $e = $m['e'] ?? ($current ? $now : null);
            if ($e !== null) $L[] = ['meeting', 'm' . $i, $m['s'], $e];
        }
        $declared = [];
        foreach ($brk as $i => $b) {
            if (! $b['declared']) continue;
            $declared[$i] = $b;
            // A break never ended: today it runs to now; on a closed day it ends when work resumed (else the day end).
            $L[] = ['break', 'b' . $i, $b['s'], $b['e'] ?? ($current ? $now : ($firstWork($b['s']) ?? $end))];
        }
        $doors = [];
        foreach ($brk as $b) {
            if (! $b['declared']) $doors[] = [$b['s'], $b['e']];
        }
        $out = null;
        foreach ($pun as $p) {
            if (! $p['in']) {
                $out ??= $p['s'];
                continue;
            }
            if ($out !== null) {
                if ($p['s'] - $out >= GateService::MERGE_UNDER_MINUTES * 60) $doors[] = [$out, $p['s']];
                $out = null;
            }
        }
        if ($out !== null) $doors[] = [$out, null];
        usort($doors, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($doors as $i => [$o, $in]) {
            $L[] = ['away', 'door' . $i, $o, $in ?? ($current ? $now : $end)];
        }
        foreach ($doors as $i => [$o, $in]) {
            // The walk back in (door IN → first keyboard/mouse) is part of that Away (29-Sep-2026).
            $back = $in === null ? null : $firstWork($in);
            if ($back !== null && $back - $in <= self::WALK_BACK_MAX) $L[] = ['away', 'walk' . $i, $in, $back];
        }
        if ($gateIn !== null && $pcIn !== null && $gateIn < $pcIn) {
            $L[] = ['gate_to_pc', 'g', $gateIn, $pcIn];
        }
        foreach ([true, false] as $work) {
            foreach ($ev as $i => $e) {
                if ($e['work'] === $work) $L[] = [$work ? 'work' : 'idle', 'e' . $i, $e['s'], $e['e']];
            }
        }

        [$parts, $holes] = self::partition($start, $end, $L);

        // ---- what nothing covers: signed in → Idle (no data from the agent); not signed in → Away
        $sessWin = $sess ? self::union(array_map(fn ($s) => [$s['s'], $s['e'] ?? $end], $sess))
            : ($attIn !== null ? [[$attIn, $useAttOut ? $attOut : $end]] : []);
        foreach ($holes as $h) {
            if (! $sessWin) {
                $parts[] = [$ev ? 'idle' : 'away', $ev ? 'nodata' : 'nosignin', [$h], $h[1] - $h[0]];
                continue;
            }
            foreach (self::intersect([$h], $sessWin) as $p) $parts[] = ['idle', 'nodata', [$p], $p[1] - $p[0]];
            foreach (self::subtract([$h], $sessWin) as $p) $parts[] = ['away', 'signedout', [$p], $p[1] - $p[0]];
        }

        $sec = array_fill_keys(self::ORDER, 0);
        foreach ($parts as $p) {
            $sec[$p[0]] += $p[3];
        }
        $gaps = [];
        foreach ($parts as $p) {
            if ($p[1] === 'nodata' && $p[3] >= 120) $gaps[] = [$p[2][0][0], $p[2][0][1], $open && $current && $p[2][0][1] >= $end - 5];
        }
        usort($gaps, fn ($a, $b) => ($b[1] - $b[0]) <=> ($a[1] - $a[0]));
        $gaps = array_slice($gaps, 0, 4);
        usort($gaps, fn ($a, $b) => $a[0] <=> $b[0]);

        return [
            'date' => $d, 'window' => [$ws, $we], 'current' => $current, 'live' => $open && $current,
            'start' => $start, 'end' => $end, 'present' => $end - $start,
            'gate_in' => $gateIn, 'pc_in' => $pcIn, 'last_out' => $lastOut,
            'end_reason' => $reason, 'clock_gap' => $gap,
            'sec' => $sec, 'parts' => $parts, 'gaps' => $gaps,
            'break_count' => count($declared),
            'timeouts' => count(array_filter($sess, fn ($s) => in_array($s['reason'], ['LOCK', 'TIMEOUT'], true))),
            'violations' => count($x['viol']),
            'raw' => $x, 'doors' => $doors,
        ];
    }

    private static function autoEnd(Employee $emp, string $d, int $start, int $autoOut, array $pun, array $ev): int
    {
        $punchOut = null;
        foreach ($pun as $p) {
            if ($p['s'] >= $start && $p['s'] <= $autoOut) $punchOut = $p['in'] ? null : $p['s'];
        }
        $activeEnd = 0;
        foreach ($ev as $e) {
            if ($e['work'] && $e['s'] >= $start && $e['s'] <= $autoOut) $activeEnd = max($activeEnd, $e['e']);
        }
        $end = max((int) $punchOut, $activeEnd) ?: (self::shiftEnd($emp->shiftOn($d), $d) ?? $autoOut);

        return max($start, min($end, $autoOut));
    }

    // ---------------------------------------------------------------- the figures every report shows

    /**
     * The report figures for one ledger (23-Oct + 26-Oct-2026 rules — each formula IS the column title):
     *   Productive Hrs     = Working + Meeting + Gate to PC up to 10 min
     *   Non-Productive Hrs = Idle + Away + Break Exceed + Gate to PC beyond 10 min
     *   Break Exceed       = Break Availed − Allotted break (never below 0)
     *   Net Hrs            = Actual Present − Break Availed up to the Allotted break
     *   Productive %       = Productive ÷ Net  → Productive + Non-Productive = Net Hrs, always.
     */
    public static function figures(Employee $emp, array $L): array
    {
        $sh = $emp->shiftOn($L['date']);
        $s = $L['sec'];
        $g2pOk = min($s['gate_to_pc'], self::GATE_TO_PC_ALLOWED_SECONDS);
        // Allotted = the shift's break allowance, pro rata only on a late login / early logout. While still
        // signed in, the rest of the shift still to come counts as present for this.
        $basis = $L['present'];
        if ($L['live'] && ($se = self::shiftEnd($sh, $L['date'])) !== null) {
            $basis += max(0, $se - $L['end']);
        }
        $allotted = ScoringService::allottedBreakSeconds($sh, $basis);
        $exceed = max(0, $s['break'] - $allotted);
        $productive = $s['work'] + $s['meeting'] + $g2pOk;
        $nonProductive = $s['idle'] + $s['away'] + $exceed + ($s['gate_to_pc'] - $g2pOk);
        $net = $L['present'] - min($s['break'], $allotted);
        $c = fn ($ts) => $ts === null ? null : Carbon::parse(date('Y-m-d H:i:s', $ts));
        $late = app(AttendanceDerivation::class)->lateFor($emp, $L['date'], $c($L['pc_in']), $c($L['gate_in']), $c($L['start']));

        return [
            'present' => $L['present'], 'work' => $s['work'], 'meeting' => $s['meeting'], 'idle' => $s['idle'],
            'away' => $s['away'], 'break' => $s['break'], 'gate_to_pc' => $s['gate_to_pc'],
            'gate_to_pc_ok' => $g2pOk, 'gate_to_pc_exceed' => $s['gate_to_pc'] - $g2pOk,
            'allotted' => $allotted, 'allot_basis' => $basis, 'exceed' => $exceed,
            'productive' => $productive, 'non_productive' => $nonProductive, 'net' => $net,
            'productivity' => $net > 0 ? round($productive / $net * 100, 1) : null,
            'late' => $late ? (int) $late['minutes'] : 0,
        ];
    }

    /** The exclusive [s, e) pieces of the given statuses — e.g. ['work'] for Extra Hours. */
    public static function pieces(array $L, array $buckets): array
    {
        $out = [];
        foreach ($L['parts'] as $p) {
            if (in_array($p[0], $buckets, true)) array_push($out, ...$p[2]);
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);

        return $out;
    }

    // ---------------------------------------------------------------- the "+" detail row

    /** One employee's day, event by event — totals are the SAME ledger the report row is built from. */
    public static function build(Employee $emp, string $date): array
    {
        $L = self::ledger($emp, $date);
        $t = fn ($ts) => $ts === null ? null : date('H:i:s', $ts);
        $base = ['date' => $date, 'employee' => ['id' => $emp->id, 'code' => $emp->employee_code, 'name' => trim($emp->first_name . ' ' . $emp->last_name)]];
        if (! $L) {
            return $base + ['door' => [], 'sessions' => [], 'idle' => [], 'away' => [], 'breaks' => [], 'totals' => [
                'door_in_count' => 0, 'door_out_count' => 0, 'sign_in_count' => 0, 'idle_count' => 0, 'idle_seconds' => 0,
                'idle_in_away_seconds' => 0, 'away_count' => 0, 'away_seconds' => 0, 'first_gate_to_pc_seconds' => 0,
                'return_gate_to_pc_seconds' => 0, 'gate_to_pc_seconds' => 0, 'break_count' => 0, 'break_seconds' => 0]];
        }
        $raw = $L['raw'];

        $door = [];
        foreach ($raw['pun'] as $p) {
            if ($p['in'] || ! $door || end($door)['out'] !== null) {
                $door[] = ['n' => count($door) + 1, 'in' => $p['in'] ? $t($p['s']) : null, 'out' => $p['in'] ? null : $t($p['s'])];
            } else {
                $door[count($door) - 1]['out'] = $t($p['s']);
            }
        }
        $sessions = array_map(fn ($s, $i) => ['n' => $i + 1, 'sign_in' => $t($s['s']), 'sign_out' => $t($s['e']), 'reason' => $s['reason']],
            $raw['sess'], array_keys($raw['sess']));

        $assigned = [];   // tag => seconds that status really got
        foreach ($L['parts'] as $p) {
            $assigned[$p[1]] = ($assigned[$p[1]] ?? 0) + $p[3];
        }

        // Idle spells, as the agent recorded them (stretches joined), each with how much of it is
        // counted as Idle and how much was already given to Away or Break (never counted twice);
        // plus the signed-in stretches the agent sent nothing for (counted as Idle).
        $pieces = ['idle' => [], 'away' => [], 'break' => []];
        foreach ($L['parts'] as $p) {
            if (isset($pieces[$p[0]])) array_push($pieces[$p[0]], ...$p[2]);
        }
        $len = fn (array $iv) => array_sum(array_map(fn ($q) => $q[1] - $q[0], $iv));
        $spells = [];
        foreach ($raw['ev'] as $e) {
            if ($e['work']) continue;
            $k = count($spells) - 1;
            if ($k >= 0 && ! $spells[$k]['nd'] && $e['s'] <= $spells[$k]['to'] + self::JOIN) {
                $spells[$k]['to'] = max($spells[$k]['to'], $e['e']);
                $spells[$k]['seconds'] += $e['e'] - $e['s'];
                $spells[$k]['iv'][] = [$e['s'], $e['e']];
                continue;
            }
            $spells[] = ['from' => $e['s'], 'to' => $e['e'], 'seconds' => $e['e'] - $e['s'], 'iv' => [[$e['s'], $e['e']]], 'nd' => false];
        }
        foreach ($L['parts'] as $p) {
            if ($p[1] === 'nodata') {
                [$a, $b] = $p[2][0];
                $spells[] = ['from' => $a, 'to' => $b, 'seconds' => $b - $a, 'iv' => [[$a, $b]], 'nd' => true];
            }
        }
        usort($spells, fn ($x, $y) => $x['from'] <=> $y['from']);
        $idle = [];
        foreach ($spells as $i => $sp) {
            $back = null;
            foreach ($raw['ev'] as $e) {
                if ($e['work'] && abs($e['s'] - $sp['to']) <= self::JOIN) { $back = $e['s']; break; }
            }
            $idle[] = ['n' => $i + 1, 'from' => $t($sp['from']), 'to' => $t($sp['to']), 'returned_to_active' => $t($back),
                'no_data' => $sp['nd'], 'seconds' => $sp['seconds'],
                'in_away_seconds' => $len(self::intersect($sp['iv'], $pieces['away'])),
                'in_break_seconds' => $len(self::intersect($sp['iv'], $pieces['break'])),
                'counted_seconds' => $len(self::intersect($sp['iv'], $pieces['idle']))];
        }

        $away = [];
        foreach ($L['doors'] as $i => [$o, $in]) {
            $ds = $assigned['door' . $i] ?? 0;
            $ws = $assigned['walk' . $i] ?? 0;
            if ($ds + $ws === 0) continue;
            $walkEnd = null;
            foreach ($L['parts'] as $p) {
                if ($p[1] === 'walk' . $i && $p[2]) $walkEnd = end($p[2])[1];
            }
            $away[] = ['how' => 'Door', '_s' => $o, 'out' => $t($o), 'in' => $t($in), 'back_at_desk' => $t($walkEnd),
                'door_seconds' => $ds, 'gate_to_pc_seconds' => $ws, 'away_seconds' => $ds + $ws];
        }
        foreach ($L['parts'] as $p) {
            if (! in_array($p[1], ['signedout', 'nosignin'], true)) continue;
            [$a, $b] = $p[2][0];
            $away[] = ['how' => $p[1] === 'signedout' ? 'Signed out' : 'Not signed in', '_s' => $a, 'out' => $t($a), 'in' => $t($b),
                'back_at_desk' => $t($b), 'door_seconds' => $b - $a, 'gate_to_pc_seconds' => 0, 'away_seconds' => $b - $a];
        }
        usort($away, fn ($x, $y) => $x['_s'] <=> $y['_s']);
        foreach ($away as $i => &$a) { $a['n'] = $i + 1; unset($a['_s']); }
        unset($a);

        $breaks = [];
        foreach ($raw['brk'] as $i => $b) {
            if (! $b['declared']) continue;
            $breaks[] = ['n' => count($breaks) + 1, 'type' => $b['type'], 'from' => $t($b['s']), 'to' => $t($b['e']), 'seconds' => $assigned['b' . $i] ?? 0];
        }

        $s = $L['sec'];

        return $base + [
            'door' => $door, 'sessions' => $sessions, 'idle' => $idle, 'away' => $away, 'breaks' => $breaks,
            'totals' => [
                'door_in_count' => count(array_filter(array_column($door, 'in'))),
                'door_out_count' => count(array_filter(array_column($door, 'out'))),
                'sign_in_count' => count($sessions),
                'idle_count' => count($idle),
                'idle_seconds' => $s['idle'],
                'idle_in_away_seconds' => 0,
                'away_count' => count($away),
                'away_seconds' => $s['away'],
                'first_gate_to_pc_seconds' => $s['gate_to_pc'],
                'return_gate_to_pc_seconds' => array_sum(array_column($away, 'gate_to_pc_seconds')),
                'gate_to_pc_seconds' => $s['gate_to_pc'],
                'break_count' => count($breaks),
                'break_seconds' => $s['break'],
                'working_seconds' => $s['work'],
                'meeting_seconds' => $s['meeting'],
                'present_seconds' => $L['present'],
            ],
        ];
    }

    // ---------------------------------------------------------------- interval arithmetic (pure, ints)

    /**
     * Give every second of [a, b) to the FIRST claim (in list order) that covers it.
     * $claims: list of [status, tag, s, e]. Returns [parts, holes]: parts = list of
     * [status, tag, pieces[[s,e]...], seconds] (claims that got nothing are dropped); holes = the
     * uncovered [s, e) pieces. Sum of all part seconds + hole seconds === b − a, always.
     */
    public static function partition(int $a, int $b, array $claims): array
    {
        $taken = [];
        $parts = [];
        foreach ($claims as [$status, $tag, $s, $e]) {
            $got = self::claim($taken, max($s, $a), min($e, $b));
            if ($got) {
                $parts[] = [$status, $tag, $got, array_sum(array_map(fn ($p) => $p[1] - $p[0], $got))];
            }
        }

        return [$parts, self::subtract([[$a, $b]], $taken)];
    }

    /** Take the not-yet-taken pieces of [s, e) and add them to $taken (sorted, disjoint). */
    private static function claim(array &$taken, int $s, int $e): array
    {
        if ($e <= $s) {
            return [];
        }
        $lo = 0;
        $hi = count($taken);
        while ($lo < $hi) {
            $m = ($lo + $hi) >> 1;
            $taken[$m][1] < $s ? $lo = $m + 1 : $hi = $m;
        }
        $free = [];
        $cur = $s;
        [$ns, $ne] = [$s, $e];
        $j = $lo;
        for ($n = count($taken); $j < $n && $taken[$j][0] <= $e; $j++) {
            [$cs, $ce] = $taken[$j];
            if ($cs > $cur) $free[] = [$cur, min($cs, $e)];
            $cur = max($cur, $ce);
            $ns = min($ns, $cs);
            $ne = max($ne, $ce);
        }
        if ($cur < $e) $free[] = [$cur, $e];
        array_splice($taken, $lo, $j - $lo, [[$ns, $ne]]);

        return $free;
    }

    /** Sorted, disjoint union of [s, e) intervals. */
    public static function union(array $iv): array
    {
        usort($iv, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($iv as [$s, $e]) {
            if ($e <= $s) continue;
            $k = count($out) - 1;
            if ($k >= 0 && $s <= $out[$k][1]) {
                $out[$k][1] = max($out[$k][1], $e);
            } else {
                $out[] = [$s, $e];
            }
        }

        return $out;
    }

    /** Pieces of $a not covered by $b. */
    public static function subtract(array $a, array $b): array
    {
        $b = self::union($b);
        $out = [];
        foreach (self::union($a) as [$s, $e]) {
            $cur = $s;
            foreach ($b as [$bs, $be]) {
                if ($be <= $cur) continue;
                if ($bs >= $e) break;
                if ($bs > $cur) $out[] = [$cur, $bs];
                $cur = max($cur, $be);
                if ($cur >= $e) break;
            }
            if ($cur < $e) $out[] = [$cur, $e];
        }

        return $out;
    }

    /** Pieces of $a covered by $b. */
    public static function intersect(array $a, array $b): array
    {
        return self::subtract(self::union($a), self::subtract(self::union($a), $b));
    }
}
