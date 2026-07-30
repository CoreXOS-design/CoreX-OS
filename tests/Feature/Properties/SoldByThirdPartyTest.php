<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\PropertyThirdPartySale;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-350 — "Sold by 3rd Party".
 *
 * Spec: .ai/specs/property-sold-by-third-party.md
 *
 * Covers the BUILD_STANDARD §8 input matrix, not the happy path alone:
 * all-fields, each-optional-field-omitted-individually, the lazy-but-valid
 * shortcut (status dropdown, zero fields), one malformed input per validated
 * field, the deleted-related-record path, and idempotency.
 *
 * Test data is real KZN South Coast stock — real suburbs, real competitor names,
 * real ZAR values — because clean-world fixtures produce clean-world tests
 * (BUILD_STANDARD §5).
 */
final class SoldByThirdPartyTest extends TestCase
{
    use RefreshDatabase;

    // ── The status itself ───────────────────────────────────────────────────

    public function test_the_migration_provisions_the_status_per_agency_and_is_idempotent(): void
    {
        // property_setting_items is STRICTLY agency-scoped: agency_id is NOT NULL
        // with an FK, so there is no such thing as a global row here. The
        // migration must therefore provision ONE ROW PER AGENCY — and must not
        // let one tenant's row suppress another's.
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();
        $agencyC = $this->makeAgency();

        $this->seedStatusItems($agencyA);
        $this->seedStatusItems($agencyB);
        // Agency C has never configured property statuses. Handing it a dropdown
        // containing nothing but "Sold by 3rd Party" would be worse than the empty
        // list it has today, so the migration must skip it.

        // Invoke the real migration directly. A fresh test DB has no agencies at
        // migrate time, so `migrate` legitimately provisions nothing — the only
        // honest way to prove the deploy path is to run it against tenants that
        // exist, exactly as it will run on live.
        $migration = require database_path('migrations/2026_08_20_000001_add_sold_by_3rd_party_status_item.php');
        $migration->up();

        foreach ([$agencyA, $agencyB] as $agencyId) {
            $item = DB::table('property_setting_items')
                ->where('agency_id', $agencyId)
                ->where('group', 'property_status')
                ->where('name', 'Sold by 3rd Party')
                ->first();

            $this->assertNotNull($item, "Agency #{$agencyId} must receive the status.");
            $this->assertEquals(1, $item->active);

            // The Status dropdown slugs the name this way, and the slug IS the
            // stored status value — a rename that breaks it breaks the feature.
            $this->assertSame(
                Property::STATUS_SOLD_BY_3RD_PARTY,
                strtolower(str_replace(' ', '_', $item->name))
            );
        }

        $this->assertDatabaseMissing('property_setting_items', [
            'agency_id' => $agencyC,
            'name'      => 'Sold by 3rd Party',
        ]);

        // Re-running the deploy must not duplicate the row.
        $migration->up();
        $this->assertSame(2, DB::table('property_setting_items')
            ->where('name', 'Sold by 3rd Party')->count());
    }

    public function test_status_badge_reads_sold_by_3rd_party_and_never_for_sale(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '14 Marine Drive, Margate');

        $property->status = Property::STATUS_SOLD_BY_3RD_PARTY;

        // The regression this guards: statusBadge()'s 'sold' arm is an exact-match
        // in_array, so without a dedicated arm this value falls through every arm
        // to the default and badges a SOLD property "For Sale".
        $this->assertSame('Sold by 3rd Party', $property->statusBadge());
        $this->assertNotSame('For Sale', $property->statusBadge());

