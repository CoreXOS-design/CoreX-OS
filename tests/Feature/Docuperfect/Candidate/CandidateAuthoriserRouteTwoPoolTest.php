<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Candidate;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\OfficerAppointment;
use App\Models\User;
use App\Services\CandidatePractitionerService;
use App\Services\Compliance\OfficerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compliance approval gate, ruling 10 (spec esign-compliance-approval-gate.md §6.5): on the RO / CO
 * route the candidate's authoriser pool is the agency's e-sign officers who are full status —
 * their co-signature IS the approval. Route 1 keeps CandidateAuthoriserPoolTest's pool untouched.
 */
final class CandidateAuthoriserRouteTwoPoolTest extends TestCase
{
    use RefreshDatabase;

    private CandidatePractitionerService $svc;
    private OfficerRegistry $registry;
    private Agency $agency;
    private Branch $b1;
    private Branch $b2;
    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = app(CandidatePractitionerService::class);
        $this->registry = app(OfficerRegistry::class);

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Scottburgh']);
        $this->candidate = $this->user('Cand Idate', 'agent', 'Candidate Property Practitioner', $this->b1);
    }

    private function user(string $name, string $role, string $designation, Branch $branch): User
    {
        return User::factory()->create([
            'name' => $name, 'role' => $role, 'designation' => $designation,
            'branch_id' => $branch->id, 'agency_id' => $this->agency->id, 'is_active' => true,
        ]);
    }

    public function test_route_two_pool_is_full_status_esign_officers_only(): void
    {
        $bmOfficer      = $this->user('BM Officer',     'branch_manager', 'Property Practitioner', $this->b1);
        $fullNotOfficer = $this->user('Full No Badge',  'agent',          'Property Practitioner', $this->b1);
        $adminOfficerNotFull = $this->user('Admin RO',  'admin',          'Office Admin',          $this->b1);
        $coFull         = $this->user('CO Full',        'admin',          'Principal Property Practitioner', $this->b2);

        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $coFull->id, $coFull->id);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [$bmOfficer->id, $adminOfficerNotFull->id], $coFull->id);
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);

        $pool = $this->svc->getEligibleAuthorisers($this->candidate)->pluck('id');

        $this->assertTrue($pool->contains($bmOfficer->id), 'a full-status branch-manager RO authorises');
        $this->assertTrue($pool->contains($coFull->id), 'a full-status admin CO authorises agency-wide');
        $this->assertFalse($pool->contains($fullNotOfficer->id), 'full status alone is no longer enough on route 2');
        $this->assertFalse($pool->contains($adminOfficerNotFull->id), 'an RO who is not full status may not co-sign a candidate');

        $this->assertTrue($this->svc->canAuthoriseFor($bmOfficer, $this->candidate));
        $this->assertFalse($this->svc->canAuthoriseFor($fullNotOfficer, $this->candidate));
        $this->assertFalse($this->svc->canAuthoriseFor($adminOfficerNotFull, $this->candidate));
    }

    public function test_route_two_with_no_full_status_officer_fails_with_a_plain_message(): void
    {
        $co = $this->user('CO Admin', 'admin', 'Principal Property Practitioner', $this->b1);
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $co->id, $co->id);
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);
        // The registry refuses to switch on / leave the agency without a full-status officer, so the
        // only way here is a designation that changed AFTER the fact — the service still answers.
        $co->update(['designation' => 'Office Admin']);
        $this->user('Full Same', 'agent', 'Property Practitioner', $this->b1); // would qualify on route 1

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No full-status Reporting Officer is appointed for e-sign/');
        $this->svc->getEligibleAuthorisers($this->candidate);
    }

    public function test_route_one_pool_is_unchanged(): void
    {
        $fullSame = $this->user('Full Same', 'agent', 'Property Practitioner', $this->b1);
        $admin    = $this->user('Agency Admin', 'admin', 'Office Admin', $this->b2);

        $pool = $this->svc->getEligibleAuthorisers($this->candidate)->pluck('id');

        $this->assertTrue($pool->contains($fullSame->id));
        $this->assertTrue($pool->contains($admin->id));
        $this->assertTrue($this->svc->canAuthoriseFor($fullSame, $this->candidate));
    }
}
