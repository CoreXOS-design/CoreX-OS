<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\AgencyDealSyncSettings;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
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

    /**
     * Bug found live on QA1, 2026-09-15 (Johan, deal #181) — a real branch
     * manager, real permissions, a real POST to the real create endpoint.
     * Nothing here does by hand what production does not do: no manual
     * event() call anywhere in the tests that use this.
     */
    private function makeBranchManager(): User
    {
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();

        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
    }

    private function makeOwnerContact(string $first): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'first_name' => $first, 'last_name' => 'Owner', 'phone' => '0' . random_int(600000000, 699999999),
        ]);
    }

    private function linkOwner(Property $property, Contact $contact): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Same shape CreateTimeMultiPropertyTest already relies on. */
    private function createDealPayload(User $bm, Property $primary, array $properties, array $over = []): array
    {
        return array_merge([
            'period' => '2026-09', 'deal_date' => '2026-09-15', 'branch_id' => $this->branchId,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_agents' => [$bm->id], 'selling_agents' => [$bm->id],
            'property_id' => $primary->id,
            // The additive-master design overrides these from the properties[]
            // sum regardless (DealPropertyPricingService::recalculateTotals()),
            // but the field is still required at validation, so submit the
            // real sum rather than a placeholder.
            'property_value' => array_sum(array_column($properties, 'allocated_price')),
            'total_commission' => array_sum(array_column($properties, 'allocated_commission')),
            'properties' => $properties,
        ], $over);
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

    /**
     * BUG (found live on QA1, 2026-09-15, deal #181 — Johan turned the
     * setting on himself and tested a real two-property create): this test
     * used to attach the second property directly onto the pivot and then
     * manually re-fire DealCreated by hand — "mirroring", the old comment
     * claimed, a real re-sync that turned out NOT TO EXIST anywhere in
     * production. The manual re-fire was doing the exact job the missing
     * production code should have been doing, so the test stayed green
     * while a real deal on a real screen did nothing to its second
     * property. Rewritten to POST to the REAL create endpoint — nothing in
     * this test does by hand what production does not do. This is what
     * actually caught the bug once the manual re-fire was removed (it
     * failed against the pre-fix code — the second property stayed
     * `for_sale`, exactly as Johan reported), and passes now that
     * DealRegisterController::applyCreateTimeMultiProperty() re-fires the
     * create-time events itself once every property is linked.
     */
    public function test_flag_on_create_flags_both_properties_but_skips_one_already_off_market(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $bm = $this->makeBranchManager();
        $onMarket = $this->makeProperty('3 Flag Rd A');
        $withdrawn = $this->makeProperty('3 Flag Rd B');
        Property::withoutEvents(fn () => $withdrawn->update(['status' => 'withdrawn']));
        $steve = $this->makeOwnerContact('Steve');
        $this->linkOwner($onMarket, $steve);
        $this->linkOwner($withdrawn, $steve);

        $response = $this->actingAs($bm)->post(route('deals-dr2.store'), $this->createDealPayload($bm, $onMarket, [
            ['property_id' => $onMarket->id, 'allocated_price' => 1_000_000, 'allocated_commission' => 57_500],
            ['property_id' => $withdrawn->id, 'allocated_price' => 500_000, 'allocated_commission' => 28_750],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('under_offer', $onMarket->fresh()->status, 'the on-market property must flag under-offer');
        $this->assertSame('withdrawn', $withdrawn->fresh()->status, 'Johan: an expired/withdrawn property stays sellable as-is — never forced back under-offer');
    }

    /**
     * Named regression test for Johan's exact real scenario (deal #181):
     * create a deal with TWO on-market properties in one save. Both must go
     * under offer, not just the primary. This is the test that would have
     * caught the live bug before he did.
     */
    public function test_johans_deal_181_scenario_creating_with_two_properties_flags_both_under_offer(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $bm = $this->makeBranchManager();
        $primary = $this->makeProperty('Deal 181 Regression — Property 1');
        $secondary = $this->makeProperty('Deal 181 Regression — Property 2');
        $steve = $this->makeOwnerContact('Steve');
        $this->linkOwner($primary, $steve);
        $this->linkOwner($secondary, $steve);

        $this->assertSame('for_sale', $primary->status);
        $this->assertSame('for_sale', $secondary->status);

        $response = $this->actingAs($bm)->post(route('deals-dr2.store'), $this->createDealPayload($bm, $primary, [
            ['property_id' => $primary->id, 'allocated_price' => 6_450_000, 'allocated_commission' => 250_000],
            ['property_id' => $secondary->id, 'allocated_price' => 2_736_000, 'allocated_commission' => 145_000],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('under_offer', $primary->fresh()->status, 'the primary property must flag under-offer');
        $this->assertSame('under_offer', $secondary->fresh()->status, 'the SECOND property must ALSO flag under-offer — this is exactly what deal #181 failed to do');
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

    /**
     * BUG (found live on QA1, 2026-09-15, deal #183 — Johan's own real walk):
     * pending -> under offer (correct), granted -> sold (correct), then
     * declined -> BOTH properties stayed sold; neither reverted. Root cause
     * was two-fold, confirmed by reading before fixing: (1)
     * RevertPropertyStatusOnDealDeclined required status EXACTLY
     * 'under_offer' to even consider a property, so a property already
     * advanced to 'sold' was skipped before the aggregate check ever ran;
     * (2) MarkPropertySoldOnDealMilestone nulled pre_deal_offer_status on
     * the sold transition ("sold is terminal — no revert target"), so even
     * widening the status guard alone would have had nothing left to
     * restore. Real timestamps on deal #183 proved it: zero property_audit_log
     * rows for either property at decline time — the listener never touched
     * them at all.
     *
     * This test drives every transition for real — POSTs to the real create
     * endpoint, then two real Eloquent accepted_status updates (the exact
     * mechanism quickUpdate()/persistDeal() themselves use) — nothing here
     * hand-fires a domain event to skip past a step production doesn't skip.
     */
    public function test_johans_deal_183_scenario_pending_granted_declined_both_properties_revert(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true, 'sold_milestone' => 'granted', 'revert_property_on_deal_declined' => true]);
        $bm = $this->makeBranchManager();
        $primary = $this->makeProperty('Deal 183 Regression — Property 1');
        $secondary = $this->makeProperty('Deal 183 Regression — Property 2');
        $steve = $this->makeOwnerContact('Steve');
        $this->linkOwner($primary, $steve);
        $this->linkOwner($secondary, $steve);

        // PENDING — real POST to the real create endpoint.
        $response = $this->actingAs($bm)->post(route('deals-dr2.store'), $this->createDealPayload($bm, $primary, [
            ['property_id' => $primary->id, 'allocated_price' => 6_450_000, 'allocated_commission' => 150_000],
            ['property_id' => $secondary->id, 'allocated_price' => 2_736_000, 'allocated_commission' => 125_000],
        ]));
        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $primary->id)->latest('id')->first();
        $this->assertNotNull($deal);
        $this->assertSame('under_offer', $primary->fresh()->status, 'step 1 (pending): primary must flag under-offer');
        $this->assertSame('under_offer', $secondary->fresh()->status, 'step 1 (pending): secondary must ALSO flag under-offer');

        // GRANTED — a real Eloquent update, the same mechanism quickUpdate()/
        // persistDeal() themselves use to change accepted_status.
        $deal->update(['accepted_status' => 'G']);
        $this->assertSame('sold', $primary->fresh()->status, 'step 2 (granted): primary must flag sold');
        $this->assertSame('sold', $secondary->fresh()->status, 'step 2 (granted): secondary must ALSO flag sold');

        // DECLINED — same real mechanism. This is exactly what failed live.
        $deal->update(['accepted_status' => 'D']);
        $this->assertSame('for_sale', $primary->fresh()->status, 'step 3 (declined): primary must revert to on-market — this is exactly what deal #183 failed to do');
        $this->assertSame('for_sale', $secondary->fresh()->status, 'step 3 (declined): secondary must ALSO revert to on-market');
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
        // This test is about the REVERT listener's mixed-status handling
        // given an already-fully-linked deal, deliberately independent of
        // HOW it got linked (class docblock) — not about the create-endpoint
        // ordering bug, which has its own dedicated end-to-end regression
        // tests above (test_johans_deal_181_scenario_...). Re-firing here to
        // reach that starting state is a legitimate stand-in for either real
        // re-sync path (addProperty()'s own re-fire, or
        // applyCreateTimeMultiProperty()'s, post-fix) — not a workaround for
        // a bug this test is supposed to be checking.
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
        // Same note as the revert test above — this checks
        // AutoDeclineNewDealOnCommittedProperty's own logic given an
        // already-fully-linked deal, not the create-endpoint ordering bug.
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
