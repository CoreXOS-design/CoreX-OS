<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 2026-09-22, verbatim: "tenant is approved. open and select
 * property. the monthly rental, deposit should populate from the property
 * screen." The property picker on the approved-application tenant-link form
 * (view-readonly.blade.php) fetches this endpoint; its JSON now carries each
 * property's own rental_amount/deposit_amount so the Alpine select()
 * handler can pre-fill the terms fields as a still-editable starting value.
 * A property with no value for one of them must resolve to null here, never
 * 0 -- the Blade/Alpine side is what turns null into an empty field.
 */
final class RentalApplicationSearchPropertiesRentalDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    public function test_search_results_carry_the_propertys_rental_amount_and_deposit(): void
    {
        Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Flat in Ramsgate', 'status' => 'active', 'property_type' => 'flat', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '12 Test Road',
            'rental_amount' => 8500, 'deposit_amount' => 8500,
        ]);

        $resp = $this->actingAs($this->agent)->get(route('corex.rental-applications.search-properties', ['q' => 'Ramsgate']));

        $resp->assertOk();
        $result = $resp->json()[0];
        self::assertEquals(8500, $result['rental_amount']);
        self::assertEquals(8500, $result['deposit_amount']);
    }

    public function test_a_property_with_no_rental_details_resolves_to_null_not_zero(): void
    {
        Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Bare Flat in Ramsgate', 'status' => 'active', 'property_type' => 'flat', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '13 Test Road',
        ]);

        $resp = $this->actingAs($this->agent)->get(route('corex.rental-applications.search-properties', ['q' => 'Ramsgate']));

        $resp->assertOk();
        $result = $resp->json()[0];
        self::assertNull($result['rental_amount']);
        self::assertNull($result['deposit_amount']);
    }
}
