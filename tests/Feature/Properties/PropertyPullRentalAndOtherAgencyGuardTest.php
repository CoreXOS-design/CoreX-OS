<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyPullRentalAndOtherAgencyGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Caprivi Properties', 'trading_name' => 'Caprivi Properties (Pty) Ltd', 'slug' => 'cap-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        Sanctum::actingAs($this->agent);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'portal_ref' => 'P24-117728039',
            'title'      => '1 Bedroom Apartment / flat to rent in Glen Austin AH',
            'price'      => 6800,
            'suburb'     => 'Glen Austin AH',
        ], $over);
    }

    public function test_a_rental_pull_stores_the_rent_in_rental_amount(): void
    {
        $this->postJson('/api/v1/properties/pull-from-portal', $this->payload(['agency_name' => 'Caprivi Properties']))->assertOk();

        $p = Property::withoutGlobalScopes()->where('external_id', 'P24-117728039')->firstOrFail();
        $this->assertSame('rental', $p->listing_type);
        $this->assertEquals(6800, $p->rental_amount);
        $this->assertEquals(0, $p->price);
    }

    public function test_a_listing_from_another_agency_is_refused(): void
    {
        $this->postJson('/api/v1/properties/pull-from-portal', $this->payload(['agency_name' => 'Pam Golding Properties']))
            ->assertStatus(422);

        $this->assertSame(0, Property::withoutGlobalScopes()->where('external_id', 'P24-117728039')->count());
    }
}
