<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.31 — the supplier's invoice DOCUMENT against a work order, with the two per-agency
 * upload limits (size, file types). Soft delete only. The amount is evidence for the cost / who-pays record; it never
 * writes to `rental_work_orders.cost_amount` by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_work_order_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('rental_work_order_id');
            $table->unsignedBigInteger('agency_service_provider_id')->nullable();
            $table->string('supplier_name', 191)->nullable();
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            $table->decimal('amount', 12, 2);
            $table->string('document_storage_path', 500);
            $table->string('document_original_name', 255)->nullable();
            $table->string('document_mime', 100)->nullable();
            $table->unsignedBigInteger('document_size')->nullable();
            // Files a replace has taken out of use: [{path, original_name, replaced_at, replaced_by}] — never deleted from disk.
            $table->json('superseded_documents')->nullable();
            // Visible to the property's owner on the portal only while this is true (the tenant never sees an invoice).
            $table->boolean('share_with_owner')->default(false);
            $table->timestamp('shared_at')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('archived_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rental_work_order_id', 'deleted_at'], 'rwoi_work_order_idx');
            $table->foreign('rental_work_order_id', 'rwoi_work_order_fk')->references('id')->on('rental_work_orders')->cascadeOnDelete();
        });

        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('invoice_max_file_mb')->nullable();
            $table->string('invoice_allowed_file_types', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->dropColumn(['invoice_max_file_mb', 'invoice_allowed_file_types']);
        });
        Schema::dropIfExists('rental_work_order_invoices');
    }
};
