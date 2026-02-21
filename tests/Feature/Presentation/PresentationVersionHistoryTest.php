<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests presentation version history endpoints (P17).
 */
class PresentationVersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch       $branch;
    private Branch       $otherBranch;
    private User         $admin;
    private User         $bm;
    private User         $agent;
    private Presentation $presentation;
    private Presentation $otherPresentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch      = Branch::create(['name' => 'Branch A', 'code' => 'A', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Branch B', 'code' => 'B', 'is_active' => true]);

        $this->admin = User::factory()->create(['role' => 'admin',          'branch_id' => $this->branch->id]);
        $this->bm    = User::factory()->create(['role' => 'branch_manager', 'branch_id' => $this->branch->id]);
        $this->agent = User::factory()->create(['role' => 'agent',          'branch_id' => $this->branch->id]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->agent->id,
            'title'              => 'Pres A',
            'property_address'   => '1 A St',
            'suburb'             => 'Alpha',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        $this->otherPresentation = Presentation::create([
            'branch_id'          => $this->otherBranch->id,
            'created_by_user_id' => $this->admin->id,
            'title'              => 'Pres B',
            'property_address'   => '2 B St',
            'suburb'             => 'Beta',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function makeVersion(Presentation $p, User $compiledBy): PresentationVersion
    {
        return PresentationVersion::create([
            'presentation_id'   => $p->id,
            'compiled_by'       => $compiledBy->id,
            'blueprint_version' => 'v1',
            'data_snapshot_json'=> '{}',
            'compiled_at'       => now(),
        ]);
    }

    // ── /presentations/versions (admin/BM) ────────────────────────────────────

    public function test_admin_can_see_all_versions(): void
    {
        $v1 = $this->makeVersion($this->presentation, $this->admin);
        $v2 = $this->makeVersion($this->otherPresentation, $this->admin);

        $this->actingAs($this->admin);

        $response = $this->get(route('presentations.versions.index'));

        $response->assertOk();
        $response->assertSeeText('Pres A');
        $response->assertSeeText('Pres B');
    }

    public function test_bm_sees_only_their_branch_versions(): void
    {
        $this->makeVersion($this->presentation, $this->bm);
        $this->makeVersion($this->otherPresentation, $this->admin);

        $this->actingAs($this->bm);

        $response = $this->get(route('presentations.versions.index'));

        $response->assertOk();
        $response->assertSeeText('Pres A');
        $response->assertDontSeeText('Pres B');
    }

    public function test_agent_cannot_access_admin_versions_index(): void
    {
        $this->actingAs($this->agent);

        $this->get(route('presentations.versions.index'))
             ->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_versions_index(): void
    {
        $this->get(route('presentations.versions.index'))
             ->assertRedirect('/login');
    }

    public function test_admin_can_filter_by_presentation_id(): void
    {
        $v1 = $this->makeVersion($this->presentation, $this->admin);
        $v2 = $this->makeVersion($this->otherPresentation, $this->admin);

        $this->actingAs($this->admin);

        $response = $this->get(route('presentations.versions.index', ['presentation_id' => $this->presentation->id]));

        $response->assertOk();
        $response->assertSeeText('Pres A');
        $response->assertDontSeeText('Pres B');
    }

    // ── /my/presentations/versions (agent) ───────────────────────────────────

    public function test_agent_sees_own_versions_only(): void
    {
        $this->makeVersion($this->presentation, $this->agent);
        $this->makeVersion($this->otherPresentation, $this->admin);

        $this->actingAs($this->agent);

        $response = $this->get(route('presentations.versions.mine'));

        $response->assertOk();
        $response->assertSeeText('Pres A');
        $response->assertDontSeeText('Pres B');
    }

    public function test_mine_requires_auth(): void
    {
        $this->get(route('presentations.versions.mine'))
             ->assertRedirect('/login');
    }

    public function test_mine_returns_empty_table_when_no_versions(): void
    {
        $this->actingAs($this->agent);

        $response = $this->get(route('presentations.versions.mine'));

        $response->assertOk();
        $response->assertSeeText('No compiled versions found.');
    }

    public function test_admin_can_filter_by_period(): void
    {
        // Version compiled in this month
        $this->makeVersion($this->presentation, $this->admin);

        $this->actingAs($this->admin);

        $currentPeriod = now()->format('Y-m');

        $response = $this->get(route('presentations.versions.index', ['period' => $currentPeriod]));

        $response->assertOk();
        $response->assertSeeText('Pres A');
    }
}
