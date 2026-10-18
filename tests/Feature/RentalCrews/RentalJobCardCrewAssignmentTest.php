<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrews;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-05 — Johan's ruling: "Agents and staff are never maintenance
 * crew... the crew dropdown on job cards must NOT list CoreX users."
 * Proves: Assign dropdown lists only active crews, never users; archived
 * crew cannot be newly picked but still displays on a card it's already
 * on; a legacy assigned_user_id card shows "Previously assigned" read-
 * only and is never touched by new code; worker sign-off records a free-
 * text crew member name.
 */
final class RentalJobCardCrewAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Crew Assign Agency', 'slug' => 'crew-assign-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Office Admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Crew Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function jobCard(): RentalJobCard
    {
        return app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Job'], $this->admin);
    }

    public function test_assigning_a_crew_sets_rental_crew_id_not_assigned_user_id(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 1', 'created_by_user_id' => $this->admin->id]);
        $jobCard = $this->jobCard();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.assign-crew', $jobCard), ['rental_crew_id' => $crew->id])
            ->assertRedirect();

        $jobCard->refresh();
        $this->assertSame($crew->id, $jobCard->rental_crew_id);
        $this->assertNull($jobCard->assigned_user_id);
    }

    public function test_show_screen_dropdown_lists_crews_never_users(): void
    {
        RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 1', 'created_by_user_id' => $this->admin->id]);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent Smith']);
        $jobCard = $this->jobCard();

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        // Scoped to the Assign dropdown's own HTML, not the whole page — the
        // shared layout's unrelated "Switch User" admin widget legitimately
        // lists every agency user elsewhere on the page (Standard -1n: check
        // the raw HTML in the right place, not just "does this text appear
        // anywhere," which would false-fail on that widget).
        $dropdown = $this->extractSelect($response->getContent(), 'rental_crew_id');
        $this->assertStringContainsString('Team 1', $dropdown);
        $this->assertStringNotContainsString('Agent Smith', $dropdown);
        $this->assertStringNotContainsString('Office Admin', $dropdown);
    }

    private function extractSelect(string $html, string $name): string
    {
        preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '"[^>]*>.*?<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, "Could not find <select name=\"{$name}\"> in the response");

        return $m[0];
    }

    public function test_archived_crew_cannot_be_newly_assigned(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Retired Team', 'created_by_user_id' => $this->admin->id]);
        $crew->archive();
        $jobCard = $this->jobCard();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.assign-crew', $jobCard), ['rental_crew_id' => $crew->id])
            ->assertSessionHasErrors('rental_crew_id');

        $this->assertNull($jobCard->fresh()->rental_crew_id);
    }

    public function test_archived_crew_still_displays_on_a_card_already_assigned_to_it(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Soon Retired Team', 'created_by_user_id' => $this->admin->id]);
        $jobCard = $this->jobCard();
        $jobCard->assignCrew($crew, $this->admin);
        $crew->archive();

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        $response->assertSee('Soon Retired Team', false);
    }

    public function test_cross_agency_crew_cannot_be_assigned(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Crew Agency', 'slug' => 'other-ca-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherCrew = RentalCrew::create(['agency_id' => $otherAgency->id, 'name' => 'Other Agency Team', 'created_by_user_id' => $otherAdmin->id]);
        $jobCard = $this->jobCard();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.assign-crew', $jobCard), ['rental_crew_id' => $otherCrew->id])
            ->assertSessionHasErrors('rental_crew_id');
    }

    // ── History preserved — legacy assigned_user_id never touched/lost ──

    public function test_a_legacy_user_assigned_card_shows_previously_assigned_read_only(): void
    {
        $jobCard = $this->jobCard();
        // Simulate a pre-crews card — assigned_user_id set directly, no crew.
        $jobCard->forceFill(['assigned_user_id' => $this->admin->id])->save();

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        $response->assertSee('Previously assigned: Office Admin', false);

        // New code never touches assigned_user_id again, even when a crew is later assigned.
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'New Team', 'created_by_user_id' => $this->admin->id]);
        $jobCard->assignCrew($crew, $this->admin);
        $this->assertSame($this->admin->id, $jobCard->fresh()->assigned_user_id);
        $this->assertSame($crew->id, $jobCard->fresh()->rental_crew_id);
    }

    public function test_list_screen_crew_filter_excludes_users(): void
    {
        RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Filter Team', 'created_by_user_id' => $this->admin->id]);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Filter Agent']);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index'));

        $response->assertOk();
        $dropdown = $this->extractSelect($response->getContent(), 'rental_crew_id');
        $this->assertStringContainsString('Filter Team', $dropdown);
        $this->assertStringNotContainsString('Filter Agent', $dropdown);
    }

    // ── Worker sign-off records a free-text crew member name ─────────────

    public function test_worker_sign_off_records_an_optional_name(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Sign Off Team', 'created_by_user_id' => $this->admin->id]);
        $crew->members()->create(['agency_id' => $this->agency->id, 'name' => 'Sipho Dlamini', 'created_by_user_id' => $this->admin->id]);
        $jobCard = $this->jobCard();
        $jobCard->assignCrew($crew, $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $jobCard), ['worker_sign_off_name' => 'Sipho Dlamini'])
            ->assertRedirect();

        $jobCard->refresh();
        $this->assertNotNull($jobCard->worker_signed_off_at);
        $this->assertSame($this->admin->id, $jobCard->worker_signed_off_by_user_id); // the AGENT who recorded it
        $this->assertSame('Sipho Dlamini', $jobCard->worker_sign_off_name); // WHO on the crew actually did it
    }

    public function test_worker_sign_off_name_is_optional(): void
    {
        $jobCard = $this->jobCard();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $jobCard), [])
            ->assertRedirect();

        $this->assertNotNull($jobCard->fresh()->worker_signed_off_at);
        $this->assertNull($jobCard->fresh()->worker_sign_off_name);
    }

    public function test_show_screen_offers_the_assigned_crews_members_as_sign_off_suggestions(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Datalist Team', 'created_by_user_id' => $this->admin->id]);
        $crew->members()->create(['agency_id' => $this->agency->id, 'name' => 'Member One', 'created_by_user_id' => $this->admin->id]);
        $jobCard = $this->jobCard();
        $jobCard->assignCrew($crew, $this->admin);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard));

        $response->assertOk();
        $response->assertSee('Member One', false);
    }
}
