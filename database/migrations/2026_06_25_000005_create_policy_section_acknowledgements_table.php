<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy section acknowledgements (AT-29) — the per-section tick within a
 * sign-off. Mirrors rmcp_section_acknowledgements but with agency_id
 * NOT NULL from creation (skipping RMCP's later add-column + backfill
 * dance). No SoftDeletes on this leaf child (matches RMCP). See spec §3.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_section_acknowledgements', function (Blueprint $table) {
            $table->id();
            // Explicit short FK/index names — the auto-generated names
            // (table + column + _foreign) exceed MySQL's 64-char limit.
            $table->foreignId('agency_id')
                  ->constrained('agencies', 'id', 'psa_agency_fk')->cascadeOnDelete();
            $table->foreignId('policy_acknowledgement_id')
                  ->constrained('policy_acknowledgements', 'id', 'psa_ack_fk')->cascadeOnDelete();
            $table->foreignId('policy_section_id')
                  ->constrained('policy_sections', 'id', 'psa_section_fk')->cascadeOnDelete();

            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_response', 100)->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            $table->unique(['policy_acknowledgement_id', 'policy_section_id'], 'policy_sec_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_section_acknowledgements');
    }
};
