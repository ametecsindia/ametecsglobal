<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 11-Oct-2026 — App & Web Rules → "Allowed ONLY". Two company-level lists (JSON
 * arrays of what the admin typed). Empty/null = off, everything works as before.
 * When set, the enforcement service lets only Windows, the SmartEPT Agent and
 * these apps start, and browsers open only these sites. See
 * EnforcerSyncController::allowOnlyFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'allow_only_apps')) {
                $t->text('allow_only_apps')->nullable();
            }
            if (! Schema::hasColumn('companies', 'allow_only_sites')) {
                $t->text('allow_only_sites')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }
        Schema::table('companies', function (Blueprint $t) {
            foreach (['allow_only_apps', 'allow_only_sites'] as $c) {
                if (Schema::hasColumn('companies', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
