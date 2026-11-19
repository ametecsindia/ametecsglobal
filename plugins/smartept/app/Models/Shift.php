<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Shift extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = [
        'working_days'            => 'array',
        'crosses_midnight'        => 'boolean',
        'restrict_login_to_shift' => 'boolean',
        'extra_needs_approval'    => 'boolean', // 10-Oct-2026: Extra Hours
        'day_times'               => 'array',   // 13-Oct-2026: per-weekday timings
    ];

    public function employees() { return $this->hasMany(Employee::class); }

    /**
     * 13-Oct-2026 (Ejaz: "every Sat the office is 9:30 AM to 4:00 PM instead of 6:00 PM"): this shift
     * as it runs on $date — that weekday's own start/end when set (day_times), else the regular
     * times. Every rule that measures against the shift (late, sign-in window, auto sign-out,
     * "Still working?", extra hours, early logout, productivity) asks for the dated shift.
     */
    public function forDate($date): self
    {
        $t = $this->day_times[strtoupper(Carbon::parse($date)->format('D'))] ?? null;
        if (! is_array($t) || empty($t['start']) || empty($t['end'])) {
            return $this;
        }
        $s = clone $this;
        $s->start_time = $t['start'];
        $s->end_time = $t['end'];

        return $s;
    }

    /**
     * 09-Oct-2026 (Ejaz: one place for every setting) — break limits live on the SHIFT, per
     * type. The agent's over-limit reason, the server's break-exceed check and the report's
     * Allotted break all read these; the Organisation break-limit card is gone.
     * "Other" is stored as break_type CUSTOM. Unset = 30 / 10 / 10 (the old company default).
     */
    public function breakLimitMinutes(?string $type): ?int
    {
        return match (strtoupper((string) $type)) {
            'LUNCH'           => (int) ($this->break_lunch_min ?? 30),
            'TEA'             => (int) ($this->break_tea_min ?? 10),
            'OTHER', 'CUSTOM' => (int) ($this->break_other_min ?? 10),
            default           => null, // BIO / TRAINING / PRAYER / MEETING have no limit
        };
    }

    /** Allotted break for the day = Lunch + Tea + Other — no separate total to disagree with. */
    public function getBreakMinutesAllowedAttribute($value): int
    {
        return $this->breakLimitMinutes('LUNCH') + $this->breakLimitMinutes('TEA') + $this->breakLimitMinutes('OTHER');
    }

    /**
     * May an agent sign-in be accepted at this wall-clock instant? (Ejaz, 26-Aug-2026 —
     * the AGENT app only; the admin console never reaches this code.)
     *
     * The window is [start_time, end_time + post_shift_auto_logout_minutes]. Reusing the auto
     * sign-out grace as the tail is deliberate: letting someone sign in at an instant the
     * server would sign them straight back out is not a window, so the two settings describe
     * one boundary instead of two that can disagree.
     *
     * Yesterday is tested as well as today, so a night shift is judged on the window it is
     * actually inside — at 02:00 a 22:00–06:00 employee belongs to YESTERDAY's window. For a
     * day shift the extra candidate simply never matches.
     *
     * A shift with no times has no window to enforce and therefore allows: nobody may be
     * locked out of their own PC because a shift record is half-filled.
     *
     * @param  Carbon  $when  local wall clock, the same frame the agent stores its times in
     */
    public function coversSignInAt(Carbon $when): bool
    {
        if (! $this->start_time || ! $this->end_time) {
            return true;
        }

        foreach ([0, -1] as $dayOffset) {
            $day = $when->copy()->startOfDay()->addDays($dayOffset)->toDateString();
            $s = $this->forDate($day); // 13-Oct-2026: that day's own timing
            $start = Carbon::parse($day . ' ' . $s->start_time);
            $end = Carbon::parse($day . ' ' . $s->end_time);

            if ($this->crosses_midnight || $end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
            $end->addMinutes((int) ($this->post_shift_auto_logout_minutes ?? 0));

            if ($when->greaterThanOrEqualTo($start) && $when->lessThanOrEqualTo($end)) {
                return true;
            }
        }

        return false;
    }

    /** "9:00 AM–6:00 PM" — for the refusal the employee reads on the agent's sign-in screen (12-hour, 20-Oct-2026). */
    public function windowLabel($date = null): string
    {
        $s = $date ? $this->forDate($date) : $this;

        return Carbon::parse($s->start_time)->format('g:i A') . '–' . Carbon::parse($s->end_time)->format('g:i A');
    }
}
