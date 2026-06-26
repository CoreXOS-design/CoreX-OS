<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-105 — PDF Splitter destination-aware routing.
 *
 * The `document_types` catalogue is GLOBAL; per-agency doc-type config already
 * lives in `agency_document_type_compliance` (the compliance-required flag).
 * This adds the per-agency "Save To" destination config to the SAME table —
 * single source of truth, no new island.
 *
 * Both columns are NULLABLE on purpose: NULL means "use the grouping-derived
 * default" (grouping=contact → contact; property/shared/null → property),
 * resolved in AgencyComplianceDocTypeService. A non-null true/false is an
 * explicit agency choice. This keeps existing rows (mandate/fica/disclosure
 * created by the compliance backfill) on sensible defaults with zero data
 * migration, and lets an agency tick either, both, or neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_document_type_compliance', function (Blueprint $table) {
            $table->boolean('save_to_property')->nullable()->after('is_compliance_required');
            $table->boolean('save_to_contact')->nullable()->after('save_to_property');
        });
    }

    public function down(): void
    {
        Schema::table('agency_document_type_compliance', function (Blueprint $table) {
            $table->dropColumn(['save_to_property', 'save_to_contact']);
        });
    }
};
