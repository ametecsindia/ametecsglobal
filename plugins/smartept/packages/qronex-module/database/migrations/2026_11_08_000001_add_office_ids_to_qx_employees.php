<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 08-Nov-2026 (Ejaz): an employee can be given more than one office. office_ids = every office they may punch at;
 * office_id stays as their main office (shown in lists, used for reports). Empty = just office_id, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qx_employees', function (Blueprint $t) {
            $t->json('office_ids')->nullable()->after('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('qx_employees', function (Blueprint $t) {
            $t->dropColumn('office_ids');
        });
    }
};
