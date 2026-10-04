<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — req #5: "Send to owner as quote" reuses the EXISTING work-order
 * quote/owner-approval mechanism (rental-work-orders.md §3.4c) rather than
 * inventing a second one. An internal job card's quote has no outside
 * supplier at all — agency_service_provider_id (required until now) is
 * relaxed to nullable for exactly that case; every existing/outside-
 * supplier quote still always sets it, unchanged. rental_job_card_id marks
 * which quotes originated from a job card (for display: "quote from your
 * maintenance team" vs a named supplier) — nullable, null for every
 * pre-existing and every outside-supplier quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_service_provider_id')->nullable()->change();
            $table->foreignId('rental_job_card_id')->nullable()
                ->after('rental_work_order_id')
                ->constrained(indexName: 'rwoq_job_card_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_job_card_id');
            $table->unsignedBigInteger('agency_service_provider_id')->nullable(false)->change();
        });
    }
};
