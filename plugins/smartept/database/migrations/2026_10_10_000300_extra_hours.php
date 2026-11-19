<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10-Oct-2026 (Ejaz): Extra Hours — work outside shift hours, for appreciation and record.
 *
 *  shifts.extra_min_minutes          minimum extra active time in a day to record it (blank = 30)
 *  shifts.still_working_every_min    after shift end, an employee who is still ACTIVE is asked
 *                                    "Still working?" every N minutes instead of being signed out
 *                                    (blank = 30); no answer within a minute → signed out
 *  shifts.extra_needs_approval       recorded days wait for a manager's Approve / Reject
 *  employees.work_outside_shift      may sign in outside the shift's permitted hours
 *  employee_devices.still_working_*  the open question and the confirmed-until instant
 *  extra_hours                       one row per employee per day with extra time
 *
 * Additive only: nothing existing changes until an admin ticks or fills a new field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            if (! Schema::hasColumn('shifts', 'extra_min_minutes')) {
                $t->unsignedSmallInteger('extra_min_minutes')->nullable();
            }
            if (! Schema::hasColumn('shifts', 'still_working_every_min')) {
                $t->unsignedSmallInteger('still_working_every_min')->nullable();
            }
            if (! Schema::hasColumn('shifts', 'extra_needs_approval')) {
                $t->boolean('extra_needs_approval')->default(false);
            }
        });
        Schema::table('employees', function (Blueprint $t) {
            if (! Schema::hasColumn('employees', 'work_outside_shift')) {
                $t->boolean('work_outside_shift')->default(false);
            }
        });
        Schema::table('employee_devices', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_devices', 'still_working_asked_at')) {
                $t->timestamp('still_working_asked_at')->nullable();
            }
            if (! Schema::hasColumn('employee_devices', 'still_working_until')) {
                $t->timestamp('still_working_until')->nullable();
            }
        });
        if (! Schema::hasTable('extra_hours')) {
            Schema::create('extra_hours', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->foreignId('employee_id')->index();
                $t->date('work_date');
                $t->unsignedInteger('before_seconds')->default(0);   // active before shift start
                $t->unsignedInteger('after_seconds')->default(0);    // active after shift end
                $t->boolean('off_day')->default(false);              // weekly off / holiday: the whole day is extra
                $t->timestamp('last_active_at')->nullable();
                $t->string('status', 20)->default('RECORDED');        // RECORDED | PENDING | APPROVED | REJECTED
                $t->foreignId('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('note', 500)->nullable();
                $t->timestamps();
                $t->unique(['employee_id', 'work_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_hours');
        foreach ([['shifts', ['extra_min_minutes', 'still_working_every_min', 'extra_needs_approval']],
                  ['employees', ['work_outside_shift']],
                  ['employee_devices', ['still_working_asked_at', 'still_working_until']]] as [$table, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
