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
        DB::table('rental_vat_types')->truncate();
    }
};
