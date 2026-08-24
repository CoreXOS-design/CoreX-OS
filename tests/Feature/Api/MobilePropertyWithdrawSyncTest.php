<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AT — mobile "Withdrawn" status must sync to web.
 * Reproduces the field report: agent marks a property Withdrawn in the mobile
 * app (PUT /api/v1/mobile/properties/{id} with {"status":"withdrawn"}) and the
 * web does not reflect it.
 */
class MobilePropertyWithdrawSyncTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $this->user->branch_id,
            'title'         => 'Sea-view 3 bed',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ], $overrides));
    }

    public function test_mobile_withdraw_persists_status(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}", [
                'status' => 'withdrawn',
            ]);

        $res->assertOk();

        $property->refresh();
        $this->assertSame('withdrawn', $property->status,
            'PUT {status: withdrawn} must persist to the properties row.');
        $this->assertFalse($property->isOnMarket(),
            'A withdrawn property must read as off-market on the web.');
    }

    /**
     * The actual field bug: a live listing routinely carries an on-market P24
     * banner (status_label = "Reduced Price"). Marking it Withdrawn on mobile
     * must CLEAR that banner — otherwise the portal-status mapper resolves the
     * stale banner ahead of the base status and the listing stays live on P24.
     * The web edit form already clears it; the mobile path must match.
     */
    public function test_mobile_withdraw_clears_stale_on_market_banner(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $property = $this->makeProperty([
            'status'       => 'active',
            'status_label' => 'Reduced Price',
        ]);

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}", [
                'status' => 'withdrawn',
            ]);

        $res->assertOk();

        $property->refresh();
        $this->assertSame('withdrawn', $property->status);
        $this->assertNull($property->status_label,
            'Leaving the on-market "active" base must clear the P24 sub-label banner.');
    }

    public function test_options_emits_withdrawn_slug(): void
    {
        // The mobile dropdown is fed from active property_status setting items.
        // Seed the "Withdrawn" item explicitly so the contract is deterministic
        // regardless of which reference-data migrations the test DB carries.
        \App\Models\PropertySettingItem::create([
            'agency_id' => $this->agency->id,
            'group'     => \App\Models\PropertySettingItem::GROUP_STATUS,
            'name'      => 'Withdrawn',
            'active'    => true,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson('/api/v1/mobile/properties/options');

        $res->assertOk();
        $values = collect($res->json('statuses'))->pluck('value')->all();
        $this->assertContains('withdrawn', $values,
            'The options endpoint must offer the exact slug the web treats as off-market.');
        // …and that slug must be one the web actually reads as off-market.
        $this->assertContains('withdrawn', Property::OFF_MARKET_STATUSES);
    }
}