        $this->assertFalse($property->isOnMarket());
        $this->assertTrue($property->isConcluded());
        $this->assertTrue($property->isSoldByThirdParty());
    }

    public function test_status_helper_tolerates_the_vocabulary_variants(): void
    {
        // properties.status is genuinely mixed-case and mixed-separator in
        // production, and the P24 mapper normalises underscores to spaces before
        // it tests. A check that only knew the canonical slug would miss these.
        foreach (['sold_by_3rd_party', 'Sold by 3rd Party', 'SOLD BY 3RD PARTY', 'sold by third party'] as $variant) {
            $this->assertTrue(
                Property::isSoldByThirdPartyStatus($variant),
                "Variant [{$variant}] must resolve as a third-party sale."
            );
        }

        foreach (['sold', 'active', 'withdrawn', '', null] as $notThirdParty) {
            $this->assertFalse(Property::isSoldByThirdPartyStatus($notThirdParty));
        }
    }

    // ── Portals (spec D3) ───────────────────────────────────────────────────

    public function test_p24_receives_withdrawn_not_sold(): void
    {
        // Pushing 'Sold' would badge a COMPETITOR's sale as an HFC sale, and
        // removesFromPortal() treats 'Sold' as still-on-portal — so the advert
        // would never come down. Audit: p24-sold-not-delisted-2026-07-10.md.
        $p24Status = Property24ListingMapper::getP24Status(Property::STATUS_SOLD_BY_3RD_PARTY);

        $this->assertSame('Withdrawn', $p24Status);
        $this->assertTrue(Property24ListingMapper::removesFromPortal($p24Status));

        // Our own sale is unchanged — 'Sold', and P24 keeps showing it.
        $this->assertSame('Sold', Property24ListingMapper::getP24Status('sold'));
        $this->assertFalse(Property24ListingMapper::removesFromPortal('Sold'));
    }

    // ── Rich path — the happy path, all fields ──────────────────────────────

    public function test_rich_path_records_the_loss_and_writes_a_comp(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '8 Ambleside Road, Shelly Beach', 2_450_000);

        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.store', $property), [
                'sold_by_agency' => 'Seeff Margate',
                'sold_price'     => 2_150_000,
                'sold_date'      => now()->subDays(9)->toDateString(),
                'loss_reason'    => 'competitor_had_buyer',
                'notes'          => 'Buyer had been through our show day in March.',
            ])
            ->assertRedirect();

        $property->refresh();
        $this->assertSame(Property::STATUS_SOLD_BY_3RD_PARTY, $property->status);

        $record = $property->openThirdPartySale();
        $this->assertNotNull($record);
        $this->assertSame('Seeff Margate', $record->sold_by_agency);
        $this->assertEquals(2_150_000, (float) $record->sold_price);
        $this->assertSame('competitor_had_buyer', $record->loss_reason);

        // Snapshot of OUR position, so re-pricing the listing later cannot
        // rewrite history.
        $this->assertEquals(2_450_000, (float) $record->our_listing_price);
        $this->assertEquals(300_000, $record->priceGap());

        // The comp — a real market fact — flagged as somebody else's sale.
        $comp = DB::table('property_sold_records')->where('property_id', $property->id)->first();
        $this->assertNotNull($comp, 'Price + date supplied must produce a comp.');
        $this->assertEquals(1, $comp->sold_by_third_party);
        $this->assertSame('Seeff Margate', $comp->sold_by_agency);
        $this->assertSame((int) $comp->id, (int) $record->sold_record_id);
    }

    // ── The lazy-but-valid shortcut — a first-class path ────────────────────

    public function test_status_dropdown_alone_creates_the_loss_record(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '22 Panorama Parade, Uvongo');

        // The agent picks the status in the Lifecycle dropdown and saves. Nothing
        // else. This MUST leave the same system state as the rich form, or we
        // have shipped two behaviours for one outcome.
        $property->status = Property::STATUS_SOLD_BY_3RD_PARTY;
        $property->save();

        $record = $property->fresh()->openThirdPartySale();
        $this->assertNotNull($record, 'The bare status change must still record the loss.');
        $this->assertNull($record->sold_by_agency);
        $this->assertNull($record->sold_price);

        // No price, no date → no comp. A half-row would pollute every suburb
        // median that reads property_sold_records.
        $this->assertDatabaseMissing('property_sold_records', ['property_id' => $property->id]);
    }

    public function test_capture_with_no_fields_at_all_is_accepted(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '5 Bazley Street, Port Shepstone');

        // "I only know that it sold." Every field optional (spec D4) — a required
        // field here would push the agent back to Withdrawn and lose the intel.
        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.store', $property), [])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($property->fresh()->openThirdPartySale());
        $this->assertSame(Property::STATUS_SOLD_BY_3RD_PARTY, $property->fresh()->status);
    }

    /**
     * Each optional field omitted INDIVIDUALLY — the empty paths, one at a time
     * (BUILD_STANDARD §5). A full-payload-only test would miss a single field
     * that breaks when absent.
     *
     * @dataProvider omittedFieldProvider
     */
    public function test_each_optional_field_may_be_omitted_individually(string $omit): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, "31 Outlook Road, Ramsgate ({$omit} omitted)");

        $payload = [
            'sold_by_agency' => 'Pam Golding Uvongo',
            'sold_price'     => 1_795_000,
            'sold_date'      => now()->subMonth()->toDateString(),
            'loss_reason'    => 'priced_lower',
            'notes'          => 'Seller took a cash offer.',
        ];
        unset($payload[$omit]);

        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.store', $property), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record = $property->fresh()->openThirdPartySale();
        $this->assertNotNull($record);
        $this->assertNull($record->{$omit}, "Omitted [{$omit}] must persist as null, not a crash or a default.");

        // A comp needs BOTH price and date; dropping either must suppress it
        // without failing the action.
        $expectComp = ! in_array($omit, ['sold_price', 'sold_date'], true);
        $this->assertSame(
            $expectComp,
            DB::table('property_sold_records')->where('property_id', $property->id)->exists(),
            $expectComp ? 'A priced+dated loss must produce a comp.' : 'An unpriced or undated loss must NOT produce a comp.'
        );
    }

    public static function omittedFieldProvider(): array
    {
        return [
            'no competitor name' => ['sold_by_agency'],
            'no price'           => ['sold_price'],
            'no date'            => ['sold_date'],
            'no reason'          => ['loss_reason'],
            'no notes'           => ['notes'],
        ];
    }

    // ── Malformed input — one per validated field ───────────────────────────

    /** @dataProvider malformedInputProvider */
    public function test_malformed_input_is_rejected_with_a_clear_message(string $field, mixed $value): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '77 Marine Drive, Southbroom');

        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.store', $property), [$field => $value])
            ->assertSessionHasErrors($field);

        // Prevent means prevent: nothing was written and the listing did not move.
        $this->assertDatabaseCount('property_third_party_sales', 0);
        $this->assertSame('active', $property->fresh()->status);
    }

    public static function malformedInputProvider(): array
    {
        return [
            'sale dated tomorrow'   => ['sold_date', '2099-01-01'],
            'negative price'        => ['sold_price', -50_000],
            'price with an extra 0' => ['sold_price', 99_000_000_000],
            'price as free text'    => ['sold_price', 'two million'],
            'unknown loss reason'   => ['loss_reason', 'they_were_just_better'],
            'agency name too long'  => ['sold_by_agency', 'A repeated ' . str_repeat('x', 250)],
        ];
    }

    public function test_whitespace_only_competitor_name_is_stored_as_null(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '3 Erasmus Drive, Uvongo');

        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.store', $property), [
                'sold_by_agency' => '   ',
            ])->assertRedirect();

        // Otherwise the Loss Analysis report grows a competitor called "   ".
        $this->assertNull($property->fresh()->openThirdPartySale()->sold_by_agency);
    }

    // ── Idempotency and guards ──────────────────────────────────────────────

    public function test_recording_twice_does_not_duplicate_the_loss_record(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '19 Bosbok Road, Margate');

        $payload = ['sold_by_agency' => 'RE/MAX Coastal', 'sold_price' => 1_250_000, 'sold_date' => now()->subWeek()->toDateString()];

        $this->actingAs($agent)->post(route('corex.properties.third-party-sale.store', $property), $payload);
        $this->actingAs($agent)->post(route('corex.properties.third-party-sale.store', $property), $payload);

        $this->assertSame(1, PropertyThirdPartySale::where('property_id', $property->id)->count());
        $this->assertSame(1, DB::table('property_sold_records')->where('property_id', $property->id)->count());
    }

    public function test_a_listing_we_already_sold_cannot_be_recorded_as_theirs(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '12 Lagoon Drive, Uvongo');
        $property->status = 'sold';
        $property->save();

        // Overwriting a real HFC sale would erase the basis of a commission
        // record. Refuse in plain language rather than silently proceeding.
        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.store', $property), ['sold_by_agency' => 'Seeff Margate'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('property_third_party_sales', 0);
        $this->assertSame('sold', $property->fresh()->status);
    }

    public function test_relisting_keeps_the_loss_record_and_stamps_reverted_at(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '44 Ridge Road, Shelly Beach');

        $this->actingAs($agent)->post(route('corex.properties.third-party-sale.store', $property), [
            'sold_by_agency' => 'Seeff Margate',
            'sold_price'     => 1_995_000,
            'sold_date'      => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($agent)
            ->post(route('corex.properties.third-party-sale.revert', $property))
            ->assertRedirect();

        $property->refresh();
        $this->assertNull($property->openThirdPartySale(), 'The record must no longer be OPEN after a re-list.');

        // Kept, never deleted — losing the loss history is the failure this
        // feature exists to fix. The comp survives too: the sale really happened.
        $record = PropertyThirdPartySale::where('property_id', $property->id)->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->reverted_at);
        $this->assertDatabaseHas('property_sold_records', ['property_id' => $property->id]);
    }

    // ── Never credited to HFC ───────────────────────────────────────────────

    public function test_it_is_excluded_from_the_sold_kpi_and_counted_as_off_market(): void
    {
        [$agencyId, $admin] = $this->agencyWithAgent('admin');
        $ours   = $this->property($agencyId, $admin, '2 Compensation Beach Road, Margate');
        $theirs = $this->property($agencyId, $admin, '6 Boyes Lane, Margate');

        $ours->status = 'sold';
        $ours->save();
        $theirs->status = Property::STATUS_SOLD_BY_3RD_PARTY;
        $theirs->save();

        $stats = $this->actingAs($admin)
            ->get(route('corex.properties.index'))
            ->assertOk()
            ->viewData('stats');

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['sold'], 'Only OUR sale may count in the Sold KPI.');
        $this->assertSame(0, $stats['active'], 'Both are off-market, so neither is live stock.');
    }

    public function test_the_index_status_filter_isolates_third_party_sales(): void
    {
        [$agencyId, $admin] = $this->agencyWithAgent('admin');
        $this->property($agencyId, $admin, 'ZZZ-OurSale-Margate')->update(['status' => 'sold']);
        $this->property($agencyId, $admin, 'ZZZ-TheirSale-Margate')->update(['status' => Property::STATUS_SOLD_BY_3RD_PARTY]);

        $this->actingAs($admin)
            ->get(route('corex.properties.index', ['status' => Property::STATUS_SOLD_BY_3RD_PARTY]))
            ->assertOk()
            ->assertSee('ZZZ-TheirSale-Margate')
            ->assertDontSee('ZZZ-OurSale-Margate');
    }

    // ── The report ──────────────────────────────────────────────────────────

    public function test_loss_report_renders_and_survives_a_deleted_agent(): void
    {
        [$agencyId, $admin] = $this->agencyWithAgent('admin');
        $leaver   = $this->agencyUser($agencyId, 'agent');
        $property = $this->property($agencyId, $leaver, '9 Riverbend Close, Port Edward');

        $this->actingAs($admin)->post(route('corex.properties.third-party-sale.store', $property), [
            'sold_by_agency' => 'Harcourts South Coast',
            'sold_price'     => 3_100_000,
            'sold_date'      => now()->subDays(20)->toDateString(),
            'loss_reason'    => 'buyer_lost_to_competitor',
        ]);

        // The agent leaves the agency. A deleted related record must render
        // gracefully, never crash the report (BUILD_STANDARD §4).
        $leaver->delete();

        $this->actingAs($admin)
            ->get(route('corex.properties.reports.lost-to-competitors'))
            ->assertOk()
            ->assertSee('Harcourts South Coast')
            ->assertSee('Our buyer bought it through the other agency');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAgent(string $role = 'admin'): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, $this->agencyUser($agencyId, $role)];
    }

    private function agencyUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, User $agent, string $title, ?int $price = 1_950_000): Property
    {
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'suburb'        => 'Margate',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
            'price'         => $price,
        ]);
    }

    /**
     * The property statuses a real, configured agency carries. Mirrors the set
     * provisioned by 2026_03_05_300003 so the fixture matches live rather than a
     * clean-world invention (BUILD_STANDARD §5).
     */
    private function seedStatusItems(int $agencyId): void
    {
        foreach (['For Sale' => 1, 'Sold' => 6, 'Under Offer' => 7, 'Withdrawn' => 11] as $name => $sort) {
            DB::table('property_setting_items')->insert([
                'agency_id'  => $agencyId,
                'group'      => 'property_status',
                'name'       => $name,
                'sort_order' => $sort,
                'is_default' => 1,
                'active'     => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Home Finders ' . Str::random(6),
            'slug'       => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
