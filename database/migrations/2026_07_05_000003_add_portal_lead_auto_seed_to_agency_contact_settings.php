<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyer-lifecycle loop — agency toggle: when a portal lead (or a manual capture
     * tied to a listing) arrives, auto-seed a criteria-bearing buyer wishlist derived
     * from the enquired property so the buyer lands on the pipeline + feeds MIC demand.
     * Default ON. When OFF, portal leads still create the contact/inbox row exactly as
     * before — only the auto-seed cascade is suppressed.
     */
    public function up(): void
    {
        if (Schema::hasColumn('agency_contact_settings', 'portal_lead_auto_seed_buyer')) {
            return;
        }

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->boolean('portal_lead_auto_seed_buyer')
                ->default(true)
                ->after('warn_on_held_address_capture');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agency_contact_settings', 'portal_lead_auto_seed_buyer')) {
            return;
        }

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('portal_lead_auto_seed_buyer');
        });
    }
};
