<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-517 - the Co-applicant signs the same form: their own declaration and their own credit-check consent, stored in the
 * same table, per generation, exactly like the Main applicant's. `kind` was an enum of the two Main-applicant kinds; it
 * gains 'co_declaration' and 'co_tpn_consent'. The unique key (application, kind, generation) is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_application_signatures')) {
            DB::statement("ALTER TABLE rental_application_signatures MODIFY kind ENUM('declaration','tpn_consent','co_declaration','co_tpn_consent') NOT NULL");
        }
    }

    public function down(): void
    {
        // Rows of the new kinds cannot live in the old enum; they are kept (never hard-deleted) and the column is left wide.
    }
};
