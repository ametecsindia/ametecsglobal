<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeAttendanceLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'work_date'         => 'date',
        'check_in_at'       => 'datetime',
        'check_out_at'      => 'datetime',
        // 11-Aug-2026: these three were added 23-Jul (QA A2) but never cast —
        // AttendanceDerivation got raw strings and crashed with
        // "Call to a member function lessThan() on string" for every agent-edge day.
        'first_login_at'    => 'datetime',
        'last_login_at'     => 'datetime',
        'final_logout_at'   => 'datetime',
        'first_activity_at' => 'datetime',
        'last_activity_at'  => 'datetime',
        'metadata'          => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }

    /**
     * 07-Nov-2026 (Ejaz): QR Attendance replaces the door reader. A QR punch is STORED like a door punch (BIOMETRIC),
     * so every gate / late / timeline rule treats it the same; this is only how it is SHOWN.
     * Returns, per "employee_id|Y-m-d", ['in' => first punch of the day is QR, 'out' => last punch of the day is QR]
     * (QR punch = biometric_logs external_id "qx:<ref>").
     * 12-Nov-2026 (Ejaz): with "QR + Biometric" both kinds count, so the day's Source is whichever was used for the
     * FIRST punch IN; an employee with no QR punch (no registered phone / didn't scan) simply shows the biometric.
     * On "QR Attendance" only QR punches count, so any QR punch that day marks the day QR (as before).
     * ponytail: matched on the punch's calendar date — a night-shift OUT after midnight shows on its own date.
     */
    public static function qrDays(iterable $rows): array
    {
        $rows = collect($rows)->filter(fn ($r) => $r->work_date);
        if ($rows->isEmpty()) {
            return [];
        }

        $punches = BiometricLog::withoutGlobalScopes()
            ->whereIn('employee_id', $rows->pluck('employee_id')->unique()->values())
            ->whereBetween('punched_at', [$rows->min('work_date')->copy()->startOfDay(), $rows->max('work_date')->copy()->endOfDay()])
            ->orderBy('punched_at')
            ->get(['company_id', 'employee_id', 'punched_at', 'external_id']);
        $qrOnly = Company::withoutGlobalScopes()->whereIn('id', $punches->pluck('company_id')->unique()->values())
            ->where('attendance_mode', 'QR')->pluck('id')->flip();

        $out = [];
        foreach ($punches->groupBy(fn ($b) => $b->employee_id.'|'.$b->punched_at->toDateString()) as $key => $day) {
            if (isset($qrOnly[$day->first()->company_id])) {
                $day = $day->filter(fn ($b) => BiometricLog::isQrPunch($b->external_id))->values();
            }
            if ($day->isNotEmpty() && $day->contains(fn ($b) => BiometricLog::isQrPunch($b->external_id))) {
                $out[$key] = ['in' => BiometricLog::isQrPunch($day->first()->external_id), 'out' => BiometricLog::isQrPunch($day->last()->external_id)];
            }
        }

        return $out;
    }

    /** "QR Attendance" for a door-punch value on a day that has QR punches; any other source as stored. */
    public static function sourceLabel(?string $source, bool $qrDay): ?string
    {
        return $qrDay && $source === 'BIOMETRIC' ? 'QR Attendance' : $source;
    }
}
