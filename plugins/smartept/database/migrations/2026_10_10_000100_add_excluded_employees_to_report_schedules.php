<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10-Oct-2026 (Ejaz): Schedule Report → "In full report" tick per employee. The ids unticked here
 * are left out of the full report and the reporting-team reports (their own report still goes).
 * NULL / empty = everyone included, so existing schedules are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (! Schema::hasColumn('report_schedules', 'excluded_employee_ids')) {
                $t->json('excluded_employee_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (Schema::hasColumn('report_schedules', 'excluded_employee_ids')) {
                $t->dropColumn('excluded_employee_ids');
            }
        });
    }
};
