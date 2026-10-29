<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2, fees phase (.ai/specs/auctions.md §17): the buyer's commission and deposit settings, and the bidder invoice
 * with its payments. Explicit `au2_` foreign-key names (constraint names are unique per schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_auction_settings', function (Blueprint $t) {
            $t->decimal('buyers_commission_percent', 5, 2)->nullable();
            $t->decimal('buyers_commission_minimum', 12, 2)->nullable();
            $t->decimal('deposit_percent', 5, 2)->nullable();
            $t->unsignedSmallInteger('deposit_due_days')->nullable();
            $t->unsignedSmallInteger('balance_due_days')->nullable();
            $t->text('payment_details')->nullable();
        });

        Schema::table('auctions', function (Blueprint $t) {
            $t->decimal('buyers_commission_percent', 5, 2)->nullable()->after('soft_close_minutes');
            $t->decimal('deposit_percent', 5, 2)->nullable()->after('buyers_commission_percent');
        });

        Schema::create('auction_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id');
            $t->unsignedBigInteger('lot_id');
            $t->unsignedBigInteger('registration_id');
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->string('number', 40)->nullable();                       // assigned when issued
            $t->string('status', 12)->default('draft');                 // draft | issued | part_paid | paid | cancelled
            // snapshot of the sum at the moment of sale
            $t->decimal('hammer_price', 14, 2);
            $t->decimal('commission_percent', 5, 2)->default(0);
            $t->decimal('commission_amount', 14, 2)->default(0);        // ex VAT
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 14, 2)->default(0);
            $t->decimal('total_amount', 14, 2);
            $t->decimal('deposit_percent', 5, 2)->default(0);
            $t->decimal('deposit_amount', 14, 2)->default(0);
            $t->dateTime('deposit_due_at')->nullable();
            $t->decimal('balance_amount', 14, 2)->default(0);
            $t->dateTime('balance_due_at')->nullable();
            $t->text('payment_details')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 300)->nullable();
            $t->dateTime('guarantee_received_at')->nullable();
            $t->string('guarantee_note', 300)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['agency_id', 'number'], 'au2_inv_number_uq');
            $t->index(['lot_id', 'status'], 'au2_inv_lot_ix');
            $t->index(['agency_id', 'status', 'created_at'], 'au2_inv_list_ix');
            $t->foreign('agency_id', 'au2_inv_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_inv_auction_fk')->references('id')->on('auctions');
            $t->foreign('lot_id', 'au2_inv_lot_fk')->references('id')->on('auction_lots');
            $t->foreign('registration_id', 'au2_inv_reg_fk')->references('id')->on('auction_registrations');
        });

        Schema::create('auction_invoice_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('invoice_id');
            $t->string('kind', 10)->default('deposit');                 // deposit | balance | other
            $t->decimal('amount', 14, 2);
            $t->date('received_at');
            $t->string('reference', 120)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->string('void_reason', 300)->nullable();
            $t->timestamps();
            $t->softDeletes();                                          // a voided payment is archived, with its reason
            $t->index('invoice_id', 'au2_pay_inv_ix');
            $t->foreign('agency_id', 'au2_pay_agency_fk')->references('id')->on('agencies');
            $t->foreign('invoice_id', 'au2_pay_inv_fk')->references('id')->on('auction_invoices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_invoice_payments');
        Schema::dropIfExists('auction_invoices');
        Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn(['buyers_commission_percent', 'deposit_percent']));
        Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->dropColumn(['buyers_commission_percent', 'buyers_commission_minimum', 'deposit_percent', 'deposit_due_days', 'balance_due_days', 'payment_details']));
    }
};
