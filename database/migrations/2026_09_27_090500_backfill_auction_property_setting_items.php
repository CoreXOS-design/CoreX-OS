<?php

use App\Models\PropertySettingItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-432 — companion to the two new PropertySettingItem groups
 * (auction_type, auction_lot_status). Follows the AT-402 pattern verbatim
 * (see 2026_09_10_220000_backfill_furnished_status_property_settings.php):
 * every existing agency gets the default rows for both new groups; every
 * agency created from now on gets them automatically via AgencyObserver,
 * which already calls PropertySettingItem::provisionDefaultsFor() with no
 * group filter — no observer change needed.
 *
 * Idempotent, safe to re-run — same guarantee as 2026_08_20_000004 /
 * 2026_09_10_220000.
 */
return new class extends Migration
{
    public function up(): void
    {
        $total    = 0;
        $agencies = 0;
        $groups   = [PropertySettingItem::GROUP_AUCTION_TYPE, PropertySettingItem::GROUP_AUCTION_LOT_STATUS];

        DB::table('agencies')->orderBy('id')->select('id')->chunk(200, function ($rows) use (&$total, &$agencies, $groups) {
            foreach ($rows as $row) {
                $inserted = PropertySettingItem::provisionDefaultsFor((int) $row->id, $groups);
                if ($inserted > 0) {
                    $agencies++;
                    $total += $inserted;
                }
            }
        });

        if ($total > 0) {
            echo "  AT-432: seeded {$total} default auction_type/auction_lot_status items across {$agencies} agencies.\n";
        }
    }

    public function down(): void
    {
        // Deliberately irreversible — same reasoning as 2026_08_20_000004's
        // down(): these rows are indistinguishable from ones an agency has
        // since adopted, renamed or reordered once seeded.
    }
};
