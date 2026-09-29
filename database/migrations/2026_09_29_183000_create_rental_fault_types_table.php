<?php

use App\Models\RentalFaultType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §2 — the agency-configurable fault
 * catalogue. Each agency owns its own list, seeded with CoreX's default
 * fault types. Same "seed + backfill every existing agency in this
 * migration" pattern as 2026_08_08_000001_create_agency_service_types.php
 * (AT-229) — AgencyServiceType::seedDefaultsFor() is the precedent
 * RentalFaultType::seedDefaultsFor() below is modelled on directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('name', 191);
            $table->string('category', 100)->nullable();
            $table->string('urgency', 20)->default('routine'); // routine | urgent | emergency
            $table->text('first_aid_steps')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_active']);
        });

        // Backfill — every existing agency gets the seeded default list so
        // the tenant-facing picker and the agent's own reporting screen are
        // never empty on day one (§13.2's own "seeded default profile,
        // never broken" reasoning, applied here to the catalogue itself).
        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            RentalFaultType::seedDefaultsFor((int) $agencyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_types');
    }
};
