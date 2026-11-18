<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMETECSGLOBAL PLUGINS + APP DASHBOARD FIGURES (owner, 18 Nov 2026 - Phase 6). Nothing existing is dropped.
 *
 * - `ag_releases.plugin`: which add-on a plugin release belongs to (one code per plugin, many versions). Releases of
 *   different plugins may now share a version number, so the unique key becomes app + kind + plugin + version.
 * - `ag_plugin_access`: one row per client per plugin - granted free by Super Admin, switched on/off by the client,
 *   blocked by Super Admin, download allowed, the version downloaded and the version installed. A purchase is not
 *   stored here: it is the paid / refunded events of the plugin's releases (ag_release_events), as for upgrades.
 * - `ag_licences.stats_json / stats_at`: the dashboard figures an on-premise install last reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ag_releases') && !Schema::hasColumn('ag_releases', 'plugin')) {
            Schema::table('ag_releases', function (Blueprint $t): void {
                $t->string('plugin', 60)->default('')->after('kind');
            });
            // Plain SQL (tools/db/migrate.php has no DB facade, and the stub's table() adds columns only).
            Schema::$statements[] = "UPDATE `ag_releases` SET `plugin` = LOWER(LEFT(TRIM(BOTH '-' FROM REGEXP_REPLACE(COALESCE(NULLIF(`title`, ''), 'module'), '[^A-Za-z0-9]+', '-')), 60)) WHERE `kind` = 'plugin' AND `plugin` = ''";
            Schema::$statements[] = 'ALTER TABLE `ag_releases` DROP INDEX `ag_releases_app_version_kind_uq`, ADD UNIQUE KEY `ag_releases_app_kind_plugin_version_uq` (`app`, `kind`, `plugin`, `version`)';
        }

        if (!Schema::hasTable('ag_plugin_access')) {
            Schema::create('ag_plugin_access', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('app', 40);
                $t->string('plugin', 60);
                $t->boolean('granted')->default(false);
                $t->boolean('enabled')->default(true);
                $t->boolean('blocked')->default(false);
                $t->boolean('download')->default(true);
                $t->string('downloaded_version', 40)->nullable();
                $t->string('installed_version', 40)->nullable();
                $t->string('updated_by', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['tenant_id', 'app', 'plugin'], 'ag_plugin_access_tenant_app_plugin_uq');
            });
        }

        if (Schema::hasTable('ag_licences') && !Schema::hasColumn('ag_licences', 'stats_json')) {
            Schema::table('ag_licences', function (Blueprint $t): void {
                $t->text('stats_json')->nullable();
                $t->dateTime('stats_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: who holds which plugin is a commercial record.
    }
};
