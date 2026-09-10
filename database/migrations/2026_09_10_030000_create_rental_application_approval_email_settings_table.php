<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — agency-configurable cap on how many matched properties appear
 * in the agent's approval email. Same safe pattern as
 * RentalApplicationQualifyingSetting: forAgency() never writes on read,
 * only returns a sensible default until the agency explicitly saves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_approval_email_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('max_properties_in_email')->default(5);
            $table->timestamps();
            $table->unique('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_approval_email_settings');
    }
};
