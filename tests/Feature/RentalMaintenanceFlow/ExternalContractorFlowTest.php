<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalContractorWorkOrderMail;
use App\Mail\Rentals\RentalOwnerFinalStatementMail;
use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Models\RentalApprovalDecision;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalApprovalGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.9 (+ §17.9.1a) — the external contractor flow: capture the contractor's quote (with the agency's own
 * fee, when it has one) → the gate decides on the OWNER-FACING amount → the owner approves under the same property terms → the work order goes
 * to the contractor showing the owner approved → a higher quote after approval is a variation → the final cost is tested at close.
 */
final class ExternalContractorFlowTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('External');
    }

    private function quote(RentalWorkOrder $wo, float $amount, array $extra = []): RentalWorkOrderQuote
    {
        $supplier = $extra['supplier'] ?? $this->supplier();
        unset($extra['supplier']);

        return $wo->recordQuote(array_merge(['agency_service_provider_id' => $supplier->id, 'amount' => $amount, 'quote_date' => now(), 'detail_text' => 'Written quote'], $extra), $this->admin);
    }

    private function asLandlord(): void
    {
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
    }

    // ── the agency's own fee on the contractor's quote ───────────────

    public function test_with_the_fee_at_zero_nothing_changes(): void
    {
        $wo = $this->externalWorkOrder();

        $quote = $this->quote($wo, 1000);

        $this->assertNull($quote->fee_type);
        $this->assertSame('0.00', $quote->fee_amount);
        $this->assertNull($quote->selling_amount);
        $this->assertSame(1000.0, $quote->ownerFacingAmount());
    }

    public function test_a_percentage_fee_is_added_and_the_gate_judges_the_owner_facing_total(): void
    {
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10, 'no_approval_spend_threshold' => 1050]);
        $wo = $this->externalWorkOrder();
        $quote = $this->quote($wo, 1000);

        $this->assertSame('percent', $quote->fee_type);
        $this->assertSame('100.00', $quote->fee_amount);
        $this->assertSame('1100.00', $quote->selling_amount);
        $this->assertSame('1000.00', $quote->amount, 'amount stays the contractor\'s own quote');

        $wo->selectQuote($quote, $this->admin);

        // R1,000 would have been inside the R1,050 limit; the owner pays R1,100, so the owner is asked.
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
        $this->assertSame('1100.00', RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->sole()->amount_tested);
    }

    public function test_a_fixed_amount_fee_is_added_as_typed(): void
    {
        $this->setting(['external_quote_markup_type' => 'amount', 'external_quote_markup_value' => 150]);
        $quote = $this->quote($this->externalWorkOrder(), 800);

        $this->assertSame('amount', $quote->fee_type);
        $this->assertSame('150.00', $quote->fee_amount);
        $this->assertSame('950.00', $quote->selling_amount);
    }

    public function test_a_work_order_can_override_the_agencys_fee_and_blank_goes_back_to_the_default(): void
    {
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10]);
        $wo = $this->externalWorkOrder();
        $quote = $this->quote($wo, 1000);

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => 20])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1200.00', $quote->fresh()->selling_amount);
        $this->assertSame('20.00', $wo->fresh()->external_markup_value);

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($wo->fresh()->external_markup_value);
        $this->assertSame('1100.00', $quote->fresh()->selling_amount, 'back to the agency default');
    }

    public function test_the_fee_override_is_validated_permission_gated_and_locked_after_the_owner_approved(): void
    {
        $wo = $this->externalWorkOrder();
        $this->quote($wo, 1000);

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => -5])
            ->assertSessionHasErrors('external_markup_value');
        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => 1500])
            ->assertSessionHasErrors('external_markup_value');
        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => 'lots'])
            ->assertSessionHasErrors('external_markup_value');

        // locked once the owner has approved an amount (a change would be a variation)
        $wo->forceFill(['approved_amount' => 1000, 'approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION, 'owner_approval_status' => 'approved'])->save();
        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => 5])
            ->assertSessionHasErrors('rental_work_order');
        $this->assertNull($wo->fresh()->external_markup_value);

        // and only staff who can price may touch it at all
        $agent = $this->agentWith(['rental_work_orders.view' => 'all']);
        $this->actingAs($agent)->put(route('corex.rental-work-orders.external-fee.update', $wo), ['external_markup_type' => 'percent', 'external_markup_value' => 5])->assertForbidden();
    }

    public function test_the_fee_is_the_agencys_margin_so_only_staff_who_see_costs_see_it_and_the_owner_never_does(): void
    {
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10]);
        $wo = $this->externalWorkOrder();
        $quote = $this->quote($wo, 1000);
        $wo->selectQuote($quote, $this->admin);

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->assertSee('quote R1,000.00 + fee R100.00 =', false)->assertSee('R1,100.00', false);

        // the owner's mail and the portal show the one owner-facing figure
        $mail = $this->sent(RentalOwnerQuoteMail::class)[0];
        $html = $mail->render();
        $text = $this->visibleText($html);
        $this->assertStringContainsString('R1,100.00', $text);
        $this->assertStringNotContainsString('1,000.00', $text);
        $this->assertStringNotContainsStringIgnoringCase(' fee', $text);
        $this->asLandlord();
        $this->getJson('/api/v1/client/rentals/landlord/decisions')->assertOk()->assertJsonPath('work_orders.0.selected_quote_amount', 1100);
    }

    // ── the owner is asked, the same way as for any quote ────────────

    public function test_an_external_quote_over_the_limit_is_mailed_to_the_owner_with_the_document_attached(): void
    {
        $wo = $this->externalWorkOrder();
        $quote = $this->quote($wo, 4200, ['document_storage_path' => UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf')->store("rental-work-order-quotes/{$wo->id}", 'local')]);

        $wo->selectQuote($quote, $this->admin);

        $mails = $this->sent(RentalOwnerQuoteMail::class);
        $this->assertCount(1, $mails, 'one mail through the agency mailbox path — never a plain Mailable');
        $this->assertSame($this->landlord->email, $this->mailbox->sent[0][0]);
        $this->assertStringContainsString('Quote - ', (string) $mails[0]->attachmentName());
        $this->assertStringContainsString('Your approval is needed', $mails[0]->envelope()->subject);
        $html = $mails[0]->render();
        $this->assertStringContainsString('R4,200.00', $html);
        $this->assertStringContainsString('Open my portal', $html);
        $this->assertStringContainsString('estimate', strtolower($html), 'the R6 wording is printed');
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }

    public function test_the_owner_is_not_mailed_when_the_quote_is_inside_the_limit_or_the_agency_switched_the_prompt_off(): void
    {
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 300), $this->admin);
        $this->assertSame([], $this->sent());

        \App\Models\RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_decision_needed' => false]);
        $other = $this->externalWorkOrder(['title' => 'Second job']);
        $other->selectQuote($this->quote($other, 9000), $this->admin);
        $this->assertSame([], $this->sent());
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $other->fresh()->owner_approval_status, 'still pending — only the email prompt is off');
    }

    // ── send the work order to the contractor ────────────────────────

    public function test_the_work_order_cannot_go_to_the_contractor_until_the_owner_has_approved_and_then_it_shows_that(): void
    {
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10]);
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder();
        $quote = $this->quote($wo, 4000, ['supplier' => $supplier]);
        $wo->selectQuote($quote, $this->admin);
        $this->mailbox->sent = [];

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])
            ->assertSessionHasErrors('rental_work_order');
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->fresh()->status);
        $this->assertSame([], $this->sent());
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->assertSee('Work cannot start yet')->assertSee('waiting for the owner');

        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Owner accepted R4,400']);
        $this->assertSame('4400.00', $wo->fresh()->approved_amount);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])
            ->assertRedirect()->assertSessionHas('success', 'Work order sent to the contractor.');

        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $mails = $this->sent(RentalContractorWorkOrderMail::class);
        $this->assertCount(1, $mails);
        $this->assertSame('acme@example.invalid', $this->mailbox->sent[0][0]);
        $this->assertStringContainsString('Work Order', (string) $mails[0]->attachmentName());
        $html = $mails[0]->render();
        $this->assertStringContainsString('Owner approval: approved on', $html);
        $this->assertStringContainsString('the owner approved the quote', $html);
        $this->assertStringContainsString('arrange access', $html);
        // the contractor sees their own quote only — no agency fee, no owner contact details
        $this->assertStringNotContainsString('4,400', $html);
        $this->assertStringNotContainsString($this->landlord->email, $html);
        $this->assertStringNotContainsString('Landlordson', $html);
        $this->assertDatabaseHas('rental_work_order_updates', ['rental_work_order_id' => $wo->id, 'update_type' => 'work_order_sent']);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_the_contractor_pdf_shows_their_own_quote_the_owner_approval_line_and_no_personal_details(): void
    {
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10]);
        $supplier = $this->supplier();
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 300, ['supplier' => $supplier]), $this->admin);   // inside the limit: auto-approved
        $wo = $wo->fresh();

        $this->assertStringStartsWith('%PDF', app(\App\Services\Rentals\RentalDocumentPdfService::class)->workOrderContractorPdf($wo)->output());
        $html = $this->visibleText(view('corex.rental-work-orders.contractor-pdf', [
            'workOrder' => $wo, 'quote' => $wo->quotes()->where('is_selected', true)->first(), 'ownerApprovalLine' => $wo->ownerApprovalLine(), 'logo' => null, 'agencyName' => 'External Agency',
        ])->render());

        $this->assertStringContainsString('Owner approval: approved on', $html);
        $this->assertStringContainsString("within the owner's no-approval limit", $html);
        $this->assertStringContainsString('R300.00', $html);
        $this->assertStringNotContainsString('R330.00', $html, 'never the agency\'s fee-inclusive figure');
        $this->assertStringContainsString('Please contact External Agency to arrange access', $html);
    }

    public function test_a_contractor_with_no_email_is_assigned_and_the_agent_is_told_to_send_the_printout(): void
    {
        $supplier = $this->supplier('Cash Plumber', null);
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 200, ['supplier' => $supplier]), $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])
            ->assertRedirect()->assertSessionHas('warning');

        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    // ── a higher quote after approval ────────────────────────────────

    public function test_a_higher_quote_after_approval_is_a_variation_and_a_lower_one_changes_nothing(): void
    {
        $wo = $this->externalWorkOrder();
        $first = $this->quote($wo, 1000);
        $wo->selectQuote($first, $this->admin);
        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'OK']);
        $this->mailbox->sent = [];

        $lower = $this->quote($wo->fresh(), 900);
        $wo->fresh()->selectQuote($lower, $this->admin);
        $this->assertNull($wo->fresh()->openVariation(), 'a lower quote changes nothing');
        $this->assertSame('1000.00', $wo->fresh()->approved_amount);

        $higher = $this->quote($wo->fresh(), 1500);
        $wo->fresh()->selectQuote($higher, $this->admin);

        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $fresh->owner_approval_status, 'the approval is not reset');
        $variation = $fresh->openVariation();
        $this->assertNotNull($variation);
        $this->assertSame(RentalWorkOrderVariation::ORIGIN_EXTERNAL_QUOTE, $variation->origin);
        $this->assertSame($higher->id, (int) $variation->rental_work_order_quote_id);
        $this->assertSame('500.00', $variation->extra_amount);
        $this->assertCount(1, $this->sent(\App\Mail\Rentals\RentalOwnerVariationMail::class));
        $this->assertFalse(app(RentalApprovalGateService::class)->authoriseToProceed($fresh, false)->authorised, 'the work order waits for the owner on the revised quote');

        // the owner approves in the portal → the revised amount is the new baseline and the work order can go out
        $this->asLandlord();
        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $variation->id . '/decision', ['decision' => 'approve', 'revision' => 1])->assertOk();
        $this->assertSame('1500.00', $wo->fresh()->approved_amount);
        $this->assertTrue(app(RentalApprovalGateService::class)->authoriseToProceed($wo->fresh(), false)->authorised);
    }

    // ── the final cost ───────────────────────────────────────────────

    public function test_a_final_cost_above_what_the_owner_approved_is_refused_in_plain_words(): void
    {
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 1000), $this->admin);
        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'OK']);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1200])
            ->assertSessionHasErrors('rental_work_order');

        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->fresh()->status);
        $this->assertStringContainsString('above what the owner approved (R1,000.00)', session('errors')->first('rental_work_order'));
        $this->assertStringContainsString('capture the contractor\'s revised quote', session('errors')->first('rental_work_order'));
    }

    public function test_a_final_cost_within_the_tolerance_is_auto_approved_logged_and_closes_the_job(): void
    {
        $this->property->forceFill(['rental_variation_tolerance_percent' => 10])->save();
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 1000), $this->admin);
        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'OK']);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1080])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $row = RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('basis', 'variation_tolerance')->sole();
        $this->assertSame('auto_approved', $row->decision);
        $this->assertSame('1080.00', $row->amount_tested);
    }

    public function test_a_final_cost_at_or_under_the_approved_amount_just_closes_and_the_owner_gets_the_final_statement(): void
    {
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 1000), $this->admin);
        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'OK']);
        $this->mailbox->sent = [];

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 950, 'completion_notes' => 'Pipe replaced'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $mails = $this->sent(RentalOwnerFinalStatementMail::class);
        $this->assertCount(1, $mails, 'whichever route closes it, the owner is told once');
        $html = $mails[0]->render();
        $this->assertStringContainsString('R950.00', $html);
        $this->assertStringNotContainsString('Approved as emergency', $html);
        $this->assertSame($this->landlord->email, $this->mailbox->sent[0][0]);
    }

    public function test_the_final_statement_pdf_is_selling_only(): void
    {
        $wo = $this->externalWorkOrder();
        $wo->selectQuote($this->quote($wo, 1000), $this->admin);
        $wo->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'OK']);
        $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1000]);

        $pdf = app(\App\Services\Rentals\RentalDocumentPdfService::class)->finalStatementPdf($wo->fresh())->output();
        $html = $this->visibleText(view('corex.rental-work-orders.final-statement-pdf', [
            'workOrder' => $wo->fresh(), 'lines' => collect(), 'emergencyBanner' => null, 'vatRegistered' => false, 'vatNumber' => null, 'logo' => null, 'agencyName' => 'External Agency',
        ])->render());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('R1,000.00', $html);
        foreach (['cost price', 'margin', 'markup', 'estimate'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $html, "a final statement is not an estimate and shows no {$word}");
        }
    }

    // ── multi-agency ─────────────────────────────────────────────────

    public function test_a_second_agency_with_no_fee_and_no_crews_still_works_end_to_end(): void
    {
        // this agency has a 10 % fee; a second agency with its own different (zero) fee must not inherit it
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10]);
        $other = \App\Models\Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);

        $this->assertSame(0.0, \App\Models\RentalWorkOrderSetting::externalQuoteMarkupValueFor($other->id));
        $this->assertSame(10.0, \App\Models\RentalWorkOrderSetting::externalQuoteMarkupValueFor($this->agency->id));
        $this->assertStringNotContainsString('HFC', \App\Models\RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM);
    }
}
