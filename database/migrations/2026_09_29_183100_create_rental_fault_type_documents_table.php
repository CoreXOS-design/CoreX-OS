<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §2 — "optional uploaded images/
 * PDF/video link... the agency can upload" per fault type. Several per
 * fault type, mixed formats, so a join table rather than columns on
 * rental_fault_types itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_type_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->foreignId('rental_fault_type_id')->constrained('rental_fault_types')->cascadeOnDelete();
            $table->string('document_type', 20); // image | pdf | video_link | document
            $table->string('storage_path')->nullable();
            $table->string('external_url')->nullable();
            $table->string('caption', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_fault_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_type_documents');
    }
};
