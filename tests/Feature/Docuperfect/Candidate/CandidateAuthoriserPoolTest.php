<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Candidate;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\CandidatePractitionerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Candidate-flow rework (Johan, confirmed 2026-08-03) — the eligible authoriser/approver POOL is
 * BRANCH-SCOPED: Branch Managers of the candidate's branch (role='branch_manager' assigned there OR
 * a user_managed_branches pivot manager of it) + full-status agents of that branch + agency admins
 * (agency-wide). A different-branch agent is NOT eligible; an agency admin IS (agency-wide).
 *
 * Locks PPA §35 authoriser determination (previously verified only via tinker).
 */
final class CandidateAuthoriserPoolTest extends TestCase
{
    use RefreshDatabase;

    private CandidatePractitionerService $svc;
    private Agency $agency;
    private Branch $b1;
    private Branch $b2;
    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CandidatePractitionerService::class);

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Scottburgh']);

        $this->candidate = $this->user('Cand Idate', 'agent', 'Candidate Property Practitioner', $this->b1);
    }

    private function user(string $name, string $role, string $designation, ?Branch $branch, ?Agency $agency = null): User
    {
        $agency = $agency ?? $this->agency;
        return User::factory()->create([
            'name'        => $name,
            'role'        => $role,
            'designation' => $designation,
            'branch_id'   => $branch?->id,
            'agency_id'   => $agency->id,
            'is_active'   => true,
        ]);
    }

    public function test_pool_is_branch_bms_plus_branch_full_status_plus_agency_admins(): void
    {
        $bmRole   = $this->user('BM Role',      'branch_manager', 'Property Practitioner', $this->b1);
        $fullSame = $this->user('Full Same',    'agent',          'Property Practitioner', $this->b1);
        $admin    = $this->user('Agency Admin', 'admin',          'Office Admin',          $this->b2);

        // Pivot BM — a user assigned to branch b2 who MANAGES b1 via the pivot.
        $pivotBm  = $this->user('Pivot BM', 'agent', '', $this->b2);
        $pivotBm->syncManagedBranches([$this->b1->id], $this->b1->id, $this->agency->id);

        // NOT eligible: full-status in a DIFFERENT branch, a plain agent, a foreign-agency admin.
        $fullOther   = $this->user('Full Other',  'agent', 'Property Practitioner', $this->b2);
        $plainAgent  = $this->user('Plain Agent', 'agent', '',                      $this->b1);
        $otherAgency = Agency::create(['name' => 'Rival', 'slug' => 'rival']);
        $foreignBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Foreign']);
        $foreignAdmin = $this->user('Foreign Admin', 'admin', '', $foreignBranch, $otherAgency);

        $pool = $this->svc->getEligibleAuthorisers($this->candidate)->pluck('id');

        // Included:
        $this->assertTrue($pool->contains($bmRole->id),   'branch_manager of the branch is eligible');
        $this->assertTrue($pool->contains($pivotBm->id),  'pivot manager of the branch is eligible');
        $this->assertTrue($pool->contains($fullSame->id), 'full-status of the branch is eligible');
        $this->assertTrue($pool->contains($admin->id),    'agency admin is eligible (agency-wide)');

        // Excluded:
        $this->assertFalse($pool->contains($fullOther->id),    'full-status of ANOTHER branch is NOT eligible');
        $this->assertFalse($pool->contains($plainAgent->id),   'a plain agent is NOT eligible');
        $this->assertFalse($pool->contains($this->candidate->id), 'the candidate is never their own authoriser');
        $this->assertFalse($pool->contains($foreignAdmin->id), 'a DIFFERENT-agency admin is NOT eligible (tenancy)');
    }

    public function test_canAuthoriseFor_admin_is_agency_wide_bm_and_full_status_are_branch_only(): void
    {
        $admin     = $this->user('Admin',      'admin', '',                      $this->b2);
        $fullSame  = $this->user('Full Same',  'agent', 'Property Practitioner', $this->b1);
        $fullOther = $this->user('Full Other', 'agent', 'Property Practitioner', $this->b2);

        $this->assertTrue($this->svc->canAuthoriseFor($admin, $this->candidate),     'admin authorises agency-wide');
        $this->assertTrue($this->svc->canAuthoriseFor($fullSame, $this->candidate),  'branch full-status authorises for its branch');
        $this->assertFalse($this->svc->canAuthoriseFor($fullOther, $this->candidate),'a different-branch full-status does NOT');

        // Cross-agency admin never qualifies.
        $otherAgency = Agency::create(['name' => 'Rival', 'slug' => 'rival']);
        $fb = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Foreign']);
        $foreignAdmin = $this->user('Foreign', 'admin', '', $fb, $otherAgency);
        $this->assertFalse($this->svc->canAuthoriseFor($foreignAdmin, $this->candidate), 'cross-agency admin does NOT authorise');
    }

    public function test_branch_and_admin_helpers(): void
    {
        $admin    = $this->user('Admin',   'admin',          '',                      $this->b1);
        $bmRole   = $this->user('BM',      'branch_manager', 'Property Practitioner', $this->b1);
        $fullSame = $this->user('Full',    'agent',          'Property Practitioner', $this->b1);

        $this->assertTrue($this->svc->isAgencyAdmin($admin));
        $this->assertFalse($this->svc->isAgencyAdmin($fullSame), 'branch full-status is not an agency admin');
        $this->assertTrue($this->svc->isBranchManagerOf($bmRole, $this->b1->id));
        $this->assertFalse($this->svc->isBranchManagerOf($fullSame, $this->b1->id));
        $this->assertSame([$this->b1->id], $this->svc->authorisingBranchIds($fullSame));
    }
}
