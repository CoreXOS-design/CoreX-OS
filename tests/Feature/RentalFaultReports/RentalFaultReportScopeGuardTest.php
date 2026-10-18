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

    /** Audit 2026-10-18 (A-L11): the "Report a fault" form pre-filled from any property / lease id in the agency. */
    public function test_report_a_fault_form_refuses_a_property_or_lease_outside_the_users_scope(): void
    {
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_fault_reports.create', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        foreach (['properties.view', 'leases.view'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'own']);
        }
        PermissionService::clearCache();

        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->other->id,
        ]);

        $this->actingAs($this->own)->get(route('corex.rental-fault-reports.create', ['property_id' => $this->property->id]))->assertNotFound();
        $this->actingAs($this->own)->get(route('corex.rental-fault-reports.create', ['lease_id' => $lease->id]))->assertNotFound();
    }

    /** Audit 2026-10-18 (A-L10): a fault type of another agency was accepted by a plain exists: rule. */
    public function test_store_refuses_another_agencys_fault_type(): void
    {
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_fault_reports.create', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'properties.view', 'agency_id' => $this->agency->id], ['scope' => 'all']);
        PermissionService::clearCache();

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $foreignType = \App\Models\RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'name' => 'Foreign type', 'urgency' => \App\Models\RentalFaultType::URGENCY_ROUTINE, 'is_active' => true,
        ]);
        $ownType = \App\Models\RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Own type', 'urgency' => \App\Models\RentalFaultType::URGENCY_ROUTINE, 'is_active' => true,
        ]);

        $payload = [
            'property_id' => $this->property->id, 'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'title' => 'Leaking tap', 'description' => 'Drips.',
        ];

        $this->actingAs($this->own)->post(route('corex.rental-fault-reports.store'), $payload + ['rental_fault_type_id' => $foreignType->id])
            ->assertSessionHasErrors('rental_fault_type_id');
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->where('rental_fault_type_id', $foreignType->id)->count());

        $this->actingAs($this->own)->post(route('corex.rental-fault-reports.store'), $payload + ['rental_fault_type_id' => $ownType->id])
            ->assertSessionHasNoErrors();
    }

    /** The list's property filter label must not name a property the user cannot see. */
    public function test_index_filter_label_ignores_a_property_outside_the_users_scope(): void
    {
        $this->faultReportOwnedByOther();
        $res = $this->actingAs($this->own)->get(route('corex.rental-fault-reports.index', ['property_id' => $this->property->id]))->assertOk();

        $this->assertNull($res->viewData('filteredProperty'));
    }
}
