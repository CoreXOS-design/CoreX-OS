<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultReports;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 item 2(b) — RentalFaultReportController::index() already scoped
 * via RentalFaultReport::scopeVisibleTo(); show()/pdf() and every other
 * non-index action had no per-record re-check. Proves
 * AuthorizesRentalRecordScope::guardRentalRecordScope() closes that gap —
 * "branch" resolves via the record's PROPERTY (matching
 * RentalFaultReport::scopeVisibleTo()'s own whereHas('property', ...)
 * check), not any column on rental_fault_reports itself.
 */
final class RentalFaultReportScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $own;
    private User $other;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'RFR Scope Guard Agency', 'slug' => 'rfrsg-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'rental_fault_reports.view', 'agency_id' => $this->agency->id],
            ['scope' => 'own'],
        );
        PermissionService::clearCache();

        $this->own = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->other->id,
            'title' => 'Scope guard property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function faultReportOwnedByOther(): RentalFaultReport
    {
        return RentalFaultReport::create([
            'agency_id' => $this->agency->id,
            'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $this->other->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Leaking tap',
            'description' => 'Kitchen tap drips constantly.',
            'captured_by_user_id' => $this->other->id,
            'created_by_user_id' => $this->other->id,
            'reported_at' => now(),
            'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
    }

    public function test_own_scope_user_gets_403_opening_another_users_fault_report(): void
    {
        $faultReport = $this->faultReportOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-fault-reports.show', $faultReport))
            ->assertForbidden();
    }

    public function test_own_scope_user_gets_403_downloading_pdf_for_another_users_fault_report(): void
    {
        $faultReport = $this->faultReportOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-fault-reports.pdf', $faultReport))
            ->assertForbidden();
    }

    public function test_own_scope_user_can_open_their_own_fault_report(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $this->own->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Leaking tap', 'description' => 'Kitchen tap drips constantly.',
            'captured_by_user_id' => $this->own->id, 'created_by_user_id' => $this->own->id,
            'reported_at' => now(), 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);

        $this->actingAs($this->own)
            ->get(route('corex.rental-fault-reports.show', $faultReport))
            ->assertOk();
    }
}
