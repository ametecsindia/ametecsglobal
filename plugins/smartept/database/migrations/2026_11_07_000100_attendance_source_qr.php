<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 07-Nov-2026 (Ejaz): Organisation → Attendance source gains "QR Attendance" and becomes the ONE switch for the QR module.
 * Companies that already turned the module on move to attendance_mode = QR, so nothing changes for them on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qx_settings')) {
            return;
        }
        DB::table('companies')
            ->whereIn('id', DB::table('qx_settings')->where('enabled', true)->pluck('tenant_id'))
            ->update(['attendance_mode' => 'QR']);
    }

    public function down(): void
    {
        DB::table('companies')->where('attendance_mode', 'QR')->update(['attendance_mode' => 'BIOMETRIC']);
    }
};
