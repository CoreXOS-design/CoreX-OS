<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * QA1 multi-tenancy sweep, 2026-09-12 — RentalApplicationGeneration carries
 * a real, always-populated agency_id (seal() has always set it) but had no
 * BelongsToAgency at all, so it was queryable fully unscoped by default.
 * Not reachable via any route today (every call site filters by
 * rental_application_id from an already-guarded parent), but a structural
 * gap this sweep was explicitly asked to check for.
 */
final class RentalApplicationGenerationAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_rows_are_invisible_to_a_different_agency_even_with_no_other_filter(): void
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

        RentalApplicationGeneration::withoutAgencyStamping(fn () => RentalApplicationGeneration::create([
            'rental_application_id' => $application->id, 'generation' => 1, 'agency_id' => $agencyA->id,
            'snapshot_json' => ['full_name' => 'Test Applicant'], 'submitted_at' => now(),
            'content_hash' => hash('sha256', 'x'), 'prev_hash' => null,
        ]));

        Auth::login($agentA);
        $this->assertSame(1, RentalApplicationGeneration::where('rental_application_id', $application->id)->count());
        Auth::logout();

        Auth::login($adminB);
        $this->assertSame(0, RentalApplicationGeneration::where('rental_application_id', $application->id)->count(), 'a different agency must never see this sealed generation, even via a direct query with no other filter');
        Auth::logout();
    }
}
