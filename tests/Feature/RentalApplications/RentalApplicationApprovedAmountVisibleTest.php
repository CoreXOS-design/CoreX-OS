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
 * cc4 walk, finding 5, 2026-09-13 — approving an application saved
 * approved_rental_amount correctly but never surfaced it anywhere on
 * view-readonly.blade.php (what RentalApplicationController::show() renders
 * for every AGENT_EDIT_LOCKED_STATUSES status, including approved) — an
 * agent had no way to answer "what did we approve them for?" from the one
 * screen built to tell them. Fixed by showing it in the sticky header,
 * next to the status badge, unconditionally visible.
 */
final class RentalApplicationApprovedAmountVisibleTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_amount_renders_in_the_sticky_header(): void
    {
        $agency = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'Contact', 'email' => 'contact-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $agent->id,
            'status' => 'approved', 'approved_rental_amount' => 9500.00,
            'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $application));

        $response->assertOk();
        $response->assertSee('Approved for R9,500.00 a month', false);
    }

    public function test_no_amount_line_when_not_approved(): void
    {
        $agency = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'Contact', 'email' => 'contact-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $agent->id,
            'status' => 'declined', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $application));

        $response->assertOk();
        $response->assertDontSee('a month', false);
    }
}
