<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Co-listing visibility: a property with a primary (agent_id) and a secondary
 * (pp_second_agent_id) agent must appear under BOTH agents' "My properties" on
 * the mobile app, and both must be able to open it — mirroring the web
 * PropertyController. An unrelated same-agency agent (own scope) sees neither.
 */
class MobileColistingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
    }

    private function agent(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    private function makeProperty(User $primary, ?User $secondary = null): Property
    {
        return Property::create([
            'agency_id'          => $this->agency->id,
            'agent_id'           => $primary->id,
            'pp_second_agent_id' => $secondary?->id,
            'branch_id'          => $this->branch->id,
            'title'              => 'Co-listed 3 bed',
            'suburb'             => 'Uvongo',
            'city'               => 'Margate',
            'province'           => 'KwaZulu-Natal',
            'property_type'      => 'house',
            'listing_type'       => 'sale',
            'status'             => 'active',
            'price'              => 2495000,
        ]);
    }

    private function listIds(User $user): array
    {
        $res = $this->actingAs($user)->getJson('/api/v1/mobile/properties');
        $res->assertOk();
        return collect($res->json('properties'))->pluck('id')->all();
    }

    public function test_colisted_property_appears_for_both_agents(): void
    {
        $primary   = $this->agent();
        $secondary = $this->agent();
        $property  = $this->makeProperty($primary, $secondary);

        $this->assertContains($property->id, $this->listIds($primary), 'Primary agent should see it.');
        $this->assertContains($property->id, $this->listIds($secondary), 'Secondary agent should see it.');
    }

    public function test_secondary_agent_can_open_the_property(): void
    {
        $primary   = $this->agent();
        $secondary = $this->agent();
        $property  = $this->makeProperty($primary, $secondary);

        $this->actingAs($secondary)
            ->getJson("/api/v1/mobile/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('property.id', $property->id);
    }

    public function test_unrelated_agent_sees_nothing_and_is_blocked(): void
    {
        $primary   = $this->agent();
        $secondary = $this->agent();
        $other     = $this->agent();
        $property  = $this->makeProperty($primary, $secondary);

        $this->assertNotContains($property->id, $this->listIds($other));

        $this->actingAs($other)
            ->getJson("/api/v1/mobile/properties/{$property->id}")
            ->assertStatus(403);
    }
}
