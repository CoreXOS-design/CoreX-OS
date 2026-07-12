<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit for every proforma action (who/when). Immutable — the model
 * throws on update()/delete(), mirroring deal_document_access_log / the comms
 * audit pattern. This is the compliance evidence the ledger + admins rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoice_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proforma_invoice_id')->index();
            $table->unsignedBigInteger('agency_id')->index();
            $table->enum('event', [
                'generated', 'line_added', 'line_removed', 'voided',
                'regenerated', 'number_changed', 'emailed',
            ]);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();   // no updated_at — append-only

            $table->foreign('proforma_invoice_id')->references('id')->on('proforma_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_audit');
    }
};
