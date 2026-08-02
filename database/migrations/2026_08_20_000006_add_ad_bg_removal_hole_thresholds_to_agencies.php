<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad Manager "Remove background" — agency-configurable hole-fill guard
 * thresholds (ad-manager.md §15.1 round 4).
 *
 * `_fillEnclosedHoles()` fills small enclosed backdrop-coloured pockets
 * (earrings, small clothing gaps) but must never fill a LARGE enclosed
 * region (a genuine patch of shirt/collar). Round 3 shipped with only a
 * floor (`ad_bg_removal_hole_min_px`); a different agent photo's pose (arms
 * crossed, open V-collar fully enclosed by a dark jacket) proved a floor
 * alone isn't enough — nothing capped how big a "hole" was allowed to be,
 * so a large genuine collar/shirt area got erased.
 *
 * All three thresholds are nullable — null means "use the kernel's
 * evidence-based default" (30 / 1200 / 45px respectively, at the fixed
 * 500px working resolution every photo is downscaled to before
 * processing). These are deliberately NOT surfaced in the Agency Onboarding
 * Setup Wizard (non-negotiable #10a carve-out) — an expert, rarely-touched
 * pixel-tuning knob for a specific photo-processing edge case, not a
 * business-relevant setting an agency needs walking through at setup. See
 * ad-manager.md §15.1 round 4 "Deliberately NOT in the wizard" note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'ad_bg_removal_hole_min_px')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedInteger('ad_bg_removal_hole_min_px')->nullable()->after('assistants_enabled');
            $table->unsignedInteger('ad_bg_removal_hole_max_px')->nullable()->after('ad_bg_removal_hole_min_px');
            $table->unsignedInteger('ad_bg_removal_hole_max_dimension_px')->nullable()->after('ad_bg_removal_hole_max_px');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'ad_bg_removal_hole_min_px')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn([
                'ad_bg_removal_hole_min_px',
                'ad_bg_removal_hole_max_px',
                'ad_bg_removal_hole_max_dimension_px',
            ]);
        });
    }
};
