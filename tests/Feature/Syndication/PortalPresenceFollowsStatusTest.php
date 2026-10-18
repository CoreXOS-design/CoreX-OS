<?php

namespace Tests\Feature\Syndication;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Portal presence follows PROPERTY STATUS (Johan, 2026-10-06):
 *   let_out            → off both portals
 *   back to on-market  → back on both portals, even while the lease still runs.
 *
 * Fix 1 — P24: a listing that was withdrawn (let_out) and goes back on-market is
 *         re-listed through the BackOnMarket path and CoreX's own off-portal marker
 *         is reset ONLY after P24 accepts the push.
 * Fix 2 — PP: the full-submit ("Refresh") status mapper must never re-advertise an
 *         off-market property (it did not recognise `let_out` → sent To Let).
 *
 * FAKE portal clients only — Http::fake() for P24, no SOAP/network for PP.
 */
class PortalPresenceFollowsStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeListing(string $status, string $marker, array $over = []): Property
    {
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal-' . uniqid(),
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'rental', 'status' => $status, 'price' => 9500,
        ]);

        $p->forceFill(array_merge([
            'p24_syndication_enabled' => true,
            'p24_syndication_status'  => $marker,
            'p24_ref'                 => '99887766',
        ], $over))->saveQuietly();

        return $p->fresh();
    }

    private function addActiveLease(Property $p): Lease
    {
        return Lease::create([
            'agency_id' => $p->agency_id, 'branch_id' => $p->branch_id, 'property_id' => $p->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'start_date' => now()->subMonth()->toDateString(), 'source' => 'manual',
        ]);
    }

    // ── let_out → withdrawn on both ─────────────────────────────────────────

    public function test_let_out_takes_the_listing_off_both_portals(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeListing('active', 'active', ['pp_syndication_enabled' => true, 'pp_ref' => 'PP-1']);
        $p->update(['status' => 'let_out']);

        // P24 hears the terminal status…
        Http::assertSent(fn ($r) => str_contains($r->url(), '99887766') && str_contains($r->url(), 'listingStatus=Rented'));
        // …and the withdraw job (P24 + PP) is queued as the real delist.
        Queue::assertPushed(DesyndicatePropertyFromPortalsJob::class, fn ($j) => $j->property->id === $p->id);
    }

    // ── let_out → active: P24 re-list ───────────────────────────────────────

    public function test_let_out_back_to_active_relists_on_p24_and_resets_the_marker_after_acceptance(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeListing('let_out', Property::PORTAL_OFF_STATUS, ['p24_last_error' => 'old']);
        $p->update(['status' => 'active']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '99887766') && str_contains($r->url(), 'listingStatus=BackOnMarket'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'listingStatus=Active'));

        $fresh = $p->fresh();
        $this->assertSame('submitted', $fresh->p24_syndication_status, 'marker reset only after P24 accepted the push');
        $this->assertNull($fresh->p24_last_error);
        $this->assertTrue($fresh->mayBeLiveOnP24());
    }

    public function test_marker_is_left_unchanged_when_p24_does_not_accept_the_relist(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('unavailable', 503)]);

        $p = $this->makeListing('let_out', Property::PORTAL_OFF_STATUS);
        $p->update(['status' => 'active']);

        $fresh = $p->fresh();
        $this->assertSame(Property::PORTAL_OFF_STATUS, $fresh->p24_syndication_status, 'CoreX must not claim the listing is back when P24 never accepted it');
        $this->assertFalse($fresh->mayBeLiveOnP24());
        $this->assertStringContainsString('temporarily unavailable', (string) $fresh->p24_last_error);
    }

    public function test_an_active_lease_does_not_block_the_relist(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeListing('let_out', Property::PORTAL_OFF_STATUS);
        $this->addActiveLease($p);
        $this->assertNotNull($p->blockingActiveLease(), 'precondition: the lease is running');

        $p->update(['status' => 'active']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'listingStatus=BackOnMarket'));
        $this->assertSame('submitted', $p->fresh()->p24_syndication_status);
    }

    public function test_let_out_parked_as_rented_on_p24_is_also_relisted_via_back_on_market(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        // The Rented push leaves the listing on P24 as rented stock (marker 'rented').
        $p = $this->makeListing('let_out', 'rented');
        $p->update(['status' => 'active']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'listingStatus=BackOnMarket'));
        $this->assertSame('submitted', $p->fresh()->p24_syndication_status);
    }

    // ── existing gates stay exactly as they were ────────────────────────────

    public function test_pp_exclusive_window_still_blocks_the_p24_relist(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeListing('let_out', Property::PORTAL_OFF_STATUS, ['pp_delay_until' => now()->addDays(10)]);
        $p->update(['status' => 'active']);

        Http::assertNothingSent();
        $fresh = $p->fresh();
        $this->assertSame(Property::PORTAL_OFF_STATUS, $fresh->p24_syndication_status);
        $this->assertStringContainsString('Private Property exclusivity', (string) $fresh->p24_last_error);
    }

    public function test_a_plain_on_market_edit_on_a_deliberately_deactivated_listing_is_not_relisted(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        // Agent switched P24 off by hand while the property stayed on-market. A later
        // on-market → on-market status tweak must not silently put it back on P24.
        $p = $this->makeListing('active', Property::PORTAL_OFF_STATUS);
        $p->update(['status' => 'under_offer']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'listingStatus=BackOnMarket'));
        $this->assertSame(Property::PORTAL_OFF_STATUS, $p->fresh()->p24_syndication_status);
    }

    // ── Fix 2: PP Refresh never re-advertises an off-market property ────────

    /** @dataProvider offMarketStatuses */
    public function test_pp_full_submit_never_sends_an_off_market_property_as_for_sale_or_to_let(string $status): void
    {
        $p = new Property(['status' => $status]);

        $this->assertSame('Inactive', $this->fullSubmitStatus($p, 'Rental'));
        $this->assertSame('Inactive', $this->fullSubmitStatus($p, 'Sale'));
        // …and the status-sync mapper agrees, so Refresh and the sync job never disagree.
        $this->assertSame('Inactive', PrivatePropertyListingMapper::statusFor($p, 'Rental'));
    }

    public static function offMarketStatuses(): array
    {
        return array_map(fn ($s) => [$s], Property::OFF_MARKET_STATUSES);
    }

    public function test_pp_full_submit_still_advertises_on_market_stock(): void
    {
        $this->assertSame('ToLet', $this->fullSubmitStatus(new Property(['status' => 'active']), 'Rental'));
        $this->assertSame('ForSale', $this->fullSubmitStatus(new Property(['status' => 'for_sale']), 'Sale'));
        $this->assertSame('ToLet', $this->fullSubmitStatus(new Property(['status' => 'to_let']), 'Rental'));
    }

    public function test_a_stale_on_market_banner_does_not_resurrect_a_let_out_property_on_pp(): void
    {
        $p = new Property(['status' => 'let_out', 'status_label' => 'Back on Market']);

        $this->assertSame('Inactive', PrivatePropertyListingMapper::statusFor($p, 'Rental'));
    }

    private function fullSubmitStatus(Property $p, string $listingType): string
    {
        $m = new ReflectionMethod(PrivatePropertyListingMapper::class, 'mapPropertyStatus');

        return $m->invoke(new PrivatePropertyListingMapper(), $p, $listingType);
    }
}
