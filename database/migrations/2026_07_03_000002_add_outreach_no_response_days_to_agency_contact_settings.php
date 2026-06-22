<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-81 — agency-configurable no-response window.
 *
 * Number of days a contact may sit PENDING (outreach consent-request sent, no
 * reply) before the outreach:recompute-no-response command lapses them to a
 * no_response opt-out. Default 7 (Johan's doctrine). Mirrors the existing
 * buyer_*_days windows on this table — resolved via AgencyContactSettings::
 * forAgency() and editable in the Contact Governance settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('outreach_no_response_days')->default(7)->after('buyer_lost_days');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('outreach_no_response_days');
        });
    }
};
