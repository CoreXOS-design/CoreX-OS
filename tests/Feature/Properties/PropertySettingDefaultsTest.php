<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertySettingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-352 — every agency gets the default property settings.
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §4.1a
 *
 * Before this, nothing provisioned property settings for an agency created after
 * the one-off migration backfills, so a new tenant opened Properties to empty
 * required dropdowns and could not capture a listing.
 *
 * The load-bearing rule under test: seeding is PER GROUP and only when the group
 * is EMPTY. An agency that curated its own vocabulary must never have a deleted
 * or renamed choice silently reinstated by a deploy.
 */
final class PropertySettingDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_newly_created_agency_receives_every_default_group(): void
    {
        $agency = Agency::create([
            'name' => 'Coastal Realty ' . Str::random(5),
            'slug' => 'coastal-' . Str::random(8),
        ]);

        foreach (array_keys(PropertySettingItem::DEFAULT_ROWS) as $group) {
            $count = DB::table('property_setting_items')
                ->where('agency_id', $agency->id)
                ->where('group', $group)
                ->count();

            $this->assertSame(
                count(PropertySettingItem::DEFAULT_ROWS[$group]),
                $count,
                "A new agency must receive the full [{$group}] default set."
            );
        }
    }

    public function test_a_new_agency_can_select_sold_by_3rd_party_out_of_the_box(): void
    {
        $agency = Agency::create([
            'name' => 'Seaside Property ' . Str::random(5),
            'slug' => 'seaside-' . Str::random(8),
        ]);

        $item = DB::table('property_setting_items')
            ->where('agency_id', $agency->id)
            ->where('group', 'property_status')
            ->where('name', 'Sold by 3rd Party')
            ->first();

        $this->assertNotNull($item, 'AT-350 must be available to agencies onboarded after it shipped.');

        // The dropdown slugs the name; the slug IS the stored status value, so
        // this is what actually binds the settings row to the code path.
        $this->assertSame(
            Property::STATUS_SOLD_BY_3RD_PARTY,
            strtolower(str_replace(' ', '_', $item->name))
        );
    }

    public function test_condition_levels_keep_their_adjustment_percentages(): void
    {
        $agency = Agency::create([
            'name' => 'Bluff Estates ' . Str::random(5),
            'slug' => 'bluff-' . Str::random(8),
        ]);

        // These drive the CMA Middle-band adjustment — a level seeded without its
        // percentage would silently value every property at the baseline.
        $baseline = DB::table('property_setting_items')
            ->where('agency_id', $agency->id)
            ->where('group', 'condition_level')
            ->where('name', PropertySettingItem::CONDITION_BASELINE_NAME)
            ->first();

        $exceptional = DB::table('property_setting_items')
            ->where('agency_id', $agency->id)
            ->where('group', 'condition_level')
            ->where('name', 'Exceptional')
            ->first();

        $this->assertNotNull($baseline);
        $this->assertEquals(0.00, (float) $baseline->adjustment_pct);
        $this->assertEquals(38.00, (float) $exceptional->adjustment_pct);
    }

    public function test_categories_keep_their_title_type(): void
    {
        $agency = Agency::create([
            'name' => 'Hibiscus Homes ' . Str::random(5),
            'slug' => 'hibiscus-' . Str::random(8),
        ]);

        // Comp-selection discipline: a vacant-land subject must never be compared
        // against sectional-title sales, and that hangs off this column.
        $residential = DB::table('property_setting_items')
            ->where('agency_id', $agency->id)
            ->where('group', 'category')
            ->where('name', 'Residential')
            ->value('title_type');

        $this->assertSame(PropertySettingItem::TITLE_FULL, $residential);
    }

    public function test_a_curated_group_is_never_reinstated(): void
    {
        $agencyId = $this->bareAgency();

        // This agency renamed one status and never wants "On Auction" back.
        DB::table('property_setting_items')->insert([
            'agency_id'  => $agencyId,
            'group'      => 'property_status',
            'name'       => 'On the Market',
            'sort_order' => 0,
            'is_default' => 0,
            'active'     => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inserted = PropertySettingItem::provisionDefaultsFor($agencyId);

        $statuses = DB::table('property_setting_items')
            ->where('agency_id', $agencyId)
            ->where('group', 'property_status')
            ->pluck('name');

        $this->assertSame(['On the Market'], $statuses->all(), 'A configured group must be left completely alone.');
        $this->assertNotContains('On Auction', $statuses->all());

        // The OTHER groups were empty, so they were still seeded — the skip is
        // per group, not all-or-nothing.
        $this->assertGreaterThan(0, $inserted);
        $this->assertSame(
            count(PropertySettingItem::DEFAULT_ROWS['mandate_type']),
            DB::table('property_setting_items')->where('agency_id', $agencyId)->where('group', 'mandate_type')->count()
        );
    }

    public function test_provisioning_is_idempotent(): void
    {
        $agencyId = $this->bareAgency();

        $first  = PropertySettingItem::provisionDefaultsFor($agencyId);
        $second = PropertySettingItem::provisionDefaultsFor($agencyId);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'A second deploy must insert nothing.');

        // And no duplicates crept in.
        $this->assertSame(
            count(PropertySettingItem::DEFAULT_ROWS['property_status']),
            DB::table('property_setting_items')
                ->where('agency_id', $agencyId)->where('group', 'property_status')->count()
        );
    }

    public function test_it_refuses_to_stamp_settings_without_an_agency(): void
    {
        // Rule 17 — a resolved-null agency is never given a sentinel tenant.
        $this->assertSame(0, PropertySettingItem::provisionDefaultsFor(0));
        $this->assertSame(0, DB::table('property_setting_items')->where('agency_id', 0)->count());
    }

    public function test_the_backfill_migration_covers_a_pre_existing_agency(): void
    {
        $bare     = $this->bareAgency();
        $migration = require database_path('migrations/2026_08_20_000004_backfill_default_property_settings_per_agency.php');

        $migration->up();

        $this->assertSame(
            count(PropertySettingItem::DEFAULT_ROWS['property_status']),
            DB::table('property_setting_items')
                ->where('agency_id', $bare)->where('group', 'property_status')->count()
        );

        // Re-running the deploy changes nothing.
        $migration->up();
        $this->assertSame(
            count(PropertySettingItem::DEFAULT_ROWS['property_status']),
            DB::table('property_setting_items')
                ->where('agency_id', $bare)->where('group', 'property_status')->count()
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * An agency row inserted WITHOUT the observer, standing in for one created
     * before AT-352 existed — which is exactly what the backfill has to fix.
     */
    private function bareAgency(): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name'       => 'Legacy Agency ' . Str::random(5),
            'slug'       => 'legacy-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
