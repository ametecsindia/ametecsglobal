<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10-Oct-2026 (Ejaz): Schedule Report can also send the Attendance report.
 * PRODUCTIVITY (default, every existing schedule) | ATTENDANCE —
 * Daily = yesterday's Day report Excel, Monthly = the previous month's Attendance report Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (! Schema::hasColumn('report_schedules', 'report_type')) {
                $t->string('report_type', 20)->default('PRODUCTIVITY');
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (Schema::hasColumn('report_schedules', 'report_type')) {
                $t->dropColumn('report_type');
            }
        });
    }
};
