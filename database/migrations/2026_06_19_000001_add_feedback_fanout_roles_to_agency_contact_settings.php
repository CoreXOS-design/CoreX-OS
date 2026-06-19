<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-66 §3 — Agency-configurable feedback fan-out role map.
 *
 * Per-property viewing feedback fans out to a property's connected
 * contacts in two directions. WHICH roles count for each direction must
 * be agency-configurable, never hardcoded (BUILD_STANDARD / "Settings
 * First"). These four JSON columns live on the per-agency
 * agency_contact_settings singleton (AgencyContactSettings::forAgency).
 *
 *  - feedback_seller_roles  : contact_property.role values that are the
 *                             seller fan-out target (seller direction).
 *  - feedback_buyer_source  : calendar_event_links roles (as
 *                             "attendee:<role>") that are the buyer
 *                             fan-out target (buyer direction).
 *  - feedback_lessor_roles  : future rentals — landlord/lessor roles.
 *  - feedback_lessee_source : future rentals — lessee attendee source.
 *
 * Nullable: a NULL column means "use the code defaults"
 * (AgencyContactSettings::fanoutDefaults), so existing rows that predate
 * this migration resolve correctly without a data backfill.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->json('feedback_seller_roles')->nullable()->after('access_log_retention_years');
            $table->json('feedback_buyer_source')->nullable()->after('feedback_seller_roles');
            $table->json('feedback_lessor_roles')->nullable()->after('feedback_buyer_source');
            $table->json('feedback_lessee_source')->nullable()->after('feedback_lessor_roles');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn([
                'feedback_seller_roles',
                'feedback_buyer_source',
                'feedback_lessor_roles',
                'feedback_lessee_source',
            ]);
        });
    }
};
