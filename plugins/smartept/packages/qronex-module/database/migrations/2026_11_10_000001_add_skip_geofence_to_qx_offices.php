<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10-Nov-2026 (Ejaz): "Skip location check" on an office — for a demo / testing office (e.g. the Google Play
 * reviewer's demo company). QR scans there are accepted from anywhere; every other check still runs. Off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qx_offices', function (Blueprint $t) {
            $t->boolean('skip_geofence')->default(false)->after('geofence_radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('qx_offices', function (Blueprint $t) {
            $t->dropColumn('skip_geofence');
        });
    }
};
