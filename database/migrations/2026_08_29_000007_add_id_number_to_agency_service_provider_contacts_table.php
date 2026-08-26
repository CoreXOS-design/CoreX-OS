<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-26 — splits the single "registration_number" field
 * (2026_08_25_150000) into two genuinely different numbers: the
 * COMPANY's registration number, which stays exactly where it was on
 * AgencyServiceProvider (the firm), and the REPRESENTATIVE's own ID
 * number, new here on AgencyServiceProviderContact (the person). "You
 * built one field covering both. Johan now wants them separate... The
 * clause needs both."
 *
 * No backfill: the old field held a COMPANY registration number, not a
 * person's ID — there is nothing in it that is correct data for this
 * new column, so copying it across would plant a wrong-shaped number
 * that looks filled-in but isn't. Every existing representative starts
 * with a blank id_number and gets it added going forward, same as any
 * other optional field.
 *
 * Optional on the Deal Register supplier screen (never breaks an
 * existing row or that screen's save path) — required only at the
 * point a representative is actually bound as a signing party for
 * e-sign, alongside the firm's own registration_number: see
 * ESignWizardController::assertSupplierRepresentativesHaveRegistrationNumber().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_service_provider_contacts', function (Blueprint $table) {
            $table->string('id_number', 20)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('agency_service_provider_contacts', function (Blueprint $table) {
            $table->dropColumn('id_number');
        });
    }
};
