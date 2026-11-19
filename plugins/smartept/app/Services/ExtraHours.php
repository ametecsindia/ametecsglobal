<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeLoginSession;
use App\Models\ExtraHour;
use Illuminate\Support\Carbon;

/**
 * 10-Oct-2026 (Ejaz): Extra Hours — ACTIVE work outside the shift window, recorded per
 * employee per day for appreciation and record.
 *
 *  - Window = the shift's [start, end] on that date (a night shift ends the next day).
 *    Active time before start = "before shift", after end = "after shift".
 *  - Weekly off or company holiday: all active time that day is extra.
 *  - Recorded only when the day's extra total reaches the shift's minimum (blank = 30 min).
 *  - All active work counts (any app); idle, breaks, meetings and away never do — each second once,
 *    from the day ledger (DayTimeline), so it can never overlap the Productivity report's other statuses.
 *  - The shift's "approval" option makes new days PENDING; otherwise RECORDED.
 *
 * Deliberately separate from the productivity report: that calculation is not changed.
 */
class ExtraHours
{
    public const DEFAULT_MIN_MINUTES = 30;

    public function __construct(private WorkCalendar $calendar) {}

    /**
     * Recompute and store the given employees' days in [from, to]. A day already Approved or
     * Rejected keeps its decision (its seconds are refreshed). A day that no longer reaches the
     * minimum is removed unless it was decided.
     */
    public function refresh(int $companyId, iterable $employees, string $from, string $to): void
    {
        $from = max($from, self::since()); // 14-Oct-2026: nothing before the feature went live
        if ($to < $from) {
            return;
        }
        $byEmp = collect($employees)->keyBy('id');
        if ($byEmp->isEmpty()) {
            return;
        }
        // 14-Oct-2026 (Ejaz: "employees not exempted are shown"; "15 hours post shift?"):
        //  1. Only employees allowed to work outside shift hours (employee record) have Extra Hours.
        //     Anyone else's undecided rows are removed.
        //  2. An ACTIVE stretch counts for its measured duration, not start → recorded end: a PC that
        //     slept or locked mid-stretch closed it the next morning, booking the whole night.
        //  3. Only time while signed in to the agent counts.
        foreach ($byEmp as $emp) {
            if (! $emp->work_outside_shift) {
                ExtraHour::withoutGlobalScopes()->where('employee_id', $emp->id)
                    ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
                    ->whereNotIn('status', ['APPROVED', 'REJECTED'])->delete();
                $byEmp->forget($emp->id);
            }
        }
        if ($byEmp->isEmpty()) {
            return;
        }
        // 26-Oct-2026 (Ejaz: no time counted twice): the working time comes from the ONE day ledger —
        // keyboard/mouse time that is not inside a break, meeting, Away or the gate → PC walk, each second
        // once (duplicate activity records cannot add up twice), measured per shift-day (night shifts whole).
        $days = DayTimeline::days($byEmp->values(), $from, $to);
        $events = [];
        foreach ($days as $k => $day) {
            $events[$k] = collect(DayTimeline::pieces($day['ledger'], ['work']))->map(fn ($p) => (object) [
                'started_at' => Carbon::parse(date('Y-m-d H:i:s', $p[0])), 'ended_at' => Carbon::parse(date('Y-m-d H:i:s', $p[1])), 'duration_seconds' => 0,
            ]);
        }

        foreach ($byEmp as $emp) {
            foreach (Carbon::parse($from)->toPeriod(Carbon::parse($to)) as $day) {
                $date = $day->toDateString();
                $calc = $this->forDay($emp, $date, $events[$emp->id . '|' . $date] ?? collect());
                $row = ExtraHour::withoutGlobalScopes()->where('employee_id', $emp->id)->whereDate('work_date', $date)->first();
                $decided = $row && in_array($row->status, ['APPROVED', 'REJECTED'], true);
                $min = (int) ($emp->shift?->extra_min_minutes ?? self::DEFAULT_MIN_MINUTES) * 60;

                if ($calc['before'] + $calc['after'] < max(60, $min)) {
                    if ($row && ! $decided) {
                        $row->delete();
                    }
                    continue;
                }
                $vals = ['before_seconds' => $calc['before'], 'after_seconds' => $calc['after'],
                    'off_day' => $calc['off_day'], 'last_active_at' => $calc['last']];
                if ($row) {
                    $row->update($vals);
                } else {
                    ExtraHour::create($vals + ['company_id' => $companyId, 'employee_id' => $emp->id, 'work_date' => $date,
                        'status' => $emp->shift?->extra_needs_approval ? 'PENDING' : 'RECORDED']);
                }
            }
        }
    }

