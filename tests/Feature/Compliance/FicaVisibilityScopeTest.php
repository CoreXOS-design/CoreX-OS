<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-346 — per-user FICA visibility scoping (own / branch / company).
 *
 * Proves FicaSubmission::scopeVisibleTo() (the choke-point the list, the queue
 * tabs, and the per-record authorize gate all share) confines each user to their
 * granted tier: an agent to their own requests, a branch manager to their branch,
 * an admin to the whole agency. Scope is driven by the Role Manager `fica.view`
 * grant via PermissionService::getDataScope, mirroring Contacts/Properties.
 */
final class FicaVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchA;
    private int $branchB;
    private User $agent;      // own
    private User $bm;         // branch
    private User $admin;      // company
    private array $ids = [];  // s1..s4

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'AT346 ' . Str::random(5), 'slug' => 'at346-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchA = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Branch A', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchB = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Branch B', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // No role_permissions rows seeded → getDataScope uses the test-posture role
        // defaults: agent = own, branch_manager = branch, admin = all. None of these
        // roles is owner-flagged or an appointed CO, so the structural elevator does
        // not fire and the fica.view tier is what is actually under test.
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'role' => 'agent']);
        $this->bm    = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'role' => 'branch_manager']);
        $this->admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'role' => 'admin']);

        $this->ids['s1'] = $this->makeSub($this->agent->id, $this->branchA); // Branch A · agent's own
        $this->ids['s2'] = $this->makeSub($this->agent->id, $this->branchA); // Branch A · agent's own
        $this->ids['s3'] = $this->makeSub($this->bm->id, $this->branchA);    // Branch A · the BM's own
        $this->ids['s4'] = $this->makeSub($this->admin->id, $this->branchB); // Branch B · company only
    }

    private function makeSub(int $requestedBy, int $branchId): int
    {
        return (int) DB::table('fica_submissions')->insertGetId([
            'agency_id'        => $this->agencyId,
            'branch_id'        => $branchId,
            'requested_by'     => $requestedBy,
            'token'            => Str::random(40),
            'token_expires_at' => now()->addDays(30),
            'entity_type'      => 'natural',
            'status'           => 'submitted',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_agent_sees_only_their_own_requests(): void
    {
        $this->assertSame('own', FicaSubmission::ficaScopeFor($this->agent));
        $this->actingAs($this->agent);

        $ids = FicaSubmission::query()->visibleTo($this->agent)->pluck('id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$this->ids['s1'], $this->ids['s2']], $ids);
        $this->assertNotContains($this->ids['s3'], $ids, 'agent must not see a branch colleague’s FICA');
        $this->assertNotContains($this->ids['s4'], $ids);
    }

    public function test_branch_manager_sees_the_whole_branch(): void
    {
        $this->assertSame('branch', FicaSubmission::ficaScopeFor($this->bm));
        $this->actingAs($this->bm);

        $ids = FicaSubmission::query()->visibleTo($this->bm)->pluck('id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$this->ids['s1'], $this->ids['s2'], $this->ids['s3']], $ids);
        $this->assertNotContains($this->ids['s4'], $ids, 'branch manager must not see another branch’s FICA');
    }

    public function test_admin_sees_the_whole_company(): void
    {
        $this->assertSame('all', FicaSubmission::ficaScopeFor($this->admin));
        $this->actingAs($this->admin);

        $ids = FicaSubmission::query()->visibleTo($this->admin)->pluck('id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing(
            [$this->ids['s1'], $this->ids['s2'], $this->ids['s3'], $this->ids['s4']],
            $ids
        );
    }
}
