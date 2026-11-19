<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** 15-Oct-2026: one uploaded screen-recording segment (Screen Recording, Commander). */
class ScreenRecording extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
        'sealed_at'  => 'datetime', // 20-Oct-2026: fingerprint + masking
        'masked_at'  => 'datetime',
        'mask_boxes' => 'array',
    ];

    /**
     * 20-Oct-2026: tamper-proof fingerprint. sha256 = the file; seal = HMAC (this server's APP_KEY) over
     * who/when/which screen + that sha256, so neither the file nor its row can be swapped unnoticed.
     */
    public function sealFor(string $sha256): string
    {
        return hash_hmac('sha256', implode('|', [$this->company_id, $this->employee_id, $this->device_uuid, (int) $this->monitor_index,
            $this->started_at?->format('Y-m-d H:i:s'), $this->ended_at?->format('Y-m-d H:i:s'), $sha256]), (string) config('app.key'));
    }

    public function fingerprint(string $sha256): void
    {
        $this->forceFill(['sha256' => $sha256, 'seal' => $this->sealFor($sha256), 'sealed_at' => now()])->save();
    }

    /** null = fine, else why this minute fails the check. */
    public function verify(?string $bytes): ?string
    {
        if ($bytes === null) return 'file missing';
        if (! $this->sha256) return 'no fingerprint';
        if (! hash_equals($this->sha256, hash('sha256', $bytes))) return 'file changed after it was recorded';
        if (! $this->seal || ! hash_equals($this->seal, $this->sealFor($this->sha256))) return 'record changed after it was recorded';

        return null;
    }

    public function employee() { return $this->belongsTo(Employee::class); }
}
