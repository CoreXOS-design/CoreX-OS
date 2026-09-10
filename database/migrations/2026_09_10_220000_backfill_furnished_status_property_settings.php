<?php

use App\Models\PropertySettingItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-402 — companion to the schema migration adding `furnished_status` etc.
 * to `properties`. Follows the exact AT-352 pattern verbatim (see
 * 2026_08_20_000004_backfill_default_property_settings_per_agency.php):
 * every existing agency gets the default Unfurnished/Furnished/Part-Furnished
 * options for the new 'furnished_status' group; every agency created from now
 * on gets it automatically via AgencyObserver, which already calls
 * PropertySettingItem::provisionDefaultsFor() with no group filter — no
 * observer change needed, the moment the group exists in DEFAULT_ROWS every
 * new agency receives it.
 *
 * Restricted to the ['furnished_status'] group only: every other group
 * already exists per-agency and provisionDefaultsFor()'s own per-group
 * exists-check would skip them anyway, but naming the group explicitly here
 * avoids re-running that check five times for nothing on every agency.
 *
 * Idempotent, safe to re-run — same guarantee as 2026_08_20_000004.
 */
return new class extends Migration
{
    public function up(): void
    {
        $total    = 0;
        $agencies = 0;

        DB::table('agencies')->orderBy('id')->select('id')->chunk(200, function ($rows) use (&$total, &$agencies) {
            foreach ($rows as $row) {
                $inserted = PropertySettingItem::provisionDefaultsFor((int) $row->id, ['furnished_status']);
                if ($inserted > 0) {
                    $agencies++;
                    $total += $inserted;
                }
            }
        });

        if ($total > 0) {
            echo "  AT-402: seeded {$total} default 'furnished_status' items across {$agencies} agencies.\n";
        }
    }

    public function down(): void
    {
        // Deliberately irreversible — same reasoning as 2026_08_20_000004's
        // down(): these rows are indistinguishable from ones an agency has
        // since adopted, renamed or reordered once seeded.
    }
};
