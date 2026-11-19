<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 13-Oct-2026 (Ejaz): the company's W.OFF schedule (Organisation → W.OFF & Holiday Schedule).
 * JSON {"SAT":[2,4],"SUN":[1,2,3,4,5]} — weekday → which occurrences in the month are off
 * (1–5; all five = every week). NULL = never saved → the old rule (the shift's working days,
 * else Monday–Saturday) still applies, so nothing changes on upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'weekly_off')) {
            Schema::table('companies', fn (Blueprint $t) => $t->text('weekly_off')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'weekly_off')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('weekly_off'));
        }
    }
};
