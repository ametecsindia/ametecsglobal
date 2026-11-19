<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** 15-Oct-2026: an employee granted Screen Recording — the licensed resource (features.recording_max_users). */
class ScreenRecordingSeat extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
}
