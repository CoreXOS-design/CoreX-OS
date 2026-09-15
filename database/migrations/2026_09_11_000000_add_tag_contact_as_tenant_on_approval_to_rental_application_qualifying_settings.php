<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, verbatim: "contact type can be added, not changed. the scenario
 * exists where a seller or any contact type can become a tenant. the
 * scenario exists that the seller of unit a decides to rent but their
 * property has not sold yet. so that contact will be dealt with as a
 * seller on their property but also as a tenant inside rentals."
 *
 * Whether an approved rental application tags its contact "Tenant" at all
 * is a business rule ("any threshold, window or business rule is an
 * agency-configurable setting with a sensible default, never hardcoded"),
 * same pattern as this table's sibling columns. Nullable so "never
 * configured" (use the system default) is distinguishable from
 * "explicitly turned off". Default (true) is enforced in code
 * (RentalApplicationQualifyingSetting::DEFAULT_TAG_CONTACT_AS_TENANT_ON_APPROVAL),
 * not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('tag_contact_as_tenant_on_approval')->nullable()->after('lock_property_after_submission');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('tag_contact_as_tenant_on_approval');
        });
    }
};
