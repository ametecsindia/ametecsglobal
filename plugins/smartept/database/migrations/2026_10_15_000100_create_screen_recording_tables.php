<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15-Oct-2026 (Ejaz): Screen Recording — Commander only, licensed per employee like LiveView.
 *
 *  screen_recording_settings   one row per company: modes, quality, pauses, upload, retention, cleanup schedule
 *  screen_recording_seats      which employees may be recorded (capped by bundle features.recording_max_users)
 *  screen_recording_requests   "Record now" windows started by an admin
 *  screen_recordings           one row per uploaded video segment (the file lives on the evidence disk)
 *
 * New tables only — nothing existing changes. Recordings are deliberately NOT storage_files rows,
 * so the screenshot storage quota (which trims oldest-first) never deletes screenshots to make
 * room for video; recordings have their own retention + cleanup schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('screen_recording_settings')) {
            Schema::create('screen_recording_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->unique();
                $t->json('modes')->nullable();                 // continuous | app_based | dashcam | on_demand
                $t->string('quality', 16)->default('standard'); // economy | standard | high
                $t->json('watched_apps')->nullable();          // app/process/site keywords for app_based
                $t->json('excluded_apps')->nullable();         // never recorded while in front
                $t->unsignedSmallInteger('pre_seconds')->default(120);
                $t->unsignedSmallInteger('post_seconds')->default(120);
                $t->boolean('pause_on_idle')->default(true);
                $t->boolean('pause_on_break')->default(true);
                $t->string('monitors', 10)->default('primary'); // primary | all
                $t->boolean('show_indicator')->default(true);
                $t->unsignedInteger('upload_kbps')->default(0);  // 0 = no limit
                $t->unsignedInteger('local_cap_mb')->default(2048);
                $t->unsignedSmallInteger('retention_days')->default(30);
                $t->string('cleanup_frequency', 16)->default('daily'); // daily | every_n_days | weekly | monthly
                $t->string('cleanup_time', 5)->default('02:00');
                $t->unsignedSmallInteger('cleanup_every_days')->default(1);
                $t->unsignedTinyInteger('cleanup_weekday')->default(0);   // 0 = Sunday
                $t->unsignedTinyInteger('cleanup_month_day')->default(1);
                $t->timestamp('last_cleanup_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('screen_recording_seats')) {
            Schema::create('screen_recording_seats', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->foreignId('employee_id')->unique();
                $t->unsignedBigInteger('granted_by_user_id')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('screen_recording_requests')) {
            Schema::create('screen_recording_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->foreignId('employee_id')->index();
                $t->unsignedBigInteger('requested_by_user_id')->nullable();
                $t->timestamp('until_at');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('screen_recordings')) {
            Schema::create('screen_recordings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id');
                $t->foreignId('employee_id');
                $t->string('device_uuid', 64)->nullable();
                $t->unsignedTinyInteger('monitor_index')->default(0);
                $t->timestamp('started_at');
                $t->timestamp('ended_at')->nullable();
                $t->unsignedInteger('duration_seconds')->default(0);
                $t->string('trigger', 16)->default('continuous'); // continuous | app_based | dashcam | on_demand
                $t->string('trigger_detail', 255)->nullable();
                $t->string('storage_driver', 32);
                $t->string('storage_key', 512);
                $t->string('mime_type', 64)->default('video/webm');
                $t->unsignedBigInteger('size_bytes')->default(0);
                $t->timestamps();
                $t->index(['company_id', 'employee_id', 'started_at']);
                $t->index(['company_id', 'started_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_recordings');
        Schema::dropIfExists('screen_recording_requests');
        Schema::dropIfExists('screen_recording_seats');
        Schema::dropIfExists('screen_recording_settings');
    }
};
