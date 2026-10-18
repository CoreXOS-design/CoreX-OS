<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan 2026-10-17: the "Send to Auction" entry moved from the top of the property page's Info tab into the page
 * header's action group. Same gates as before (Auctions feature + auctions.create), same target, rendered once.
 */
final class AuctionHeaderEntryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->agency = Agency::create(['name' => 'Header Entry Agency', 'slug' => 'header-entry-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function featureOn(bool $on): void
    {
        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => $on]);
        config(['features.auctions' => $on]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
    }

    private function property(): Property
    {
        return Property::create([
            'title' => 'Header entry property', 'agency_id' => $this->agency->id, 'agent_id' => $this->user->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
    }

    public function test_send_to_auction_sits_in_the_header_once_and_not_in_the_info_tab(): void
    {
        $this->featureOn(true);
        $property = $this->property();

        $html = $this->actingAs($this->user)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-prop-auction-entry="send"'));
        $this->assertStringContainsString(e(route('corex.auctions.create', ['property_id' => $property->id])), $html);
        // Inside the identity strip (header), before the Info tab panel begins.
        $this->assertLessThan(strpos($html, 'class="prop-info-panel'), strpos($html, 'data-prop-auction-entry="send"'));
        $this->assertGreaterThan(strpos($html, 'class="prop-identity-strip'), strpos($html, 'data-prop-auction-entry="send"'));
    }

    public function test_entry_is_absent_when_the_auctions_feature_is_off(): void
    {
        $this->featureOn(false);
        $property = $this->property();

        $html = $this->actingAs($this->user)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-prop-auction-entry', $html);
    }
}
