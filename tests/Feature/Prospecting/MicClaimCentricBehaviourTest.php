<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\Property;
use App\Models\User;
use App\Services\Prospecting\OnMarketStockService;
use App\Services\Prospecting\ProspectingListingStateEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC claim-centric behaviour — the 2026-08-27 fix batch.
 *
 * Written by the post-commit audit of that batch: nine behavioural fixes
 * shipped that day and only AT-384 (calendar) carried a test, so every claim
 * rule below was regression-exposed. Each case here is the live symptom Johan
 * reported, expressed as an assertion.
 *
 * Covered:
 *  - "if the pitch is not done we cannot show in stock" — a property in a
 *    MIC-only synthetic status (prospecting / not_selling) was never real
 *    agency stock, so it must never enter the stock identity map, regardless
 *    of isStaleStock()'s 7-day recency grace period.
 *  - "the my claim has run its cycle... should fall off My Claims" — marking
 *    a property Not Selling closes every active claim on it, reached EITHER
 *    through its listing's matched_property_id (pitch never completed) or
 *    through the claim's own property_id (pitch completed).
 *  - Tile-vs-list disagreement — every claim write invalidates the MIC
 *    action-preset count cache, including the two bulk ->update() sites that
 *    bypass the observer entirely.
 *  - "Pitched" showing on a pitch that was never completed — is_promoted may
 *    only trust claim.property_id when the listing's own pitched_at backs it.
 *  - A role whose MIC data scope is 'none' is not re-opened by a
 *    claim-centric preset (audit fix, 2026-08-27).
 */
