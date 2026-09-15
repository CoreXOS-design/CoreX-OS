<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA1 multi-tenancy sweep, 2026-09-12 — Johan: "we have found TWO
 * cross-agency holes in two days, both the same root cause — Laravel's
 * exists: validation rule runs a raw table query that BYPASSES Eloquent
 * global scopes." store()'s `contact_id` rule was the same bypassing
 * shape, provably safe by accident only because of a downstream
 * Contact::findOrFail() — converted to ExistsInScope so the validation
 * layer is correct on its own, not safe by accident.
 */
final class RentalApplicationContactIdAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $agentA;
    private Contact $contactA;
    private Contact $contactB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);
        $branchA = Branch::create(['agency_id' => $agencyA->id, 'name' => 'Branch A']);
        $branchB = Branch::create(['agency_id' => $agencyB->id, 'name' => 'Branch B']);

        $this->agentA = User::factory()->create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin']);
        $this->contactA = Contact::create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'first_name' => 'A', 'last_name' => 'Contact', 'email' => 'contact-a-' . uniqid() . '@example.test']);
        $this->contactB = Contact::create(['agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'first_name' => 'B', 'last_name' => 'Contact', 'email' => 'contact-b-' . uniqid() . '@example.test']);
    }

    public function test_store_refuses_a_cross_agency_contact_id_and_creates_nothing(): void
    {
        $response = $this->actingAs($this->agentA)->post(route('corex.rental-applications.store'), [
            'contact_id' => $this->contactB->id,
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, RentalApplication::withoutGlobalScopes()->where('contact_id', $this->contactB->id)->count());
    }

    public function test_store_still_accepts_a_genuine_same_agency_contact_id(): void
    {
        $response = $this->actingAs($this->agentA)->post(route('corex.rental-applications.store'), [
            'contact_id' => $this->contactA->id,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, RentalApplication::where('contact_id', $this->contactA->id)->count());
    }
}
