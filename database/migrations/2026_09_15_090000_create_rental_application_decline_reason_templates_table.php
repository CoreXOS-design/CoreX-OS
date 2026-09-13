<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-15: "a decline reason template... two parts — the reason
 * itself... and the guidance that goes with it." Agency-owned, full CRUD,
 * archive-not-delete — same shape as RentalApplicationHighlighter, not a
 * new pattern. Boundary agreed with cc5 before either lane wrote code: this
 * table is the reason+guidance LIBRARY only; the existing decline email
 * envelope (RentalApplicationDeclineEmailSetting) and its merge are cc5's,
 * untouched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_decline_reason_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->text('guidance');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Explicit short name — Laravel's auto-generated name for this
            // long table name (rental_application_decline_reason_templates_
            // agency_id_deleted_at_index) exceeds MySQL's 64-character
            // identifier limit; caught by the real schema bootstrap, not by
            // reading the DDL and assuming it fits.
            $table->index(['agency_id', 'deleted_at'], 'ra_decline_reason_templates_agency_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_decline_reason_templates');
    }
};
