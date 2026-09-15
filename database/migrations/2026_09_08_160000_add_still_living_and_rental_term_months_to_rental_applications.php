<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — Johan, QA1, testing the applicant form: "current landlord we have
 * from and to dates - put a tick at to date to tick that states still living
 * in current premises. so theres no to date applicable?"
 *
 * current_rental_still_living is a separate column from current_rental_to,
 * not a sentinel date value, because "still living there" (true) and "we
 * don't know the end date" (still null, still_living false/null) are
 * different facts for a reference check — collapsing them into one nullable
 * date would make that distinction unrecoverable.
 *
 * Johan, same session, on rental_terms: "rental term required - we need to
 * have it in years / months / maybe a couple of quick clicks buttons? ...
 * 6 months, 12 months, 24 months - nothing longer as law states no lease may
 * exceed 24 months." rental_term_months is a NEW integer column rather than
 * repurposing the existing rental_terms string column, because rental_terms
 * already holds free-text values on real QA1 applications (46 rows as at
 * 2026-09-08) and changing its type in place would corrupt that history.
 * rental_terms stays as-is, untouched, for those existing rows; new
 * applications are written through rental_term_months going forward. See
 * fieldValidationRules() / NUMERIC_FIELDS on RentalApplication for the
 * shared validation, and the "Rental term" section of
 * .ai/specs/rental-applications.md for the full decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->boolean('current_rental_still_living')->nullable()->default(false)->after('current_rental_to');
            $table->unsignedSmallInteger('rental_term_months')->nullable()->after('rental_terms');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn(['current_rental_still_living', 'rental_term_months']);
        });
    }
};
