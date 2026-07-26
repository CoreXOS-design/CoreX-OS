<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 audit C1 — lease_records has no agency_id and no global scope, so route-model binding
 * resolved ANY lease by id. renew/terminate/history had NO authorization → any DocuPerfect user
 * could terminate any lease in any agency. This proves the scope (which the controller guard is
 * built on) isolates leases by agent AND agency, incl. the 'all' scope.
 */
final class LeaseAccessScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;
    private Branch $branchA;
    private Branch $branchB;
    private User $agentA;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyA = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->agencyB = Agency::create(['name' => 'Rival', 'slug' => 'riv-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agencyA->id, 'name' => 'Margate']);
        $this->branchB = Branch::create(['agency_id' => $this->agencyB->id, 'name' => 'Durban']);

        foreach ([$this->agencyA, $this->agencyB] as $ag) {
            Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $ag->id]);
        }

        $this->agentA = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'is_active' => true]);
        $this->agentB = User::factory()->create(['agency_id' => $this->agencyB->id, 'branch_id' => $this->branchB->id, 'role' => 'agent', 'is_active' => true]);

        PermissionService::clearCache();
    }

    public function test_own_scope_user_does_not_see_another_agents_or_agencys_lease(): void
    {
        $this->grantRentals('agent', $this->agencyA, 'own');

        $mine   = $this->leaseFor($this->agentA, $this->branchA);
        $rivals = $this->leaseFor($this->agentB, $this->branchB);

        $visible = LeaseRecord::visibleTo(User::find($this->agentA->id))->pluck('id');

        $this->assertContains($mine->id, $visible->all());
        $this->assertNotContains($rivals->id, $visible->all(), 'An own-scope agent must not see another agent/agency lease.');
    }

    public function test_all_scope_user_is_still_bounded_to_their_own_agency(): void
    {
        // Even an agency-wide ('all') rentals user must not reach a DIFFERENT agency's leases.
        $this->grantRentals('agent', $this->agencyA, 'all');

        $mine   = $this->leaseFor($this->agentA, $this->branchA);
        $rivals = $this->leaseFor($this->agentB, $this->branchB);

        $visible = LeaseRecord::visibleTo(User::find($this->agentA->id))->pluck('id');

        $this->assertContains($mine->id, $visible->all());
        $this->assertNotContains($rivals->id, $visible->all(), "'all' scope must still be agency-bounded.");
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function grantRentals(string $role, Agency $agency, string $scope): void
    {
        RolePermission::updateOrCreate(
            ['role' => $role, 'permission_key' => 'rentals.view', 'agency_id' => $agency->id],
            ['scope' => $scope],
        );
        PermissionService::clearCache();
    }

    private function leaseFor(User $owner, Branch $branch): LeaseRecord
    {
        $doc = Document::create([
            'name'      => 'Lease ' . uniqid(),
            'owner_id'  => $owner->id,
            'branch_id' => $branch->id,
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'status'      => 'completed',
        ]);
        return LeaseRecord::create([
            'document_id'           => $doc->id,
            'signature_template_id' => $tpl->id,
            'property_address'      => '14 Marine Drive',
            'tenant_name'           => 'Tenant',
            'tenant_email'          => 'tenant@example.co.za',
            'landlord_name'         => 'Landlord',
            'landlord_email'        => 'landlord@example.co.za',
            'rental_amount'         => 12000,
            'lease_start_date'      => '2026-01-01',
            'lease_end_date'        => '2026-12-31',
            'status'                => LeaseRecord::STATUS_ACTIVE,
        ]);
    }
}
