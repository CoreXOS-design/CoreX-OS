<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — "pull from contact": a document already on file against a
 * contact (FICA, a prior application, another rental application) can be
 * attached to a NEW rental application without the applicant re-sending it
 * and without duplicating the file. Mirrors document_contacts/
 * document_properties exactly (see 2026_03_24_200001_create_unified_documents_table.php)
 * — source_type/source_id stays the document's one filing home; this pivot
 * is purely "also relevant here."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_document', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('rental_application_id');
            $table->unsignedBigInteger('attached_by')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            $table->foreign('rental_application_id')->references('id')->on('rental_applications')->onDelete('cascade');
            $table->foreign('attached_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['document_id', 'rental_application_id'], 'rental_app_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_document');
    }
};
