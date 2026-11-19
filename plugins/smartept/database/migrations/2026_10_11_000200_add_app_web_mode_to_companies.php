<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 11-Oct-2026 — App & Web Rules: ONE of the two sections applies, chosen by a radio button.
 * ALLOW_ONLY = section 1 (Allowed ONLY) applies and section 2 is switched off;
 * BLOCK_SPECIFIC (or null) = section 2 (Track / Block Specific) applies, exactly as before.
 * VARCHAR, not ENUM — the application owns the value set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'app_web_mode')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('app_web_mode', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'app_web_mode')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('app_web_mode');
            });
        }
    }
};
