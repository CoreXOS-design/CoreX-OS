<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Note 2 (external agency) — add `external_agency` to the agency_service_providers.specialty
 * enum so an external estate agency can be captured as a searchable SUPPLIER (same as the
 * transferring attorney / bond originator), emailable via Email Parties and a document-copy
 * recipient. Additive; existing rows/values untouched. Raw ALTER (enum changes aren't
 * portable through the Blueprint/DBAL path).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `agency_service_providers` MODIFY COLUMN `specialty` ENUM("
            . "'electrician','entomologist','plumber','gas','electric_fence',"
            . "'transfer_attorney','bond_attorney','conveyancer','bond_originator','external_agency','other'"
            . ") NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        // Revert to the pre-external_agency enum (any external_agency rows would need remapping first).
        DB::statement("ALTER TABLE `agency_service_providers` MODIFY COLUMN `specialty` ENUM("
            . "'electrician','entomologist','plumber','gas','electric_fence',"
            . "'transfer_attorney','bond_attorney','conveyancer','bond_originator','other'"
            . ") NOT NULL DEFAULT 'other'");
    }
};
