<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMETECSGLOBAL BILLING (owner, 18 Nov 2026 - Phase 3). Invoices and payments for paid upgrades, licence renewals and
 * anything Super Admin bills, paid by Razorpay (keys in the existing payment_gateways table) or recorded offline.
 * Nothing existing is altered or dropped.
 *
 * - `ag_invoices`: number, client, purpose (upgrade | renewal | other), the release / licence it pays for, amount,
 *   GST, total, status (pending | paid | cancelled | refunded), the Razorpay order.
 * - `ag_payments`: every payment received (Razorpay or offline) and its refund, if any.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ag_invoices')) {
            Schema::create('ag_invoices', function (Blueprint $t): void {
                $t->id();
                $t->string('number', 40);
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('purpose', 12)->default('other');
                $t->unsignedBigInteger('licence_id')->nullable();
                $t->unsignedBigInteger('release_id')->nullable();
                $t->string('description', 255);
                $t->decimal('amount', 12, 2);
                $t->decimal('tax_percent', 5, 2)->default(0);
                $t->decimal('tax', 12, 2)->default(0);
                $t->decimal('total', 12, 2);
                $t->char('currency', 3)->default('INR');
                $t->string('status', 12)->default('pending');
                $t->string('gateway_order_id', 64)->nullable();
                $t->date('issued_on')->nullable();
                $t->date('due_on')->nullable();
                $t->dateTime('paid_at')->nullable();
                $t->text('notes')->nullable();
                $t->string('created_by', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['number'], 'ag_invoices_number_uq');
                $t->index(['status'], 'ag_invoices_status_ix');
            });
        }

        if (!Schema::hasTable('ag_payments')) {
            Schema::create('ag_payments', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('invoice_id')->index();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->decimal('amount', 12, 2);
                $t->char('currency', 3)->default('INR');
                $t->string('gateway', 20)->default('razorpay');
                $t->string('method', 40)->nullable();
                $t->string('gateway_payment_id', 64)->nullable();
                $t->string('status', 12)->default('succeeded');
                $t->string('refund_id', 64)->nullable();
                $t->dateTime('refunded_at')->nullable();
                $t->text('detail')->nullable();
                $t->string('by_who', 191)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->unique(['gateway_payment_id'], 'ag_payments_gateway_payment_uq');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: invoices and payments are financial records.
    }
};
