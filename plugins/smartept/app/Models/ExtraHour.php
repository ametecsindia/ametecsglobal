<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** 10-Oct-2026 (Ejaz): one employee-day of work outside shift hours (see App\Services\ExtraHours). */
class ExtraHour extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'work_date'      => 'date',
        'off_day'        => 'boolean',
        'last_active_at' => 'datetime',
        'decided_at'     => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }

    public function totalSeconds(): int
    {
        return (int) $this->before_seconds + (int) $this->after_seconds;
    }
}
