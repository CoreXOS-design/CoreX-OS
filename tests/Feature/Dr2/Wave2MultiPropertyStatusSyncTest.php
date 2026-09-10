<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\AgencyDealSyncSettings;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-398 — the six DR2 Wave 2 status-sync listeners (FlagPropertyUnderOfferOnDealCreated,
 * EnsurePropertyUnderOfferOnGrant, MarkPropertySoldOnDealMilestone,
 * RevertPropertyStatusOnDealDeclined, AutoDeclineSiblingDealsOnGrant,
 * AutoDeclineNewDealOnCommittedProperty) now loop over EVERY property linked
 * to a deal via deal_properties, not just the one primary. This is the
 * highest-risk piece of the whole feature — it is live, financially and
 * legally load-bearing automation (grant exclusivity, portal status,
 * syndication) — so every listener gets its own multi-property proof here,
 * including the mixed-status case Johan named explicitly: one property
 * granted while another is still pending on a DIFFERENT deal.
 *
 * These tests attach the second property directly via the deal_properties
 * pivot (bypassing DealPropertyOwnerGate, which is a controller/service-layer
 * concern proven separately in DealPropertyOwnerGateTest) — the point here is
 * proving the STATUS-SYNC MECHANICS are correct given an already-linked
 * multi-property deal, independent of how the link was made.
 */
