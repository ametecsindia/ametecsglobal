<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 29-Oct-2026 (Altaf): SmartDCM Sign on — Organisation → "SmartDCM Sign on" card.
 * smartdcm_secret is the per-company shared secret, stored encrypted (Company 'encrypted' cast)
 * and hidden from every API response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'smartdcm_sso_enabled')) $t->boolean('smartdcm_sso_enabled')->default(false);
            if (! Schema::hasColumn('companies', 'smartdcm_url')) $t->string('smartdcm_url', 500)->nullable();
            if (! Schema::hasColumn('companies', 'smartdcm_secret')) $t->text('smartdcm_secret')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['smartdcm_sso_enabled', 'smartdcm_url', 'smartdcm_secret'] as $col) {
            if (Schema::hasColumn('companies', $col)) {
                Schema::table('companies', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
