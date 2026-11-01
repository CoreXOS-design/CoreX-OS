<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-520 (conductor walk 1 Nov, "Emtunzi" search): the Adverts view shows a property whatever its claim state ("Claimed: any"); only "Claimed: unclaimed" keeps claimed ones out.
 * The List view keeps the canvass-pool rule (claimed adverts hidden).
 */
final class MicAdvertsShowClaimedTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $tp = TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $this->agency->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '4', 'street_name' => 'Emtunzi Drive', 'suburb' => 'Uvongo']);
        $l = new ProspectingListing();
        $l->forceFill(['agency_id' => $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-117177245',
            'portal_url' => 'https://www.property24.com/x/117177245', 'address' => '4 Emtunzi Drive', 'suburb' => 'Uvongo', 'price' => 950_000, 'bedrooms' => 2, 'property_type' => 'Apartment',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale'])->save();
        DB::table('prospecting_claims')->insert(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $l->id, 'user_id' => $this->admin->id, 'status' => 'claimed',
            'claimed_at' => now(), 'last_updated_at' => now(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_claimed_property_still_shows_in_the_adverts_view_and_in_a_search(): void
    {
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'properties', 'search' => 'Emtunzi', '_defer_tiles' => 1]))
            ->assertOk()->assertSee('117177245', false);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'properties', '_defer_tiles' => 1]))
            ->assertOk()->assertSee('117177245', false);
    }

    public function test_claimed_unclaimed_keeps_claimed_properties_out_and_the_list_view_keeps_its_canvass_rule(): void
    {
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'properties', 'search' => 'Emtunzi', 'claimed' => 'unclaimed', '_defer_tiles' => 1]))
            ->assertOk()->assertDontSee('117177245', false);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'list', 'search' => 'Emtunzi', '_defer_tiles' => 1]))
            ->assertOk()->assertDontSee('117177245', false);
    }
}
