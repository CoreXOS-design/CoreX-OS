<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad Manager "Remove background" — agency-configurable flood-fill drift cap
 * (ad-manager.md §15.1 round 5).
 *
 * Round 4 fixed enclosed backdrop pockets being over- or under-filled
 * (earrings vs. a large collar patch). This is a THIRD, distinct failure
 * shape found on a real photo: the MAIN border-seeded flood fill
 * (`_floodFillTransparent()`, rounds 1/2) marching out of genuine backdrop
 * and into a well-lit collar highlight, one small-enough-to-pass-tolerance
 * step at a time, because each individual pixel is within the flat colour
 * tolerance even though the CUMULATIVE drift from true backdrop keeps
 * climbing the whole way in.
 *
 * `ad_bg_removal_flood_fill_drift_cap_px` caps how far (in colour-distance
 * units) a flood-fill path is allowed to drift from the sampled backdrop
 * colour, at any single point along its chain back to a seed, before
 * propagation stops. Nullable — null means "use the kernel's evidence-based
 * default" (20px). Measured across 5 real photos: the 90th percentile of
 * this exact signal is 0 in EVERY photo (real backdrop is flat), so the cap
 * is invisible to ordinary backdrop removal and only engages on genuine
 * drift-into-fabric cases.
 *
 * Deliberately NOT surfaced in the Agency Onboarding Setup Wizard — same
 * non-negotiable #10a carve-out as the round-4 thresholds this column sits
 * alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'ad_bg_removal_flood_fill_drift_cap_px')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedInteger('ad_bg_removal_flood_fill_drift_cap_px')->nullable()
                ->after('ad_bg_removal_hole_max_dimension_px');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'ad_bg_removal_flood_fill_drift_cap_px')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('ad_bg_removal_flood_fill_drift_cap_px');
        });
    }
};
