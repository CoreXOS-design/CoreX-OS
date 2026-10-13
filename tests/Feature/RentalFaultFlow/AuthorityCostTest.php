<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalAuthorisationToOwnerMail;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalApprovalDecision;
use App\Models\RentalAuthorisation;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalAuthorisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.6, stage 2: "Approve the cost". Nothing is approved by itself: the work cannot start until the cost
 * has an authorisation record - the agent under the owner's repair / emergency limit, the owner on their link, or the agent recording the owner's decision. Limits are
 * read only through the owner-settings resolver (null = the owner must authorise first). A final cost above what was authorised sends the difference to the owner.
 */
final class AuthorityCostTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private RentalAuthorisationService $auth;
    private RentalApprovalGateService $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('CostAuth');
        $this->auth = app(RentalAuthorisationService::class);
        $this->gate = app(RentalApprovalGateService::class);
    }

    /** A work order with ONE selected outside quote of $amount. */
    private function quoted(float $amount): RentalWorkOrder
    {
        $wo = $this->externalWorkOrder();
        $quote = $wo->recordQuote(['agency_service_provider_id' => $this->supplier('Quote Plumbing')->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);

        return $wo->fresh();
    }

    private function records(RentalWorkOrder $wo)
    {
        return RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->orderBy('id')->get();
    }

    // ── nothing is approved by itself ────────────────────────────────

    public function test_a_quote_inside_the_repair_limit_approves_nothing_until_someone_authorises_it(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1200.0);

        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->owner_approval_status, 'the owner is not asked - it is inside the limit');
        $this->assertNull($wo->approved_amount, 'but nothing is approved');
        $this->assertNull($wo->approval_basis);
        $this->assertSame(0, $this->records($wo)->count());
        $this->assertSame('needs_agent', RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->latest('id')->value('decision'));

        $proceed = $this->gate->authoriseToProceed($wo, false);
        $this->assertFalse($proceed->authorised);
        $this->assertStringContainsString('has not been authorised yet', $proceed->note);
        $this->assertStringContainsString('R1,200.00', $proceed->note);
        $this->assertCount(0, $this->sent(), 'nothing went to the owner');
    }

    public function test_a_quote_above_the_limit_or_with_no_limit_set_goes_to_the_owner(): void
    {
        $this->ownerLimits(1000.0);
        $over = $this->quoted(1001.0);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $over->owner_approval_status);

        $this->ownerLimits(null);   // the owner has not set a limit: must ask
        $none = $this->quoted(50.0);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $none->owner_approval_status, 'no limit set = must ask, even for R50');

        $this->ownerLimits(5000.0, null, 'disputed');   // the owner does not agree with the settings: must ask
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $this->quoted(50.0)->owner_approval_status);
    }

    public function test_a_legacy_no_approval_threshold_on_the_property_no_longer_authorises_anything(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 5000])->save();
        $wo = $this->quoted(100.0);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status, 'only the owner-settings resolver counts');
    }

    // ── "Authorise R___ within the repair limit" ─────────────────────

    public function test_the_agent_authorises_within_the_repair_limit_and_the_owner_is_told(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1200.0);

        $row = $this->authoriseCostOf($wo);

        $this->assertSame('cost', $row->point);
        $this->assertSame('authorised', $row->outcome);
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_REPAIR_LIMIT, $row->basis);
        $this->assertSame(1200.0, (float) $row->amount_up_to);
        $this->assertSame(1500.0, (float) $row->limit_amount);
        $this->assertFalse((bool) $row->is_emergency);
        $this->assertSame($this->admin->id, (int) $row->by_user_id);
        $this->assertNotNull($row->told_at);
        $this->assertNotNull($row->owner_told_at, 'the owner was told at once');
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => $m->hasTo($this->landlord->email)
            && str_contains($m->sentence, "under your repair limit of R1,500.00") && str_contains($m->sentence, 'R1,200.00'));

        $wo = $wo->fresh();
        $this->assertSame(1200.0, (float) $wo->approved_amount, 'the baseline later extras are measured against');
        $this->assertSame(RentalWorkOrder::BASIS_AGENT_REPAIR_LIMIT, $wo->approval_basis);
        $this->assertTrue($this->gate->authoriseToProceed($wo, false)->authorised);
        $this->assertStringContainsString("Authorised by agent {$this->admin->name}", $this->gate->authoriseToProceed($wo, false)->note);
        $this->assertDatabaseHas('rental_work_order_updates', ['rental_work_order_id' => $wo->id, 'update_type' => 'cost_authorised']);
        $this->assertSame('approved', RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->latest('id')->value('decision'));
    }

    public function test_an_agent_can_never_authorise_above_the_limit_or_without_one(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->quoted(1000.01);
        try {
            $this->authoriseCostOf($wo);
            $this->fail('above the limit must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("is above the owner's repair limit of R1,000.00. The owner must authorise this first.", $e->getMessage());
        }
        $this->assertSame(0, $this->records($wo)->count());

        $this->ownerLimits(null);
        $wo2 = $this->quoted(10.0);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the owner must authorise this first');
        $this->authoriseCostOf($wo2);
    }

    public function test_the_limit_boundary_is_inclusive_and_the_cost_can_only_be_authorised_once(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->quoted(1000.0);
        $this->assertSame(1000.0, (float) $this->authoriseCostOf($wo)->amount_up_to);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already authorised');
        $this->authoriseCostOf($wo->fresh());
    }

    // ── a small known job: the agent types the amount ────────────────

    public function test_a_typed_amount_for_a_small_known_job_within_the_limit(): void
    {
        $this->ownerLimits(800.0);
        $wo = $this->externalWorkOrder();   // no quote at all

        $this->assertFalse($this->gate->authoriseToProceed($wo, false)->authorised);
        $eval = $this->auth->costEvaluation($wo, 450.0);
        $this->assertSame('typed', $eval['source']);
        $this->assertTrue($eval['agent_can']);

        $row = $this->authoriseCostOf($wo, 450.0);
        $this->assertSame(450.0, (float) $row->amount_up_to);
        $this->assertSame('Amount typed by the agent (no quote).', $row->note);
        $this->assertTrue($this->gate->authoriseToProceed($wo->fresh(), false)->authorised);
    }

    public function test_a_typed_amount_above_the_limit_or_no_amount_at_all_is_refused(): void
    {
        $this->ownerLimits(800.0);
        $wo = $this->externalWorkOrder();
        $this->assertFalse($this->auth->costEvaluation($wo, 801.0)['agent_can']);
        $this->assertStringContainsString('Nothing is priced yet', $this->auth->costEvaluation($wo)['line']);
        $this->expectException(\LogicException::class);
        $this->authoriseCostOf($wo, 801.0);
    }

    // ── emergencies ──────────────────────────────────────────────────

    public function test_an_emergency_needs_a_reason_and_uses_the_emergency_limit(): void
    {
        $this->ownerLimits(500.0, 3000.0);
        $wo = $this->quoted(2500.0);   // above the repair limit, inside the emergency limit

        try {
            $this->auth->authoriseCost($wo, $this->admin, ['emergency' => true, 'emergency_reason' => '   ']);
            $this->fail('the reason is required');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Say why this is an emergency - it is required.', $e->getMessage());
        }
        $this->assertFalse($this->auth->costEvaluation($wo, null, false)['agent_can'], 'not an emergency: the repair limit applies');

        $row = $this->auth->authoriseCost($wo, $this->admin, ['emergency' => true, 'emergency_reason' => 'Burst geyser flooding the ceiling']);
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_EMERGENCY_LIMIT, $row->basis);
        $this->assertTrue((bool) $row->is_emergency);
        $this->assertSame('Burst geyser flooding the ceiling', $row->emergency_reason);
        $this->assertSame(3000.0, (float) $row->limit_amount);
        $this->assertSame(RentalWorkOrder::BASIS_AGENT_EMERGENCY_LIMIT, $wo->fresh()->approval_basis);
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => str_contains($m->sentence, 'emergency limit of R3,000.00') && str_contains($m->sentence, 'Burst geyser flooding the ceiling'));
    }

    public function test_above_the_emergency_limit_or_with_none_set_the_owner_comes_first_even_in_an_emergency(): void
    {
        $this->ownerLimits(500.0, 3000.0);
        $this->assertFalse($this->auth->costEvaluation($this->quoted(3001.0), null, true)['agent_can']);
        $this->ownerLimits(500.0, null);
        $e = $this->auth->costEvaluation($this->quoted(100.0), null, true);
        $this->assertFalse($e['agent_can']);
        $this->assertStringContainsString('emergency limit', $e['line']);
        $this->assertStringContainsString('the owner must authorise this first', $e['line']);
    }

    // ── own crew: the work-order selling price, never the cost ───────

    public function test_our_own_crews_amount_is_the_work_order_selling_price_and_the_card_cost_never_reaches_the_owner(): void
    {
        $this->ownerLimits(2000.0);
        [$card, $wo] = $this->internalJob(1000.0, ['unit_cost' => 640.0]);

        $m = $this->auth->costMeasure($wo);
        $this->assertSame('crew', $m['source']);
        $this->assertSame(1000.0, $m['amount'], 'the owner-facing price, not the 640 cost');

        $this->assertFalse($this->gate->authoriseToProceed($wo, false)->authorised, 'pricing the card approves nothing');
        $row = $this->authoriseCostOf($wo);
        $this->assertSame(1000.0, (float) $row->amount_up_to);
        $sentence = $this->auth->sentenceFor($row->load('byUser'));
        $this->assertStringNotContainsString('640', $sentence);
        $this->assertTrue($this->gate->authoriseToProceed($wo->fresh(), false)->authorised);
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => ! str_contains($m->sentence, '640'));
    }

    public function test_confirming_the_crews_price_inside_the_limit_sends_nothing_and_approves_nothing(): void
    {
        $this->ownerLimits(2000.0);
        [$card, $wo] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);

        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->owner_approval_status);
        $this->assertNull($wo->approved_amount);
        $this->assertFalse($this->gate->authoriseCard($card->fresh(), false)->authorised);
        $this->assertSame(RentalJobCard::STATUS_QUOTED, $card->fresh()->status, 'not "approved" - nobody authorised it');
        $this->assertCount(0, $this->sent(), 'nothing is sent to the owner inside the limit');

        $this->authoriseCostOf($wo);
        $card->fresh()->syncStatusFromWorkOrder();
        $this->assertSame(RentalJobCard::STATUS_APPROVED, $card->fresh()->status, 'now it can be booked');
        $this->assertTrue($this->gate->authoriseCard($card->fresh(), false)->authorised);
    }

    // ── the owner's authority: their link, or the agent recording their words ─────────

    public function test_the_owner_pressing_approve_on_their_link_leaves_an_owner_link_record(): void
    {
        $this->ownerLimits(100.0);
        $wo = $this->quoted(900.0);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status);

        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => null]);

        $row = $this->records($wo)->sole();
        $this->assertSame(RentalAuthorisation::BASIS_OWNER_LINK, $row->basis);
        $this->assertSame('authorised', $row->outcome);
        $this->assertSame(900.0, (float) $row->amount_up_to);
        $this->assertSame($this->landlord->id, (int) $row->by_contact_id);
        $this->assertSame(900.0, (float) $wo->fresh()->approved_amount);
        $this->assertTrue($this->gate->authoriseToProceed($wo->fresh(), false)->authorised);
    }

    public function test_recording_the_owners_decision_approved_up_to_an_amount(): void
    {
        $this->ownerLimits(100.0);
        $wo = $this->quoted(900.0);

        $row = $this->auth->recordOwnerCostDecision($this->admin, $wo, [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 1000, 'told_via' => 'phone_call', 'told_at' => now()->subMinutes(10)->toDateTimeString(),
            'note' => 'Owner said go ahead up to a thousand',
        ]);

        $this->assertSame(RentalAuthorisation::BASIS_OWNER_INSTRUCTION, $row->basis);
        $this->assertSame(1000.0, (float) $row->amount_up_to);
        $this->assertSame('phone_call', $row->told_via);
        $this->assertSame('Owner said go ahead up to a thousand', $row->note);
        $this->assertSame($this->landlord->id, (int) $row->owner_contact_id);
        $this->assertSame(1000.0, (float) $wo->fresh()->approved_amount, 'what the owner approved up to is the baseline');
        $this->assertTrue($this->gate->authoriseToProceed($wo->fresh(), false)->authorised);
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => str_contains($m->sentence, "by your agent {$this->admin->name} on your instruction, given by phone call") && str_contains($m->sentence, 'Approved up to R1,000.00'));
    }

    public function test_recording_the_owners_decision_has_its_rules(): void
    {
        $this->ownerLimits(100.0);
        $wo = $this->quoted(900.0);
        $base = ['owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 900, 'told_via' => 'whatsapp', 'note' => 'ok'];

        foreach ([
            [['approved_up_to' => 800], 'less than the R900.00 being asked'],
            [['approved_up_to' => ''], 'Say the amount the owner approved up to.'],
            [['note' => ' '], 'what the owner said - it is required'],
            [['told_via' => 'smoke_signal'], 'Say how the owner told you'],
            [['owner_contact_id' => 99999999], 'Pick which owner told you'],
        ] as [$over, $msg]) {
            try {
                $this->auth->recordOwnerCostDecision($this->admin, $wo, array_merge($base, $over));
                $this->fail("should have refused: {$msg}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->assertSame(0, $this->records($wo)->count());

        // a decline is recorded with the owner's reason, and blocks the work
        $row = $this->auth->recordOwnerCostDecision($this->admin, $wo, ['owner_contact_id' => $this->landlord->id, 'decision' => 'declined', 'told_via' => 'email', 'note' => 'Too expensive, get another quote']);
        $this->assertSame('declined', $row->outcome);
        $this->assertFalse($this->gate->authoriseToProceed($wo->fresh(), false)->authorised);
    }

    public function test_a_newer_recorded_decision_replaces_an_older_one_and_the_owners_own_press_cannot_be_replaced(): void
    {
        $this->ownerLimits(100.0);
        $wo = $this->quoted(900.0);
        $first = $this->auth->recordOwnerCostDecision($this->admin, $wo, ['owner_contact_id' => $this->landlord->id, 'decision' => 'declined', 'told_via' => 'email', 'note' => 'no']);
        $second = $this->auth->recordOwnerCostDecision($this->admin, $wo->fresh(), ['owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 900, 'told_via' => 'phone_call', 'note' => 'changed their mind']);
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame($second->id, (int) $first->fresh()->superseded_by_id);
        $this->assertSame($second->id, $this->auth->activeFor($wo, 'cost')->id);
        $this->assertSame(2, $this->records($wo)->count(), 'both stay in the history');

        $wo2 = $this->quoted(900.0);
        $wo2->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already decided on their own link');
        $this->auth->recordOwnerCostDecision($this->admin, $wo2->fresh(), ['owner_contact_id' => $this->landlord->id, 'decision' => 'declined', 'told_via' => 'email', 'note' => 'x']);
    }

    // ── a final cost above what was authorised goes to the owner ─────

    public function test_a_final_cost_above_what_was_authorised_sends_the_difference_to_the_owner_and_holds_the_close(): void
    {
        $this->ownerLimits(1500.0);
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->quoted(1000.0);
        $this->authoriseCostOf($wo);
        $wo = $wo->fresh();
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();

        try {
            $wo->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1400.0]);
            $this->fail('the close must wait for the owner');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('The final cost of R1,400.00 is R400.00 above what was authorised (R1,000.00). The difference has been sent to the owner', $e->getMessage());
        }
        $v = RentalWorkOrderVariation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->sole();
        $this->assertSame('final_invoice', $v->origin);
        $this->assertSame('awaiting_owner', $v->status);
        $this->assertSame(400.0, (float) $v->extra_amount);
        $this->assertSame(1400.0, (float) $v->new_total);
        $this->assertNotEmpty($this->sent(\App\Mail\Rentals\RentalOwnerVariationMail::class), 'the owner is asked');
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);

        // asking again revises the same request, never a second one
        try { $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1450.0]); } catch (\LogicException) {}
        $this->assertSame(1, RentalWorkOrderVariation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
    }

    public function test_a_final_cost_at_or_under_what_was_authorised_closes_normally(): void
    {
        $this->ownerLimits(1500.0);
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->quoted(1000.0);
        $this->authoriseCostOf($wo);
        $wo = $wo->fresh();
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $wo->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1000.0]);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_extra_work_is_never_approved_by_the_repair_limit(): void
    {
        $this->ownerLimits(5000.0);
        $wo = $this->quoted(1000.0);
        $this->authoriseCostOf($wo);
        $d = $this->gate->evaluateVariation($wo->fresh(), 1200.0, $this->admin, false);
        $this->assertFalse($d->authorised, 'R1200 is inside the R5000 limit, yet extra money goes to the owner');
        $this->assertSame('needs_owner', $d->decision);
    }

    public function test_there_is_no_tolerance_exactly_the_limit_passes_and_one_cent_over_goes_to_the_owner(): void
    {
        $this->ownerLimits(1000.0, 3000.0);

        // repair limit: R1,000.00 can be authorised by the agent, R1,000.01 cannot - the owner is asked first
        $this->assertTrue($this->auth->costEvaluation($this->quoted(1000.00), null, false)['agent_can']);
        $over = $this->auth->costEvaluation($this->quoted(1000.01), null, false);
        $this->assertFalse($over['agent_can']);
        $this->assertStringContainsString('The owner must authorise this first.', $over['line']);

        // emergency limit: the same rule
        $this->assertTrue($this->auth->costEvaluation($this->quoted(3000.00), null, true)['agent_can']);
        $this->assertFalse($this->auth->costEvaluation($this->quoted(3000.01), null, true)['agent_can']);

        // the gate agrees: one cent over the repair limit is pending and the owner is asked
        $wo = $this->externalWorkOrder();
        $this->assertSame('needs_agent', $this->gate->evaluateQuote($wo, 1000.00, $this->admin)->decision);
        $this->assertSame('needs_owner', $this->gate->evaluateQuote($wo, 1000.01, $this->admin)->decision);

        // an agent cannot authorise R1,000.01 under a R1,000 limit
        $this->expectException(\LogicException::class);
        $this->authoriseCostOf($this->quoted(1000.01));
    }

    public function test_the_owner_approved_up_to_amount_is_also_a_hard_lock_for_the_amount_being_asked(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quoted(1200.00);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('less than the');
        $this->auth->recordOwnerCostDecision($this->admin, $wo, ['owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 1199.99, 'told_via' => 'email', 'note' => 'R1,199.99 only']);
    }

    public function test_while_the_owner_has_paused_the_job_no_cost_can_be_authorised_or_recorded(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1200.0);
        app(\App\Services\Rentals\RentalOwnerHoldService::class)->stop($wo, $this->landlord, 'Please wait, I want a second quote', 'link');

        $this->assertStringContainsString('PAUSED BY OWNER', (string) $this->auth->costBlockReason($wo->fresh()));
        foreach ([
            fn () => $this->authoriseCostOf($wo->fresh()),
            fn () => $this->auth->recordOwnerCostDecision($this->admin, $wo->fresh(), ['owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 1200, 'told_via' => 'email', 'note' => 'ok']),
        ] as $try) {
            try {
                $try();
                $this->fail('a paused job cannot be approved');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('PAUSED BY OWNER', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->records($wo)->count());
    }

    public function test_the_agents_queue_says_when_a_cost_is_waiting_to_be_authorised(): void
    {
        $this->ownerLimits(2000.0);
        $wo = $this->quoted(300.0);
        [$card, $cardWo] = $this->internalJob(345.0);
        $card->forceFill(['rental_crew_id' => $this->crew->id])->save();
        $this->sendQuote($card);
        $types = fn () => app(\App\Services\Rentals\RentalCommandCentreService::class)->queueItems($this->admin, 'all')->pluck('type')->all();

        $this->assertContains('wo_authorise_cost', $types(), 'a selected quote inside the limit is waiting for the agent');
        $this->assertContains('jc_authorise_cost', $types(), 'and so is the crew\'s confirmed price');

        $this->authoriseCostOf($wo->fresh());
        $this->authoriseCostOf($cardWo->fresh());
        $this->assertNotContains('wo_authorise_cost', $types());
        $this->assertNotContains('jc_authorise_cost', $types());
        $this->assertContains('wo_send', $types(), 'authorised: the work order is ready to go to the contractor');
    }

    public function test_a_job_nobody_authorised_cannot_be_finished_by_the_office_or_by_the_contractors_link(): void
    {
        $this->ownerLimits(1500.0);
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->quoted(1200.0);   // selected, inside the limit, NOT authorised
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();

        try {
            $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 100]);
            $this->fail('an unauthorised job cannot be completed');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('has not been authorised yet', $e->getMessage());
        }
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);

        // the contractor's own link (they have accepted the job): refused in the contractor's words, nothing finished
        $wo->forceFill(['contractor_response' => \App\Services\Rentals\RentalContractorJobService::RESPONSE_ACCEPTED])->save();
        $raw = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($wo->fresh(), $this->admin)['raw_token'];
        $this->post(route('rentals.secure-link.mark-done', $raw), [])->assertSessionHasErrors('work_order');
        $this->assertStringContainsString('has not been authorised yet - the agency will tell you', session('errors')->first('work_order'));
        $this->assertStringNotContainsString('Approve the cost', session('errors')->first('work_order'));
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);

        // authorised: it can be finished
        $this->authoriseCostOf($wo->fresh());
        $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 100]);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_the_tenants_self_check_reads_for_the_agent_and_never_shows_an_unfilled_placeholder(): void
    {
        $svc = app(\App\Services\Rentals\RentalFaultTypeService::class);
        $tenantFallback = "Your agent hasn't recorded where the main water valve is — check near the meter/boundary.";
        $w = $svc->agentWording($tenantFallback);
        $this->assertSame('Main water valve location not recorded on this property', $w['text']);
        $this->assertSame('rental_main_water_valve_location', $w['field']);
        $this->assertSame('DB board location not recorded on this property', $svc->agentWording('Your agent hasn\'t recorded where the DB board is — check near the meter/boundary.')['text']);
        $raw = $svc->agentWording('Your main water valve is at: {{main_water_valve_location}}. Close it.');
        $this->assertStringNotContainsString('{{', $raw['text']);
        $this->assertSame('Main water valve location not recorded on this property', $raw['text']);
        $this->assertSame('Main water valve is at: Behind the gate. Close it.', $svc->agentWording('Your main water valve is at: Behind the gate. Close it.')['text']);
        $this->assertSame('Check the washer', $svc->agentWording('Check the washer')['text']);
    }

    public function test_the_cost_box_says_nothing_is_approved_only_while_nothing_is_authorised_and_guides_after_a_decline(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1200.0);
        $before = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertStringNotContainsString('data-nothing-approved', $before, 'the Next line already says what is waiting');
        $this->assertStringNotContainsString('Nothing is approved until you press a button.', $before);

        $this->authoriseCostOf($wo);
        $after = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertStringNotContainsString('data-nothing-approved', $after);
        $this->assertStringNotContainsString('verbal note', strtolower($after));
        // the amount is said once, in the app's money format, and the record says where the job stood in plain words
        $text = $this->visibleText($after);
        $this->assertSame(1, substr_count($text, 'R1,200.00') >= 1 ? 1 : 0);
        $this->assertStringNotContainsString('R1 200.00', $text, 'one money format on the page');
        $this->assertStringNotContainsString('Authorised up to', $text);
        $this->assertStringNotContainsString('job was:', $text, 'no jargon: the record already says when and how');
        $this->assertStringNotContainsString('this is the one that stands', $text);

        // declined by the owner: one line of guidance
        $other = $this->quoted(2900.0);
        $this->auth->recordOwnerCostDecision($this->admin, $other, ['owner_contact_id' => $this->landlord->id, 'decision' => 'declined', 'told_via' => 'phone_call', 'note' => 'Too dear']);
        $declined = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $other))->getContent();
        $this->assertStringContainsString('The owner declined this. Capture another quote and approve its cost, or cancel the work order.', $declined);
    }

    public function test_the_time_typed_in_record_the_owners_decision_is_the_time_stored_and_shown(): void
    {
        $this->ownerLimits(100.0);
        $wo = $this->quoted(900.0);
        $row = $this->auth->recordOwnerCostDecision($this->admin, $wo, [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 900, 'told_via' => 'phone_call', 'told_at' => '2026-10-13T06:20', 'note' => 'Owner phoned',
        ]);
        $this->assertSame('2026-10-13 06:20', $row->fresh()->told_at->format('Y-m-d H:i'), 'stored as typed (agency time), not as UTC');
        $this->assertStringContainsString('13 Oct 2026 06:20', $this->auth->sentenceFor($row->fresh()->load('byUser')));
        $this->assertStringContainsString('13 Oct 2026 06:20', $this->auth->recordTextFor($row->fresh()->load(['byUser', 'ownerContact'])));
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => str_contains($m->sentence, '13 Oct 2026 06:20'));
    }

    // ── old work keeps working ────────────────────────────────────────

    public function test_work_approved_before_stage_2_keeps_working_without_a_record(): void
    {
        foreach ([
            ['approval_basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, 'approved_amount' => 400, 'owner_approval_status' => 'not_required'],
            ['approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION, 'approved_amount' => 400, 'owner_approval_status' => 'approved'],
            ['approval_basis' => RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, 'owner_approval_status' => 'not_required'],
        ] as $attrs) {
            $this->assertTrue($this->gate->authoriseToProceed($this->externalWorkOrder($attrs), false)->authorised, json_encode($attrs));
        }
        $this->assertTrue($this->gate->authoriseToProceed($this->externalWorkOrder(['assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR]), false)->authorised, "the owner's own contractor is not ours to authorise");
    }

    // ── the screen ────────────────────────────────────────────────────

    public function test_the_work_order_page_has_approve_the_cost_with_two_equal_buttons_and_no_old_emergency_form(): void
    {
        $this->ownerLimits(1500.0, 3000.0);
        $wo = $this->quoted(1200.0);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();

        $this->assertStringContainsString('data-cost-decision', $html);
        $this->assertStringContainsString('href="#cost-decision"', $html, '"What happens next" links straight to the box');
        $this->assertStringContainsString('Approve the cost', $html);
        $this->assertStringNotContainsString('Nothing is approved until you press a button.', $html);
        $this->assertStringNotContainsString('Decision 2', $html);
        $this->assertStringContainsString('data-cost-authorise', $html);
        $this->assertStringContainsString("Record the owner's decision", $html);
        $this->assertStringContainsString('data-cost-emergency', $html, 'the emergency tick is part of the same box');
        $this->assertStringContainsString('data-cost-reason', $html);
        $this->assertStringNotContainsString('Record owner\'s emergency approval', $html, 'the old Emergency approval form is gone');
        $this->assertStringNotContainsString('Record decision on the owner\'s behalf', $html);
        $this->assertStringNotContainsString('approved automatically', $html);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('corex.rental-work-orders.emergency-approval.store'));

        // the limits shown are the OWNER's, on two aligned lines, with who said so and a link to where they are kept
        $this->assertStringContainsString('Repairs: up to R1,500.00', $html);
        $this->assertStringContainsString('Emergencies: up to R3,000.00', $html);
        $this->assertStringContainsString('up to R1,500.00', $html);
        $this->assertStringContainsString('up to R3,000.00', $html);
        $this->assertStringContainsString('Confirmed by the owner', $html);
        $this->assertStringContainsString("Owner's fault settings", $html);
        $this->assertStringContainsString('#owner-fault-settings-panel', $html);
        $this->ownerLimits(900.0, null, \App\Models\RentalPropertyFaultSetting::STATUS_CAPTURED);
        $again = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertStringContainsString('Recorded by ', $again);
        $this->assertStringContainsString('not yet confirmed by the owner', $again);
        $this->assertStringContainsString('not set - ask the owner', $again, 'no emergency limit set');
        $this->ownerLimits(null, null, 'not_set');
        $this->assertStringContainsString('Not set yet', $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->getContent());
    }

    public function test_the_work_order_page_has_one_approval_section_and_says_nothing_is_approved_once(): void
    {
        $this->ownerLimits(1500.0, 3000.0);
        $wo = $this->quoted(1200.0);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $text = $this->visibleText($html);

        // ONE section: no separate "Approval" card ("Why was this approved?", "Approval decision", "Work cannot start yet")
        $this->assertSame(1, substr_count($html, 'id="cost-decision"'));
        foreach (['Approval decision:', 'Why was this approved?', "Why is the owner's approval needed?", 'Work cannot start yet', 'No approval decision recorded'] as $gone) {
            $this->assertStringNotContainsString($gone, $text);
        }
        $this->assertStringNotContainsString('id="approval-panel"', $html);

        // said ONCE, under "Approve the cost"
        $this->assertSame(0, substr_count($text, 'Nothing is approved until you press a button.'));
        foreach (['Nothing is approved by itself', 'Selecting a quote approves nothing', 'Nothing is approved by that', 'Nothing has been priced or approved'] as $repeated) {
            $this->assertStringNotContainsString($repeated, $text);
        }
        $this->assertStringContainsString('An appointment can be set once the cost is authorised.', $text);

        // the detail block drops empty lines and what the Supplier / Appointment cards already say
        $this->assertStringNotContainsString('Item:', $text);
        $this->assertStringNotContainsString('Trade: —', $text);
        $this->assertStringNotContainsString('Who does the work:', $text);
        $this->assertStringNotContainsString('Appointment: Not booked yet', $text);

        // the records come below the buttons, in the same section
        $this->authoriseCostOf($wo);
        $after = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertLessThan(strpos($after, 'data-cost-records'), strpos($after, 'id="cost-decision"'));
        $this->assertSame(1, substr_count($after, 'id="cost-decision"'));
    }

    public function test_when_nothing_is_priced_the_authorise_button_is_off_with_one_line_and_no_second_paragraph(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->externalWorkOrder();
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-cost-line', $html);
        $this->assertStringContainsString('Nothing is priced yet - select a quote or type an amount.', $html);
        $this->assertStringNotContainsString('data-cost-pending', $html);
        $this->assertStringNotContainsString('Select a quote, price the job card, or type the amount if it is a small job', $html);
    }

    public function test_the_authorise_press_and_the_recording_work_through_the_screen_routes(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1200.0);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cost.authorise', $wo), [])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->records($wo)->count());

        $wo2 = $this->quoted(2900.0);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cost.authorise', $wo2), [])->assertSessionHasErrors('cost_decision');
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cost.owner-decision', $wo2), [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 3000, 'told_via' => 'email', 'note' => 'Email from the owner',
        ])->assertSessionHasNoErrors();
        $this->assertSame(RentalAuthorisation::BASIS_OWNER_INSTRUCTION, $this->records($wo2)->last()->basis);
    }

    public function test_the_cost_routes_are_scoped_to_the_agency(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1200.0);
        $other = \App\Models\User::factory()->create(['agency_id' => \App\Models\Agency::create(['name' => 'Other', 'slug' => 'oth-' . uniqid()])->id, 'role' => 'admin']);
        $r = $this->actingAs($other)->post(route('corex.rental-work-orders.cost.authorise', $wo), []);
        $this->assertContains($r->getStatusCode(), [403, 404]);
        $this->assertSame(0, $this->records($wo)->count());
    }
}
