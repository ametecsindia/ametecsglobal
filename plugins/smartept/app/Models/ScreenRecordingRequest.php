<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** 15-Oct-2026: an admin's "Record now for N minutes" window for one employee. */
class ScreenRecordingRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['until_at' => 'datetime'];
}
