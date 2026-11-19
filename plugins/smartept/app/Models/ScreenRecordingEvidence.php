<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** 20-Oct-2026: one Screen Recording evidence pack (watermarked video + fingerprints + chain of custody). */
class ScreenRecordingEvidence extends Model
{
    use BelongsToCompany;

    protected $table = 'screen_recording_evidence';

    protected $guarded = ['id'];

    protected $casts = [
        'from_at' => 'datetime', 'to_at' => 'datetime', 'finished_at' => 'datetime',
        'masked' => 'boolean', 'manifest' => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
