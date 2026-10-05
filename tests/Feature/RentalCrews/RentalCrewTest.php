<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrews;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalCrew;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-05 — Johan's ruling: "Agents and staff are never maintenance
 * crew. Crew are people with NO CoreX access, set up by the agency admin,
 * pickable on job cards." Proves: full CRUD (create/edit/archive/restore),
 * agency isolation, members management, unique-name-among-active (never
 * among archived — an archived crew's name is reusable).
 */
final class RentalCrewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Crew Test Agency', 'slug' => 'crew-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    public function test_create_a_crew_with_no_members_is_valid(): void
    {
        $response = $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Team 1']);

        $response->assertRedirect();
        $crew = RentalCrew::firstWhere('name', 'Team 1');
        $this->assertNotNull($crew);
        $this->assertSame($this->agency->id, $crew->agency_id);
        $this->assertCount(0, $crew->members);
    }

    public function test_full_crud_create_edit_archive_restore(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team A', 'created_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->put(route('corex.rental-crews.update', $crew), ['name' => 'Team A Renamed', 'is_active' => '1'])
            ->assertRedirect();
        $this->assertSame('Team A Renamed', $crew->fresh()->name);

        $this->actingAs($this->admin)->delete(route('corex.rental-crews.archive', $crew))->assertRedirect();
        $this->assertSoftDeleted($crew);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.restore', $crew->id))->assertRedirect();
        $this->assertNotSoftDeleted($crew->fresh());
    }

    public function test_members_full_crud(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team B', 'created_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.members.store', $crew), [
            'name' => 'Sipho Dlamini', 'phone' => '0821234567', 'role' => 'Plumber',
        ])->assertRedirect();
        $member = $crew->members()->firstWhere('name', 'Sipho Dlamini');
        $this->assertNotNull($member);
        $this->assertSame('Plumber', $member->role);

        $this->actingAs($this->admin)->put(route('corex.rental-crews.members.update', [$crew, $member]), ['name' => 'Sipho D.'])
            ->assertRedirect();
        $this->assertSame('Sipho D.', $member->fresh()->name);

        $this->actingAs($this->admin)->delete(route('corex.rental-crews.members.archive', [$crew, $member]))->assertRedirect();
        $this->assertSoftDeleted($member);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.members.restore', [$crew, $member->id]))->assertRedirect();
        $this->assertNotSoftDeleted($member->fresh());
    }

    public function test_a_crew_with_several_members_is_valid(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team C', 'created_by_user_id' => $this->admin->id]);
        $crew->members()->create(['agency_id' => $this->agency->id, 'name' => 'A', 'created_by_user_id' => $this->admin->id]);
        $crew->members()->create(['agency_id' => $this->agency->id, 'name' => 'B', 'created_by_user_id' => $this->admin->id]);

        $this->assertCount(2, $crew->fresh()->members);
    }

    // ── Agency isolation ──────────────────────────────────────────────────

    public function test_a_crew_from_another_agency_is_not_reachable(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Crew Agency', 'slug' => 'other-crew-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherCrew = RentalCrew::create(['agency_id' => $otherAgency->id, 'name' => 'Other Team', 'created_by_user_id' => $otherAdmin->id]);

        $this->actingAs($this->admin)->get(route('corex.rental-crews.edit', $otherCrew))->assertNotFound();
    }

    public function test_index_only_lists_this_agencys_crews(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Crew Agency 2', 'slug' => 'other-crew2-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        RentalCrew::create(['agency_id' => $otherAgency->id, 'name' => 'Invisible Team', 'created_by_user_id' => $otherAdmin->id]);
        RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Visible Team', 'created_by_user_id' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->get(route('corex.rental-crews.index'));

        $response->assertOk();
        $response->assertSee('Visible Team');
        $response->assertDontSee('Invisible Team');
    }

    // ── Unique per agency among ACTIVE crews only ────────────────────────

    public function test_cannot_create_two_active_crews_with_the_same_name(): void
    {
        RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team D', 'created_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Team D'])
            ->assertSessionHasErrors('name');
    }

    public function test_an_archived_crews_name_can_be_reused_by_a_new_crew(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team E', 'created_by_user_id' => $this->admin->id]);
        $crew->archive();

        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Team E'])
            ->assertRedirect();

        $this->assertSame(2, RentalCrew::withTrashed()->where('agency_id', $this->agency->id)->where('name', 'Team E')->count());
    }

    public function test_cross_agency_same_name_is_allowed(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Crew Agency 3', 'slug' => 'other-crew3-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        RentalCrew::create(['agency_id' => $otherAgency->id, 'name' => 'Shared Name Team', 'created_by_user_id' => $otherAdmin->id]);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Shared Name Team'])
            ->assertRedirect();

        $this->assertNotNull(RentalCrew::where('agency_id', $this->agency->id)->where('name', 'Shared Name Team')->first());
    }

    // ── No hard deletes ────────────────────────────────────────────────

    public function test_archiving_never_hard_deletes(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team F', 'created_by_user_id' => $this->admin->id]);
        $id = $crew->id;

        $this->actingAs($this->admin)->delete(route('corex.rental-crews.archive', $crew))->assertRedirect();

        $this->assertDatabaseHas('rental_crews', ['id' => $id]);
        $this->assertNotNull(RentalCrew::withTrashed()->find($id)->deleted_at);
    }
}
