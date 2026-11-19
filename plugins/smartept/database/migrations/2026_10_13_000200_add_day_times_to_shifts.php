<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 13-Oct-2026 (Ejaz): a shift's own timing per weekday, e.g. Saturday 09:30–16:00 while the
 * other days are 09:30–18:00. JSON {"SAT":{"start":"09:30:00","end":"16:00:00"}} — only the days
 * that differ from the shift's regular start_time / end_time. NULL = every day uses the regular times.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'day_times')) {
            Schema::table('shifts', fn (Blueprint $t) => $t->text('day_times')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'day_times')) {
            Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('day_times'));
        }
    }
};