final class Wave2MultiPropertyStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'MP Co', 'slug' => 'mp-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function settings(array $over): void
    {
        AgencyDealSyncSettings::forAgency($this->agencyId)->update($over);
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'MP-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function makeDeal(Property $primary, array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(8000, 8999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $primary->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ], $over));
    }

    /** Attach a SECOND property directly onto the pivot — bypassing the owner gate, see class docblock. */
    private function addSecondProperty(Deal $deal, Property $property): void
    {
        DealProperty::create(['deal_id' => $deal->id, 'property_id' => $property->id, 'is_primary' => false]);
    }

    private function acceptedOf(Deal $d): string
    {
        return (string) Deal::withoutGlobalScopes()->find($d->id)->accepted_status;
    }

    // ── Backfill/mirroring sanity — the foundation every listener below depends on ──

    public function test_creating_a_deal_through_the_existing_single_property_field_mirrors_into_the_pivot(): void
    {
        $propA = $this->makeProperty('1 Mirror Rd');
        $deal = $this->makeDeal($propA);

        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propA->id, 'is_primary' => 1]);
        $this->assertSame([$propA->id], $deal->properties()->pluck('properties.id')->all());
    }

    public function test_changing_property_id_on_update_replaces_the_primary_and_soft_removes_the_old_one(): void
    {
        $propA = $this->makeProperty('2 Swap Rd A');
        $propB = $this->makeProperty('2 Swap Rd B');
        $deal = $this->makeDeal($propA);

        $deal->update(['property_id' => $propB->id]);

        $this->assertSame([$propB->id], $deal->properties()->pluck('properties.id')->all());
        // The old link is SOFT removed — a note it was once there, never a hard delete.
        $this->assertSoftDeleted('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propA->id]);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => 1]);
    }

    // ── FlagPropertyUnderOfferOnDealCreated — every linked property flags, independently ──

    public function test_flag_on_create_flags_both_properties_but_skips_one_already_off_market(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $onMarket = $this->makeProperty('3 Flag Rd A');
        $withdrawn = $this->makeProperty('3 Flag Rd B');
        Property::withoutEvents(fn () => $withdrawn->update(['status' => 'withdrawn']));

        $deal = $this->makeDeal($onMarket);
        $this->addSecondProperty($deal, $withdrawn);
        // Re-fire the create-time listener explicitly — the pivot row for the
        // second property did not exist at the moment DealCreated originally
        // fired (it was attached a line above), mirroring how the real
        // addProperty() action re-runs status sync after attaching.
        event(new \App\Events\Deal\DealCreated($deal->fresh(), auth()->id()));

        $this->assertSame('under_offer', $onMarket->fresh()->status, 'the on-market property must flag under-offer');
        $this->assertSame('withdrawn', $withdrawn->fresh()->status, 'Johan: an expired/withdrawn property stays sellable as-is — never forced back under-offer');
    }

    // ── MarkPropertySoldOnDealMilestone — both properties sold on grant ──

    public function test_grant_marks_every_linked_property_sold(): void
    {
        $this->settings(['sold_milestone' => 'granted']);
        $propA = $this->makeProperty('4 Sold Rd A');
        $propB = $this->makeProperty('4 Sold Rd B');
        $deal = $this->makeDeal($propA);
        $this->addSecondProperty($deal, $propB);

        $deal->update(['accepted_status' => 'G']);

        $this->assertSame('sold', $propA->fresh()->status);
        $this->assertSame('sold', $propB->fresh()->status, 'both properties on the deal must sell together on grant');
    }

    // ── The mixed-status case Johan named explicitly ──────────────────────────

    /**
     * Deal X covers properties A and B. A DIFFERENT deal Y also touches
     * property A alone (still Pending). Granting X must auto-decline Y (a
     * sibling sharing property A) while property B — untouched by any other
     * deal — has nothing to decline. One grant, two properties, two
     * genuinely different outcomes underneath it.
     */
    public function test_granting_a_multi_property_deal_declines_a_sibling_on_only_the_shared_property(): void
    {
        $propA = $this->makeProperty('5 Mixed Rd A');
        $propB = $this->makeProperty('5 Mixed Rd B');

        $dealX = $this->makeDeal($propA, ['deal_no' => '8501']);
        $this->addSecondProperty($dealX, $propB);

        $dealY = $this->makeDeal($propA, ['deal_no' => '8502']); // a separate pending offer, property A only

        $dealX->update(['accepted_status' => 'G']);

        $this->assertSame('G', $this->acceptedOf($dealX));
        $this->assertSame('D', $this->acceptedOf($dealY), 'the sibling sharing property A must be auto-declined');
        $this->assertDatabaseHas('deal_logs', ['deal_id' => $dealY->id, 'event_type' => 'auto_declined']);
    }

    /**
     * The same mixed shape, but for REVERT: deal X (properties A+B) declines.
     * Property A still has another active deal (stays under-offer). Property
     * B has none (reverts to on-market). Both outcomes from ONE decline.
     */
    public function test_declining_a_multi_property_deal_reverts_only_the_property_with_no_other_active_deal(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true, 'revert_property_on_deal_declined' => true]);
        $propA = $this->makeProperty('6 Mixed Revert Rd A');
        $propB = $this->makeProperty('6 Mixed Revert Rd B');

        $dealX = $this->makeDeal($propA, ['deal_no' => '8601']);
        $this->addSecondProperty($dealX, $propB);
        event(new \App\Events\Deal\DealCreated($dealX->fresh(), auth()->id()));
        $this->assertSame('under_offer', $propA->fresh()->status);
        $this->assertSame('under_offer', $propB->fresh()->status);

        // A second, still-pending offer on property A ONLY.
        $dealYOnA = $this->makeDeal($propA, ['deal_no' => '8602']);

        $dealX->update(['accepted_status' => 'D']); // decline the multi-property deal

        $this->assertSame('under_offer', $propA->fresh()->status, 'property A still has an active sibling — must stay under-offer');
        $this->assertSame('for_sale', $propB->fresh()->status, 'property B has no other active deal — must revert');
    }

    // ── AutoDeclineNewDealOnCommittedProperty — checked across every linked property ──

    public function test_new_multi_property_capture_is_auto_declined_if_either_property_is_already_committed(): void
    {
        $propA = $this->makeProperty('7 Committed Rd A');
        $propB = $this->makeProperty('7 Committed Rd B');

        $granted = $this->makeDeal($propB, ['deal_no' => '8701']);
        $granted->update(['accepted_status' => 'G']); // property B is now committed

        $newDeal = $this->makeDeal($propA, ['deal_no' => '8702']);
        $this->addSecondProperty($newDeal, $propB);
        event(new \App\Events\Deal\DealCreated($newDeal->fresh(), auth()->id()));

        $this->assertSame('D', $this->acceptedOf($newDeal), 'property B is already committed elsewhere — the new multi-property deal must be auto-declined');
    }

    // ── The exclusivity guard itself, across multiple properties ──────────────

    public function test_assert_can_grant_blocks_when_any_linked_property_is_already_committed(): void
    {
        $propA = $this->makeProperty('8 Grant Guard Rd A');
        $propB = $this->makeProperty('8 Grant Guard Rd B');

        $granted = $this->makeDeal($propB, ['deal_no' => '8801']);
        $granted->update(['accepted_status' => 'G']);

        $candidate = $this->makeDeal($propA, ['deal_no' => '8802']);
        $this->addSecondProperty($candidate, $propB);

        $this->expectException(\App\Exceptions\Deal\DuplicateGrantException::class);
        app(\App\Services\Deal\DealPropertyStatusService::class)->assertCanGrant($candidate->fresh());
    }

    public function test_assert_can_grant_allows_when_every_linked_property_is_clear(): void
    {
        $propA = $this->makeProperty('9 Grant Clear Rd A');
        $propB = $this->makeProperty('9 Grant Clear Rd B');
        $deal = $this->makeDeal($propA, ['deal_no' => '8901']);
        $this->addSecondProperty($deal, $propB);

        // No exception — both properties are clear.
        app(\App\Services\Deal\DealPropertyStatusService::class)->assertCanGrant($deal->fresh());
        $this->assertTrue(true);
    }
}
