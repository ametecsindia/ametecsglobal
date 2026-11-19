<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * Working-day awareness: a day counts as a working day for an employee only if
 * it is not a company holiday AND it is one of their shift's working days.
 * Everything that judges attendance (late minutes, auto-absent marking, daily
 * summaries, the payroll register) asks this service instead of assuming
 * every calendar day is workable.
 */
class WorkCalendar
{
    /** No shift assigned (or shift without working_days) → Indian-office default of MON–SAT. */
    public const DEFAULT_WORKING_DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

    /** Per-request cache: company_id => [Y-m-d => true]. Holiday tables are tiny; load once. */
    private array $holidays = [];

    /** Per-request cache: company_id => W.OFF schedule|null. */
    private array $weeklyOff = [];

    public const DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    /** W.OFF schedule never saved → the default the W.OFF screen shows: every Sunday off. */
    public const DEFAULT_WEEKLY_OFF = ['SUN' => [1, 2, 3, 4, 5]];

    public function isWorkingDay(Employee $employee, string $date): bool
    {
        $date = Carbon::parse($date)->toDateString();

        return ! $this->isHoliday($employee->company_id, $date)
            && $this->isShiftDay($employee, $date);
    }

    /**
     * Is this a working weekday for the employee (ignores holidays)?
     * 13-Oct-2026 (Ejaz): the company's W.OFF schedule decides once it has been saved
     * (Organisation → W.OFF & Holiday Schedule) — e.g. 2nd & 4th Saturday + every Sunday.
     * 31-Oct-2026 (Ejaz: Saturday late minutes all 0): never saved → the default the W.OFF screen
     * shows (every Sunday off). It used to fall back to the shift's hidden working_days — General
     * Shift still carried MON–FRI from before day-wise timings, so every Saturday was a silent weekly
     * off: no late, no absent, the whole day counted as Extra Hours. Weekly offs live in ONE place.
     */
    public function isShiftDay(Employee $employee, string $date): bool
    {
        return ! $this->isScheduledOff($this->weeklyOff((int) $employee->company_id) ?? self::DEFAULT_WEEKLY_OFF, $date);
    }

    /** A company-wide day off: a holiday, or a W.OFF (saved schedule, else the every-Sunday default). */
    public function isCompanyOff(int $companyId, string $date): bool
    {
        $date = Carbon::parse($date)->toDateString();

        return $this->isHoliday($companyId, $date)
            || $this->isScheduledOff($this->weeklyOff($companyId) ?? self::DEFAULT_WEEKLY_OFF, $date);
    }

    /** The saved W.OFF schedule ['SAT' => [2, 4], ...] (week-of-month 1–5), or null = never saved. */
    public function weeklyOff(int $companyId): ?array
    {
        if (! array_key_exists($companyId, $this->weeklyOff)) {
            $raw = Company::find($companyId)?->weekly_off; // safe before migrate: a missing column reads null
            $this->weeklyOff[$companyId] = is_array($raw) ? self::normalise($raw) : null;
        }

        return $this->weeklyOff[$companyId];
    }

    /** Keep only real weekdays and week numbers 1–5. */
    public static function normalise(array $raw): array
    {
        $out = [];
        foreach (self::DAYS as $d) {
            $weeks = array_values(array_unique(array_filter(array_map('intval', (array) ($raw[$d] ?? [])), fn ($w) => $w >= 1 && $w <= 5)));
            sort($weeks);
            if ($weeks) {
                $out[$d] = $weeks;
            }
        }

        return $out;
    }

    /** Week of the month for a date: days 1–7 = 1st, 8–14 = 2nd, … 29–31 = 5th. */
    private function isScheduledOff(array $wo, string $date): bool
    {
        $c = Carbon::parse($date);

        return in_array((int) ceil($c->day / 7), $wo[strtoupper($c->format('D'))] ?? [], true);
    }

    public function isHoliday(int $companyId, string $date): bool
    {
        if (! array_key_exists($companyId, $this->holidays)) {
            $this->holidays[$companyId] = Holiday::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->pluck('holiday_date')
                ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
                ->all();
        }

        return isset($this->holidays[$companyId][$date]);
    }

    /** The employee's effective weekly roster (uppercase 3-letter day codes). */
    public function workingDays(Employee $employee): array
    {
        $days = $employee->shift?->working_days;

        return (is_array($days) && $days !== [])
            ? array_map('strtoupper', $days)
            : self::DEFAULT_WORKING_DAYS;
    }
}
