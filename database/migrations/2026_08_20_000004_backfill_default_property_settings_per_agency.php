<?php

use App\Models\PropertySettingItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-352 — every agency gets the default property settings.
 *
 * Companion to the AgencyObserver hook, which covers agencies created from now
 * on. This covers the ones that already exist and were never provisioned:
 * the original sets were one-off backfills (2026_03_05_300002 property_type,
 * 2026_03_05_300003 category/status/mandate, 2026_06_17_120000 condition_level)
 * and any agency created AFTER those ran got nothing — an empty Status, Type,
 * Category and Mandate dropdown, and no way to capture a listing.
 *
 * Safety is delegated to PropertySettingItem::provisionDefaultsFor(), which
 * seeds PER GROUP and only when that group is completely empty for the agency.
 * An agency that curated its own statuses — renamed one, deleted the auction
 * status it never uses — is left entirely alone. A deploy must never silently
 * reinstate a choice a tenant deliberately removed (SYSTEM.md §3: the agency
 * owns its own vocabulary).
 *
 * Complementary to 2026_08_20_000001 by construction: that one adds
 * "Sold by 3rd Party" to agencies that ALREADY have statuses; this one seeds the
 * full default set to agencies that have none. Between them every agency ends up
 * with the new status, and neither can overwrite tenant-authored data. Ordered
 * after 000001 so an agency seeded here is not then double-handled.
 *
 * Idempotent: a second run finds every group populated and inserts nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $total    = 0;
        $agencies = 0;

        // Chunked: this runs on every install including live, where the agency
        // count is small today but the query should not assume it.
        DB::table('agencies')->orderBy('id')->select('id')->chunk(200, function ($rows) use (&$total, &$agencies) {
            foreach ($rows as $row) {
                $inserted = PropertySettingItem::provisionDefaultsFor((int) $row->id);
                if ($inserted > 0) {
                    $agencies++;
                    $total += $inserted;
                }
            }
        });

        if ($total > 0) {
            echo "  AT-352: seeded {$total} default property setting items across {$agencies} agencies.\n";
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. These rows are indistinguishable from ones
        // an agency has since adopted, renamed or reordered, and a rollback that
        // deleted every is_default row would strip working agencies of the
        // vocabulary their live listings reference. Leaving them costs nothing;
        // deleting them breaks the Properties page.
    }
};
