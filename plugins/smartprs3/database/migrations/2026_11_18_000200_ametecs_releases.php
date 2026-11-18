<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMETECSGLOBAL UPDATE HUB (owner, 18 Nov 2026 - Phase 2). Releases, patches and plugins for EVERY app, published
 * from AmetecsGlobal Super Admin (core/releases.php). Nothing existing is altered or dropped.
 *
 * - `ag_releases`: app, version, kind (full | patch | plugin), notes, requirements, package SHA-256 + signature,
 *   status (draft | published | disabled | withdrawn), scheduled publish time, mandatory, download allowed,
 *   audience (all | selected), price.
 * - `ag_release_access`: which clients a "selected" release goes to, and free grants per client.
 * - `ag_release_events`: uploads, publishing, downloads, installs, failures, rollbacks. Only ever appended.
 *   tenant_id 0 = Ametecs' own cloud server.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ag_releases')) {
            Schema::create('ag_releases', function (Blueprint $t): void {
                $t->id();
                $t->string('app', 40);
                $t->string('version', 40);
                $t->string('kind', 10)->default('full');
                $t->string('title', 191)->nullable();
                $t->text('notes')->nullable();
                $t->string('requires_version', 40)->nullable();
                $t->string('min_php', 10)->nullable();
                $t->string('file_name', 191);
                $t->unsignedBigInteger('size')->default(0);
                $t->char('sha256', 64);
                $t->text('signature')->nullable();
                $t->string('status', 12)->default('draft');
                $t->dateTime('publish_at')->nullable();
                $t->boolean('mandatory')->default(false);
                $t->boolean('downloadable')->default(true);
                $t->string('audience', 10)->default('all');
                $t->decimal('price', 12, 2)->nullable();
                $t->char('currency', 3)->default('INR');
                $t->string('created_by', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['app', 'version', 'kind'], 'ag_releases_app_version_kind_uq');
                $t->index(['app', 'status'], 'ag_releases_app_status_ix');
            });
        }

        if (!Schema::hasTable('ag_release_access')) {
            Schema::create('ag_release_access', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('release_id');
                $t->unsignedBigInteger('tenant_id');
                $t->boolean('free')->default(false);
                $t->string('created_by', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->unique(['release_id', 'tenant_id'], 'ag_release_access_uq');
            });
        }

        if (!Schema::hasTable('ag_release_events')) {
            Schema::create('ag_release_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('release_id')->nullable()->index();
                $t->string('app', 40);
                $t->unsignedBigInteger('tenant_id')->default(0)->index();
                $t->unsignedBigInteger('licence_id')->nullable();
                $t->string('action', 30);
                $t->string('from_version', 40)->nullable();
                $t->string('to_version', 40)->nullable();
                $t->text('detail')->nullable();
                $t->string('by_who', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['app', 'action'], 'ag_release_events_app_action_ix');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: release history is a record.
    }
};
