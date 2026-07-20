<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * PP-7 — a sole RENTAL (listing_type=rental, mandate_type=sole) must be
 * deactivated on PP as 'Rental', not 'Sale'. PP keys listings by
 * (PropertyId, ListingType); the old mandate_type-only derivation resolved
 * 'Sale' and missed the record, leaving the rental live.
 *
 * Audit: .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-7)
 */
class PrivatePropertyDeactivateTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sole_rental_deactivates_as_rental(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Rental', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 12000,
            'listing_type' => 'rental', 'mandate_type' => 'sole',
            'pp_syndication_enabled' => true, 'pp_syndication_status' => 'active', 'pp_ref' => 'T55',
        ]);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('deactivateListing')
            ->once()
            ->with((string) $p->id, 'Rental')   // <-- the assertion: Rental, not Sale
            ->andReturn(['error' => false]);
        // AT-68 audit-truth read-back — deactivateListing now confirms the listing
        // is actually OFF before recording 'deactivated'. PP reports it Inactive.
        $client->shouldReceive('getListingStatus')
            ->once()
            ->with((string) $p->id)
            ->andReturn(['GetListingStatusResult' => 'Inactive']);

        $service = new PrivatePropertySyndicationService($client, app(\App\Services\PrivateProperty\PrivatePropertyListingMapper::class));
        $result = $service->deactivateListing($p);

        $this->assertTrue($result['success']);
        $this->assertSame('deactivated', $p->fresh()->pp_syndication_status);
    }
}
