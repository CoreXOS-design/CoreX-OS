<?php

namespace Tests\Feature\Syndication;

use App\Jobs\PrivateProperty\SyncPpListingStatusJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\ListingLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-282 — Private Property status parity: PP now hears under-offer / sold, via
 * the two-tier ListingLifecycle resolver + a dedicated ListingStatusUpdate push,
 * dispatched from the same PropertyObserver trigger that already fed P24.
 */
class PpStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // neutralise observer-dispatched jobs
    }

    // ── the two-tier resolver (root cause: sub-label authoritative) ──

    public function test_under_offer_resolves_from_base_status_or_sub_label(): void
    {
        $this->assertSame(ListingLifecycle::UNDER_OFFER, ListingLifecycle::resolve('under_offer', null));
        // The exact defect: on-market base with an "Under Offer" sub-label.
        $this->assertSame(ListingLifecycle::UNDER_OFFER, ListingLifecycle::resolve('for_sale', 'Under Offer'));
        $this->assertSame(ListingLifecycle::UNDER_OFFER, ListingLifecycle::resolve('for_sale', '• Under Offer'));
        $this->assertSame(ListingLifecycle::SOLD, ListingLifecycle::resolve('sold', null));
        $this->assertSame(ListingLifecycle::ON_MARKET, ListingLifecycle::resolve('for_sale', null));
    }

    public function test_mapper_status_for_maps_lifecycle_to_the_pp_write_enum(): void
    {
        $w = $this->world(['status' => 'under_offer']);
        $this->assertSame('PendingOffer', PrivatePropertyListingMapper::statusFor($w['property'], 'Sale'));

        $w2 = $this->world(['status' => 'for_sale', 'status_label' => 'Under Offer']);
        $this->assertSame('PendingOffer', PrivatePropertyListingMapper::statusFor($w2['property'], 'Sale'), 'sub-label under-offer reaches PP');

        $w3 = $this->world(['status' => 'sold']);
        $this->assertSame('Inactive', PrivatePropertyListingMapper::statusFor($w3['property'], 'Sale'));

        $w4 = $this->world(['status' => 'for_sale']);
        $this->assertSame('ForSale', PrivatePropertyListingMapper::statusFor($w4['property'], 'Sale'));
        $this->assertSame('ToLet', PrivatePropertyListingMapper::statusFor($w4['property'], 'Rental'));
    }

    // ── the wire: observer dispatches the PP status job ──

    public function test_status_change_dispatches_pp_status_job_for_a_pp_listing(): void
    {
        $w = $this->world(['status' => 'for_sale', 'pp_syndication_enabled' => true, 'pp_ref' => 'PP-123']);
        $w['property']->update(['status' => 'under_offer']);
        Bus::assertDispatched(SyncPpListingStatusJob::class, fn ($j) => $j->propertyId === $w['property']->id);
    }

    public function test_status_change_does_not_dispatch_when_pp_not_enabled(): void
    {
        $w = $this->world(['status' => 'for_sale', 'pp_syndication_enabled' => false, 'pp_ref' => null]);
        Bus::fake(); // reset the recording after create
        $w['property']->update(['status' => 'sold']);
        Bus::assertNotDispatched(SyncPpListingStatusJob::class);
    }

    // ── syncStatus: push + read-back verify ──

    public function test_sync_status_records_active_when_pp_applies_it(): void
    {
        $w = $this->world(['status' => 'under_offer', 'pp_syndication_enabled' => true, 'pp_ref' => 'PP-9']);

        $this->mock(PrivatePropertySoapClient::class, function ($m) {
            $m->shouldReceive('forAgency')->andReturnSelf();
            $m->shouldReceive('setListingStatus')->andReturn(['ok' => true]);
            // PP reads back with a SPACE ("Pending Offer") — must still verify against "PendingOffer".
            $m->shouldReceive('getListingStatus')->andReturn(['GetListingStatusResult' => 'Pending Offer']);
        });

        $res = app(PrivatePropertySyndicationService::class)->syncStatus($w['property']);

        $this->assertTrue($res['success']);
        $this->assertSame('active', $w['property']->fresh()->pp_syndication_status, 'under-offer keeps the listing live, not deactivated');
    }

    public function test_sync_status_records_error_when_pp_accepts_but_does_not_apply(): void
    {
        $w = $this->world(['status' => 'under_offer', 'pp_syndication_enabled' => true, 'pp_ref' => 'PP-9']);

        $this->mock(PrivatePropertySoapClient::class, function ($m) {
            $m->shouldReceive('forAgency')->andReturnSelf();
            $m->shouldReceive('setListingStatus')->andReturn(['ok' => true]);
            $m->shouldReceive('getListingStatus')->andReturn(['GetListingStatusResult' => 'ForSale']); // did NOT move
        });

        $res = app(PrivatePropertySyndicationService::class)->syncStatus($w['property']);

        $this->assertFalse($res['success']);
        $this->assertSame('error', $w['property']->fresh()->pp_syndication_status);
    }

    private function world(array $over = []): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Home', 'address' => '12 Marine Dr', 'suburb' => 'Shelly Beach',
            'agent_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ], $over)));

        return compact('agency', 'branch', 'agent', 'property');
    }
}
