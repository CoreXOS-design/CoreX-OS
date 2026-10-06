<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 3 fixes (2026-09-30): the approval gate on startProgress()/complete(),
 * lock-after-complete, quote-edit approval preservation, supplier/quote tie,
 * the fault-report duplicate work-order hole, lease state machine, and
 * own/branch scoping on a direct-URL work order.
 */
final class RentalWorkOrderAuditFixesTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'RWO Audit Agency', 'slug' => 'rwo-audit-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500, 'completion_requires_photo' => false]);
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

    private function supplier(string $name = 'Acme Plumbing'): AgencyServiceProvider
    {
        return AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => $name, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    // ── H2: approval gate on start / complete ───────────────────────────

    public function test_start_progress_is_blocked_while_approval_is_pending_or_declined(): void
    {
        foreach ([RentalWorkOrder::APPROVAL_PENDING, RentalWorkOrder::APPROVAL_DECLINED] as $status) {
            $workOrder = $this->workOrder(['owner_approval_status' => $status]);

            $this->actingAs($this->admin)->post(route('corex.rental-work-orders.start-progress', $workOrder))
                ->assertSessionHasErrors('rental_work_order');

            $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $workOrder->fresh()->status);
        }
    }

    public function test_complete_is_blocked_while_approval_is_pending_or_declined(): void
    {
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_DECLINED]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), ['paid_by' => 'owner', 'cost_amount' => 100])
            ->assertSessionHasErrors('rental_work_order');

        $this->assertNull($workOrder->fresh()->completed_at);
    }

    public function test_complete_above_the_threshold_needs_a_recorded_approval(): void
    {
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), ['paid_by' => 'owner', 'cost_amount' => 20000])
            ->assertSessionHasErrors('rental_work_order');
        $this->assertNull($workOrder->fresh()->completed_at);

        // At or under the threshold needs nothing.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), ['paid_by' => 'owner', 'cost_amount' => 500])
            ->assertRedirect();
        $this->assertNotNull($workOrder->fresh()->completed_at);
    }

    public function test_complete_above_the_threshold_succeeds_once_approved(): void
    {
        $workOrder = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED]);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $workOrder), ['paid_by' => 'owner', 'cost_amount' => 20000])
            ->assertRedirect();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $workOrder->fresh()->status);
    }

    // ── M2: lock after complete ─────────────────────────────────────────

    public function test_a_completed_work_order_cannot_be_cancelled_or_have_quotes_archived(): void
    {
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()], $this->admin);
        $workOrder->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cancel', $workOrder), ['cancel_reason' => 'oops'])
            ->assertSessionHasErrors('rental_work_order');
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $workOrder->fresh()->status);

        $this->actingAs($this->admin)->delete(route('corex.rental-work-orders.quotes.destroy', [$workOrder, $quote]))
            ->assertSessionHasErrors('quote');
        $this->assertFalse($quote->fresh()->trashed());
    }

    // ── M1: quote edit must not wipe an approval; supplier tie ──────────

    public function test_editing_only_the_detail_text_of_the_selected_quote_keeps_a_recorded_approval(): void
    {
        $supplier = $this->supplier();
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 20000, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);
        $workOrder->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => RentalApproval::EVIDENCE_EMAIL, 'evidence_text' => 'ok']);

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.quotes.update', [$workOrder, $quote]), [
            'agency_service_provider_id' => $supplier->id, 'amount' => 20000, 'quote_date' => now()->format('Y-m-d'),
            'detail_text' => 'clarified wording only',
        ])->assertRedirect();

        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $workOrder->fresh()->owner_approval_status);
    }

    /**
     * BUILD 2 (.ai/specs/rental-work-orders.md §17.9.4) supersedes audit M1's "a price change drops the approval": once the owner has
     * approved an amount, a HIGHER price is a variation for the extra (the approval stands for what the owner agreed); the owner is asked
     * about the difference only. A change on a work order with NO approved amount still re-evaluates from scratch.
     */
    public function test_changing_the_price_of_the_selected_quote_after_approval_raises_a_variation_for_the_extra(): void
    {
        $supplier = $this->supplier();
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 20000, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);
        $workOrder->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => RentalApproval::EVIDENCE_EMAIL, 'evidence_text' => 'ok']);

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.quotes.update', [$workOrder, $quote]), [
            'agency_service_provider_id' => $supplier->id, 'amount' => 30000, 'quote_date' => now()->format('Y-m-d'),
            'detail_text' => 'price went up',
        ])->assertRedirect();

        $fresh = $workOrder->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $fresh->owner_approval_status, 'what the owner approved stays approved');
        $this->assertSame('20000.00', $fresh->approved_amount);
        $variation = $fresh->openVariation();
        $this->assertNotNull($variation, 'the extra waits for the owner');
        $this->assertSame('10000.00', $variation->extra_amount);
        $this->assertSame('30000.00', $variation->new_total);
    }

    public function test_the_assigned_supplier_must_be_the_selected_quotes_supplier(): void
    {
        $quoted = $this->supplier('Quoted Plumbing');
        $other = $this->supplier('Someone Else');
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $quoted->id, 'amount' => 100, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $workOrder), ['agency_service_provider_id' => $other->id])
            ->assertSessionHasErrors('rental_work_order');
        $this->assertNull($workOrder->fresh()->agency_service_provider_id);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $workOrder), ['agency_service_provider_id' => $quoted->id])
            ->assertRedirect();
        $this->assertSame($quoted->id, $workOrder->fresh()->agency_service_provider_id);
    }

    // ── L1: archived property must not 500 the page ─────────────────────

    public function test_the_work_order_page_renders_after_its_property_is_archived(): void
    {
        $workOrder = $this->workOrder();
        $this->property->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $workOrder))->assertOk();
    }

    // ── M3: fault report approval cannot be re-run after a work order ───

    public function test_a_fault_report_approval_cannot_be_recorded_again_after_a_work_order_is_raised(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalFaultReport::STATUS_APPROVED,
            'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED,
            'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $faultReport), ['title' => 'x', 'description' => 'x'])->assertRedirect();
        $this->assertSame(1, RentalWorkOrder::count());

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport->fresh()), [
            'decision' => 'approved', 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_EMAIL, 'evidence_text' => 'again',
        ])->assertSessionHasErrors('rental_fault_report');

        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $faultReport->fresh()->status);
    }

    // ── M6: lease state machine ─────────────────────────────────────────

    public function test_a_cancelled_lease_cannot_be_reactivated_or_cancelled_again(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_CANCELLED, 'rental_amount' => 9000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->admin->id, 'cancel_reason' => 'first reason',
        ]);

        $this->actingAs($this->admin)->post(route('corex.leases.activate', $lease))->assertSessionHasErrors('status');
        $this->assertSame(Lease::STATUS_CANCELLED, $lease->fresh()->status);

        $this->actingAs($this->admin)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'second reason'])
            ->assertSessionHasErrors('lease');
        $this->assertSame('first reason', $lease->fresh()->cancel_reason);
    }

    public function test_a_future_dated_escalation_does_not_change_the_rent_until_due(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.leases.escalate', $lease), [
            'effective_date' => now()->addMonths(2)->format('Y-m-d'), 'new_rental_amount' => 10800,
        ])->assertRedirect();

        $this->assertEquals(10000, (float) $lease->fresh()->rental_amount);
        $this->assertSame(1, $lease->escalations()->count());

        $this->actingAs($this->admin)->post(route('corex.leases.escalate', $lease), [
            'effective_date' => now()->subDay()->format('Y-m-d'), 'new_rental_amount' => 10400,
        ])->assertRedirect();

        $this->assertEquals(10400, (float) $lease->fresh()->rental_amount);
    }

    // ── H3: own-scope direct URL access is blocked ──────────────────────

    public function test_an_own_scope_agent_cannot_open_or_act_on_a_colleagues_work_order_by_id(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        foreach (['view', 'create', 'complete', 'cancel'] as $perm) {
            \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => "rental_work_orders.{$perm}", 'scope' => 'own']);
        }
        \App\Services\PermissionService::clearCache();

        $others = $this->workOrder(['created_by_user_id' => $this->admin->id, 'status' => RentalWorkOrder::STATUS_ORDERED]);
        $mine = $this->workOrder(['created_by_user_id' => $agent->id]);

        // Guard mechanism is now AuthorizesRentalRecordScope::guardRentalRecordScope()
        // (AT-439, adopted over this controller's own former assertVisible()/404 on
        // Johan's explicit ruling, 2026-10-04) — out-of-scope is refused with 403, not
        // 404. The record is still fully unreachable; only the status code changed.
        $this->actingAs($agent)->get(route('corex.rental-work-orders.show', $others))->assertForbidden();
        $this->actingAs($agent)->post(route('corex.rental-work-orders.complete', $others), ['paid_by' => 'owner'])->assertForbidden();
        $this->actingAs($agent)->post(route('corex.rental-work-orders.cancel', $others), ['cancel_reason' => 'x'])->assertForbidden();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $others->fresh()->status);

        $this->actingAs($agent)->get(route('corex.rental-work-orders.show', $mine))->assertOk();
    }

    // ── N3: create paths respect property visibility ────────────────────

    public function test_an_own_scope_agent_cannot_raise_a_work_order_against_a_colleagues_property(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        // The route group is gated on .view, the store route on .create.
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_work_orders.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_work_orders.create', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'own']);
        \App\Services\PermissionService::clearCache();

        // $this->property belongs to the admin (agent_id), not to $agent.
        $payload = [
            'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'title' => 'Leaking tap', 'description' => 'Kitchen tap drips.',
        ];

        $before = RentalWorkOrder::withoutGlobalScopes()->count();
        $this->actingAs($agent)->post(route('corex.rental-work-orders.store'), $payload)->assertNotFound();
        $this->assertSame($before, RentalWorkOrder::withoutGlobalScopes()->count());
    }
}
