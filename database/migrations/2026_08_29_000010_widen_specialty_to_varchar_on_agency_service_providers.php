<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The specialty dropdown on /deals-v2/suppliers reads agency-configurable
 * codes (Settings -> COC / Service Types) — free text an agency can rename
 * or add to at any time. The column storing it was a fixed ENUM of a much
 * older, hardcoded list, so any live code that isn't one of those exact
 * eleven values (case-sensitive) fails to save at all: "Data truncated for
 * column 'specialty'". Found while proving the registration/ID split on
 * /deals-v2/suppliers — saving a NEW or EDITED supplier was already broken
 * before that work, for any agency-configured specialty.
 *
 * Widening to VARCHAR matches what validation already treats it as (an
 * arbitrary string checked against the live list, not a hardcoded set) —
 * the fix belongs at the column, not in a hand-maintained code-mapping
 * table that breaks again the next time an agency renames a service type.
 * Existing rows keep their exact stored value; nothing is renamed or
 * remapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE agency_service_providers MODIFY specialty VARCHAR(50) NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agency_service_providers MODIFY specialty ENUM('electrician','entomologist','plumber','gas','electric_fence','transfer_attorney','bond_attorney','conveyancer','bond_originator','external_agency','other') NOT NULL DEFAULT 'other'");
    }
};
