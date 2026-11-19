<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 10-Nov-2026 (Ejaz): Explore Demo — which companies are demo companies (set up from smartept.com → Explore Demo). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_accounts')) {
            return;
        }
        Schema::create('demo_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->string('city', 40);
            $t->string('activation_code', 12);     // the one QR app activation code every demo employee accepts
            $t->string('status', 10)->default('building'); // building → ready
            $t->json('employee_ids')->nullable();  // the sample employees whose days are simulated
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_accounts');
    }
};
