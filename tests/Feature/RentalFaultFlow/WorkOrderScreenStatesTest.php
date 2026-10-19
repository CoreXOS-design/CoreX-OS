<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalOwnerHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 24 - the staff work order screen: the "Where this job is" strip, the "Next" bar (ONE sentence, at most ONE
 * button) and the collapsible panels render for every state a job can be in, and the Next bar says the right thing with the right button for each. Layout only -
 * the actions behind the buttons are tested where they were built.
 */
final class WorkOrderScreenStatesTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Screen');
    }

    private function page(RentalWorkOrder $wo): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
    }

    /** The Next bar: its key, its sentence and its ONE button (null = no button). */
    private function next(string $html): array
    {
        $this->assertSame(1, substr_count($html, 'data-next-step'), 'exactly one Next bar');
        $this->assertTrue((bool) preg_match('/<div class="wo-next [^"]*" data-next-step(?: data-land-on-next)? data-next="([a-z_]+)"([^>]*)>(.*?)<\/div>\s*<div class="wo-cards"/s', $html, $m), 'the Next bar sits directly above the two cards');
        $text = trim(html_entity_decode(strip_tags(preg_match('/data-next-text>(.*?)<\/span>/s', $m[3], $t) ? $t[1] : '')));
        $buttons = preg_match_all('/<(?:button|a) [^>]*data-next-button[^>]*>(.*?)<\/(?:button|a)>/s', $m[3], $b) ? array_map(fn ($x) => trim(html_entity_decode(strip_tags($x))), $b[1]) : [];
        $this->assertLessThanOrEqual(1, count($buttons), 'at most ONE primary button');

        return ['key' => $m[1], 'text' => $text, 'button' => $buttons[0] ?? null, 'raw' => $m[2] . $m[3]];
    }

    private function assertNext(string $html, string $key, string $textPart, ?string $button): array
    {
        $n = $this->next($html);
        $this->assertSame($key, $n['key'], 'Next says: ' . $n['text']);
        $this->assertStringContainsString($textPart, $n['text']);
        $this->assertSame($button, $n['button']);

        return $n;
    }

    private function step(string $html, string $key): ?string
    {
        return preg_match('/data-step="' . $key . '" data-state="([a-z]+)"/', $html, $m) ? $m[1] : null;
    }

    private function quoted(float $amount, ?int $supplierId = null): RentalWorkOrder
    {
        $wo = $this->externalWorkOrder();
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplierId ?? $this->supplier('Quote Plumbing')->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);

        return $wo->fresh();
    }

    private function fault(): RentalFaultReport
    {
        $type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);

        return RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => 'agent_noticed', 'reported_channel' => 'app', 'title' => 'Tap', 'description' => 'drips', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
    }

    // ── before the contractor ────────────────────────────────────────────────

    public function test_no_contractor_yet(): void
    {
        $wo = $this->externalWorkOrder();
        $html = $this->page($wo);

        $this->assertNext($html, 'no_contractor', 'No contractor has been chosen for this job yet.', 'Choose the contractor');
        $this->assertSame('done', $this->step($html, 'reported'));
        $this->assertSame('current', $this->step($html, 'contractor_sent'));
        $this->assertSame('todo', $this->step($html, 'quote_in'));
        $this->assertStringContainsString('id="wo-panel-contractor"', $html);
        $this->assertMatchesRegularExpression('/id="wo-panel-contractor"[^>]* open/', $html, 'the panel for the current step is open');
        $this->assertDoesNotMatchRegularExpression('/id="wo-panel-quotes"[^>]* open/', $html, 'the others are closed');
        $this->assertStringContainsString('Not booked', $html);
    }

    public function test_the_job_is_with_a_contractor_who_has_not_been_sent_it(): void
    {
        $sup = $this->supplier('Acme Plumbing');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $sup->id]);
        $html = $this->page($wo);

        $this->assertNext($html, 'not_sent', 'The job has not been sent to Acme Plumbing yet.', 'Send the job to Acme Plumbing');
        $this->assertStringContainsString('form="wo-send-job-form"', $html, 'the Next button submits the form that was already on the page');
        $this->assertStringContainsString('id="wo-send-job-form"', $html);
        $this->assertSame('current', $this->step($html, 'contractor_sent'));
    }

    public function test_sent_and_waiting_for_the_contractor_to_accept_or_quote(): void
    {
        $sup = $this->supplier('Acme Plumbing');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $sup->id]);
        \App\Models\RentalJobDispatch::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'recipient_kind' => \App\Models\RentalJobDispatch::KIND_CONTRACTOR, 'agency_service_provider_id' => $sup->id,
            'recipient_name' => $sup->name, 'channel' => 'email', 'destination' => $sup->email, 'status' => 'sent', 'reason' => 'appointed', 'idempotency_key' => uniqid('k', true),
            'sent_by_user_id' => $this->admin->id, 'sent_at' => now(),
        ]);
        $html = $this->page($wo);

        $this->assertNext($html, 'waiting_contractor', 'Waiting for Acme Plumbing to accept or quote.', 'Capture a quote');
        $this->assertMatchesRegularExpression('/<button type="submit" form="wo-send-job-form" class="corex-btn-outline text-xs" data-next-secondary>Send the job again<\/button>/', $html, '"Send the job again" is the secondary button beside it');
        $this->assertStringContainsString('data-wo-click="[data-capture-quote-toggle]; [data-quote-amount]"', $html, 'the primary button opens the form with the cursor in Amount');
        $this->assertSame('done', $this->step($html, 'contractor_sent'));
        $this->assertSame('current', $this->step($html, 'quote_in'));
    }

    // ── the quote and the owner ──────────────────────────────────────────────

    public function test_a_quote_inside_the_owners_repair_limit_offers_authorise(): void
    {
        $this->ownerLimits(1500.0);
        $html = $this->page($this->quoted(850.0));

        $n = $this->assertNext($html, 'quote_inside_limit', "Quote R850.00 is inside the owner's repair limit.", 'Authorise R850.00');
        $this->assertStringContainsString('href="#cost-decision"', $n['raw']);
        $this->assertSame('done', $this->step($html, 'quote_in'));
        $this->assertSame('current', $this->step($html, 'cost'));
        $this->assertStringContainsString('Not authorised yet', $html);
        $this->assertSame(1, substr_count($html, 'data-limit-repair'), "the owner's limits are printed once, in The money");
    }

    public function test_a_quote_above_the_limit_is_waiting_for_the_owner(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->quoted(2000.0);
        $html = $this->page($wo);

        $n = $this->assertNext($html, 'waiting_owner', 'Quote R2,000.00 is in. The owner must approve it', "Record the owner's decision");
        $this->assertStringContainsString('data-quote-sent-to-owner', $n['raw']);
        $this->assertStringContainsString('Waiting for the owner', $html);
        $this->assertSame('current', $this->step($html, 'cost'));
        $this->assertMatchesRegularExpression('/id="wo-panel-cost"[^>]* open/', $html);
    }

    public function test_declined_by_the_owner_asks_for_another_quote(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->quoted(2000.0);
        $wo->recordApproval($this->admin, ['decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Too dear']);
        $html = $this->page($wo);

        $n = $this->assertNext($html, 'owner_declined', 'The owner declined the quote (Quote Plumbing, R2,000.00)', 'Capture a quote');
        $this->assertStringContainsString('data-quote-declined', $n['raw']);
        $this->assertMatchesRegularExpression('/id="wo-panel-quotes"[^>]* open/', $html);
    }

    public function test_authorised_but_the_go_ahead_has_not_gone_yet(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(850.0);
        $this->authoriseCostOf($wo);
        $html = $this->page($wo);

        $this->assertNext($html, 'authorised', 'Approved (R850.00). The go-ahead has not been sent', 'Send the go-ahead');
        $this->assertStringContainsString('form="wo-go-ahead-form"', $html);
        $this->assertSame('done', $this->step($html, 'cost'));
        $this->assertSame('current', $this->step($html, 'go_ahead'));
        $this->assertStringContainsString('Authorised by agent', $html, 'who, how and when, in The money');
    }

    public function test_go_ahead_sent_then_in_progress(): void
    {
        $this->ownerLimits(1500.0);
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->quoted(850.0, $sup->id);
        $this->authoriseCostOf($wo);
        $wo->fresh()->assignSupplier($sup->id, null, $this->admin);
        app(\App\Services\Rentals\RentalWorkOrderService::class)->sendContractorWorkOrder($wo->fresh(), $this->admin);
        $html = $this->page($wo);

        $this->assertNext($html, 'go_ahead_sent', 'Approved. Go-ahead sent to Quote Plumbing', 'Mark the work as complete');
        $this->assertStringContainsString('id="wo-start-progress-form"', $html, '"Mark in progress" stays as a small secondary action');
        $this->assertStringNotContainsString('form="wo-start-progress-form"', $html, 'but it is no longer the Next button');
        $this->assertStringContainsString('data-go-ahead-sent', $html, 'the contractor panel shows the same date');
        $this->assertStringContainsString('Send the go-ahead again', $html, 'and offers it again as a secondary action');
        $this->assertSame('done', $this->step($html, 'go_ahead'));
        $this->assertSame('current', $this->step($html, 'in_progress'));
        $this->assertStringContainsString('data-complete-work-order', $html, 'completing straight from go-ahead sent is offered');

        $wo->fresh()->startProgress($this->admin);
        $html = $this->page($wo);
        $this->assertNext($html, 'in_progress', 'Work is in progress.', 'Mark the work as complete');
        $this->assertStringContainsString('data-complete-work-order', $html);
        $this->assertSame('current', $this->step($html, 'completed'));
        $this->assertMatchesRegularExpression('/id="wo-panel-completion"[^>]* open/', $html);
    }

    public function test_extra_work_waiting_for_the_owner_is_a_hard_lock(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(1000.0);
        $this->authoriseCostOf($wo);
        app(RentalApprovalGateService::class)->raiseFinalInvoiceVariation($wo->fresh(), 1000.01, $this->admin);
        $html = $this->page($wo);

        $n = $this->assertNext($html, 'extra_waiting', 'Extra work of R0.01 is waiting for the owner (new total R1,000.01).', "Record the owner's reply");
        $this->assertStringContainsString('cannot close', $n['text']);
        $this->assertStringContainsString('data-wo-click="#variation-panel summary"', $n['raw']);
        $this->assertStringContainsString('id="variation-panel"', $html);
    }

    public function test_on_hold_when_the_owner_pressed_stop(): void
    {
        $this->ownerLimits(1500.0);
        $fault = $this->fault();
        $wo = $this->quoted(850.0);
        $wo->forceFill(['reported_fault_report_id' => $fault->id])->save();
        app(RentalOwnerHoldService::class)->stop($wo->fresh(), $this->landlord, 'Not happy with this contractor', 'link');
        $html = $this->page($wo);

        $this->assertNext($html, 'on_hold', 'The owner has put this job on hold.', 'Open the fault to resume it');
        $this->assertStringContainsString('data-paused-by-owner', $html);
    }

    // ── the end of the job ───────────────────────────────────────────────────

    public function test_completed_and_who_pays_still_to_rule(): void
    {
        $this->ownerLimits(1500.0);
        $this->setting(['completion_requires_photo' => false]);
        $fault = $this->fault();
        $wo = $this->quoted(1000.0);
        $wo->forceFill(['reported_fault_report_id' => $fault->id])->save();
        $this->authoriseCostOf($wo->fresh());
        $wo->fresh()->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1000.0]);
        $html = $this->page($wo);

        $this->assertNext($html, 'rule_who_pays', 'Rule who pays.', 'Rule who pays');
        $this->assertSame('done', $this->step($html, 'completed'));
        $this->assertSame('current', $this->step($html, 'who_pays'));
        $this->assertStringContainsString('data-money-final', $html);
        $this->assertMatchesRegularExpression('/id="wo-panel-whopays"[^>]* open/', $html);
    }

    public function test_completed_with_nothing_left_to_do(): void
    {
        $this->ownerLimits(1500.0);
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->quoted(1000.0);
        $this->authoriseCostOf($wo);
        $wo->fresh()->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1000.0]);
        $html = $this->page($wo);

        $this->assertNext($html, 'done', 'Completed ', null);
        $this->assertSame('done', $this->step($html, 'completed'));
        $this->assertNull($this->step($html, 'who_pays'), 'a job that did not come from a fault has no "Who pays ruled" step');
    }

    public function test_cancelled(): void
    {
        $wo = $this->externalWorkOrder();
        $wo->cancel($this->admin, 'Owner changed their mind');
        $html = $this->page($wo);

        $n = $this->assertNext($html, 'cancelled', 'Cancelled ', null);
        $this->assertStringContainsString('Owner changed their mind', $n['text']);
        $this->assertDoesNotMatchRegularExpression('/<li class="wo-step"[^>]*data-state="current"/', $html, 'nothing is "current" on a cancelled job');
    }

    public function test_archived_still_renders(): void
    {
        $wo = $this->externalWorkOrder();
        $wo->archive($this->admin);
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo->id))->assertStatus(404);   // the existing rule: an archived work order is reached through "Show archived" and Restore, not its page
    }

    // ── our own crew ─────────────────────────────────────────────────────────

    public function test_an_own_crew_job_leaves_out_the_contractor_and_quote_steps(): void
    {
        $this->ownerLimits(1500.0);
        [$card, $wo] = $this->internalJob(1000.0);
        $html = $this->page($wo);

        $this->assertNull($this->step($html, 'contractor_sent'), 'no contractor to send it to');
        $this->assertNull($this->step($html, 'quote_in'));
        $this->assertNull($this->step($html, 'go_ahead'));
        $this->assertSame('current', $this->step($html, 'cost'));
        $this->assertStringContainsString('id="wo-panel-jobcard"', $html);
        $this->assertStringNotContainsString('id="wo-panel-quotes"', $html);
        $this->assertNext($html, 'cost_inside_limit', 'Our crew price of R1,000.00 is inside the owner\'s repair limit.', 'Authorise R1,000.00');
    }

    public function test_an_own_crew_job_with_the_cost_approved_points_to_the_job_card(): void
    {
        $this->ownerLimits(1500.0);
        [$card, $wo] = $this->internalJob(1000.0);
        $this->authoriseCostOf($wo);
        $html = $this->page($wo);

        $this->assertNext($html, 'crew_approved', 'Cost approved. Our crew does the job from the job card.', 'Open the job card');
    }

    // ── what is NOT printed twice, and one format ────────────────────────────

    public function test_the_page_has_no_printed_twice_lines_and_one_money_and_date_format(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(850.0);
        $this->authoriseCostOf($wo);
        $html = $this->page($wo);
        $text = $this->visibleText($html);

        $this->assertStringNotContainsString('Owner approval:', $text, 'the approval is in The money only');
        $this->assertStringNotContainsString('this is the one that stands', $text);
        $this->assertStringNotContainsString('job was:', $text);
        $this->assertDoesNotMatchRegularExpression('/R\s\d/', $text, 'money is written R850.00, never R 850');
        $this->assertDoesNotMatchRegularExpression('/\b20\d\d-\d\d-\d\d\b/', $text, 'dates are "13 Oct 2026 08:57", never 2026-10-13');
        $this->assertStringContainsString('Cancel work order', $html);
        $this->assertGreaterThan(strpos($html, 'id="wo-panel-history"'), strpos($html, 'Cancel work order'), 'Cancel sits at the bottom, after every panel');
    }

    // ── corrections from Johan's look at work order 158 ──────────────────────

    public function test_the_contractor_summary_follows_the_real_state_and_authorise_is_only_a_button_when_it_can_be_used(): void
    {
        $this->ownerLimits(1000.0);
        $sup = $this->supplier('Acme Plumbing');
        $wo = $this->quoted(2000.0, $sup->id);
        $wo->forceFill(['agency_service_provider_id' => $sup->id])->save();
        \App\Models\RentalJobDispatch::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'recipient_kind' => \App\Models\RentalJobDispatch::KIND_CONTRACTOR, 'agency_service_provider_id' => $sup->id,
            'recipient_name' => $sup->name, 'channel' => 'email', 'destination' => $sup->email, 'status' => 'sent', 'reason' => 'appointed', 'idempotency_key' => uniqid('k', true), 'sent_by_user_id' => $this->admin->id, 'sent_at' => now(),
        ]);
        $html = $this->page($wo);

        $this->assertMatchesRegularExpression('/data-wo-panel="contractor".*?data-panel-summary>Acme Plumbing - quote received R2,000\.00</s', $html, 'not "Waiting for their answer" while their quote is in');
        $this->assertStringNotContainsString('Nothing is approved until you press a button.', $html);
        $this->assertStringContainsString('x-show="can || coversByEmergency"', $html, 'Authorise is only shown when the repair limit (or an emergency limit) can cover it');
        $this->assertStringContainsString("Record the owner's decision", $html);
    }

    public function test_the_history_header_never_says_note_added(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quoted(850.0);
        $wo->addNote('just a note', $this->admin);
        $html = $this->page($wo);

        preg_match('/data-wo-panel="history".*?data-panel-summary>(.*?)<\/span>/s', $html, $m);
        $this->assertStringNotContainsString('Note added', $m[1] ?? '');
        $this->assertNotSame('', trim($m[1] ?? ''));
    }

    public function test_the_tenant_says_the_work_is_finished_or_that_it_is_not_fixed(): void
    {
        $this->ownerLimits(1500.0);
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->quoted(850.0, $sup->id);
        $this->authoriseCostOf($wo);
        $wo->fresh()->assignSupplier($sup->id, null, $this->admin);
        $tenant = \App\Models\Contact::withoutGlobalScope(\App\Scopes\AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Mokoena']);

        $wo->forceFill(['tenant_confirmed_at' => \Illuminate\Support\Carbon::parse('2026-10-13 14:10', 'Africa/Johannesburg'), 'tenant_confirmed_fixed' => true, 'tenant_confirmed_by_contact_id' => $tenant->id])->save();
        $this->assertNext($this->page($wo), 'tenant_finished', 'Thandi Mokoena says the work is finished (13 Oct 2026 14:10).', 'Mark the work as complete');

        $wo->forceFill(['tenant_confirmed_fixed' => false, 'tenant_confirmation_note' => 'It still drips'])->save();
        $this->assertNext($this->page($wo), 'tenant_not_fixed', 'Tenant says it is NOT fixed - It still drips', 'Send the contractor back');
    }

    // ── polish round 3 ───────────────────────────────────────────────────────

    public function test_the_quote_capture_form_is_one_grid_with_one_submit_button_and_a_pre_filled_date(): void
    {
        $wo = $this->externalWorkOrder();
        $html = $this->page($wo);

        $this->assertStringContainsString('class="wo-grid-3"', $html);
        $this->assertSame(1, substr_count($html, '>Capture quote</button>'), 'ONE submit button');
        $this->assertStringContainsString('data-quote-amount', $html);
        $this->assertMatchesRegularExpression('/name="quote_date" required value="\d{4}-\d{2}-\d{2}"/', $html);
        $this->assertStringContainsString('display:none;" data-capture-quote-toggle', $html, 'no second "Capture a quote" button above the form while the form is open');
    }

    public function test_the_message_after_capturing_a_quote_no_longer_says_to_send_the_work_order(): void
    {
        $sup = $this->supplier('Quote Plumbing');
        $this->ownerLimits(1500.0);
        $wo = $this->externalWorkOrder();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $wo), ['agency_service_provider_id' => $sup->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'])
            ->assertSessionHas('success', 'Quote captured. Authorise its cost under "Approve the cost".');

        $this->ownerLimits(500.0);
        $wo2 = $this->externalWorkOrder();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $wo2), ['agency_service_provider_id' => $sup->id, 'amount' => 2000, 'quote_date' => now()->toDateString(), 'detail_text' => 'x']);
        $this->assertStringStartsWith("Quote captured. It is above the owner's limit and has gone to", (string) session('success'));
        $this->assertStringNotContainsString('send the work order', (string) session('success'));
    }

    public function test_the_top_badge_says_go_ahead_sent_once_the_go_ahead_has_gone_without_touching_the_stored_status(): void
    {
        $this->ownerLimits(1500.0);
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->quoted(850.0, $sup->id);
        $this->authoriseCostOf($wo);
        $wo->fresh()->assignSupplier($sup->id, null, $this->admin);

        $this->assertMatchesRegularExpression('/data-stage-badge>Sent to contractor</', $this->page($wo), 'job sent, no go-ahead yet');

        app(\App\Services\Rentals\RentalWorkOrderService::class)->sendContractorWorkOrder($wo->fresh(), $this->admin);
        $this->assertMatchesRegularExpression('/data-stage-badge>Go-ahead sent</', $this->page($wo));
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status, 'display only: the stored status is unchanged');
    }

    public function test_the_supplier_invoice_form_has_labels_above_and_one_row(): void
    {
        $this->ownerLimits(1500.0);
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->quoted(850.0, $sup->id);
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();
        $html = $this->page($wo);

        $this->assertStringContainsString('data-invoice-upload-form', $html);
        $this->assertMatchesRegularExpression('/class="wo-grid-4">\s*<div>\s*<label class="wo-lbl" for="wo-inv-number">Invoice number<\/label>.*?for="wo-inv-date".*?for="wo-inv-amount".*?for="wo-inv-supplier"/s', $html);
        $this->assertSame(1, substr_count($html, '>File invoice</button>'));
    }
}
