<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — Johan, from his own reading of the law: "the law states you may
 * not spend more than 30% of your gross income on rentals... its not 3.5
 * or what you created it as of nett disposable income. its of the gross
 * income." A multiplier-of-rent (3.0x) and a percentage-of-gross-income
 * (30%) are the same arithmetic wearing a disguise, but a multiplier gives
 * nobody at an agency a way to check it against the actual legal figure at
 * a glance. Replacing, not adding alongside — one rule, one column, no
 * second implementation anywhere (checked: zero real agencies had ever
 * configured the old column at the point of this migration — the one row
 * that existed was a leftover test artifact from an earlier round's own
 * verification, already cleaned up separately; nothing real to convert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('income_to_rent_multiplier');
        });

        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            // Default 30.00 — the legal ceiling itself (Rental Housing Act
            // affordability guideline: rent must not exceed 30% of GROSS
            // monthly income). An agency may set a STRICTER (lower) figure;
            // the UI warns, but does not block, if they set higher.
            $table->decimal('max_rent_percent_of_gross_income', 5, 2)->default(30.00);
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('max_rent_percent_of_gross_income');
        });

        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->decimal('income_to_rent_multiplier', 5, 2)->default(3.00);
        });
    }
};
