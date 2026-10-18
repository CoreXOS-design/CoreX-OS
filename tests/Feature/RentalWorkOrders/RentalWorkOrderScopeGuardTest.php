<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 item 2(b) — same gap, same fix as RentalFaultReportScopeGuardTest:
 * RentalWorkOrderController::index() already scoped via
 * RentalWorkOrder::scopeVisibleTo(); show()/pdf() had no per-record
 * re-check. "Branch" resolves via the record's PROPERTY, matching
 * scopeVisibleTo()'s own whereHas('property', ...) check — NOT
 * rental_work_orders' own (unread-by-scope) branch_id column.
 */
final class RentalWorkOrderScopeGuardTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'RWO Scope Guard Agency', 'slug' => 'rwosg-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'rental_work_orders.view', 'agency_id' => $this->agency->id],
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

    private function workOrderOwnedByOther(): RentalWorkOrder
    {
        return RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->other->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->other->id,
        ]);
    }

    public function test_own_scope_user_gets_403_opening_another_users_work_order(): void
    {
        $workOrder = $this->workOrderOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-work-orders.show', $workOrder))
            ->assertForbidden();
    }

    public function test_own_scope_user_gets_403_downloading_pdf_for_another_users_work_order(): void
    {
        $workOrder = $this->workOrderOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-work-orders.pdf', $workOrder))
            ->assertForbidden();
    }

    public function test_own_scope_user_can_open_their_own_work_order(): void
    {
        $workOrder = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->own->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->own->id,
        ]);

        $this->actingAs($this->own)
            ->get(route('corex.rental-work-orders.show', $workOrder))
            ->assertOk();
    }
}
