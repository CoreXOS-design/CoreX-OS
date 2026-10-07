<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-448 — "Expired" listing status, pickable by agents.
 *
 * Spec: .ai/specs/at448-property-expiry.md §4.2
 *
 * `expired` has always been a SYSTEM status (the midnight mandates:expire
 * sweep writes it, Property::INACTIVE_STATUSES carries it) but it was never in
 * the agency's Property Statuses list, so an agent could not CHOOSE it and the
 * Properties page could not offer it as a tile. This provisions the status
 * item exactly the way AT-350 provisioned "Sold by 3rd Party":
 *
 * - `property_setting_items` is STRICTLY agency-scoped (agency_id NOT NULL,
 *   FK) — ONE ROW PER AGENCY, derived from the tenants that already carry
 *   property_status rows, so the FK can never be violated and an agency that
 *   has never configured statuses is not handed a one-item dropdown.
 * - Idempotent; a soft-deleted row does not count as present.
 * - NAME CHOICE IS LOAD-BEARING: the Status dropdown slugs the name with
 *   strtolower(str_replace(' ', '_', $name)); "Expired" → `expired`, the
 *   system value. No punctuation.
 * - sort_order 12 = the same slot as 'Withdrawn' (scopeGroup orders by
 *   sort_order then name), so the pair reads "Expired", "Withdrawn".
 * - MIGRATION BACKFILL, not a seeder: seeders never run on a `git pull`
 *   deploy (BUILD_STANDARD §8 / AT-162). New agencies get the row from
 *   PropertySettingItem::DEFAULT_ROWS (AT-352).
 */
return new class extends Migration
{
    private const GROUP = 'property_status';
    private const NAME  = 'Expired';

    public function up(): void
    {
        $agencyIds = DB::table('property_setting_items')
            ->where('group', self::GROUP)
            ->distinct()
            ->pluck('agency_id');

        foreach ($agencyIds as $agencyId) {
            if ($agencyId === null) {
                continue;
            }

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
                'sort_order' => 12,
                'is_default' => 1,
                'active'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only the rows this migration owns (is_default = 1) — never tenant-authored copies.
        DB::table('property_setting_items')
            ->where('group', self::GROUP)
            ->where('name', self::NAME)
            ->where('is_default', 1)
            ->delete();
    }
};
