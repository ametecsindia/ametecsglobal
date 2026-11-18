<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMETECSGLOBAL LICENSING (owner, 18 Nov 2026 - Phase 1). One licence per client per app, for EVERY app on
 * AmetecsGlobal (not only SmartPRS3), on-premise or cloud. Replaces core/data/access.json, which core/licensing.php
 * imports once. Nothing existing is altered or dropped.
 *
 * - `ag_licences`: client x app: deployment (cloud | onprem), plan (trial | monthly | yearly | perpetual),
 *   status (active | suspended | cancelled), seats, validity, price; for on-premise also the licence key
 *   (sha256 for lookup + encrypted copy) and the server it is bound to.
 * - `ag_licence_events`: every change, activation and check-in. Only ever appended.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ag_licences')) {
            Schema::create('ag_licences', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('app', 40);
                $t->string('deployment', 10)->default('cloud');
                $t->string('plan', 12)->default('yearly');
                $t->string('status', 12)->default('active');
                $t->unsignedInteger('seats')->default(0);
                $t->date('starts_on')->nullable();
                $t->date('valid_until')->nullable();
                $t->decimal('price', 12, 2)->nullable();
                $t->char('currency', 3)->default('INR');
                $t->boolean('auto_renew')->default(false);
                $t->string('key_hash', 64)->nullable();
                $t->text('key_enc')->nullable();
                $t->string('fingerprint', 191)->nullable();
                $t->string('server_name', 191)->nullable();
                $t->string('server_os', 100)->nullable();
                $t->string('app_version', 40)->nullable();
                $t->dateTime('activated_at')->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->text('notes')->nullable();
                $t->string('updated_by', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['tenant_id', 'app'], 'ag_licences_tenant_app_uq');
                $t->unique(['key_hash'], 'ag_licences_key_uq');
                $t->index(['app', 'deployment', 'status'], 'ag_licences_app_dep_status_ix');
            });
        }

        if (!Schema::hasTable('ag_licence_events')) {
            Schema::create('ag_licence_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('licence_id')->index();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('app', 40);
                $t->string('action', 40);
                $t->text('detail')->nullable();
                $t->string('by_who', 191)->nullable();
                $t->dateTime('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: licences are records.
    }
};
