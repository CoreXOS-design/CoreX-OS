<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Scopes\AgencyScope;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan, 13 Oct 2026 (QA1 fault 338): a work order that came from a fault must close that fault whichever way it is completed. There is ONE place that does it
 * (RentalWorkOrder::complete() -> resolveReportedFault() -> RentalFaultReport::resolveFromCompletedWorkOrder()); this test walks every route that completes a work order -
 * the office's "Mark the work as complete" form, the contractor's own link, our crew's job card - and checks the fault is resolved ("repaired", set automatically) each time,
 * and that a one-sided or stale link between the two records is healed rather than skipped.
 */
final class FaultResolvedOnEveryCompletionRouteTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('FaultClose');
        $this->setting(['completion_requires_photo' => false]);
        $this->ownerLimits(1500.0);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
    }

    /** A fault that has a work order raised - linked BOTH ways, exactly as "Raise work order" does. */
    private function raisedFault(RentalWorkOrder $wo): RentalFaultReport
    {
        $type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Gate motor', 'category' => 'Security', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => 'agent_noticed', 'reported_channel' => 'app', 'title' => 'Gate', 'description' => 'stuck', 'status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id, 'rental_work_order_id' => $wo->id,
        ]);
        $wo->forceFill(['reported_fault_report_id' => $fault->id, 'lease_id' => $this->lease->id])->save();

        return $fault->fresh();
    }

    private function assertResolved(RentalFaultReport $fault, string $route): void
    {
        $fault = $fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->status, "{$route}: the fault must be resolved");
        $this->assertSame(RentalFaultReport::OUTCOME_REPAIRED, $fault->outcome, $route);
        $this->assertTrue((bool) $fault->outcome_set_automatically, $route);
        $this->assertNotNull($fault->resolved_at ?? $fault->updated_at);
    }

    /** An outside-contractor job with the cost authorised (R1,000) and the go-ahead sent. */
    private function authorisedJob(float $amount = 1000.0): RentalWorkOrder
    {
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->externalWorkOrder(['lease_id' => $this->lease->id]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $sup->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $this->authoriseCostOf($wo->fresh());
        $wo->fresh()->assignSupplier($sup->id, null, $this->admin);
        app(RentalWorkOrderService::class)->sendContractorWorkOrder($wo->fresh(), $this->admin);

        return $wo->fresh();
    }

    public function test_route_1_the_office_mark_the_work_as_complete_form_resolves_the_fault(): void
    {
        $wo = $this->authorisedJob();
        $fault = $this->raisedFault($wo);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1000.00, 'found_text' => 'Board reset', 'cause_note' => 'Normal wear'])->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertResolved($fault, 'office form');
    }

    public function test_route_2_the_contractors_own_link_resolves_the_fault(): void
    {
        $wo = $this->authorisedJob();
        $fault = $this->raisedFault($wo);
        $raw = app(RentalSecureAccessTokenService::class)->issueFor($wo->fresh(), $this->admin)['raw_token'];

        $this->post(route('rentals.secure-link.mark-done', $raw), ['found_text' => 'Board reset', 'cause_note' => 'Normal wear'])->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertResolved($fault, 'contractor link');
    }

    public function test_route_3_closing_our_crews_job_card_resolves_the_fault(): void
    {
        [$card, $wo] = $this->internalJob(1000.0);
        $fault = $this->raisedFault($wo);
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();
        $this->authoriseCostOf($wo->fresh());
        $card->forceFill(['status' => RentalJobCard::STATUS_IN_PROGRESS, 'worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Crew Lead', 'agent_signed_off_at' => now(), 'agent_signed_off_by_user_id' => $this->admin->id])->save();

        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin, 'owner');

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertResolved($fault, 'job card close');
    }

    public function test_a_one_sided_or_stale_link_is_healed_not_skipped(): void
    {
        // the fault does not point back at the work order (or points at a cancelled one): completing the work order that points at the fault still closes it
        $wo = $this->authorisedJob();
        $fault = $this->raisedFault($wo);
        $fault->forceFill(['rental_work_order_id' => null])->saveQuietly();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1000.00])->assertSessionHasNoErrors();
        $this->assertResolved($fault, 'fault with no link back');
        $this->assertSame($wo->id, (int) $fault->fresh()->rental_work_order_id, 'and the link is repaired');

        $dead = $this->externalWorkOrder(['lease_id' => $this->lease->id, 'status' => RentalWorkOrder::STATUS_CANCELLED]);
        $wo2 = $this->authorisedJob();
        $fault2 = $this->raisedFault($wo2);
        $fault2->forceFill(['rental_work_order_id' => $dead->id])->saveQuietly();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo2), ['paid_by' => 'owner', 'cost_amount' => 1000.00])->assertSessionHasNoErrors();
        $this->assertResolved($fault2, 'fault pointing at a cancelled work order');
    }

    public function test_a_fault_pointed_at_a_different_live_work_order_is_left_to_that_one(): void
    {
        $other = $this->authorisedJob();
        $fault = $this->raisedFault($other);
        $wo = $this->authorisedJob();
        $wo->forceFill(['reported_fault_report_id' => $fault->id])->save();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1000.00])->assertSessionHasNoErrors();

        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status, 'the live work order it points at still decides');
    }

    public function test_the_contractor_reporting_the_job_done_does_not_complete_it_nor_resolve_the_fault_until_the_office_closes_it(): void
    {
        // QA1 work order 154 / fault 338 (13 Oct): the contractor reported done and the tenant confirmed - that is "reported finished", waiting for the agent. Nothing is completed yet.
        $wo = $this->authorisedJob();
        $fault = $this->raisedFault($wo);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'note' => 'Done'])->assertSessionHasNoErrors();

        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-next="reported_finished"', $html, 'the page asks the agent to close it');
    }
}
