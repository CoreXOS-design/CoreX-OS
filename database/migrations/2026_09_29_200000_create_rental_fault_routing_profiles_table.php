<?php

use App\Models\Agency;
use App\Models\RentalFaultRoutingProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.2 — one row per property
 * (override) or per agency (default, property_id null). Same
 * null-means-agency-default shape §3.4b of rental-work-orders.md already
 * established for the spend threshold.
 *
 * Every agency gets a seeded default profile (§13.2's own "never broken"
 * note) — today's built behaviour exactly (agent_review/agent_review, no
 * auto-routing) until an agency deliberately configures a caretaker or
 * supplier route. Same "backfill every existing agency in this migration"
 * pattern as 2026_08_08_000001_create_agency_service_types.php (AT-229).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_routing_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();

            $table->string('emergency_route', 20)->default('owner_first');
            $table->decimal('emergency_caretaker_spend_limit', 10, 2)->nullable();
            $table->foreignId('emergency_supplier_id')->nullable()->constrained('agency_service_providers')->nullOnDelete();
            $table->decimal('emergency_supplier_spend_limit', 10, 2)->nullable();
            $table->decimal('emergency_owner_first_spend_limit', 10, 2)->nullable();

            $table->string('non_emergency_route', 20)->default('agent_review');
            $table->decimal('non_emergency_caretaker_spend_limit', 10, 2)->nullable();
            $table->foreignId('non_emergency_supplier_id')->nullable()->constrained('agency_service_providers')->nullOnDelete();
            $table->decimal('non_emergency_supplier_spend_limit', 10, 2)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Exactly one default profile per agency; at most one override
            // profile per property. NULL property_id rows are NOT covered
            // by a unique index in MySQL by default (NULLs don't collide),
            // so the agency-default uniqueness is enforced in the model/
            // service layer (RentalFaultRoutingService), not the schema —
            // same treatment this codebase already gives comparable
            // "at most one row of this kind" cases without a DB-level
            // partial-unique-index (MySQL has no native support for one).
            $table->unique(['agency_id', 'property_id'], 'rfrp_agency_property_unique');
        });

        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            RentalFaultRoutingProfile::seedDefaultFor((int) $agencyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_routing_profiles');
    }
};
