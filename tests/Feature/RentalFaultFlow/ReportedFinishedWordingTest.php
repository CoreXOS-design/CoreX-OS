<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Scopes\AgencyScope;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan, 13 Oct 2026 (QA1 fault 338 / work order 154): the owner's fault LIST said "Work order raised" while the DETAIL read finished. When the work is reported finished
 * (contractor / owner / tenant) and the agent has not closed the work order, EVERY screen - owner list, owner detail, owner progress line, the repair card, the tenant's
 * progress line, the staff work-order list - says so in the same words, and "completed" only appears once the work order is completed.
 */
final class ReportedFinishedWordingTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private const WORDS = 'Work reported finished - your agent is closing it off';
    private const OWNER = '/api/v1/client/rentals/landlord';
    private const TENANT = '/api/v1/client/rentals';

    private Lease $lease;
    private Contact $tenant;
    private $ownerClient = null;
    private $tenantClient = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Words');
        $this->ownerLimits(1500.0);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
    }

    /** A fault with its work order raised, the cost authorised and the go-ahead sent - the contractor has NOT reported anything yet. */
    private function job(): array
    {
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->externalWorkOrder(['lease_id' => $this->lease->id]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $sup->id, 'amount' => 1000.0, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $this->authoriseCostOf($wo->fresh());
        $wo->fresh()->assignSupplier($sup->id, null, $this->admin);
        $type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Gate motor', 'category' => 'Security', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => 'agent_noticed', 'reported_channel' => 'app', 'title' => 'Gate', 'description' => 'stuck', 'status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id, 'rental_work_order_id' => $wo->id,
            'owner_title' => 'Gate motor', 'owner_description' => 'The gate sticks', 'owner_sent_at' => now(),
        ]);
        $wo->forceFill(['reported_fault_report_id' => $fault->id])->save();

        return [$fault->fresh(), $wo->fresh()];
    }

    private function contractorReportsDone(RentalWorkOrder $wo): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'note' => 'Done'])->assertSessionHasNoErrors();
    }

    private function ownerList(): array
    {
        Sanctum::actingAs($this->ownerClient ??= $this->clientFor($this->landlord), ['client']);

        return collect($this->getJson(self::OWNER . '/fault-reports')->assertOk()->json('fault_reports'))->all();
    }

    public function test_owner_list_detail_and_progress_agree_while_the_agent_has_not_closed_it(): void
    {
        [$fault, $wo] = $this->job();
        $this->contractorReportsDone($wo);
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);

        $row = collect($this->ownerList())->firstWhere('id', $fault->id);
        $this->assertSame(self::WORDS, $row['status_label'], 'the LIST');

        Sanctum::actingAs($this->ownerClient ??= $this->clientFor($this->landlord), ['client']);
        $detail = $this->getJson(self::OWNER . "/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertSame(self::WORDS, $detail['status_label'], 'the DETAIL header');
        $this->assertSame(self::WORDS, $detail['progress']['current_label'], 'the progress line');
        $this->assertSame(self::WORDS, $detail['work_order']['stage_label'], 'the repair card');

        $last = collect($detail['progress']['steps'])->last();
        $this->assertSame('completed', $last['key']);
        $this->assertSame('todo', $last['state'], 'not done');
        $this->assertStringNotContainsStringIgnoringCase('complete', $last['label'], 'the step still to come never says completed');
        $this->assertSame('Your agent closes it off', $last['label']);
    }

    public function test_the_tenant_reads_the_same_words_and_never_completed(): void
    {
        [$fault, $wo] = $this->job();
        $this->contractorReportsDone($wo);

        Sanctum::actingAs($this->tenantClient ??= $this->clientFor($this->tenant), ['client']);
        $detail = $this->getJson(self::TENANT . "/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertSame(self::WORDS, $detail['progress']['current_label']);
        $this->assertSame('todo', collect($detail['progress']['steps'])->last()['state']);
        $this->assertStringNotContainsStringIgnoringCase('complete', collect($detail['progress']['steps'])->last()['label']);
    }

    public function test_completed_only_when_the_work_order_is_completed_and_then_every_screen_agrees(): void
    {
        [$fault, $wo] = $this->job();
        $this->contractorReportsDone($wo);
        $this->setting(['completion_requires_photo' => false]);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1000.00])->assertSessionHasNoErrors();

        $row = collect($this->ownerList())->firstWhere('id', $fault->id);
        $this->assertSame('Resolved', $row['status_label']);
        Sanctum::actingAs($this->ownerClient ??= $this->clientFor($this->landlord), ['client']);
        $detail = $this->getJson(self::OWNER . "/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertSame('Resolved', $detail['status_label']);
        $this->assertSame('Work completed', collect($detail['progress']['steps'])->last()['label']);
        $this->assertSame('done', collect($detail['progress']['steps'])->last()['state']);
    }

    public function test_before_the_contractor_reports_anything_the_owner_still_reads_work_order_raised(): void
    {
        [$fault] = $this->job();

        $this->assertSame('Work order raised', collect($this->ownerList())->firstWhere('id', $fault->id)['status_label']);
    }

    public function test_the_staff_work_order_list_says_finished_awaiting_agent_check_where_the_page_badge_does(): void
    {
        [$fault, $wo] = $this->job();
        $this->contractorReportsDone($wo);

        $list = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/ds-badge[^"]*">\s*Finished - awaiting agent check\s*<\/span>/', $list);
        $page = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-stage-badge>Finished - awaiting agent check<', $page);
    }

    public function test_the_work_order_list_has_a_finished_awaiting_agent_check_tile_and_filter(): void
    {
        [$fault, $wo] = $this->job();
        $other = $this->externalWorkOrder(['title' => 'Still being done']);
        $this->contractorReportsDone($wo);

        $list = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Finished - awaiting agent check', $list);   // the tile and the checkbox beside the others
        $this->assertStringContainsString('name="awaiting_check"', $list);

        $filtered = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.index', ['awaiting_check' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString(route('corex.rental-work-orders.show', $wo), $filtered);
        $this->assertStringNotContainsString(route('corex.rental-work-orders.show', $other), $filtered);

        // once the agent closes it, it leaves the tile
        $this->assertTrue(\App\Models\RentalWorkOrder::query()->awaitingAgentCheck()->whereKey($wo->id)->exists());
        $wo->forceFill(['status' => \App\Models\RentalWorkOrder::STATUS_COMPLETED])->save();
        $this->assertFalse(\App\Models\RentalWorkOrder::query()->awaitingAgentCheck()->whereKey($wo->id)->exists());
    }
}
