<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — Johan: "validity windows are per document type PER PURPOSE,
 * agency-configurable — 2 months for the rental application, 3 months for
 * FICA including the ID copy." No existing settings table models this axis
 * (RentalApplicationDocumentRequirement's "$type" is employment_type, a
 * different concept entirely) — genuinely new, not a reuse candidate.
 *
 * document_type_id nullable = the PURPOSE-WIDE default (Johan's own "2
 * months for the rental application" / "3 months for FICA" figures); a
 * present document_type_id is a per-type override on top of that default.
 * No row at all for a purpose = the hardcoded fallback (60/90 days) applies
 * in-memory — same "presence-row-as-configured-signal" pattern
 * RentalApplicationChecklistConfig already uses for the employment-type
 * checklist, reused here rather than invented twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_document_validity_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('purpose', 30); // 'rental_application' | 'fica'
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->unsignedSmallInteger('validity_days');
            $table->timestamps();

            $table->foreign('agency_id', 'rental_app_doc_validity_agency_fk')->references('id')->on('agencies')->onDelete('cascade');
            $table->foreign('document_type_id', 'rental_app_doc_validity_doctype_fk')->references('id')->on('document_types')->onDelete('cascade');
            $table->unique(['agency_id', 'purpose', 'document_type_id'], 'rental_app_doc_validity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_document_validity_windows');
    }
};
