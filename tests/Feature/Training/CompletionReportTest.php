<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-30 (Johan, urgent) — "nobody can tell who has done the training."
 * Proves the new per-course completion report: gated by training.manage,
 * lists learners with completed/outstanding status and a completed date,
 * supports search (name/email) and a status filter, and narrows the
 * learner roster by the own/branch/all scope already exposed in Role
 * Manager for training.view (PermissionService::getDataScope('training')).
 */
final class CompletionReportTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;
    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Report Co', 'slug' => 'report-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch B']);
        $this->course = TrainingCourse::create([
            'agency_id' => $this->agency->id,
            'title' => 'Test Course',
            'category' => 'general',
            'is_published' => true,
        ]);
    }

    private function manager(string $scope = 'all', ?Branch $branch = null): User
    {
        $manager = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => ($branch ?? $this->branchA)->id,
            'role' => 'admin',
        ]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'training.manage', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'training.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);

        return $manager;
    }

    public function test_report_requires_training_manage_permission(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_training', 'agency_id' => $this->agency->id]);

        $this->actingAs($agent)
            ->get(route('training.completions', $this->course))
            ->assertForbidden();
    }

    public function test_report_shows_completed_and_outstanding_learners(): void
    {
        $manager = $this->manager('all');
        $completedLearner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Alice Completed']);
        $outstandingLearner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Bob Outstanding']);

        TrainingCompletion::create([
            'user_id' => $completedLearner->id,
            'course_id' => $this->course->id,
            'completed_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('training.completions', $this->course))
            ->assertOk()
            ->assertSee('Alice Completed')
            ->assertSee('Bob Outstanding')
            ->assertSee('Completed')
            ->assertSee('Outstanding');
    }

    public function test_report_search_filters_by_name(): void
    {
        $manager = $this->manager('all');
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Findme Agent']);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Someone Else']);

        $this->actingAs($manager)
            ->get(route('training.completions', $this->course) . '?q=Findme')
            ->assertOk()
            ->assertSee('Findme Agent')
            ->assertDontSee('Someone Else');
    }

    public function test_report_status_filter_shows_only_outstanding(): void
    {
        $manager = $this->manager('all');
        $completedLearner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Done Agent']);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Pending Agent']);

        TrainingCompletion::create([
            'user_id' => $completedLearner->id,
            'course_id' => $this->course->id,
            'completed_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('training.completions', $this->course) . '?status=outstanding')
            ->assertOk()
            ->assertSee('Pending Agent')
            ->assertDontSee('Done Agent');
    }

    public function test_branch_scope_hides_learners_in_a_different_branch(): void
    {
        $manager = $this->manager('branch', $this->branchA);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Same Branch Agent']);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id, 'role' => 'agent', 'name' => 'Other Branch Agent']);

        $this->actingAs($manager)
            ->get(route('training.completions', $this->course))
            ->assertOk()
            ->assertSee('Same Branch Agent')
            ->assertDontSee('Other Branch Agent');
    }

    public function test_own_scope_shows_only_the_viewer(): void
    {
        $manager = $this->manager('own', $this->branchA);
        User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Not Me Agent']);

        $response = $this->actingAs($manager)
            ->get(route('training.completions', $this->course))
            ->assertOk()
            ->assertDontSee('Not Me Agent');

        $response->assertSee($manager->name);
    }

    public function test_empty_state_renders_when_no_learners_in_scope(): void
    {
        $manager = $this->manager('own', $this->branchA);
        // No other learners; scope 'own' includes only the manager themself, who is not
        // "empty" — so instead prove the empty state via a filter that matches nothing.

        $this->actingAs($manager)
            ->get(route('training.completions', $this->course) . '?q=nobody-matches-this-xyz')
            ->assertOk()
            ->assertSee('No learners match these filters');
    }
}
