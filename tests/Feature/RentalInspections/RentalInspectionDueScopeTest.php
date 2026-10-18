<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInspectionSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Due board enforces own / branch / agency visibility at the QUERY layer (spec rental-inspections §45.7, §51) — on the
 * screen, the printed list and the CSV, for the computed move-in/out items AND the loaded dates. A requested wider scope
 * (?scope=all) never widens a narrower role. Every fixture lease starts in the past, so each property has an overdue Move-in.
 */
final class RentalInspectionDueScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $main;
    private Branch $north;
    private User $admin;
    private User $agentA;
    private User $agentB;
    private User $northAgent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));

        $this->agency = Agency::create(['name' => 'Scope Agency', 'slug' => 'scope-' . uniqid()]);
        $this->main = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->north = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'North']);

        foreach (['agent' => 'own', 'branch_mgr' => 'branch', 'principal' => 'all'] as $role => $scope) {
            Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $this->agency->id]);
            foreach (['rental_inspections.view', 'rental_inspections.export'] as $key) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
            }
        }
        PermissionService::clearCache();

        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);

        $this->admin = $this->user('principal', $this->main);
        $this->agentA = $this->user('agent', $this->main);
        $this->agentB = $this->user('agent', $this->main);
        $this->northAgent = $this->user('agent', $this->north);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
    }

    /** A rental with an active, already-started lease (so a Move-in is overdue) and one loaded interim date carrying $note. */
    private function rental(string $title, User $agent, Branch $branch, string $note, ?Agency $agency = null): Lease
    {
        $agency ??= $this->agency;
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
        // created by the admin, so the lease does not "involve" any of the agents by creation
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31',
            'created_by_user_id' => $this->admin->id, 'source' => 'manual',
        ]);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Tenant',
            'last_name' => 'T' . uniqid(), 'email' => 'tenant-' . uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
        RentalInspectionPlannedDate::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'property_id' => $property->id, 'type' => 'interim',
            'planned_on' => '2026-10-05', 'status' => 'planned', 'created_by_user_id' => $this->admin->id, 'note' => $note,
        ]);

        return $lease;
    }

    /** @return array{screen:string, print:string, csv:string} everything the Due board would show this user. */
    private function boardFor(User $user, array $query = []): array
    {
        return [
            'screen' => $this->actingAs($user)->get(route('corex.rental-inspections.due', $query))->assertOk()->getContent(),
            'print' => $this->actingAs($user)->get(route('corex.rental-inspections.due.print', $query))->assertOk()->getContent(),
            'csv' => $this->actingAs($user)->get(route('corex.rental-inspections.due.export', $query + ['format' => 'csv']))->assertOk()->streamedContent(),
        ];
    }

    private function assertShows(array $board, string ...$needles): void
    {
        foreach ($board as $where => $content) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $content, "'{$needle}' should be on the {$where}");
            }
        }
    }

    private function assertHides(array $board, string ...$needles): void
    {
        foreach ($board as $where => $content) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $content, "'{$needle}' must NOT be on the {$where}");
            }
        }
    }

    public function test_an_own_scope_agent_sees_only_their_own_due_inspections_never_another_agents(): void
    {
        $this->rental('Alpha Row 11', $this->agentA, $this->main, 'note-alpha');
        $this->rental('Bravo Row 22', $this->agentB, $this->main, 'note-bravo');
        $this->rental('Charlie North 33', $this->northAgent, $this->north, 'note-charlie');

        $board = $this->boardFor($this->agentA);

        // their own: the computed Move-in AND the loaded date
        $this->assertShows($board, 'Alpha Row 11', 'note-alpha');
        // a colleague in the same branch, and a colleague in another branch: nothing — not the property, not the date's note
        $this->assertHides($board, 'Bravo Row 22', 'note-bravo', 'Charlie North 33', 'note-charlie');
    }

    public function test_asking_for_a_wider_scope_in_the_url_never_widens_an_own_scope_agent(): void
    {
        $this->rental('Alpha Row 11', $this->agentA, $this->main, 'note-alpha');
        $this->rental('Bravo Row 22', $this->agentB, $this->main, 'note-bravo');

        foreach (['all', 'branch'] as $asked) {
            $board = $this->boardFor($this->agentA, ['scope' => $asked]);
            $this->assertShows($board, 'Alpha Row 11');
            $this->assertHides($board, 'Bravo Row 22', 'note-bravo');
        }
    }

    public function test_a_branch_scope_manager_sees_their_branch_and_nothing_from_another_branch(): void
    {
        $manager = $this->user('branch_mgr', $this->main);
        $this->rental('Alpha Row 11', $this->agentA, $this->main, 'note-alpha');
        $this->rental('Bravo Row 22', $this->agentB, $this->main, 'note-bravo');
        $this->rental('Charlie North 33', $this->northAgent, $this->north, 'note-charlie');

        $board = $this->boardFor($manager);
        $this->assertShows($board, 'Alpha Row 11', 'Bravo Row 22', 'note-alpha', 'note-bravo');
        $this->assertHides($board, 'Charlie North 33', 'note-charlie');

        // and cannot widen to the agency by asking
        $this->assertHides($this->boardFor($manager, ['scope' => 'all']), 'Charlie North 33', 'note-charlie');
    }

    public function test_an_agency_scope_user_sees_every_branch_but_never_another_agencys_inspections(): void
    {
        $this->rental('Alpha Row 11', $this->agentA, $this->main, 'note-alpha');
        $this->rental('Charlie North 33', $this->northAgent, $this->north, 'note-charlie');

        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['agency_id' => $other->id, 'name' => 'Cape Town']);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
        $this->rental('Delta Foreign 44', $otherAgent, $otherBranch, 'note-delta', $other);

        $board = $this->boardFor($this->admin);

        $this->assertShows($board, 'Alpha Row 11', 'Charlie North 33', 'note-alpha', 'note-charlie');
        $this->assertHides($board, 'Delta Foreign 44', 'note-delta');
    }

    public function test_a_lease_the_agent_is_on_counts_as_theirs_even_when_the_property_is_a_colleagues(): void
    {
        // "own" means the property's agent OR a lease they created — the same definition on every Due surface
        $lease = $this->rental('Bravo Row 22', $this->agentB, $this->main, 'note-bravo');
        $this->assertHides($this->boardFor($this->agentA), 'Bravo Row 22');

        $lease->forceFill(['created_by_user_id' => $this->agentA->id])->save();

        $this->assertShows($this->boardFor($this->agentA), 'Bravo Row 22');
    }
}
