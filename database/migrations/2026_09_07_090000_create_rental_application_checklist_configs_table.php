<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AT-392 — Johan, 2026-09-07: "configured but empty" (the agency
     * deliberately requires nothing for this employment type) must be
     * distinguishable from "never configured" (V8 defaults apply). Zero
     * saved rows in rental_application_document_requirements meant both,
     * indistinguishably — an agency that cleared a list would find it
     * silently reappear.
     *
     * This table stores ONLY the fact "this agency has saved this
     * employment type's checklist" — one row per (agency, employment_type)
     * once the settings screen has been submitted for it, regardless of
     * how many document types ended up selected. Its mere presence is the
     * signal; nothing else is read from it.
     */
    public function up(): void
    {
        Schema::create('rental_application_checklist_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->enum('employment_type', [
                'permanently_employed', 'business_owner_personal_account', 'business_owner_business_account',
            ]);
            $table->timestamps();

            $table->unique(['agency_id', 'employment_type'], 'rental_app_checklist_config_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_checklist_configs');
    }
};
