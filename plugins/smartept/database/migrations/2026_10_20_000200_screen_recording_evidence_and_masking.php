<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20-Oct-2026 (Ejaz) — Screen Recording: evidence pack (P2) + hiding customer data (P3, DPDP Act).
 *
 *  screen_recordings  + sha256 / seal / sealed_at        tamper-proof fingerprint of every minute
 *                     + mask_status / mask_boxes / masked_key / masked_at   where sensitive numbers were, and the blurred copy
 *  companies          + mask_sensitive / mask_types       ONE company setting: hide card / Aadhaar / PAN / account numbers
 *                                                         in LiveView, Live Wall and recordings
 *  screen_recording_evidence                              one row per evidence pack
 *  permissions        recording.evidence (granted to every role that can manage recordings today)
 *                     recording.unmask   (Company Admin only — Super Admin has every permission anyway)
 * Additive only: nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screen_recordings', function (Blueprint $t) {
            if (! Schema::hasColumn('screen_recordings', 'sha256')) {
                $t->char('sha256', 64)->nullable();
                $t->char('seal', 64)->nullable();          // HMAC of the minute's identity + sha256 with this server's key
                $t->timestamp('sealed_at')->nullable();    // = upload time; later for minutes recorded before 20-Oct-2026
                $t->string('mask_status', 12)->nullable(); // null = not checked · clean · masked · failed
                $t->json('mask_boxes')->nullable();        // {w,h,boxes:[{s,e,x,y,w,h,type}]} — never the numbers themselves
                $t->string('masked_key', 512)->nullable(); // the blurred copy (same disk as the original)
                $t->timestamp('masked_at')->nullable();
                $t->index(['company_id', 'mask_status']);
            }
        });

        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'mask_sensitive')) {
                $t->boolean('mask_sensitive')->default(false);
                $t->json('mask_types')->nullable(); // null = all four
            }
        });

        if (! Schema::hasTable('screen_recording_evidence')) {
            Schema::create('screen_recording_evidence', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->foreignId('employee_id')->index();
                $t->string('code', 32)->unique();               // EV-YYYYMMDD-XXXXXX, printed on the video, PDF and files
                $t->timestamp('from_at');
                $t->timestamp('to_at');
                $t->unsignedTinyInteger('monitor_index')->default(0);
                $t->boolean('masked')->default(false);         // customer data hidden in this pack
                $t->string('reason', 255)->nullable();
                $t->string('status', 12)->default('queued');   // queued · running · done · failed
                $t->string('error', 500)->nullable();
                $t->unsignedInteger('minutes')->default(0);
                $t->string('zip_path', 255)->nullable();       // storage/app/…
                $t->char('zip_sha256', 64)->nullable();
                $t->unsignedBigInteger('zip_bytes')->default(0);
                $t->json('manifest')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }

        $evidence = Permission::updateOrCreate(['slug' => 'recording.evidence'], ['name' => 'Screen Recording — create and download evidence packs', 'group' => 'Screen Recording']);
        Permission::updateOrCreate(['slug' => 'recording.unmask'], ['name' => 'Screen Recording — show hidden customer data (every use is logged)', 'group' => 'Screen Recording']);
        $unmask = Permission::where('slug', 'recording.unmask')->first();
        foreach (Role::with('permissions:id,slug')->get() as $role) {
            if ($role->permissions->contains('slug', 'liveview.start')) $role->permissions()->syncWithoutDetaching([$evidence->id]);
            if ($role->slug === 'COMPANY_ADMIN') $role->permissions()->syncWithoutDetaching([$unmask->id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_recording_evidence');
        foreach ([['screen_recordings', ['sha256', 'seal', 'sealed_at', 'mask_status', 'mask_boxes', 'masked_key', 'masked_at']], ['companies', ['mask_sensitive', 'mask_types']]] as [$table, $cols]) {
            if (Schema::hasColumn($table, $cols[0])) {
                Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                    if ($table === 'screen_recordings') $t->dropIndex(['company_id', 'mask_status']);
                    $t->dropColumn($cols);
                });
            }
        }
        $ids = Permission::whereIn('slug', ['recording.evidence', 'recording.unmask'])->pluck('id');
        foreach (Role::all() as $role) $role->permissions()->detach($ids);
        Permission::whereIn('id', $ids)->delete();
    }
};
