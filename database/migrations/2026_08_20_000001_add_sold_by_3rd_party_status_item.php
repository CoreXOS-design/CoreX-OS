<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-350 — "Sold by 3rd Party" listing status.
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §4.1
 *
 * `property_setting_items` is STRICTLY AGENCY-SCOPED — `agency_id` is NOT NULL
 * with no default and an FK to `agencies` (see
 * 2026_05_23_081000_add_agency_id_to_property_setting_items_table, which
 * backfilled the originally-nullable column and then hard-locked it). There is no
 * such thing as a global/shared row in this table: an `agency_id => null` insert
 * dies with "Column 'agency_id' cannot be null", and a duplicate check that omits
 * the agency lets one tenant's row suppress the insert for another. The same trap
 * is documented at DemoDataSeeder::backfillPropertyStatusItems().
 *
 * So this provisions ONE ROW PER AGENCY, and derives the agency list from the
 * tenants that already carry property_status items rather than from `agencies`.
 * That does two things at once: it guarantees every agency_id is FK-valid, and it
 * skips agencies that have never configured property statuses — handing such a
 * tenant a dropdown containing nothing but "Sold by 3rd Party" would be worse
 * than the empty list they have today.
 *
 * NAME CHOICE IS LOAD-BEARING. The Status dropdown slugs the item name with
 * strtolower(str_replace(' ', '_', $name)) — see
 * resources/views/corex/properties/show.blade.php. "Sold by 3rd Party" slugs to
 * exactly `sold_by_3rd_party`, which is Property::STATUS_SOLD_BY_3RD_PARTY. No
 * bullets, no em-dashes, no punctuation: migration 2026_03_30_100001 exists
 * precisely because "For Sale • Reduced Price" slugged to
 * `for_sale_•_reduced_price`.
 *
 * sort_order 6 = the same slot as 'Sold'; PropertySettingItem::scopeGroup orders
 * by sort_order then name, so the pair reads "Sold", "Sold by 3rd Party" —
 * adjacent, which is where an agent looks for it.
 *
 * Provisioned by MIGRATION BACKFILL, not a seeder: seeders do not run on a
 * `git pull` deploy, so a seeded-only row silently fails to reach live
 * (BUILD_STANDARD §8 / AT-162 — the "Private" calendar type).
 *
 * KNOWN, PRE-EXISTING GAP (not introduced here, not fixed here): nothing in CoreX
 * provisions default property statuses for an agency created AFTER this
 * migration — the original set came from 2026_03_05_300003 and there is no
 * agency-creation hook. A new tenant therefore starts with no statuses at all,
 * this one included, and configures them under Settings → Properties. Closing
 * that gap is a platform-wide change, well outside AT-350; raised in the spec.
 */
return new class extends Migration
{
    private const GROUP = 'property_status';
    private const NAME  = 'Sold by 3rd Party';

    public function up(): void
    {
        // Every agency that actually uses property statuses. Sourced from the
        // table itself so the FK can never be violated.
        $agencyIds = DB::table('property_setting_items')
            ->where('group', self::GROUP)
            ->distinct()
            ->pluck('agency_id');

        foreach ($agencyIds as $agencyId) {
            if ($agencyId === null) {
                continue;
            }

            // Idempotent per tenant — mirrors 2026_03_05_300003. Soft-deleted rows
            // do not count as present: an agency that archived the item and later
            // re-runs the migration should get it back.
            $exists = DB::table('property_setting_items')
                ->where('agency_id', $agencyId)
                ->where('group', self::GROUP)
                ->where('name', self::NAME)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('property_setting_items')->insert([
                'agency_id'  => $agencyId,
                'group'      => self::GROUP,
                'name'       => self::NAME,
                'sort_order' => 6,
                'is_default' => 1,
                'active'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only the rows this migration owns (is_default = 1). An agency that
        // renamed or re-created its own copy keeps it — a rollback does not
        // delete tenant-authored data.
        DB::table('property_setting_items')
            ->where('group', self::GROUP)
            ->where('name', self::NAME)
            ->where('is_default', 1)
            ->delete();
    }
};
