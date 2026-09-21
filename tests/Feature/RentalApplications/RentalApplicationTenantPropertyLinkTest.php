<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan: "on approval then we have a way for the agent to link the
 * application to a property... when an approved tenant is linked the
 * property changes to let out status." The status half was built and then
 * deliberately pulled apart by the conductor's ruling, 2026-09-13, after an
 * investigation found flipping to let_out risked a live Property24/Private
 * Property listing silently vanishing (DesyndicatePropertyFromPortalsJob's
 * off-market-delist path does not recognise the RENTED lifecycle as
 * protected the way it protects SOLD). See .ai/specs/rental-applications.md,
 * "Property status side effects — DO NOT flip on tenant link".
 *
 * These tests lock in the decoupled shape: linking a tenant writes ONLY the
 * contact_property pivot (role='tenant') and never touches Property::status,
 * is additive (a second approved application can link a second tenant to
 * the same property without disturbing the first), and unlinking removes
 * only the pivot row — nothing is deleted, no status is ever restored
 * because none was ever changed.
 */
final class RentalApplicationTenantPropertyLinkTest extends TestCase
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

    private function rentalProperty(string $status = 'active'): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'House to let in Ramsgate', 'status' => $status, 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
    }

    private function contact(string $email): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => $email, 'phone' => '0821234567',
        ]);
    }

    private function application(Contact $contact, string $status = 'approved'): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status,
        ]);
    }

    public function test_linking_a_tenant_writes_the_pivot_and_never_touches_property_status(): void
    {
        $property = $this->rentalProperty('active');
        $contact = $this->contact('tenant1@example.co.za');
        $app = $this->application($contact);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $app), [
            'property_id' => $property->id,
        ])->assertRedirect();

        $this->assertTrue(
            $contact->properties()->wherePivot('role', 'tenant')->where('properties.id', $property->id)->exists()
        );
        $this->assertSame('active', $property->fresh()->status);
        $this->assertNull($property->fresh()->pre_tenant_link_status);
        $this->assertSame($property->id, $app->fresh()->property_id);
    }

    public function test_a_second_approved_application_can_link_a_second_tenant_to_the_same_property(): void
    {
        $property = $this->rentalProperty('active');
        $contactA = $this->contact('tenantA@example.co.za');
        $contactB = $this->contact('tenantB@example.co.za');
        $appA = $this->application($contactA);
        $appB = $this->application($contactB);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $appA), ['property_id' => $property->id]);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $appB), ['property_id' => $property->id]);

        $this->assertTrue($contactA->properties()->wherePivot('role', 'tenant')->where('properties.id', $property->id)->exists());
        $this->assertTrue($contactB->properties()->wherePivot('role', 'tenant')->where('properties.id', $property->id)->exists());
        $this->assertSame('active', $property->fresh()->status);
    }

    public function test_unlinking_removes_only_the_pivot_row_and_never_restores_a_status(): void
    {
        $property = $this->rentalProperty('active');
        $contact = $this->contact('tenant2@example.co.za');
        $app = $this->application($contact);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $app), ['property_id' => $property->id]);
        $this->assertTrue($contact->properties()->wherePivot('role', 'tenant')->exists());

        $this->actingAs($this->agent)->delete(route('corex.rental-applications.unlink-tenant-property', $app))->assertRedirect();

        $this->assertFalse($contact->properties()->wherePivot('role', 'tenant')->where('properties.id', $property->id)->exists());
        $this->assertSame('active', $property->fresh()->status);
        // The contact and property both still exist — nothing hard-deleted.
        $this->assertNotNull($contact->fresh());
        $this->assertNotNull($property->fresh());
    }

    public function test_only_an_approved_application_can_link_a_tenant(): void
    {
        $property = $this->rentalProperty('active');
        $contact = $this->contact('tenant3@example.co.za');
        $app = $this->application($contact, status: 'under_assessment');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $app), [
            'property_id' => $property->id,
        ])->assertStatus(422);

        $this->assertFalse($contact->properties()->wherePivot('role', 'tenant')->exists());
    }

    public function test_linking_a_tenant_to_an_already_off_market_property_does_not_disturb_its_status(): void
    {
        $property = $this->rentalProperty('let_out');
        $contact = $this->contact('tenant4@example.co.za');
        $app = $this->application($contact);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $app), [
            'property_id' => $property->id,
        ])->assertRedirect();

        $this->assertTrue($contact->properties()->wherePivot('role', 'tenant')->exists());
        $this->assertSame('let_out', $property->fresh()->status);
    }

    /**
     * Johan, QA1 walk, 2026-09-21 — "Im going from an approved tenant to
     * linking them to a property etc. the search on application status is
     * wrong. its displays the header and not the property address." This
     * is the exact screen (RentalApplicationController::show() ->
     * view-readonly.blade.php's "Link as tenant" section) — proves the
     * already-linked property's own label is the address, not the
     * listing's marketing title, and that the search endpoint it calls
     * returns the same.
     */
    public function test_the_link_as_tenant_screen_shows_the_address_not_the_listing_title(): void
    {
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Modern family home with sea views', 'status' => 'active', 'property_type' => 'house',
            'listing_type' => 'rental', 'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
            'address' => '42 Marine Drive',
        ]);
        $contact = $this->contact('tenant5@example.co.za');
        $app = $this->application($contact);
        $app->update(['property_id' => $property->id]);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertSee($property->buildDisplayAddress());
        $response->assertDontSee('Modern family home with sea views');
    }
}
