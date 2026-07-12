<?php

declare(strict_types=1);

namespace Tests\Feature\Syndication;

use App\Jobs\PrivateProperty\SyncPpListingStatusJob;
use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * AT-68 WS2 + WS3.
 *
 * WS2 — THE WIRE THAT DID NOT EXIST. PropertyObserver fanned a status change out
 * to P24 only (zero PrivateProperty references), so a property going under offer
 * updated P24 in seconds and PP received NOTHING — it kept advertising the
 * property as plainly "For Sale" until an agent manually refreshed it.
 *
 * WS3 — PP ANSWERS "Successful" WHILE DOING NOTHING. A live probe pushed
 * PropertyStatus=Sold, PP returned ListingStatusUpdateResult "Successful", and
 * the status did not move. Identical bug-class to AT-221 (P24 returned HTTP 200
 * with isOnPortal:false for a rejected listing). So a status push is only
 * "successful" once we have READ IT BACK and PP agrees.
 * (.ai/audits/2026-07-11-at68-pp-status-parity.md §7.1)
 */
final class PpStatusSyncWireTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private \App\Models\Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);

        // Properties auto-stamp a branch_id (FK, ON DELETE RESTRICT) — give the
        // agency a real branch or every Property::create() fails on the constraint.
        $this->branch = \App\Models\Branch::create([
            'agency_id' => $this->agency->id,
            'name'      => 'Shelly Beach',
        ]);

        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    private function listedProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id'               => $this->agency->id,
            'branch_id'               => $this->branch->id,
            'agent_id'                => $this->agent->id,
            'title'                   => '3 Bed House in Shelly Beach',
            'address'                 => '14 Marine Drive, Shelly Beach',
            'suburb'                  => 'Shelly Beach',
            'status'                  => 'for_sale',
            'listing_type'            => 'sale',
            'pp_syndication_enabled'  => true,
            'pp_ref'                  => 'T5539364',
        ], $overrides));
    }

    // ── WS2: the wire ───────────────────────────────────────────────────────

    public function test_a_status_change_now_reaches_private_property(): void
    {
        $property = $this->listedProperty();
        Queue::fake(); // observe the UPDATE, not the create

        $property->update(['status' => 'under_offer']);

        Queue::assertPushed(SyncPpListingStatusJob::class,
            fn ($job) => $job->propertyId === $property->id);
    }

    /**
     * THE common under-offer case, and the one a base-status-only trigger misses:
     * the property stays "for sale" and gains the sub-label.
     */
    public function test_a_sub_label_only_change_also_reaches_private_property(): void
    {
        $property = $this->listedProperty();
        Queue::fake(); // observe the UPDATE, not the create

        $property->update(['status_label' => 'Under Offer']); // base status untouched

        // Under-offer normally lives in the sub-label — a trigger watching only the
        // base status never fires, which is why PP never heard about it.
        Queue::assertPushed(SyncPpListingStatusJob::class,
            fn ($job) => $job->propertyId === $property->id);
    }

    public function test_a_property_not_on_pp_is_not_pushed(): void
    {
        $property = $this->listedProperty(['pp_ref' => null, 'pp_syndication_enabled' => false]);
        Queue::fake();

        $property->update(['status' => 'under_offer']);

        Queue::assertNotPushed(SyncPpListingStatusJob::class);
    }

    public function test_an_unrelated_field_change_does_not_push_status(): void
    {
        $property = $this->listedProperty();
        Queue::fake();

        $property->update(['suburb' => 'Uvongo']);

        // Only a lifecycle change is a status change.
        Queue::assertNotPushed(SyncPpListingStatusJob::class);
    }

    // ── WS3: "Successful" is not proof ──────────────────────────────────────

    private function serviceWithClient($client): PrivatePropertySyndicationService
    {
        return new PrivatePropertySyndicationService(
            $client,
            app(\App\Services\PrivateProperty\PrivatePropertyListingMapper::class)
        );
    }

    public function test_a_verified_status_push_is_recorded_as_success(): void
    {
        $property = $this->listedProperty(['status' => 'under_offer']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('setListingStatus')
            ->once()
            ->with((string) $property->id, 'Sale', 'PendingOffer')
            ->andReturn(['ListingStatusUpdateResult' => 'Successful']);
        // PP agrees on read-back.
        $client->shouldReceive('getListingStatus')
            ->once()
            ->andReturn(['GetListingStatusResult' => 'PendingOffer']);

        $result = $this->serviceWithClient($client)->syncStatus($property);

        $this->assertTrue($result['success']);
        $this->assertSame('PendingOffer', $result['status']);

        $property->refresh();
        $this->assertSame('active', $property->pp_syndication_status,
            'an under-offer listing is STILL ON the portal — it must never be recorded as deactivated, '
            . 'or the next delist would skip it as "already off" (the property #2142 stranding class)');
        $this->assertNull($property->pp_last_error);
    }

    /**
     * THE PROBE, REPRODUCED. PP says "Successful" and changes nothing.
     * Trusting the response string here is what would silently break the feature.
     */
    public function test_successful_but_not_applied_is_recorded_as_a_failure_not_a_success(): void
    {
        $property = $this->listedProperty(['status' => 'under_offer']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('setListingStatus')
            ->once()
            ->andReturn(['ListingStatusUpdateResult' => 'Successful']); // PP: "sure, done!"
        $client->shouldReceive('getListingStatus')
            ->once()
            ->andReturn(['GetListingStatusResult' => 'ForSale']);       // PP: ...but it isn't.

        $result = $this->serviceWithClient($client)->syncStatus($property);

        $this->assertFalse($result['success'],
            'PP returned "Successful" but did NOT apply the status — that is a FAILURE, not a success');
        $this->assertSame('PendingOffer', $result['desired']);
        $this->assertSame('ForSale', $result['actual']);

        $property->refresh();
        $this->assertSame('error', $property->pp_syndication_status);
        $this->assertStringContainsString('did not apply', $property->pp_last_error);
        $this->assertStringContainsString('PendingOffer', $property->pp_last_error,
            'the error must name what we asked for and what the portal actually reports');
    }

    /** A flaky read-back is "could not check", not "the portal is wrong". */
    public function test_an_unavailable_read_back_does_not_mark_the_property_broken(): void
    {
        $property = $this->listedProperty(['status' => 'under_offer']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('setListingStatus')->once()
            ->andReturn(['ListingStatusUpdateResult' => 'Successful']);
        $client->shouldReceive('getListingStatus')->once()
            ->andReturn(['error' => true, 'message' => 'Private Property is not responding right now.']);

        $result = $this->serviceWithClient($client)->syncStatus($property);

        $this->assertTrue($result['success'],
            'we could not verify — that is not the same as the portal being wrong');

        $property->refresh();
        $this->assertNotSame('error', $property->pp_syndication_status);
    }

    public function test_a_failed_push_records_the_error_and_does_not_claim_success(): void
    {
        $property = $this->listedProperty(['status' => 'under_offer']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('setListingStatus')->once()
            ->andReturn(['error' => true, 'message' => 'Private Property is not responding right now.']);
        $client->shouldNotReceive('getListingStatus');

        $result = $this->serviceWithClient($client)->syncStatus($property);

        $this->assertFalse($result['success']);
        $property->refresh();
        $this->assertSame('error', $property->pp_syndication_status);
    }

    /** A sold listing leaves the portal — that one MAY be recorded as deactivated. */
    public function test_an_off_market_status_is_recorded_as_deactivated(): void
    {
        $property = $this->listedProperty(['status' => 'sold']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('setListingStatus')->once()
            ->with((string) $property->id, 'Sale', 'Inactive')
            ->andReturn(['ListingStatusUpdateResult' => 'Successful']);
        $client->shouldReceive('getListingStatus')->once()
            ->andReturn(['GetListingStatusResult' => 'Inactive']);

        $result = $this->serviceWithClient($client)->syncStatus($property);

        $this->assertTrue($result['success']);
        $property->refresh();
        $this->assertSame(Property::PORTAL_OFF_STATUS, $property->pp_syndication_status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
