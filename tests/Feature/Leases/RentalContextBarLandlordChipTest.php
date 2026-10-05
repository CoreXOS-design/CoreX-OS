<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 follow-up, Item B — the shared <x-rental-context-bar> landlord chip
 * used to dead-end on "Not linked" when a property had no landlord/lessor
 * contact. This gives it the same treatment already shipped on the Lease Hub
 * (corex/leases/show.blade.php): "No landlord linked" + a "Link landlord"
 * action into the property's Contacts tab. One component change, no second
 * Lease::landlordContacts() implementation.
 */
final class RentalContextBarLandlordChipTest extends TestCase
{
    use RefreshDatabase;

    public function test_lease_screen_shows_no_landlord_linked_with_a_link_when_landlord_is_missing(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('No landlord linked');
        $response->assertSee('Link landlord');
        $response->assertSee(route('corex.properties.show', ['property' => $property, 'tab' => 'contacts']), false);
    }

    public function test_lease_screen_shows_landlord_names_once_linked_no_dead_end_copy(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        $landlord = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Owner', 'last_name' => 'Person',
            'email' => 'owner-' . uniqid() . '@example.test',
        ]);
        ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');

        $response = $this->actingAs(User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']))
            ->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Owner Person');
        $response->assertDontSee('No landlord linked');
    }

    public function test_property_rental_tab_shows_no_landlord_linked_with_a_link_when_landlord_is_missing(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.properties.show', ['property' => $property, 'tab' => 'rental']));

        $response->assertOk();
        $response->assertSee('No landlord linked');
        $response->assertSee('Link landlord');
    }

    /** @return array{0: Agency, 1: Branch, 2: Property} */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function baseLeaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);
    }
}
