<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3 — the agency's own configured checklist template. Sections are
 * orderable and archive-only (SoftDeletes), same shape as
 * RentalApplicationDeclineReasonTemplate. An application never reads this
 * table directly after creation — see rental_application_checklist_sections
 * for the per-application snapshot that protects applications already in
 * flight from a later template edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_checklist_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_checklist_template_sections');
    }
};
