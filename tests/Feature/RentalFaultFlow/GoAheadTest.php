<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalContractorWorkOrderMail;
use App\Models\RentalAuthorisation;
use App\Models\RentalJobDispatch;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalAuthorisationService;
use App\Services\Rentals\RentalGoAheadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.15: the go-ahead goes out by itself the moment a cost authorisation covering the selected quote
 * is recorded (owner on their link, agent recording the owner's instruction, agent inside the limit) - once, with the explicit wording and the private job link,
 * and never while the owner has the job stopped or when the contractor cannot be emailed.
 */
final class GoAheadTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('GoAhead');
    }

    /** An outside job with its contractor appointed and ONE selected quote of $amount from them. */
    private function quotedJob(float $amount, ?string $email = 'acme@example.invalid'): RentalWorkOrder
    {
        $supplier = $this->supplier('Acme Plumbing', $email);
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);

        return $wo->fresh();
    }

    private function goAheads(): array
    {
        return $this->sent(RentalContractorWorkOrderMail::class);
    }

    private function dispatches(RentalWorkOrder $wo)
    {
        return RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('reason', 'go_ahead')->get();
    }

    private function history(RentalWorkOrder $wo, string $type)
    {
        return $wo->updates()->where('update_type', $type)->get()->sortBy('id')->values();
    }

    public function test_the_owner_approving_the_quote_on_their_link_sends_the_go_ahead_by_itself(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0);
        $this->assertCount(0, $this->goAheads());

        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'Please go ahead', 'approved_up_to' => 850.0]);

        $mails = $this->goAheads();
        $this->assertCount(1, $mails);
        $this->assertSame('Acme Plumbing', $this->dispatches($wo)->first()->recipient_name);
        $this->assertSame('sent', $this->dispatches($wo)->first()->status);
        $this->assertNotNull($mails[0]->jobUrl, 'the mail carries the private job link');
        $this->assertStringContainsString('/secure/work-orders/', (string) $mails[0]->jobUrl);
        $line = $this->history($wo, 'work_order_sent')->last()->note;
        $this->assertStringContainsString('Go-ahead sent automatically to Acme Plumbing', $line);
        $this->assertStringContainsString('Authorised by', $line, 'the history says who authorised');
        $this->assertStringContainsString('Lenny', $line);
    }

    public function test_the_wording_is_explicit_and_names_the_contractors_own_quote(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0);
        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);

        $text = (string) $this->goAheads()[0]->goAheadText;
        $this->assertStringStartsWith('Your quote of R850.00 for Burst pipe at ', $text);
        $this->assertStringContainsString('has been accepted. You may go ahead.', $text);
        $this->assertStringContainsString('any work that would take the cost above R850.00 must first be authorised by the owner before that work is done - tell us through the link below and wait for the answer.', $text);
        $this->assertStringNotContainsString('T/A', $text);

        $html = $this->goAheads()[0]->render();
        $this->assertStringContainsString('Open the job', $html);
        $this->assertStringContainsString((string) $this->goAheads()[0]->jobUrl, $html);
    }

    public function test_the_agent_authorising_inside_the_limit_sends_it_and_a_small_job_with_no_quote_says_up_to(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->quotedJob(1200.0);
        $this->authoriseCostOf($wo);
        $this->assertCount(1, $this->goAheads());
        $this->assertStringContainsString('Your quote of R1 200.00', (string) $this->goAheads()[0]->goAheadText);

        // a small known job: no quote, the agent types the amount
        $supplier = $this->supplier('Handy Andy', 'andy@example.invalid');
        $small = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'title' => 'Fix the gate latch']);
        $this->authoriseCostOf($small, 300.0);
        $mails = $this->goAheads();
        $this->assertCount(2, $mails);
        $text = (string) $mails[1]->goAheadText;
        $this->assertStringStartsWith('You may go ahead with Fix the gate latch at ', $text);
        $this->assertStringContainsString('up to R300.00.', $text);
        $this->assertStringContainsString('above R300.00 must first be authorised by the owner', $text);
    }

    public function test_the_agent_recording_the_owners_instruction_sends_it(): void
    {
        $this->ownerLimits(null);
        $wo = $this->quotedJob(850.0);
        app(RentalAuthorisationService::class)->recordOwnerCostDecision($this->admin, $wo, [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'approved_up_to' => 850, 'told_via' => 'phone_call', 'note' => 'Owner phoned: go ahead',
        ]);

        $this->assertCount(1, $this->goAheads());
        $this->assertStringContainsString('Authorised by agent', $this->history($wo, 'work_order_sent')->last()->note);
    }

    public function test_it_is_sent_once_per_authorisation_and_again_for_a_newer_one(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0);
        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->latest('id')->first();

        app(RentalGoAheadService::class)->onCostAuthorised($wo->fresh(), $row, null);
        app(RentalGoAheadService::class)->onCostAuthorised($wo->fresh(), $row, null);
        $this->assertCount(1, $this->goAheads(), 'the same record never sends twice');
        $this->assertCount(1, $this->dispatches($wo));
    }

    public function test_nothing_is_sent_when_the_owner_has_stopped_the_job_and_the_agent_sees_why(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0);
        \App\Models\RentalOwnerHold::withoutGlobalScopes()->forceCreate([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'rental_fault_report_id' => null, 'stopped_by_contact_id' => $this->landlord->id,
            'reason' => 'Not happy with this contractor', 'stopped_via' => 'link', 'stopped_at' => now(),
        ]);

        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);

        $this->assertCount(0, $this->goAheads());
        $this->assertStringContainsString('Go-ahead NOT sent to Acme Plumbing - PAUSED BY OWNER', $this->history($wo, 'job_not_sent')->last()->note);
    }

    public function test_nothing_is_sent_without_a_contractor_email_and_the_agent_is_told_in_the_history(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0, null);
        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);

        $this->assertCount(0, $this->goAheads());
        $this->assertSame('not_sent', $this->dispatches($wo)->first()->status);
        $this->assertStringContainsString('no email address on file', $this->history($wo, 'job_not_sent')->last()->note);
    }

    public function test_an_authorisation_that_does_not_cover_the_selected_quote_sends_nothing(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0);
        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'only this much', 'approved_up_to' => 500.0]);

        $this->assertCount(0, $this->goAheads(), 'R500 does not cover an R850.00 quote');
        $this->assertCount(0, $this->dispatches($wo));
    }

    public function test_the_agents_button_stays_as_send_the_go_ahead_again_and_uses_the_same_mail(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->quotedJob(850.0);
        $wo->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        $this->assertCount(1, $this->goAheads());

        $page = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('Send the go-ahead again', $page);
        $this->assertStringNotContainsString('Resend work order to contractor', $page);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $wo->agency_service_provider_id])->assertSessionHasNoErrors();
        $mails = $this->goAheads();
        $this->assertCount(2, $mails);
        $this->assertStringStartsWith('Your quote of R850.00', (string) $mails[1]->goAheadText);
        $this->assertStringContainsString('Go-ahead emailed to Acme Plumbing', $this->history($wo, 'work_order_sent')->last()->note);
    }
}
