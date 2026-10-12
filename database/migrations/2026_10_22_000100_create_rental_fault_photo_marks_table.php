<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §18 (AT-452) — the agent's marks on a fault photo and a short description per photo.
 * Both sit BESIDE rental_fault_report_photos (immutable evidence): the tenant's file and its row are never modified. Marks are data
 * (shape + normalised 0-1 coordinates + colour + who + when), drawn as an overlay by the viewer. Soft delete only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_fault_photo_marks')) {
            Schema::create('rental_fault_photo_marks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_fault_report_id')->constrained(indexName: 'rfpm_report_fk')->cascadeOnDelete();
                $table->foreignId('rental_fault_report_photo_id')->constrained(indexName: 'rfpm_photo_fk')->cascadeOnDelete();
                $table->string('shape', 12);
                $table->json('geometry');
                $table->string('colour', 9)->default('#e11d48');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['agency_id', 'rental_fault_report_id'], 'rfpm_agency_report_idx');
                $table->index('rental_fault_report_photo_id', 'rfpm_photo_idx');
            });
        }

        if (! Schema::hasTable('rental_fault_photo_notes')) {
            Schema::create('rental_fault_photo_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_fault_report_id')->constrained(indexName: 'rfpn_report_fk')->cascadeOnDelete();
                $table->foreignId('rental_fault_report_photo_id')->constrained(indexName: 'rfpn_photo_fk')->cascadeOnDelete();
                $table->string('description', 500);
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('recorded_at')->nullable();
                $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('rental_fault_report_photo_id', 'rfpn_photo_unique');
                $table->index(['agency_id', 'rental_fault_report_id'], 'rfpn_agency_report_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_photo_notes');
        Schema::dropIfExists('rental_fault_photo_marks');
    }
};
