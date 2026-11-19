<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeDevice extends Model
{
    /**
     * Revoke THIS device's agent token, so the running agent gets a 401 on its next
     * heartbeat and returns itself to the login screen (main.js handleSessionRevoked()).
     *
     * 26-Aug-2026 — every caller used to walk `employee?->user?->tokens()`. The token is
     * created on `$request->user()` at register time, which is not guaranteed to be the same
     * account as `employees.user_id`, and is null outright when the employee has no linked
     * user. Both cases revoked NOTHING and said nothing: the server closed the session and
     * marked the device FORCE_LOGOUT while the agent, still holding a live token, carried on
     * tracking and showing "Signed in". The token's NAME already carries the unique
     * device_uuid, so match on that and the account holding it stops mattering.
     *
     * Returns how many tokens were deleted — 0 is a real signal (nothing was revoked).
     */
    public function revokeAgentToken(): int
    {
        return \Laravel\Sanctum\PersonalAccessToken::where('name', 'device:' . $this->device_uuid)->delete();
    }

    /**
     * End this device's agent session now — the ONE way the server signs an agent out (admin
     * Force logout, SmartDCM logout, door Away). Revokes the token, so the agent gets 401 on its
     * next heartbeat and returns to the login screen; the licence seat stays bound.
     */
    public function endSession(): void
    {
        $this->revokeAgentToken();
        $this->update(['session_status' => 'FORCE_LOGOUT', 'force_logout_at' => now(), 'current_status' => 'OFFLINE']);
    }

    use BelongsToCompany;

    /** 10-Nov-2026 (Ejaz): an agent session ending (ACTIVE → LOGGED_OUT / FORCE_LOGOUT, by any path) = punch OUT on QR Attendance. */
    protected static function booted(): void
    {
        static::updated(function (self $d) {
            if ($d->wasChanged('session_status') && $d->getOriginal('session_status') === 'ACTIVE'
                && in_array($d->session_status, ['LOGGED_OUT', 'FORCE_LOGOUT'], true)) {
                \App\Services\GateService::punchOutOnSignOut($d);
            }
        });
    }

    protected $guarded = ['id'];

    protected $hidden = ['device_token_hash'];

    protected $casts = [
        'camera_available'     => 'boolean',
        'microphone_available' => 'boolean',
        'last_heartbeat_at'    => 'datetime',
        'last_sync_at'         => 'datetime',
        'registered_at'        => 'datetime',
        'clock_skew_since'     => 'datetime',
        'clock_skew_until'     => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
}
