<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Jobs\Prospecting\MatchPropertyProspectingJob;
use App\Models\Property;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\ProspectingStockMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC stock matcher — ON-MARKET gate.
 *
 * A prospecting listing may only carry an "IN STOCK" badge (matched_property_id
 * set → suppressed from the prospectable pool) when CoreX still holds the
 * matched property LIVE on the market. Stock that has gone sold / withdrawn /
 * expired / cancelled / let_out / etc. is off-market, so the listing is a
 * legitimate canvass target again and MUST return to the pool.
 *
 * Single source of truth for "off-market" is Property::OFF_MARKET_STATUSES /
 * scopeOnMarket() / isOnMarket() — the matcher reuses it, never forks it.
 *
 * Covers (BUILD_STANDARD §5): happy on-market match, every off-market status
 * individually (fix-the-class), the on→off-market transition self-heal (reverse
 * path), the off→on-market re-match, idempotency, soft-deleted/orphan property,
 * and the observer wiring that re-runs the matcher on a status change.
 */
final class MicStockMatchOnMarketTest extends TestCase
{
    use RefreshDatabase;

    private ProspectingStockMatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ProspectingStockMatchService::class);
    }

    /** Happy path: on-market property at the same address → listing badged IN STOCK. */
    public function test_on_market_property_is_matched_and_suppressed(): void
    {
        [$agencyId] = $this->seedAgency();
        $propId = $this->seedProperty($agencyId, '14 Marine Drive', 'Margate', 'for_sale');
        $listing = $this->seedListing($agencyId, '14 Marine Drive', 'Margate');

        $result = $this->svc->matchProspect($listing);

        $this->assertNotNull($result, 'on-market property should match');
        $this->assertSame($propId, $listing->fresh()->matched_property_id, 'listing should be badged IN STOCK');
    }

    /**
     * Fix-the-class: EVERY off-market status must fail to match, individually.
     * A matched listing against an off-market property is the exact over-claim
     * bug (740 withdrawn / 304 expired / 119 sold falsely IN STOCK).
     */
    public function test_no_off_market_status_is_ever_matched(): void
    {
        [$agencyId] = $this->seedAgency();

        foreach (Property::OFF_MARKET_STATUSES as $i => $status) {
            $addr = (10 + $i) . ' Off Market Road';
            $this->seedProperty($agencyId, $addr, 'Uvongo', $status);
            $listing = $this->seedListing($agencyId, $addr, 'Uvongo');

            $result = $this->svc->matchProspect($listing);

            $this->assertNull($result, "status '{$status}' must NOT match");
            $this->assertNull(
                $listing->fresh()->matched_property_id,
                "status '{$status}' listing must stay in the prospectable pool"
            );
        }
    }

    /**
     * Reverse / transition self-heal: a listing matched while the property was
     * on-market must be CLEARED when the property goes off-market — returning it
     * to the pool. This is matchAllForProperty()'s off-market branch (what the
     * PropertyObserver status-change job invokes).
     */
    public function test_property_going_off_market_clears_the_stale_badge(): void
    {
        [$agencyId] = $this->seedAgency();
        $propId = $this->seedProperty($agencyId, '7 Seaview Crescent', 'Ramsgate', 'for_sale');
        $listing = $this->seedListing($agencyId, '7 Seaview Crescent', 'Ramsgate');

        // Matched while on-market.
        $this->svc->matchProspect($listing);
        $this->assertSame($propId, $listing->fresh()->matched_property_id);

        // Property goes withdrawn → reverse path clears the match.
        DB::table('properties')->where('id', $propId)->update(['status' => 'withdrawn']);
        $cleared = $this->svc->matchAllForProperty(Property::find($propId));

        $this->assertSame(1, $cleared, 'one stale match should be cleared');
        $this->assertNull($listing->fresh()->matched_property_id, 'listing back in the pool');
    }

    /** Reverse on-market: an off→on-market property picks up unmatched prospects. */
    public function test_property_coming_on_market_matches_unmatched_prospects(): void
    {
        [$agencyId] = $this->seedAgency();
        $propId = $this->seedProperty($agencyId, '21 Pioneer Street', 'Shelly Beach', 'withdrawn');
        $listing = $this->seedListing($agencyId, '21 Pioneer Street', 'Shelly Beach');

        // Off-market: no match.
        $this->assertSame(0, $this->svc->matchAllForProperty(Property::find($propId)));
        $this->assertNull($listing->fresh()->matched_property_id);

        // Comes on-market → matches.
        DB::table('properties')->where('id', $propId)->update(['status' => 'for_sale']);
        $matched = $this->svc->matchAllForProperty(Property::find($propId));

        $this->assertSame(1, $matched);
        $this->assertSame($propId, $listing->fresh()->matched_property_id);
    }

    /** Idempotent: re-running the matcher on an on-market match is stable. */
    public function test_matcher_is_idempotent(): void
    {
        [$agencyId] = $this->seedAgency();
        $propId = $this->seedProperty($agencyId, '3 Aloe Avenue', 'Southbroom', 'under_offer');
        $listing = $this->seedListing($agencyId, '3 Aloe Avenue', 'Southbroom');

        $this->svc->matchProspect($listing);
        $this->svc->matchProspect($listing->fresh());

        $this->assertSame($propId, $listing->fresh()->matched_property_id);
    }

    /** Orphan path: a match to a soft-deleted property is cleared on re-run. */
    public function test_soft_deleted_property_match_is_cleared(): void
    {
        [$agencyId] = $this->seedAgency();
        $propId = $this->seedProperty($agencyId, '5 Erica Lane', 'Port Edward', 'for_sale');
        $listing = $this->seedListing($agencyId, '5 Erica Lane', 'Port Edward');

        $this->svc->matchProspect($listing);
        $this->assertSame($propId, $listing->fresh()->matched_property_id);

        // Soft-delete the property — it is no longer live stock.
        DB::table('properties')->where('id', $propId)->update(['deleted_at' => now()]);
        $result = $this->svc->matchProspect($listing->fresh());

        $this->assertNull($result);
        $this->assertNull($listing->fresh()->matched_property_id, 'orphaned match returns to pool');
    }

    /** Observer wiring: a status change re-dispatches the reverse matcher job. */
    public function test_status_change_dispatches_rematch_job(): void
    {
        [$agencyId] = $this->seedAgency();
        $propId = $this->seedProperty($agencyId, '9 Protea Road', 'Munster', 'for_sale');

        Queue::fake();
        $prop = Property::find($propId);
        $prop->status = 'sold';
        $prop->save();

        Queue::assertPushed(MatchPropertyProspectingJob::class);
    }

    // ---- helpers -------------------------------------------------------------

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
        return [$agencyId, $user->id];
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
            'agency_id'          => $agencyId,
            'portal_source'      => 'p24',
            'portal_ref'         => 'test-' . Str::random(10),
            'portal_url'         => 'https://example.test/' . Str::random(6),
            'captured_by_user_id'=> $capturedBy,
            'is_active'          => true,
            'address'            => $address,
            'suburb'             => $suburb,
            'normalized_address' => ProspectingListing::normalizeAddress($address, $suburb),
            'price'              => 0,
            'first_seen_at'      => now(),
            'last_seen_at'       => now(),
            'created_at'         => now(), 'updated_at' => now(),
        ]);
        return ProspectingListing::findOrFail($id);
    }
}
