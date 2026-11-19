<?php

namespace App\Demo;

use Illuminate\Database\Eloquent\Model;

/** 10-Nov-2026: a company created from smartept.com → Explore Demo. */
class DemoAccount extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['employee_ids' => 'array'];

    /**
     * Is this company a demo company? Never throws before the table exists.
     * 11-Nov-2026: no per-process cache — it outlived the database it described (in the test run every fresh database's
     * company #1 stayed "demo" after DemoAccountTest, so ordinary phones were auto-approved and geofences skipped).
     * ponytail: one exists() on the small demo_accounts table per save; cache per request only if it ever shows up in a profile.
     */
    public static function isDemo(?int $companyId): bool
    {
        if (! $companyId) {
            return false;
        }
        try {
            return static::query()->where('company_id', $companyId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
