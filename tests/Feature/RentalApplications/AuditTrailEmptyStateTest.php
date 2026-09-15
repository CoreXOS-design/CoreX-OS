<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conductor, 2026-09-09 — Johan loaded a genuinely clean application
 * (#12, deliberately built with no authoriser actions taken on it yet)
 * and found no Audit Trail section at all — indistinguishable from the
 * feature being missing entirely. It was neither missing nor broken:
 * @if($auditLog->isNotEmpty()) wrapped the WHOLE card, header included, so
 * zero entries meant zero output. "An empty state that says so is the fix."
 *
 * Also proves the shared unified view (corex.rental-applications.review)
 * renders the audit trail identically to both roles — Johan: "the agent
 * must be able to see what happened to their own submission."
 */
final class AuditTrailEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.co.za',
        ]);
    }

    private function application(): RentalApplication
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'under_assessment',
            'submitted_at' => now()->subDay(), 'submitted_for_approval_at' => now(),
        ]);
    }

    private function authoriser(): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$user->id]]);
        $this->agency->refresh();

        return $user;
    }

    public function test_authoriser_screen_shows_a_plain_language_empty_state_with_no_entries(): void
    {
        $ro = $this->authoriser();
        $app = $this->application();

        $response = $this->actingAs($ro)->get(route('corex.rental-applications.authorisation.show', $app));

        $response->assertOk();
        $response->assertSee('Audit Trail (0)');
        $response->assertSee('Nothing has happened on this application yet');
    }

    public function test_authoriser_screen_shows_real_entries_and_not_the_empty_state(): void
    {
        $ro = $this->authoriser();
        $app = $this->application();
        RentalApplicationAuditLog::create([
            'rental_application_id' => $app->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'user_id' => $ro->id, 'actor_type' => 'user', 'actor_label' => $ro->name, 'source' => 'authoriser',
            'event_category' => 'assessment', 'event_type' => 'income_struck', 'is_override' => false,
            'human_summary' => 'Struck out a income line: Monthly income — R10,000.00',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($ro)->get(route('corex.rental-applications.authorisation.show', $app));

        $response->assertOk();
        $response->assertSee('Audit Trail (1)');
        $response->assertSee('Struck out a income line');
        $response->assertDontSee('Nothing has happened on this application yet');
    }

    public function test_agent_review_screen_shows_the_same_empty_state_as_the_authoriser_screen(): void
    {
        $app = $this->application();
        $agent = User::find($app->created_by_user_id);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Audit Trail (0)');
        $response->assertSee('Nothing has happened on this application yet');
    }

    public function test_agent_review_screen_shows_the_same_entries_as_the_authoriser_screen(): void
    {
        $ro = $this->authoriser();
        $app = $this->application();
        $agent = User::find($app->created_by_user_id);
        RentalApplicationAuditLog::create([
            'rental_application_id' => $app->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'user_id' => $ro->id, 'actor_type' => 'user', 'actor_label' => $ro->name, 'source' => 'authoriser',
            'event_category' => 'decision', 'event_type' => 'declined', 'is_override' => false,
            'human_summary' => 'Declined',
            'reason' => 'Income does not cover the rent.',
            'created_at' => now(),
        ]);

        // Johan: "an agent seeing what happened to their own submission is
        // a feature not a leak" — the agent sees the SAME entry, unfiltered.
        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Audit Trail (1)');
        $response->assertSee('Declined');
        $response->assertSee('Income does not cover the rent.');
    }
}
