<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.6 — the lightweight signature pattern
 * (canvas capture, base64 PNG decoded server-side to a file, only the PATH
 * stored) already proven outside DocuPerfect for compliance policy
 * acknowledgements. Deliberately NOT the full e-sign ceremony — that
 * requires a Template/CdsDraft document flow this single-form use case
 * doesn't need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();

            $table->string('signer_role', 20);
            // tenant | agent_on_behalf | landlord (landlord not required by Johan's
            // ruling, per spec §3.2 — enum leaves room for a future ruling without a
            // schema change)
            $table->foreignId('signer_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            // set for tenant/landlord
            $table->foreignId('signed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            // set for agent_on_behalf — WHO pressed the button

            $table->string('signature_path', 500)->nullable();
            $table->text('refused_note')->nullable();
            // REQUIRED at the application layer when signer_role='agent_on_behalf' —
            // Johan's exact required wording: "tenant refused to sign out inspection."
            // Free text so the agent can add detail, but validated to contain that exact
            // phrase (§11 acceptance criteria).

            $table->timestamp('signed_at');
            $table->timestamps();

            $table->index(['agency_id', 'rental_inspection_id'], 'ri_signatures_agency_inspection_idx');
            // explicit short name — auto-generated one exceeds MySQL's 64-char limit.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_signatures');
    }
};
