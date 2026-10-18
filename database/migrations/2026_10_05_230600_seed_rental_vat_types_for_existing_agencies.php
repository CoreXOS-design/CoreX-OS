<?php

use App\Models\RentalVatType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time seed for every EXISTING, non-archived agency — new agencies get
 * the same three defaults via AgencyCreated (SeedDefaultRentalVatTypes),
 * same split RentalApplicationDeclineReasonTemplate's own backfill uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agencyIds = DB::table('agencies')->whereNull('deleted_at')->pluck('id');

        foreach ($agencyIds as $agencyId) {
            RentalVatType::seedDefaultsFor((int) $agencyId);
        }
    }

    public function down(): void
    {
        // Audit fix 2026-10-18: was a truncate() - that wiped every agency's VAT types (including ones agencies created
        // themselves) and orphaned job-card lines pointing at them. up() is idempotent seeding of defaults; nothing to undo.
    }
};
