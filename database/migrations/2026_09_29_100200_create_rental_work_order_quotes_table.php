<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.4c — Johan's ruling: "agents will
 * obtain quotes and thats the value that approval will ride against. so an
 * upload or attach of the quote, or punching in the details is whats
 * needed." Neither the document nor the keyed-in detail is mandatory over
 * the other (application-layer rule — at least one required, enforced in
 * RentalWorkOrderQuoteController::store()). Several quotes can exist per
 * work order; exactly one is_selected at a time — RentalWorkOrder::
 * selectQuote() is the only place that flips it. Soft-deletes: a superseded
 * or withdrawn quote is archived, never destroyed (non-negotiable #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_work_order_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_work_order_id')->constrained(indexName: 'rwoq_work_order_fk')->cascadeOnDelete();
            // Required — "at minimum: supplier" (Johan). Suppliers are
            // soft-deleted, never hard-deleted (non-negotiable #1), so a
            // real FK-delete here should never legitimately happen —
            // restrictOnDelete() is the safety net, not nullOnDelete() as
            // rental_work_orders.agency_service_provider_id uses (that FK
            // is nullable there; this one is required).
            $table->foreignId('agency_service_provider_id')->constrained(indexName: 'rwoq_supplier_fk')->restrictOnDelete();

            $table->decimal('amount', 10, 2);
            $table->date('quote_date');
            $table->string('document_storage_path')->nullable();
            // private disk (Storage::disk('local')) — never the public-disk
            // PropertyImageStorer pattern rental_work_order_photos uses; a
            // quote is priced evidence, gated the same way PropertyFileController
            // gates a property Drive document.
            $table->text('detail_text')->nullable();
            $table->boolean('is_selected')->default(false);

            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_work_order_id'], 'rwoq_agency_wo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_work_order_quotes');
    }
};
