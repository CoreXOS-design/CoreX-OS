<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, from his own live walk, 2026-09-21 — "once a applicant has been
 * linked we should update this screen to reflected rented out" /
 * "rental application screen should update to renting a property, not just
 * approved?" An approved application that has since been linked to an
 * active lease is showing as merely "Approved" everywhere — the list, the
 * detail screen, the contact record — with nothing distinguishing a
 * decision made last week from a tenancy that is actually live today.
 *
 * The wording an agency wants for that state ("Rented Out", "Tenanted",
 * "Renting", whatever fits their own process) is exactly the kind of thing
 * that varies per agency — same convention as credit_bureau_name
 * immediately above this column: nullable, null falls through to a
 * documented default, resolved in exactly one place
 * (RentalApplicationQualifyingSetting::tenantedLabelFor()), never hardcoded
 * in a view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->string('tenanted_label', 60)->nullable()->after('credit_bureau_name');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('tenanted_label');
        });
    }
};
