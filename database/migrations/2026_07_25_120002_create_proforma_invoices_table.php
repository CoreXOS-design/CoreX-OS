<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proforma Invoices — the structured financial RECORD (the future ledger consumes
 * these; the PDF is only a rendering). Split deals carry THIS agency's share only.
 * Parties + figures are snapshotted at issue so the record is a fixed point in time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('deal_id')->index();

            // Number + the raw integer behind it (integrity/ordering; never reused).
            $table->string('number', 32);
            $table->unsignedInteger('sequence_no');
            $table->enum('status', ['issued', 'voided'])->default('issued');

            // Issued to: the SELLER c/o the TRANSFERRING ATTORNEY (snapshots at issue).
            $table->unsignedBigInteger('issued_to_contact_id')->nullable();
            $table->string('issued_to_name')->nullable();
            $table->unsignedBigInteger('care_of_provider_id')->nullable();
            $table->string('care_of_name')->nullable();

            $table->string('reference');                 // "deal# – property address"
            $table->date('due_date');

            // VAT rendering follows the agency setting, snapshotted here.
            $table->boolean('vat_registered')->default(false);
            $table->decimal('vat_rate', 5, 2)->default(0);

            $table->decimal('subtotal_excl', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_incl', 14, 2)->default(0);

            $table->unsignedBigInteger('document_id')->nullable();       // the filed PDF
            $table->unsignedBigInteger('communication_id')->nullable();  // the email comms record

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('voided_by_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();
            $table->unique(['agency_id', 'sequence_no'], 'pi_agency_seq_unique');
            $table->unique(['agency_id', 'number'], 'pi_agency_number_unique');
            $table->index(['agency_id', 'status'], 'pi_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoices');
    }
};
