<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 14-Oct-2026 (Ejaz: "what does DEGRADED mean — make it visible"). DEGRADED = the PC clock is more
 * than 3 minutes off server time (EPT-25 tamper check). These keep HOW FAR (seconds, + = PC ahead)
 * and WHEN (since / until; until null = still off), so the Devices and Live Dashboard screens can say
 * "PC clock 7m ahead · since 10:02" — and still show a fixed one for a week afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->integer('clock_skew_seconds')->nullable()->after('agent_health');
            $table->timestamp('clock_skew_since')->nullable()->after('clock_skew_seconds');
            $table->timestamp('clock_skew_until')->nullable()->after('clock_skew_since');
        });
    }

    public function down(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->dropColumn(['clock_skew_seconds', 'clock_skew_since', 'clock_skew_until']);
        });
    }
};
