<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-20 — "hfc uses tpn so thats why we have that." The credit
 * bureau named throughout the rental application (heading, PDF signature
 * caption, field label) was hardcoded to "TPN" — HFC's own choice, not a
 * universal one. A second agency (Cape Town, rentals-focused, onboarding
 * October) may use a different bureau, or none.
 *
 * Nullable, same convention as every other agency-configurable knob on this
 * table — a row is never required to exist for an agency to get sensible
 * (here: bureau-agnostic) behaviour. Deliberately NO global PHP-constant
 * default of 'TPN': defaulting every future agency to a named competitor
 * product neither they nor CoreX chose for them is wrong, not merely
 * untidy — RentalApplication::creditBureauConsentLabel() falls through to
 * generic "Credit Bureau Consent" wording instead when this is null, which
 * is also the correct rendering for an agency that genuinely uses no
 * bureau at all (Johan's other named case) — same state, same safe text.
 *
 * HFC's own existing row IS backfilled below, by name lookup (same
 * established pattern as SalesMandatoryDisclosureEsignSeeder /
 * HfcAddendumBEsignSeeder — never a hardcoded agency_id), so nothing
 * changes for their applicants today. A fresh/test DB with no matching
 * agency simply has nothing to backfill — harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->string('credit_bureau_name', 100)->nullable()->after('marital_status_options');
        });

        $agencyId = DB::table('agencies')->where('name', 'Home Finders Coastal')->value('id');

        if ($agencyId !== null) {
            $exists = DB::table('rental_application_qualifying_settings')->where('agency_id', $agencyId)->exists();

            if ($exists) {
                DB::table('rental_application_qualifying_settings')
                    ->where('agency_id', $agencyId)
                    ->update(['credit_bureau_name' => 'TPN']);
            } else {
                DB::table('rental_application_qualifying_settings')->insert([
                    'agency_id' => $agencyId,
                    'credit_bureau_name' => 'TPN',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('credit_bureau_name');
        });
    }
};
