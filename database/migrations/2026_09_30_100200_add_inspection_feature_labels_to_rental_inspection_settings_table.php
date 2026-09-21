<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-20: "we have a features list in settings somewhere. should
 * we expand it that we tick which features are allowed on inspections? I
 * think its the better shape than relying on the system to do it
 * automatically and get it right." A rule could not reliably tell "Built-in
 * Cupboards" (a feature AND an inspection item) from "Investment" (neither);
 * only the agency knows which of its own features are physical things
 * somebody inspects.
 *
 * Deliberately NOT a redesign of the existing property feature catalog
 * (config('property-spaces.feature_categories') — every label there is
 * keyed on by Property24/Private Property syndication's FEATURE_TAG_MAP and
 * read by the mobile API and AI vision vocabulary; renaming or restructuring
 * that list would silently break portal syndication for the affected
 * features). This is a purely additive, agency-scoped OVERLAY: which of the
 * EXISTING, unchanged feature labels this agency wants carried into an
 * inspection checklist when one is seeded from a property's advertising
 * features. JSON so it stores a flat array of the catalog's own label
 * strings; null resolves to a neutral, agency-editable default (never
 * hardcoded), same read-time-default pattern as refusal_reason_presets
 * immediately above this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('inspection_feature_labels')->nullable()->after('refusal_reason_presets');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('inspection_feature_labels');
        });
    }
};
