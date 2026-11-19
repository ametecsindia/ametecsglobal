<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20-Oct-2026 (Ejaz: "1-minute videos create a lot of files"). Screen Recording → Download Criteria:
 * how long each downloaded video file is — 1, 5, 10, 15, 20, 30, 45 or 60 minutes, or 0 = the whole
 * range in one file. Default 1 = the files exactly as recorded (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('screen_recording_settings') && ! Schema::hasColumn('screen_recording_settings', 'download_split_minutes')) {
            Schema::table('screen_recording_settings', function (Blueprint $t) {
                $t->unsignedSmallInteger('download_split_minutes')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('screen_recording_settings', 'download_split_minutes')) {
            Schema::table('screen_recording_settings', function (Blueprint $t) {
                $t->dropColumn('download_split_minutes');
            });
        }
    }
};
