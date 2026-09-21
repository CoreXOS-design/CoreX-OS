<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §5 — "every page is signed" (Johan). Mirrors
 * RentalInspectionSignature's shape deliberately: three party roles (tenant,
 * landlord, agent), refusal as a first-class disposition, the agent signing
 * last and attesting to the complete record. [design call, flagged not
 * silently assumed] — this is a genuinely separate table, not a polymorphic
 * relation shared with rental_inspection_signatures: unifying the two is a
 * legitimate future refactor, but rewriting the signing model §15/§16 just
 * landed and verified tonight is out of scope for this build. Wet-ink
 * (§16 on the inspection side) is likewise NOT built here — that was its
 * own explicit, separately-scoped task for inspections; if wanted here too,
 * that is a follow-up ask, not assumed from "every page is signed" alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();

            $table->string('party_role', 20); // tenant | landlord | agent
            $table->foreignId('party_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('disposition', 10); // signed | refused
            $table->string('party_signature_path')->nullable();
            $table->string('refusal_reason_preset', 60)->nullable();
            $table->text('refusal_reason_note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disposition_recorded_at')->nullable();

            $table->timestamps();

            $table->index(['agency_id', 'rental_inventory_id', 'party_role'], 'rental_inventory_signatures_agency_inventory_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_signatures');
    }
};
