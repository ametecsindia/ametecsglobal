<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * 15-Oct-2026: the company's Screen Recording settings — the ONE place they live.
 * A company without a row gets the defaults below (recommended in the plan).
 */
class ScreenRecordingSetting extends Model
{
    use BelongsToCompany;

    public const MODES = ['continuous', 'app_based', 'dashcam', 'on_demand'];
    public const QUALITIES = ['economy', 'standard', 'high'];
    public const FREQUENCIES = ['daily', 'every_n_days', 'weekly', 'monthly'];
    /** 20-Oct-2026: Download Criteria — minutes per downloaded file; 0 = the whole range in one file. */
    public const DOWNLOAD_SPLITS = [1, 5, 10, 15, 20, 30, 45, 60, 0];

    protected $guarded = ['id'];

    protected $casts = [
        'modes'           => 'array',
        'watched_apps'    => 'array',
        'excluded_apps'   => 'array',
        'pause_on_idle'   => 'boolean',
        'pause_on_break'  => 'boolean',
        'show_indicator'  => 'boolean',
        'last_cleanup_at' => 'datetime',
    ];

    protected $attributes = [
        'modes' => '["dashcam","app_based","on_demand"]',
        'watched_apps' => '[]',
        'excluded_apps' => '[]',
    ];

    public static function forCompany(int $companyId): self
    {
        return static::withoutGlobalScopes()->firstOrNew(['company_id' => $companyId]);
    }

    /**
     * Is the scheduled cleanup due at $local (the company's own clock)?
     * Due once the configured time has passed on a matching day, and not already run since.
     */
    public function cleanupDue(Carbon $local): bool
    {
        [$h, $m] = array_map('intval', explode(':', $this->cleanup_time ?: '02:00') + [1 => 0]);
        $at = $local->copy()->setTime($h, $m);
        if ($local->lt($at)) {
            return false;
        }
        $last = $this->last_cleanup_at?->copy()->setTimezone($local->getTimezone());
        if ($last && $last->gte($at)) {
            return false; // already ran for this slot
        }

        return match ($this->cleanup_frequency) {
            'every_n_days' => ! $last || $last->copy()->startOfDay()->lte($local->copy()->startOfDay()->subDays(max(1, (int) $this->cleanup_every_days))),
            'weekly'       => $local->dayOfWeek === (int) $this->cleanup_weekday,
            'monthly'      => $local->day === min(max(1, (int) $this->cleanup_month_day), $local->daysInMonth),
            default        => true, // daily
        };
    }
}
