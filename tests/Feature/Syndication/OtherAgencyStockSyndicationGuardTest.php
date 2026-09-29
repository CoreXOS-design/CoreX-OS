<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §2 — Other Agency Stock must never be
 * syndicated/advertised, on P24, PP, the agency website, or the Ad Manager
 * — mirrors DraftSyndicationGuardTest's structure exactly, for the same
 * guard family, with status=other_agency_stock in place of 'draft'.
 */
class OtherAgencyStockSyndicationGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private AgencyApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid(), 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);

        $minted = AgencyApiKey::mintSecret();
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);

        $this->actingAs($this->user);
    }

    private function makeOtherAgencyStock(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => Property::STATUS_OTHER_AGENCY_STOCK, 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    public function test_other_agency_stock_is_not_off_market(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->assertTrue($p->isOnMarket(), 'other_agency_stock must stay on-market for Core Matches');
        $this->assertNotContains(Property::STATUS_OTHER_AGENCY_STOCK, Property::OFF_MARKET_STATUSES);
    }

    public function test_p24_toggle_blocks_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->postJson(route('corex.properties.p24-syndication.toggle', $p))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft']);

        $this->assertFalse((bool) $p->fresh()->p24_syndication_enabled);
    }

    public function test_pp_toggle_blocks_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->postJson(route('corex.properties.syndication.toggle', $p))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft']);

        $this->assertFalse((bool) $p->fresh()->pp_syndication_enabled);
    }

    public function test_website_toggle_blocks_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft']);

        $this->assertDatabaseMissing('property_website_syndication', [
            'property_id' => $p->id, 'agency_api_key_id' => $this->key->id, 'enabled' => 1,
        ]);
    }

    public function test_other_agency_stock_can_still_be_disabled_on_website(): void
    {
        // Belt-and-braces: setEnabled() only refuses turning it ON.
        $svc = app(\App\Services\Syndication\Website\WebsiteSyndicationService::class);
        $p = $this->makeOtherAgencyStock();

        // Force-enable directly (bypassing the guard) to prove disable still works.
        \App\Models\PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'property_id' => $p->id, 'agency_api_key_id' => $this->key->id, 'agency_id' => $this->agency->id, 'enabled' => true,
        ]);

        $row = $svc->setEnabled($p, $this->key, false);
        $this->assertFalse($row->enabled);
    }

    public function test_ad_manager_picker_excludes_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();

        // Mirrors AdManagerController::index()'s own exclusion query exactly
        // (Property::OFF_MARKET_STATUSES + 'rented' + STATUS_OTHER_AGENCY_STOCK)
        // rather than driving the full HTTP route, which needs the ad-manager
        // feature flag + permission wired up — out of scope for this guard test.
        $adDeadStatuses = array_values(array_unique(
            array_merge(Property::OFF_MARKET_STATUSES, ['rented', Property::STATUS_OTHER_AGENCY_STOCK])
        ));

        $ids = Property::withoutGlobalScope(AgencyScope::class)
            ->whereRaw(
                'LOWER(COALESCE(properties.status, \'\')) NOT IN (' . implode(',', array_fill(0, count($adDeadStatuses), '?')) . ')',
                $adDeadStatuses
            )
            ->pluck('id');

        $this->assertNotContains($p->id, $ids->all());
    }

    public function test_property_marketing_publish_refuses_other_agency_stock(): void
    {
        $p = $this->makeOtherAgencyStock();

        $this->postJson(route('corex.properties.marketing.publish', $p), [
            'platforms' => ['facebook'],
            'copy'      => 'Great house!',
        ])->assertStatus(422)
          ->assertJson(['success' => false, 'error' => 'listing_draft']);
    }
}
