<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-25 — "so add a registration field on suppliers." Suppliers
 * are a mixed bag: an attorney FIRM has a company registration number
 * (e.g. "2020/778899/23"), while a sole practitioner or an executor acting
 * personally has their own ID number instead. One generic, nullable field
 * at the firm level covers both without forcing either shape onto the
 * other — a sole-practitioner "firm" record IS effectively that one
 * person, so their ID number belongs in the same slot a company's
 * registration number would. Never required on the form itself (would
 * break every existing supplier row and every screen that saves one);
 * required only at the point it actually matters — see
 * ESignWizardController::assertSupplierRepresentativesHaveRegistration().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_service_providers', function (Blueprint $table) {
            $table->string('registration_number', 100)->nullable()->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('agency_service_providers', function (Blueprint $table) {
            $table->dropColumn('registration_number');
        });
    }
};
