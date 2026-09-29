<?php

use App\Models\PropertySettingItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-property-tab.md §5, Part 4 — companion to making
 * lease_type's option list agency-editable instead of two divergent
 * hardcoded Blade arrays. Follows the exact AT-352/AT-402 pattern verbatim
 * (see 2026_09_10_220000_backfill_furnished_status_property_settings.php):
 * every existing agency gets the default lease-type options for the new
 * 'lease_type' group; every agency created from now on gets it
 * automatically via AgencyObserver, which already calls
 * PropertySettingItem::provisionDefaultsFor() with no group filter — no
 * observer change needed.
 *
 * Idempotent, safe to re-run — same guarantee as its sibling migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $total    = 0;
        $agencies = 0;

        DB::table('agencies')->orderBy('id')->select('id')->chunk(200, function ($rows) use (&$total, &$agencies) {
            foreach ($rows as $row) {
                $inserted = PropertySettingItem::provisionDefaultsFor((int) $row->id, ['lease_type']);
                if ($inserted > 0) {
                    $agencies++;
                    $total += $inserted;
                }
            }
        });

        if ($total > 0) {
            echo "  rental-property-tab §5: seeded {$total} default 'lease_type' items across {$agencies} agencies.\n";
        }
    }

    public function down(): void
    {
        // Deliberately irreversible — same reasoning as the sibling
        // migrations' down(): these rows are indistinguishable from ones an
        // agency has since adopted, renamed or reordered once seeded.
    }
};
