<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalApprovalDecision;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.6.5 — work does not start without an authorisation: the owner's approval, the no-approval limit
 * or an emergency agreement. Enforced in the MODELS (job card schedule / start / crew completion, work order assign-supplier / start),
 * not just the controllers; in-flight work at deploy time is grandfathered; a screen can ask without writing anything.
 */
final class AuthoriseToProceedTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Authorise');
    }

    private function emergency(RentalWorkOrder $wo): void
    {
        app(RentalApprovalGateService::class)->recordEmergency($wo, ['approved_by_name' => 'Mr Owner', 'approved_via' => 'whatsapp', 'approved_at' => now()->subMinute(), 'reason' => 'Burst geyser'], $this->admin);
    }

    private function decisionCount(RentalWorkOrder $wo): int
    {
        return RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count();
    }

    // ── job card: schedule / start ───────────────────────────────────

    public function test_a_job_with_nothing_priced_cannot_be_scheduled_or_started(): void
    {
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Nothing priced'], $this->admin);

        foreach ([
            fn () => $card->schedule(now()->addDay(), null, $this->admin),
            fn () => $card->start($this->admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('an unpriced, unapproved job must not proceed');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('Nothing has been priced or approved', $e->getMessage());
            }
        }
        $this->assertSame(RentalJobCard::STATUS_DRAFT, $card->fresh()->status);
    }

    public function test_a_priced_job_inside_the_limit_is_authorised_and_the_decision_is_recorded_once(): void
    {
        [$card, $wo] = $this->internalJob(300.0);

        $card->schedule(now()->addDay(), null, $this->admin);

        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
        $this->assertSame('300.00', $wo->fresh()->approved_amount, 'the job amount is now the approved baseline');
        $this->assertSame(RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, $wo->fresh()->approval_basis);
        $row = RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->sole();
        $this->assertSame('auto_approved', $row->decision);
        $this->assertSame('no_approval_limit', $row->term_key);

        $card->fresh()->start($this->admin);   // a second guarded transition: authorised, and no second decision row
        $this->assertSame(1, $this->decisionCount($wo));
    }

    public function test_a_priced_job_above_the_limit_that_was_never_quoted_is_refused_with_what_to_do_next(): void
    {
        [$card] = $this->internalJob(1500.0);

        try {
            $card->schedule(now()->addDay(), null, $this->admin);
            $this->fail('R1,500 is above the R500 limit');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('above the owner\'s no-approval limit of R500.00', $e->getMessage());
            $this->assertStringContainsString('send the quote to the owner', $e->getMessage());
        }
    }

    public function test_a_quote_waiting_for_the_owner_blocks_the_work_until_the_owner_approves(): void
    {
        [$card] = $this->internalJob(1500.0);
        $wo = $this->sendQuote($card);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status);

        try {
            $card->fresh()->schedule(now()->addDay(), null, $this->admin);
            $this->fail('pending approval must block');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('has not been approved by the owner yet', $e->getMessage());
        }

        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Owner said yes']);
        $card->fresh()->schedule(now()->addDay(), null, $this->admin);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
    }

    public function test_a_declined_job_is_refused(): void
    {
        [$card] = $this->internalJob(1500.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'declined', 'evidence_type' => 'email', 'evidence_text' => 'Owner said no']);

        $this->expectExceptionMessage('The owner declined this work.');
        $card->fresh()->start($this->admin);
    }

    public function test_the_office_screen_refuses_with_the_plain_message_not_a_500(): void
    {
        [$card] = $this->internalJob(1500.0);

        $this->actingAs($this->admin)->from(route('corex.rental-job-cards.show', $card))
            ->post(route('corex.rental-job-cards.start', $card))
            ->assertRedirect()->assertSessionHasErrors();

        $this->assertSame(RentalJobCard::STATUS_DRAFT, $card->fresh()->status);
    }

    // ── crew completion ──────────────────────────────────────────────

    public function test_the_crew_cannot_complete_a_job_the_owner_has_not_approved(): void
    {
        [$card] = $this->internalJob(1500.0);
        $this->sendQuote($card);

        try {
            $card->fresh()->recordCrewCompletion('Sipho Dlamini', 'crew_link', '203.0.113.9', 'TestPhone', null);
            $this->fail('crew completion needs an authorisation');
        } catch (\LogicException $e) {
            $this->assertSame('This job has not been approved by the owner — contact the office.', $e->getMessage());
        }
        $this->assertNull($card->fresh()->worker_signed_off_at);
    }

    public function test_the_crew_can_complete_once_the_work_is_covered(): void
    {
        [$card, $wo] = $this->internalJob(300.0);

        $card->recordCrewCompletion('Sipho Dlamini', 'crew_link', '203.0.113.9', 'TestPhone', null);

        $this->assertNotNull($card->fresh()->worker_signed_off_at);
    }

    // ── external work order ──────────────────────────────────────────

    public function test_an_outside_contractor_work_order_needs_a_quote_or_an_emergency_before_it_can_go_out(): void
    {
        $wo = $this->externalWorkOrder();
        $supplier = $this->supplier();

        try {
            $wo->assignSupplier($supplier->id, 'plumbing', $this->admin);
            $this->fail('nothing is priced, nothing is approved');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Nothing has been priced or approved', $e->getMessage());
        }
        try {
            $wo->startProgress($this->admin);
            $this->fail('and it cannot be started either');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 320, 'quote_date' => now(), 'detail_text' => 'phone quote'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $wo->assignSupplier($supplier->id, 'plumbing', $this->admin);

        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
    }

    // ── the other ways to be authorised ──────────────────────────────

    public function test_in_flight_work_at_deploy_time_is_grandfathered_whatever_its_approval_state(): void
    {
        [$card, $wo] = $this->internalJob(9000.0);
        $wo->forceFill(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING, 'approval_basis' => RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED])->save();

        $card->fresh()->start($this->admin);

        $this->assertSame(RentalJobCard::STATUS_IN_PROGRESS, $card->fresh()->status);
    }

    public function test_an_emergency_approval_authorises_work_with_nothing_priced(): void
    {
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Burst main'], $this->admin);
        $this->emergency($card->workOrder()->first());

        $card->fresh()->schedule(now()->addHour(), null, $this->admin);

        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
    }

    public function test_with_pricing_switched_off_nothing_can_be_priced_so_nothing_is_gated(): void
    {
        $this->setting(['capture_prices_on_job_cards' => false]);
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Garden service'], $this->admin);

        $card->schedule(now()->addDay(), null, $this->admin);

        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
    }

    public function test_a_card_with_no_work_order_is_judged_on_its_own_amount_against_the_property_limit(): void
    {
        $small = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id, 'title' => 'Standalone small',
            'general_lines' => [['type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit_price' => 120]],
        ], $this->admin);
        $big = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id, 'title' => 'Standalone big',
            'general_lines' => [['type' => 'part', 'description' => 'Boiler', 'quantity' => 1, 'unit_price' => 4000]],
        ], $this->admin);
        $empty = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Standalone empty'], $this->admin);

        $small->schedule(now()->addDay(), null, $this->admin);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $small->fresh()->status);

        foreach ([$big, $empty] as $card) {
            try {
                $card->schedule(now()->addDay(), null, $this->admin);
                $this->fail('over the limit / nothing priced');
            } catch (\LogicException) {
                $this->assertSame(RentalJobCard::STATUS_DRAFT, $card->fresh()->status);
            }
        }
        $this->assertSame(0, RentalApprovalDecision::withoutGlobalScopes()->count(), 'no work order, so nothing is recorded');
    }

    public function test_asking_without_recording_writes_nothing(): void
    {
        [$card, $wo] = $this->internalJob(300.0);

        $decision = app(RentalApprovalGateService::class)->authoriseToProceed($wo, false);

        $this->assertTrue($decision->authorised);
        $this->assertSame(0, $this->decisionCount($wo));
        $this->assertNull($wo->fresh()->approved_amount);
    }
}
