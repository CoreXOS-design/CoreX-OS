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
 * AT-392 — Johan, 2026-09-07, permanent standard (mid-build, applied
 * immediately, not deferred): "we always need proper crud? search / sort /
 * own / branch / agency levels. that should be the design standard...
 * from the word go."
 *
 * Own/branch/agency is enforced via RentalApplication::scopeVisibleTo() +
 * AuthorizesRentalApplicationAccess::guardRentalApplication() — the exact
 * PermissionService::getDataScope() mechanism the Documents module already
 * uses, so this is wiring an existing pattern into a new module, not new
 * architecture. On a fresh (unseeded) permissions table, PermissionService's
 * own AT-265 fallback resolves 'admin' → 'all', 'branch_manager' → 'branch',
 * 'agent' → 'own' — used below to exercise all three tiers without needing
 * real role_permissions rows.
 */
final class RentalApplicationCrudStandardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(User $creator, Branch $branch, array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $creator->id, 'status' => 'sent',
        ], $attrs));
    }

    // ── Search ────────────────────────────────────────────────────────

    public function test_search_matches_contact_name_email_property_and_id(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $target = $this->application($admin, $this->branchA, ['property_address_override' => '19 Windsor Avenue']);
        $this->application($admin, $this->branchA, ['property_address_override' => 'Somewhere else entirely']);

        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['q' => 'Ndlovu']))
            ->assertOk()->assertSee('Sipho');
        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['q' => 'sipho@example.co.za']))
            ->assertOk()->assertSee('Sipho');
        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['q' => 'Windsor Avenue']))
            ->assertOk()->assertSee('Windsor Avenue')->assertDontSee('Somewhere else entirely');
        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['q' => (string) $target->id]))
            ->assertOk()->assertSee('Windsor Avenue');
    }

    public function test_search_with_no_matches_shows_a_real_empty_state(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->application($admin, $this->branchA);

        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['q' => 'ZZZNoMatch']))
            ->assertOk()->assertSee('No rental applications match this search');
    }

    // ── Sort ──────────────────────────────────────────────────────────

    public function test_sort_by_status_changes_row_order(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->application($admin, $this->branchA, ['status' => 'sent', 'property_address_override' => 'Marker Sent Row']);
        $this->application($admin, $this->branchA, ['status' => 'in_progress', 'property_address_override' => 'Marker InProgress Row']);

        // `status` is a MySQL enum; it sorts by DECLARED index
        // (RentalApplication::STATUSES: 'sent' index 1, 'in_progress' index
        // 2), never alphabetically — asc puts 'sent' first.
        $ascending = $this->actingAs($admin)->get(route('corex.rental-applications.index', ['sort' => 'status', 'direction' => 'asc']))->getContent();
        $this->assertLessThan(strpos($ascending, 'Marker InProgress Row'), strpos($ascending, 'Marker Sent Row'));

        $descending = $this->actingAs($admin)->get(route('corex.rental-applications.index', ['sort' => 'status', 'direction' => 'desc']))->getContent();
        $this->assertLessThan(strpos($descending, 'Marker Sent Row'), strpos($descending, 'Marker InProgress Row'));
    }

    // ── Date range ────────────────────────────────────────────────────

    public function test_date_range_excludes_applications_outside_the_window(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->application($admin, $this->branchA);

        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['date_from' => '2020-01-01', 'date_to' => '2020-01-02']))
            ->assertOk()->assertSee('No rental applications match this search');
    }

    // ── Restore ───────────────────────────────────────────────────────

    public function test_restore_brings_an_archived_application_back(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $app = $this->application($admin, $this->branchA, ['property_address_override' => 'Restore Me Marker']);
        $app->delete();

        $this->actingAs($admin)->get(route('corex.rental-applications.index', ['archived' => 1]))
            ->assertOk()->assertSee('Restore Me Marker');

        $response = $this->actingAs($admin)->post(route('corex.rental-applications.restore', $app->id));

        $response->assertRedirect();
        $this->assertFalse($app->fresh()->trashed());
        $this->actingAs($admin)->get(route('corex.rental-applications.index'))->assertSee('Restore Me Marker');
    }

    // ── Own / branch / agency scoping ────────────────────────────────

    public function test_agent_role_own_scope_only_sees_applications_they_created(): void
    {
        $agentA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentB = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $this->assertSame('own', \App\Services\PermissionService::getDataScope($agentA, 'rental_applications'));

        $mine = $this->application($agentA, $this->branchA, ['property_address_override' => 'Mine Marker']);
        $theirs = $this->application($agentB, $this->branchA, ['property_address_override' => 'Theirs Marker']);

        $this->actingAs($agentA)->get(route('corex.rental-applications.index'))
            ->assertOk()->assertSee('Mine Marker')->assertDontSee('Theirs Marker');

        $this->actingAs($agentA)->get(route('corex.rental-applications.show', $theirs))->assertStatus(403);
        $this->actingAs($agentA)->get(route('corex.rental-applications.show', $mine))->assertOk();
    }

    public function test_branch_manager_branch_scope_sees_their_branch_only(): void
    {
        $bmBranchA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'branch_manager']);
        $this->assertSame('branch', \App\Services\PermissionService::getDataScope($bmBranchA, 'rental_applications'));

        $agentInA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentInB = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id, 'role' => 'agent']);
        $inBranchA = $this->application($agentInA, $this->branchA, ['property_address_override' => 'In Branch A Marker']);
        $inBranchB = $this->application($agentInB, $this->branchB, ['property_address_override' => 'In Branch B Marker']);

        $this->actingAs($bmBranchA)->get(route('corex.rental-applications.index'))
            ->assertOk()->assertSee('In Branch A Marker')->assertDontSee('In Branch B Marker');

        $this->actingAs($bmBranchA)->get(route('corex.rental-applications.show', $inBranchB))->assertStatus(403);
        $this->actingAs($bmBranchA)->get(route('corex.rental-applications.show', $inBranchA))->assertOk();
    }

    public function test_admin_agency_scope_sees_every_branch_within_the_agency(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->assertSame('all', \App\Services\PermissionService::getDataScope($admin, 'rental_applications'));

        $agentInA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentInB = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id, 'role' => 'agent']);
        $this->application($agentInA, $this->branchA, ['property_address_override' => 'Branch A App Marker']);
        $this->application($agentInB, $this->branchB, ['property_address_override' => 'Branch B App Marker']);

        $this->actingAs($admin)->get(route('corex.rental-applications.index'))
            ->assertOk()->assertSee('Branch A App Marker')->assertSee('Branch B App Marker');
    }

    public function test_a_different_agency_admin_is_blocked_by_direct_url_not_just_hidden(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $app = $this->application($admin, $this->branchA);

        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        // A cross-agency id 404s at route-model-binding (BelongsToAgency's
        // global scope never resolves the row for a different tenant) —
        // proven with an actual request, not asserted.
        $this->actingAs($otherAdmin)->get(route('corex.rental-applications.show', $app))->assertStatus(404);
        $this->actingAs($otherAdmin)->get(route('corex.rental-applications.pdf', $app))->assertStatus(404);
    }
}
