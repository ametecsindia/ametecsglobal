<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AmetecsGlobal: which AmetecsGlobal client this QRONEX company is (null = an ordinary QRONEX company). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('ag_tenant', 40)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropUnique(['ag_tenant']);
            $t->dropColumn('ag_tenant');
        });
    }
};
