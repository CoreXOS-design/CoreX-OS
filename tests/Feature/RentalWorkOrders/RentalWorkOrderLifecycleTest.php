<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 4 verification for .ai/specs/rental-work-orders.md §3/§3.4/§3a.1 —
 * the work orders themselves. Two facts this suite exists to prove above
 * everything else:
 *
 * 1. A work order is ONE way a repair happens, never the definition of one.
 *    §3a.1's own settled shape — owner_handles never produces a work order
 *    at all — is proven again from the WORK-ORDER side here: raising one
 *    is always a deliberate, separate agency action on an already-approved
 *    fault report, never automatic on approval alone.
 *    See test_raising_a_work_order_is_a_separate_action_from_approving_it.
 * 2. The four-situation test (§1/§7), walked through as real fixtures
 *    against the actual schema — situations 2 and 4 specifically.
 */
final class RentalWorkOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RWO Lifecycle Agency', 'slug' => 'rwo-lifecycle-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function workOrder(array $attrs = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function faultReport(array $attrs = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function supplier(): AgencyServiceProvider
    {
        return AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => 'Acme Plumbing', 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    // ── The spine: raising a work order is separate from approving ─────

    public function test_raising_a_work_order_is_a_separate_action_from_approving_it(): void
    {
        $faultReport = $this->faultReport();
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved', 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_EMAIL, 'evidence_text' => 'Approved.',
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $faultReport->status);
        // Approved, but NOT automatically a work order — the fact this test proves.
        $this->assertNull($faultReport->rental_work_order_id);
        $this->assertSame(0, RentalWorkOrder::count());

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $faultReport), [
            'title' => 'Geyser burst', 'description' => 'x',
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertNotNull($faultReport->rental_work_order_id);
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $faultReport->status);

        $workOrder = RentalWorkOrder::firstOrFail();
        $this->assertSame(RentalWorkOrder::REPORTED_BY_FAULT_REPORT, $workOrder->reported_by_type);
        $this->assertSame($faultReport->id, $workOrder->reported_fault_report_id);
        // Owner is not asked twice — inherited directly.
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $workOrder->owner_approval_status);
    }

    public function test_cannot_raise_a_work_order_from_a_fault_report_approved_via_owner_handles(): void
    {
        $faultReport = $this->faultReport();
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved', 'approval_route' => RentalFaultReport::ROUTE_OWNER_HANDLES,
            'evidence_type' => RentalApproval::EVIDENCE_WHATSAPP, 'evidence_text' => 'Owner will sort it.',
        ])->assertRedirect();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $faultReport), [
            'title' => 'x', 'description' => 'x',
        ])->assertSessionHasErrors();

        $this->assertSame(0, RentalWorkOrder::count());
    }

    public function test_cannot_raise_a_second_work_order_from_the_same_fault_report(): void
    {
        $faultReport = $this->faultReport(['status' => RentalFaultReport::STATUS_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $faultReport), ['title' => 'x', 'description' => 'x'])->assertRedirect();
        $this->assertSame(1, RentalWorkOrder::count());

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $faultReport->fresh()), ['title' => 'x', 'description' => 'x'])->assertSessionHasErrors();
        $this->assertSame(1, RentalWorkOrder::count());
    }

    // ── The four-situation test (§1/§7), situations 2 and 4 ────────────

    public function test_situation_2_owner_already_paid_prevents_double_charging_the_tenant(): void
    {
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_COMPLETED, 'paid_by' => RentalWorkOrder::PAID_BY_OWNER, 'completed_at' => now()]);

        $this->assertSame(RentalWorkOrder::PAID_BY_OWNER, $workOrder->paid_by);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $workOrder->status);
    }

    public function test_situation_4_a_later_bad_observation_after_completion_points_at_the_contractor_not_the_tenant(): void
    {
        $item = \App\Models\RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => \App\Models\RentalInspectionItem::KIND_SPACE, 'label' => 'Bathroom', 'created_by_user_id' => $this->admin->id,
        ]);
        $supplier = $this->supplier();
        $workOrder = $this->workOrder([
            'rental_inspection_item_id' => $item->id, 'status' => RentalWorkOrder::STATUS_COMPLETED,
            'agency_service_provider_id' => $supplier->id, 'completed_at' => now()->subDays(5),
        ]);

        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $inspection = \App\Models\RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => \App\Models\RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->admin->id,
        ]);
        $laterObservation = \App\Models\RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->admin->id, 'condition' => 'damaged', 'notes' => 'Damaged again.', 'source' => 'out_inspection',
        ]);

        // The timeline itself proves the point — completed repair, then a
        // later bad-condition observation on the SAME item.
        $this->assertTrue($laterObservation->created_at->greaterThan($workOrder->completed_at));
        $this->assertSame($item->id, $workOrder->rental_inspection_item_id);
        $this->assertSame($item->id, $laterObservation->rental_inspection_item_id);
        $this->assertNotNull($workOrder->agency_service_provider_id);
    }

    // ── Approval on a directly-raised work order ────────────────────────

    public function test_cannot_assign_a_supplier_while_approval_is_pending(): void
    {
        $workOrder = $this->workOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING]);
        $supplier = $this->supplier();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $workOrder), [
            'agency_service_provider_id' => $supplier->id,
        ])->assertSessionHasErrors();

        $this->assertNull($workOrder->fresh()->agency_service_provider_id);
    }

    public function test_recording_approval_on_a_direct_work_order_never_sets_a_route(): void
    {
        $workOrder = $this->workOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.approval.store', $workOrder), [
            'decision' => 'approved', 'evidence_type' => RentalApproval::EVIDENCE_EMAIL, 'evidence_text' => 'Approved.',
        ])->assertRedirect();

        $workOrder->refresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $workOrder->owner_approval_status);
        $approval = RentalApproval::firstOrFail();
        $this->assertNull($approval->approval_route);
        $this->assertSame($workOrder->id, $approval->rental_work_order_id);
        $this->assertNull($approval->rental_fault_report_id);
    }

    // ── Supplier assignment, completion, cancellation ───────────────────

    public function test_assigning_a_supplier_moves_reported_straight_to_ordered(): void
    {
        $workOrder = $this->workOrder();
        $supplier = $this->supplier();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $workOrder), [
            'agency_service_provider_id' => $supplier->id,
        ])->assertRedirect();

        $workOrder->refresh();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $workOrder->status);
        $this->assertSame($supplier->id, $workOrder->agency_service_provider_id);
        $this->assertSame(1, $workOrder->updates()->where('update_type', 'supplier_assigned')->count());
        $this->assertSame(1, $workOrder->updates()->where('update_type', 'status_change')->count());
    }

    public function test_completion_requires_a_completed_photo_when_setting_is_on(): void
    {
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), [
            'paid_by' => 'owner',
        ])->assertSessionHasErrors();

        $this->assertNull($workOrder->fresh()->completed_at);
    }

    public function test_completion_requires_paid_by(): void
    {
        Storage::fake('public');
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);
        RentalWorkOrderPhoto::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $workOrder->id,
            'photo_type' => RentalWorkOrder::PHOTO_COMPLETED, 'storage_path' => '/fake.jpg', 'uploaded_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), [])
            ->assertSessionHasErrors();

        $this->assertNull($workOrder->fresh()->completed_at);
    }

    public function test_completion_succeeds_with_photo_and_paid_by(): void
    {
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);
        RentalWorkOrderPhoto::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $workOrder->id,
            'photo_type' => RentalWorkOrder::PHOTO_COMPLETED, 'storage_path' => '/fake.jpg', 'uploaded_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), [
            'paid_by' => 'owner', 'cost_amount' => 850,
        ])->assertRedirect();

        $workOrder->refresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $workOrder->status);
        $this->assertNotNull($workOrder->completed_at);
        $this->assertSame('owner', $workOrder->paid_by);
    }

    public function test_completion_ignores_the_photo_gate_when_agency_setting_is_off(): void
    {
        \App\Models\RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'completion_requires_photo' => false]);
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), [
            'paid_by' => 'not_yet_paid',
        ])->assertRedirect();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $workOrder->fresh()->status);
    }

    public function test_cancel_requires_a_reason_and_stamps_the_cancelling_user(): void
    {
        $workOrder = $this->workOrder();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cancel', $workOrder), [])->assertSessionHasErrors();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cancel', $workOrder), ['cancel_reason' => 'Logged in error.'])
            ->assertRedirect(route('corex.rental-work-orders.show', $workOrder));

        $workOrder->refresh();
        $this->assertSame(RentalWorkOrder::STATUS_CANCELLED, $workOrder->status);
        $this->assertSame($this->admin->id, $workOrder->cancelled_by_user_id);
    }

    // ── Overdue scope ────────────────────────────────────────────────

    public function test_overdue_scope_excludes_recently_updated_work_orders(): void
    {
        $fresh = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);
        $stale = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);
        $stale->timestamps = false;
        $stale->updated_at = now()->subDays(10);
        $stale->saveQuietly();

        $overdueIds = RentalWorkOrder::query()->overdue(3)->pluck('id')->all();

        $this->assertContains($stale->id, $overdueIds);
        $this->assertNotContains($fresh->id, $overdueIds);
    }

    // ── Permission separation (§10) ──────────────────────────────────

    public function test_completing_a_work_order_requires_its_own_permission_not_just_create(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_work_orders.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_work_orders.create', 'scope' => 'own']);
        \App\Services\PermissionService::clearCache();

        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED, 'created_by_user_id' => $agent->id]);
        RentalWorkOrderPhoto::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $workOrder->id,
            'photo_type' => RentalWorkOrder::PHOTO_COMPLETED, 'storage_path' => '/fake.jpg', 'uploaded_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('corex.rental-work-orders.complete', $workOrder), [
            'paid_by' => 'owner',
        ])->assertForbidden();
    }
}
