<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalPayerRuling;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalWhoPaysService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-460 - the four defects found walking job card 97 on QA1 (15 Oct 2026): (1) "Who pays: Owner" chosen when the job card was completed never reached the fault,
 * (2) the money card kept its pre-authorisation warning after the cost was authorised, (3) the owner-approved alert always said "send it to the contractor", (4) the owner
 * portal's work order pill read "Created" after the owner approved an own-crew job. rental-work-orders.md §17.39.
 */
final class JobCardAt460FixesTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('AT460 Fixes');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function page(RentalJobCard $card): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card->fresh()))->assertOk()->getContent();
    }

    /** An own-crew job whose work order came from a fault; $found = enter "what was found" + the cause the way the crew does. */
    private function faultJob(bool $found = true): array
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Front door lock jammed', 'description' => 'The latch does not release', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        [$card, $wo] = $this->internalJob(345);
        $wo->forceFill(['reported_fault_report_id' => $fault->id, 'lease_id' => $lease->id])->save();
        $fault->forceFill(['rental_work_order_id' => $wo->id])->save();
        if ($found) {
            app(RentalWhoPaysService::class)->recordFindings($wo->fresh(), 'Latch spring broken, replaced the lock', null, 'Worn out with age', 'Crew (link)');
        }

        return [$card->fresh(), $wo->fresh(), $fault->fresh()];
    }

    /** Take an own-crew card from draft to the point where "Complete job card" is offered. */
    private function readyToComplete(RentalJobCard $card, RentalWorkOrder $wo): void
    {
        $this->ownerLimits(1000000.0);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->authoriseCostOf($wo->fresh());
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card), ['worker_sign_off_name' => 'Sam'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.agent-sign-off', $card))->assertSessionHasNoErrors();
    }

    private function complete(RentalJobCard $card, string $paidBy): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.complete', $card), ['paid_by' => $paidBy])->assertSessionHasNoErrors();
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $card->fresh()->status);
    }

    // ── 1. who pays reaches the fault ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_completing_a_crew_job_with_who_pays_owner_records_the_ruling_the_fault_and_the_portals_read(): void
    {
        [$card, $wo, $fault] = $this->faultJob();
        $svc = app(RentalWhoPaysService::class);
        $this->assertNull($svc->rulingForFault($fault), 'no ruling before the close');

        $this->readyToComplete($card, $wo);
        $this->complete($card, 'owner');

        $ruling = $svc->rulingForFault($fault->fresh());
        $this->assertNotNull($ruling, 'the fault now has a ruling');
        $this->assertSame(RentalPayerRuling::PAYER_OWNER, $ruling->payer);
        $this->assertSame($this->admin->id, (int) $ruling->ruled_by_user_id);
        $this->assertStringContainsString('when the job card was completed', $ruling->reason);
        $this->assertSame('owner', $wo->fresh()->who_pays);
        $this->assertSame($ruling->id, (int) $wo->fresh()->who_pays_ruling_id);

        // the fault page reads it - not "No ruling yet"
        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-who-pays-summary', $html);
        $this->assertStringNotContainsString('No ruling yet', $html);
        $this->assertStringContainsString('Ruling:', $html);

        // the owner's portal says so; the tenant's shows nothing (they pay nothing)
        $this->assertSame('You will pay for this.', $svc->portalPayload($fault->fresh(), 'owner')['headline']);
        $this->assertNull($svc->portalPayload($fault->fresh(), 'tenant'));

        // closing it did not leave a second ruling behind
        $this->assertSame(1, RentalPayerRuling::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
    }

    public function test_a_ruling_is_not_invented_when_there_is_nothing_to_base_it_on_or_one_exists(): void
    {
        $svc = app(RentalWhoPaysService::class);

        // not a fault job: nothing to rule on, and the close is unaffected
        [$plain, $plainWo] = $this->internalJob(345);
        $this->readyToComplete($plain, $plainWo);
        $this->complete($plain, 'owner');
        $this->assertSame(0, RentalPayerRuling::withoutGlobalScopes()->where('rental_work_order_id', $plainWo->id)->count());

        // a fault job with no "what was found" / cause yet: the ruling needs them, so none is made - and the close still works
        [$card, $wo, $fault] = $this->faultJob(found: false);
        $this->readyToComplete($card, $wo);
        $this->complete($card, 'owner');
        $this->assertNull($svc->rulingForFault($fault->fresh()));
    }

    public function test_choosing_the_tenant_is_not_turned_into_a_ruling_that_tells_the_tenant(): void
    {
        [$card, $wo, $fault] = $this->faultJob();
        $this->readyToComplete($card, $wo);
        $this->complete($card, 'tenant');

        $this->assertNull(app(RentalWhoPaysService::class)->rulingForFault($fault->fresh()), 'a tenant charge stays a deliberate ruling on the work order');
        $this->assertSame('tenant', $wo->fresh()->paid_by, 'the close itself still records who settles it');
    }

    public function test_an_existing_ruling_is_left_alone(): void
    {
        [$card, $wo, $fault] = $this->faultJob();
        $svc = app(RentalWhoPaysService::class);
        $svc->rule($wo->fresh(), $this->admin, RentalPayerRuling::PAYER_SHARED, 40, 'Shared after the tenant admitted forcing it');
        $this->readyToComplete($card, $wo);
        $this->complete($card, 'owner');

        $this->assertSame(RentalPayerRuling::PAYER_SHARED, $svc->rulingForFault($fault->fresh())->payer, 'the agent\'s own ruling stands');
        $this->assertSame(1, RentalPayerRuling::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
    }

    // ── 2. the money card's pre-authorisation warning goes once authorised ─────────────────────────────────────────────────────────

    public function test_the_money_card_drops_the_no_limit_warning_once_the_cost_is_authorised(): void
    {
        [$card, $wo] = $this->internalJob(1000);   // no owner limit set: the owner must decide
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();

        $before = $this->visibleText($this->page($card));
        $this->assertStringContainsString('The owner has not set a repair limit - the owner must approve this.', $before);

        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok by email']);

        $html = $this->page($card);
        $this->assertStringNotContainsString('data-owner-limit-verdict', $html);
        $text = $this->visibleText($html);
        $this->assertStringNotContainsString('The owner has not set a repair limit', $text);
        $this->assertStringContainsString('Authorised by agent', $text, 'the Authorised row says it instead');
    }

    // ── 3. the alert wording follows the route ─────────────────────────────────────────────────────────────────────────────────────

    private ?\App\Models\ClientUser $owner = null;

    private function ownerApprovalAlertTitle(RentalWorkOrder $wo): string
    {
        Sanctum::actingAs($this->owner ??= $this->clientFor($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'] + \Tests\Support\OwnerQuoteSnapshot::for($wo))->assertOk();

        $titles = \Illuminate\Support\Facades\DB::table('notifications')->get()
            ->map(fn ($n) => json_decode($n->data, true))
            ->filter(fn ($d) => ($d['event_key'] ?? null) === 'rental_work_order.owner_decided')
            ->pluck('title')->all();
        $this->assertNotEmpty($titles, 'the agent was alerted');

        return (string) end($titles);
    }

    public function test_the_owner_approved_alert_says_book_the_date_on_a_crew_job_and_send_it_on_a_contractor_job(): void
    {
        [$card, $crewWo] = $this->internalJob(1200);
        $crewWo->forceFill(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING])->save();
        $crew = $this->ownerApprovalAlertTitle($crewWo);
        $this->assertStringContainsString('Owner approved the quote', $crew);
        $this->assertStringContainsString('book the date with the crew', $crew);
        $this->assertStringNotContainsString('contractor', $crew);

        \Illuminate\Support\Facades\DB::table('notifications')->delete();
        $outside = $this->externalWorkOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING]);
        $contractor = $this->ownerApprovalAlertTitle($outside);
        $this->assertStringContainsString('send it to the contractor', $contractor);
        $this->assertStringNotContainsString('crew', $contractor);
    }

    // ── 4. the owner portal pill reads the approval ────────────────────────────────────────────────────────────────────────────────

    public function test_the_owner_portal_pill_follows_an_own_crew_job_from_approval_to_completed(): void
    {
        $view = app(RentalWorkOrderClientViewService::class);
        [$card, $wo] = $this->internalJob(1000);   // no limit set: the owner decides
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->assertSame('Needs your decision', $view->stageLabel($wo->fresh(), $view::AUDIENCE_LANDLORD));

        // the owner approves: the pill is no longer "Created" - and never reaches the tenant
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'] + \Tests\Support\OwnerQuoteSnapshot::for($wo))->assertOk();
        $this->assertSame('Approved - being booked', $view->stageLabel($wo->fresh(), $view::AUDIENCE_LANDLORD));
        $this->assertSame('Approved - ready to book', $view->stageLabel($wo->fresh(), 'agent'), 'the office reads its own words');
        $this->assertSame('Created', $view->stageLabel($wo->fresh(), $view::AUDIENCE_TENANT), 'the tenant is never told about approvals');
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->json('work_orders'))->firstWhere('id', $wo->id);
        $this->assertSame('Approved - being booked', $row['client']['stage_label'], 'what the owner portal card shows');

        // booked -> appointment; closed -> completed (as the contractor route already does)
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame('Appointment set', $view->stageLabel($wo->fresh(), $view::AUDIENCE_LANDLORD));
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card), ['worker_sign_off_name' => 'Sam'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.agent-sign-off', $card))->assertSessionHasNoErrors();
        $this->complete($card, 'owner');
        $this->assertSame('Completed', $view->stageLabel($wo->fresh(), $view::AUDIENCE_LANDLORD));
    }

    public function test_an_own_crew_job_authorised_within_the_limit_also_reads_approved_not_created(): void
    {
        $view = app(RentalWorkOrderClientViewService::class);
        $this->ownerLimits(1000000.0);
        [$card, $wo] = $this->internalJob(345);
        $this->assertSame('Created', $view->stageLabel($wo->fresh(), $view::AUDIENCE_LANDLORD), 'nothing authorised yet');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->authoriseCostOf($wo->fresh());
        $this->assertSame('Approved - being booked', $view->stageLabel($wo->fresh(), $view::AUDIENCE_LANDLORD));
    }
}