final class MicClaimCentricBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OnMarketStockService::flushCache();
    }

    // ── "if the pitch is not done we cannot show in stock" ──────────────────

    /**
     * Fix-the-class: BOTH MIC-only synthetic statuses are excluded, and a
     * genuine on-market status is still included — otherwise the exclusion
     * could pass by suppressing everything.
     */
    public function test_synthetic_mic_statuses_never_count_as_company_stock(): void
    {
        [$agencyId] = $this->seedAgency();

        $realId    = $this->seedProperty($agencyId, '5 Real Road', 'Margate', 'for_sale');
        $prospectId = $this->seedProperty($agencyId, '6 Prospect Road', 'Margate', Property::STATUS_PROSPECTING);
        $notSellId  = $this->seedProperty($agencyId, '7 Declined Road', 'Margate', Property::STATUS_NOT_SELLING);

        $real     = $this->seedListing($agencyId, '5 Real Road', 'Margate');
        $prospect = $this->seedListing($agencyId, '6 Prospect Road', 'Margate');
        $notSell  = $this->seedListing($agencyId, '7 Declined Road', 'Margate');

        $map = app(OnMarketStockService::class)
            ->stockMapForListings([$real, $prospect, $notSell], $agencyId);

        $this->assertSame(
            $realId,
            $map[$real->id] ?? null,
            'a genuinely on-market property must still badge as company stock'
        );
        $this->assertArrayNotHasKey(
            $prospect->id,
            $map,
            "status 'prospecting' was never real stock — it must not badge IN STOCK"
        );
        $this->assertArrayNotHasKey(
            $notSell->id,
            $map,
            "status 'not_selling' was never real stock — it must not badge IN STOCK"
        );
        $this->assertNotContains($prospectId, $map, 'the prospecting property must not appear as any listing\'s stock');
        $this->assertNotContains($notSellId, $map, 'the not-selling property must not appear as any listing\'s stock');
    }

    // ── Not Selling closes the claim ────────────────────────────────────────

    /** The incomplete-pitch path: the claim is reached via its listing's matched_property_id. */
    public function test_mark_not_selling_closes_a_claim_linked_through_its_listing(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propId  = $this->seedProperty($agencyId, '18 Riviera Crescent', 'Uvongo', Property::STATUS_PROSPECTING);
        $listing = $this->seedListing($agencyId, '18 Riviera Crescent', 'Uvongo');
        DB::table('prospecting_listings')->where('id', $listing->id)->update(['matched_property_id' => $propId]);

        // property_id deliberately NULL — the pitch never completed.
        $claimId = $this->seedClaim($agencyId, $listing->id, $userId, null);

        $this->actingAsOwner($agencyId)
            ->post(route('corex.properties.mark-not-selling', $propId), ['reason' => 'Owner declined'])
            ->assertRedirect();

        $claim = ProspectingClaim::withoutGlobalScopes()->findOrFail($claimId);
        $this->assertFalse((bool) $claim->is_active, 'the claim must drop off My Claims');
        $this->assertSame(ProspectingClaim::STATUS_NOT_INTERESTED, $claim->status);
        $this->assertNotNull($claim->released_at);
        $this->assertSame('not_selling', $claim->release_reason);
        $this->assertStringContainsString('Marked Not selling', (string) $claim->notes);
    }

    /** The completed-pitch path: the claim carries its own property_id. */
    public function test_mark_not_selling_closes_a_claim_linked_through_its_own_property_id(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propId  = $this->seedProperty($agencyId, '12 Radstock Road', 'Ramsgate', Property::STATUS_PROSPECTING);
        $listing = $this->seedListing($agencyId, '12 Radstock Road', 'Ramsgate');

        // No matched_property_id on the listing — only the claim knows.
        $claimId = $this->seedClaim($agencyId, $listing->id, $userId, $propId);

        $this->actingAsOwner($agencyId)
            ->post(route('corex.properties.mark-not-selling', $propId), ['reason' => 'Owner declined'])
            ->assertRedirect();

        $claim = ProspectingClaim::withoutGlobalScopes()->findOrFail($claimId);
        $this->assertFalse((bool) $claim->is_active, 'a completed pitch marked Not Selling must also close');
        $this->assertSame(ProspectingClaim::STATUS_NOT_INTERESTED, $claim->status);
    }

    /** An already-closed claim, and another agency's claim, are both left alone. */
    public function test_mark_not_selling_does_not_touch_other_agencies_or_closed_claims(): void
    {
        [$agencyId, $userId]   = $this->seedAgency();
        [$otherAgency, $otherUser] = $this->seedAgency();

        $propId  = $this->seedProperty($agencyId, '9 Shared Street', 'Margate', Property::STATUS_PROSPECTING);
        $listing = $this->seedListing($agencyId, '9 Shared Street', 'Margate');
        DB::table('prospecting_listings')->where('id', $listing->id)->update(['matched_property_id' => $propId]);

        $closedId = $this->seedClaim($agencyId, $listing->id, $userId, null);
        DB::table('prospecting_claims')->where('id', $closedId)->update([
            'is_active' => false, 'released_at' => now()->subDay(), 'status' => ProspectingClaim::STATUS_LOST,
        ]);

        $otherListing = $this->seedListing($otherAgency, '9 Shared Street', 'Margate');
        DB::table('prospecting_listings')->where('id', $otherListing->id)->update(['matched_property_id' => $propId]);
        $otherClaimId = $this->seedClaim($otherAgency, $otherListing->id, $otherUser, null);

        $this->actingAsOwner($agencyId)
            ->post(route('corex.properties.mark-not-selling', $propId), ['reason' => 'Owner declined'])
            ->assertRedirect();

        $closed = ProspectingClaim::withoutGlobalScopes()->findOrFail($closedId);
        $this->assertSame(ProspectingClaim::STATUS_LOST, $closed->status, 'an already-closed claim keeps its own outcome');

        $other = ProspectingClaim::withoutGlobalScopes()->findOrFail($otherClaimId);
        $this->assertTrue((bool) $other->is_active, "another agency's claim must never be closed by this action");
    }

    // ── MIC counts cache invalidation ───────────────────────────────────────

    /** Single-model writes reach the observer. */
    public function test_a_claim_write_bumps_the_counts_cache_version(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listing = $this->seedListing($agencyId, '3 Version Road', 'Margate');

        $before = ProspectingClaim::countsCacheVersion($agencyId);
        $claimId = $this->seedClaim($agencyId, $listing->id, $userId, null);
        $afterCreate = ProspectingClaim::countsCacheVersion($agencyId);
        $this->assertGreaterThan($before, $afterCreate, 'creating a claim must invalidate the tile counts');

        $claim = ProspectingClaim::withoutGlobalScopes()->findOrFail($claimId);
        $claim->is_active = false;
        $claim->released_at = now();
        $claim->save();

        $this->assertGreaterThan($afterCreate, ProspectingClaim::countsCacheVersion($agencyId), 'releasing a claim must invalidate too');
    }

    /** The bulk ->update() in markNotSelling bypasses the observer — it bumps at its own call site. */
    public function test_mark_not_selling_bumps_the_counts_cache_version(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propId  = $this->seedProperty($agencyId, '21 Bulk Street', 'Margate', Property::STATUS_PROSPECTING);
        $listing = $this->seedListing($agencyId, '21 Bulk Street', 'Margate');
        DB::table('prospecting_listings')->where('id', $listing->id)->update(['matched_property_id' => $propId]);
        $this->seedClaim($agencyId, $listing->id, $userId, null);

        $before = ProspectingClaim::countsCacheVersion($agencyId);

        $this->actingAsOwner($agencyId)
            ->post(route('corex.properties.mark-not-selling', $propId), ['reason' => 'Owner declined'])
            ->assertRedirect();

        $this->assertGreaterThan(
            $before,
            ProspectingClaim::countsCacheVersion($agencyId),
            'a bulk claim close must still invalidate the tile counts'
        );
    }

    /** The 48h auto-release sweep is the other observer-bypassing site. */
    public function test_the_expiry_sweep_bumps_the_counts_cache_version(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listing = $this->seedListing($agencyId, '4 Expired Way', 'Margate');
        $claimId = $this->seedClaim($agencyId, $listing->id, $userId, null);
        DB::table('prospecting_claims')->where('id', $claimId)->update(['claimed_at' => now()->subHours(72)]);

        $before = ProspectingClaim::countsCacheVersion($agencyId);

        $this->artisan('prospecting:maintain-claims')->assertSuccessful();

        $this->assertFalse(
            (bool) ProspectingClaim::withoutGlobalScopes()->findOrFail($claimId)->is_active,
            'a 72h-old unpitched claim must auto-release'
        );
        $this->assertGreaterThan(
            $before,
            ProspectingClaim::countsCacheVersion($agencyId),
            'the sweep must invalidate the tile counts for every agency it touched'
        );
    }

    // ── "Pitched" must mean the pitch actually completed ────────────────────

    public function test_is_promoted_requires_the_listing_to_have_been_pitched(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propId  = $this->seedProperty($agencyId, '30 Halfway Lane', 'Uvongo', 'for_sale');

        // A deed was linked (so a Property exists and the claim carries its id)
        // but the agent never reached "Create & continue" — pitched_at is NULL.
        $incomplete = $this->seedListing($agencyId, '30 Halfway Lane', 'Uvongo');
        $this->seedClaim($agencyId, $incomplete->id, $userId, $propId);

        $state = app(ProspectingListingStateEnricher::class)->enrich([$incomplete], $agencyId);
        $this->assertFalse(
            (bool) ($state['claims'][$incomplete->id]['is_promoted'] ?? true),
            'an unfinished pitch must NOT read as Pitched'
        );

        // Same claim shape, but the listing's own pitched_at is stamped.
        $completed = $this->seedListing($agencyId, '31 Halfway Lane', 'Uvongo');
        DB::table('prospecting_listings')->where('id', $completed->id)->update(['pitched_at' => now()]);
        $this->seedClaim($agencyId, $completed->id, $userId, $propId);

        OnMarketStockService::flushCache();
        $state2 = app(ProspectingListingStateEnricher::class)->enrich([$completed], $agencyId);
        $this->assertTrue(
            (bool) ($state2['claims'][$completed->id]['is_promoted'] ?? false),
            'a completed pitch must read as Pitched'
        );
    }

    // ── A 'none' MIC scope stays closed (audit fix) ─────────────────────────

    public function test_a_claim_centric_preset_does_not_reopen_a_none_scope(): void
    {
        $ref = new \ReflectionMethod(\App\Models\ProspectingListing::class, 'scopeVisibleTo');
        $this->assertTrue($ref->isPublic(), 'scopeVisibleTo is the gate this fix relies on');

        [$agencyId, $userId] = $this->seedAgency();
        $listing = $this->seedListing($agencyId, '8 Denied Drive', 'Margate');
        $this->seedClaim($agencyId, $listing->id, $userId, null);

        $user = User::findOrFail($userId);

        // 'none' is a configured "sees nothing", not merely a narrower window:
        // widening it to 'all' for a claim preset would silently re-open it.
        $visible = ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->visibleTo($user, 'none')
            ->count();

        $this->assertSame(0, $visible, "'none' must resolve to zero rows — the preset override must not replace it");
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [agencyId, userId] */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        return [$agencyId, (int) $user->id];
    }

    /** Owner role — markNotSelling is permission-gated; the audit is of the claim rule, not the gate. */
    private function actingAsOwner(int $agencyId): self
    {
        $owner = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        return $this->actingAs($owner);
    }

    private function seedProperty(int $agencyId, string $address, string $suburb, string $status): int
    {
        $agentId = (int) DB::table('users')->where('agency_id', $agencyId)->value('id');

        return (int) DB::table('properties')->insertGetId([
            'agency_id'   => $agencyId,
            'branch_id'   => $agencyId,
            'agent_id'    => $agentId,
            'external_id' => (string) Str::uuid(),
            'title'       => $address,
            'address'     => $address,
            'suburb'      => $suburb,
            'status'      => $status,
            'created_at'  => now(), 'updated_at' => now(),
        ]);
    }

    private function seedListing(int $agencyId, string $address, string $suburb): ProspectingListing
    {
        $capturedBy = (int) DB::table('users')->where('agency_id', $agencyId)->value('id');
        $id = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'           => $agencyId,
            'portal_source'       => 'p24',
            'portal_ref'          => 'test-' . Str::random(10),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'captured_by_user_id' => $capturedBy,
            'is_active'           => true,
            'address'             => $address,
            'suburb'              => $suburb,
            'normalized_address'  => ProspectingListing::normalizeAddress($address, $suburb),
            'price'               => 0,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        return ProspectingListing::withoutGlobalScopes()->findOrFail($id);
    }

    /** Goes through the model so the observer fires — that IS the mechanism under test. */
    private function seedClaim(int $agencyId, int $listingId, int $userId, ?int $propertyId): int
    {
        $claim = new ProspectingClaim();
        $claim->forceFill([
            'agency_id'              => $agencyId,
            'prospecting_listing_id' => $listingId,
            'user_id'                => $userId,
            'property_id'            => $propertyId,
            'status'                 => ProspectingClaim::STATUS_CLAIMED,
            'claimed_at'             => now(),
            'last_updated_at'        => now(),
            'is_active'              => true,
        ])->save();

        return (int) $claim->id;
    }
}
