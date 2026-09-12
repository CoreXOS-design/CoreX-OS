<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationGeneration;
use App\Models\RentalApplicationSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * QA1 multi-tenancy sweep, 2026-09-12 — RentalApplicationSignature had no
 * `agency_id` column at all until today's migration, and no
 * BelongsToAgency — unlike every sibling model on this same rental
 * application. Not reachable via any route today (the only write site
 * always scopes by an already-token-resolved application, and no route
 * binds a signature id directly), but a tenant table with no agency_id
 * violates CLAUDE.md Non-negotiable #7 outright.
 */
final class RentalApplicationSignatureAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_rows_are_invisible_to_a_different_agency_even_with_no_other_filter(): void
    {
        $agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);
        $branchA = Branch::create(['agency_id' => $agencyA->id, 'name' => 'Branch A']);
        $branchB = Branch::create(['agency_id' => $agencyB->id, 'name' => 'Branch B']);
        $agentA = User::factory()->create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin']);
        $adminB = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'role' => 'admin']);
        $contactA = Contact::create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'first_name' => 'A', 'last_name' => 'Contact', 'email' => 'contact-a-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agencyA->id, 'branch_id' => $branchA->id,
            'contact_id' => $contactA->id, 'created_by_user_id' => $agentA->id,
            'status' => 'sent', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        RentalApplicationSignature::withoutAgencyStamping(fn () => RentalApplicationSignature::create([
            'rental_application_id' => $application->id, 'agency_id' => $agencyA->id,
            'kind' => 'declaration', 'generation' => 1, 'signature_path' => 'x.png', 'signed_at' => now(),
        ]));

        Auth::login($agentA);
        $this->assertSame(1, RentalApplicationSignature::where('rental_application_id', $application->id)->count());
        Auth::logout();

        Auth::login($adminB);
        $this->assertSame(0, RentalApplicationSignature::where('rental_application_id', $application->id)->count(), 'a different agency must never see this signature, even via a direct query with no other filter');
        Auth::logout();
    }

    public function test_public_submit_stamps_the_applications_own_agency_onto_generation_and_signatures(): void
    {
        $agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $branchA = Branch::create(['agency_id' => $agencyA->id, 'name' => 'Branch A']);
        $agentA = User::factory()->create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin']);
        $contactA = Contact::create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'first_name' => 'A', 'last_name' => 'Contact', 'email' => 'contact-a-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agencyA->id, 'branch_id' => $branchA->id,
            'contact_id' => $contactA->id, 'created_by_user_id' => $agentA->id,
            'status' => 'sent', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
            'token_expires_at' => now()->addDays(14),
        ]);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $response = $this->post(route('rental-applications.public.submit', $application->token), [
            'declaration_signature' => $png,
            'tpn_consent_signature' => $png,
        ]);

        $response->assertRedirect();
        $application->refresh();
        $this->assertNotSame('sent', $application->status);

        $generation = RentalApplicationGeneration::where('rental_application_id', $application->id)->first();
        $this->assertNotNull($generation);
        $this->assertSame($agencyA->id, $generation->agency_id);

        $signatures = RentalApplicationSignature::where('rental_application_id', $application->id)->get();
        $this->assertCount(2, $signatures);
        $this->assertTrue($signatures->every(fn ($s) => $s->agency_id === $agencyA->id));
    }
}