    /** @return array{before:int, after:int, off_day:bool, last:?Carbon} */
    public function forDay(Employee $emp, string $date, $events): array
    {
        $shift = $emp->shiftOn($date); // 13-Oct-2026: that day's own timing
        $offDay = $this->calendar->isHoliday((int) $emp->company_id, $date) || ! $this->calendar->isShiftDay($emp, $date);
        $start = $end = null;
        $windows = [];
        if (! $offDay && $shift?->start_time && $shift?->end_time) {
            $start = Carbon::parse($date . ' ' . $shift->start_time);
            $end = Carbon::parse($date . ' ' . $shift->end_time);
            $windows[] = [$start, $end];
            if ($shift->crosses_midnight || $end->lessThanOrEqualTo($start)) {
                $end->addDay();
                // Night shift: the early hours of this date belong to LAST night's shift.
                $windows[] = [$start->copy()->subDay(), $end->copy()->subDay()];
            }
        }
        $overlap = fn (Carbon $a, Carbon $b, Carbon $c, Carbon $d) => max(0, (int) ($a->greaterThan($c) ? $a : $c)->diffInSeconds($b->lessThan($d) ? $b : $d, false));
        $before = $after = 0;
        $last = null;
        foreach ($events as $e) {
            $s = $e->started_at->copy();
            $f = $e->ended_at ? $e->ended_at->copy() : $s->copy()->addSeconds((int) $e->duration_seconds);
            if ($f->lessThanOrEqualTo($s)) {
                continue;
            }
            if ($offDay) {
                $after += (int) $s->diffInSeconds($f, true);
            } elseif ($start) {
                $dur = (int) $s->diffInSeconds($f, true);
                $inside = 0;
                foreach ($windows as [$ws, $we]) {
                    $inside += $overlap($s, $f, $ws, $we);
                }
                $out = $dur - $inside;
                if ($out <= 0) {
                    continue;
                }
                // Outside time up to today's shift start is "before"; the rest is "after".
                $pre = $s->lessThan($start) ? $overlap($s, $f, $s, $start) : 0;
                foreach (array_slice($windows, 1) as [$ws, $we]) {
                    $pre -= $overlap($s, $f->lessThan($start) ? $f : $start, $ws, $we);
                }
                $pre = max(0, min($out, $pre));
                $before += $pre;
                $after += $out - $pre;
            } else {
                continue; // a working day with no shift times has no "outside" to measure
            }
            $last = (! $last || $f->greaterThan($last)) ? $f : $last;
        }

        return ['before' => $before, 'after' => $after, 'off_day' => $offDay, 'last' => $last];
    }

    /**
     * 14-Oct-2026 (Ejaz): the report starts on the day Extra Hours went live on this server — the day
     * its first row was written (fixed once, stored, so it never moves). A server with no rows yet
     * starts today.
     */
    public static function since(): string
    {
        $v = \App\Models\Setting::get('extra_hours.since');
        if (! $v) {
            $first = ExtraHour::withoutGlobalScopes()->min('created_at');
            $v = ($first ? Carbon::parse($first) : now())->toDateString();
            \App\Models\Setting::put('extra_hours.since', $v);
        }

        return $v;
    }

    /** "1h 05m" */
    public static function hm(int $s): string
    {
        return intdiv($s, 3600) . 'h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
    }
}
