<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\RentalOwnerHold;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.17 (Johan, 14 Oct 2026): the contractor's private link lets them FINISH the job once the cost is
 * authorised - see the appointment, send before/after photos, file their invoice (refused above the authorised amount - the hard lock), ask for extra money before
 * doing extra work (it goes to the owner; the contractor waits), and say the work is done. Each lands on the work order and the agents are told.
 */
final class ContractorFinishTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->approvalWorld('Finish');
    }

    /** @return array{0: RentalWorkOrder, 1: string} a job with the contractor appointed and ONE R850 quote; the raw link token */
    private function job(bool $authorised): array
    {
        $this->ownerLimits(500.0);
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'contractor_response' => 'accepted']);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        if ($authorised) {
            $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        }
        $wo = $wo->fresh();
        $raw = app(RentalSecureAccessTokenService::class)->issueFor($wo, $this->admin)['raw_token'];

        return [$wo, $raw];
    }

    private function invoiceFile(): UploadedFile
    {
        return UploadedFile::fake()->create('invoice.pdf', 30, 'application/pdf');
    }

    private function postInvoice(string $raw, float $amount, string $number = 'INV-1')
    {
        return $this->post(route('rentals.secure-link.invoice', $raw), ['invoice_number' => $number, 'invoice_date' => now()->toDateString(), 'amount' => $amount, 'document' => $this->invoiceFile()]);
    }

    public function test_before_the_cost_is_authorised_the_page_only_offers_the_quote(): void
    {
        [$wo, $raw] = $this->job(false);
        $html = $this->get(route('rentals.secure-link.show', $raw))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-quote', $html);
        foreach (['data-go-ahead', 'data-contractor-invoice', 'data-contractor-extra', 'data-contractor-appointment'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->postInvoice($raw, 100.0)->assertSessionHasErrors('invoice');
        $this->post(route('rentals.secure-link.extra', $raw), ['extra_amount' => 100, 'reason' => 'More pipe needed'])->assertSessionHasErrors('extra');
        $this->assertSame(0, $wo->invoices()->count());
    }

    public function test_once_the_cost_is_authorised_the_page_reacts_with_everything_needed_to_finish(): void
    {
        [$wo, $raw] = $this->job(true);
        app(\App\Services\Rentals\RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(2)->setTime(9, 0), 'Gate code 1234', $this->admin);

        $html = $this->get(route('rentals.secure-link.show', $raw))->assertOk()->getContent();
        foreach (['data-go-ahead', 'You may go ahead', 'R850.00', 'data-contractor-appointment', 'Gate code 1234', 'data-contractor-photos', 'Before or during the work', 'After the work',
            'data-contractor-extra', 'Ask for extra money', 'data-contractor-invoice', 'Invoice number', 'data-contractor-done', 'Mark job done'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('data-contractor-quote', $html, 'the job is going ahead - no second quote');
    }

    public function test_before_and_after_photos_land_in_the_right_phase_and_the_agents_are_told(): void
    {
        [$wo, $raw] = $this->job(true);
        $this->post(route('rentals.secure-link.photo', $raw), ['phase' => 'before', 'photo' => UploadedFile::fake()->image('b.jpg')])->assertSessionHasNoErrors();
        $this->post(route('rentals.secure-link.photo', $raw), ['phase' => 'after', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasNoErrors();
        $this->post(route('rentals.secure-link.photo', $raw), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertSessionHasNoErrors();   // no choice = after, as before

        $types = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->orderBy('id')->pluck('photo_type')->all();
        $this->assertSame([RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED, RentalWorkOrder::PHOTO_COMPLETED], $types);
    }

    public function test_an_invoice_up_to_the_authorised_amount_is_filed_under_supplier_invoices_with_who_sent_it(): void
    {
        [$wo, $raw] = $this->job(true);
        $this->postInvoice($raw, 850.00)->assertSessionHasNoErrors();   // exactly equal passes

        $invoice = $wo->invoices()->first();
        $this->assertSame('INV-1', $invoice->invoice_number);
        $this->assertSame('850.00', (string) $invoice->amount);
        $this->assertNull($invoice->uploaded_by_user_id, 'no staff user - the contractor on their link');
        $this->assertNotNull($invoice->document_storage_path);
        Storage::disk('local')->assertExists($invoice->document_storage_path);
        $line = $wo->updates()->where('update_type', 'invoice_uploaded')->first()->note;
        $this->assertStringContainsString('Acme Plumbing on their link', $line);

        // it is on the office's Supplier invoices panel
        $page = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('INV-1', $page);
    }

    public function test_an_invoice_above_the_authorised_amount_is_refused_with_the_hard_lock_words_even_by_one_cent(): void
    {
        [$wo, $raw] = $this->job(true);

        $resp = $this->postInvoice($raw, 850.01);
        $resp->assertSessionHasErrors('invoice');
        $message = session('errors')->first('invoice');
        $this->assertStringContainsString('cannot be accepted', $message);
        $this->assertStringContainsString('R850.01', $message);
        $this->assertStringContainsString('above the R850.00 the owner authorised', $message);
        $this->assertStringContainsString('No amount above the authorised amount can be accepted', $message);
        $this->assertSame(0, $wo->invoices()->count(), 'never accepted');

        // invoices add up: R500 is fine, a second R400 would be R900 and is refused
        $this->postInvoice($raw, 500.00, 'INV-A')->assertSessionHasNoErrors();
        $this->postInvoice($raw, 400.00, 'INV-B')->assertSessionHasErrors('invoice');
        $this->assertSame(1, $wo->invoices()->count());
    }

    public function test_extra_money_goes_to_the_owner_the_contractor_waits_and_only_an_approved_extra_lifts_the_lock(): void
    {
        [$wo, $raw] = $this->job(true);

        $this->post(route('rentals.secure-link.extra', $raw), ['extra_amount' => 150, 'reason' => 'The old pipe behind the wall was rotten'])->assertSessionHasNoErrors();
        $variation = RentalWorkOrderVariation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $this->assertSame(RentalWorkOrderVariation::ORIGIN_CONTRACTOR, $variation->origin);
        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $variation->status);
        $this->assertSame(['850.00', '150.00', '1000.00'], [(string) $variation->baseline_amount, (string) $variation->extra_amount, (string) $variation->new_total]);
        $this->assertStringContainsString('Acme Plumbing asks for R150.00 extra: The old pipe behind the wall was rotten', (string) $variation->note);

        // the owner is emailed and reads the contractor's own words
        $mails = $this->sent(\App\Mail\Rentals\RentalOwnerVariationMail::class);
        $this->assertNotEmpty($mails);
        $this->assertStringContainsString('The contractor says', $mails[0]->render());
        $this->assertStringContainsString('rotten', $mails[0]->render());

        // the page says wait, and a second request is refused while one is waiting
        $html = $this->get(route('rentals.secure-link.show', $raw))->getContent();
        $this->assertStringContainsString('data-extra-waiting', $html);
        $this->assertStringContainsString('Please do not do the extra work', $html);
        $this->post(route('rentals.secure-link.extra', $raw), ['extra_amount' => 50, 'reason' => 'One more thing'])->assertSessionHasErrors('extra');

        // nothing above R850.00 is accepted while the owner has not decided
        $this->postInvoice($raw, 900.00)->assertSessionHasErrors('invoice');

        // the owner agrees: the authorised amount becomes R1000.00 and the invoice goes through
        app(RentalApprovalGateService::class)->recordVariationDecision($variation->fresh(), 'approve', ['revision' => 1, 'via' => 'portal', 'note' => 'Fine'], ['contact' => $this->landlord]);
        $this->assertSame('1000.00', (string) $wo->fresh()->approved_amount);
        $this->postInvoice($raw, 900.00)->assertSessionHasNoErrors();
        $this->assertSame(1, $wo->invoices()->count());
        $this->assertStringContainsString('the owner agreed', $this->get(route('rentals.secure-link.show', $raw))->getContent());
    }

    public function test_the_job_done_button_completes_it_with_what_was_found_and_the_link_then_stops_working(): void
    {
        [$wo, $raw] = $this->job(true);
        $this->post(route('rentals.secure-link.mark-done', $raw))->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertStringContainsString('via contractor link', (string) $wo->updates()->where('to_status', 'completed')->first()->note);

        $html = $this->get(route('rentals.secure-link.show', $raw))->getContent();
        $this->assertStringNotContainsString('data-contractor-invoice', $html);
        $this->postInvoice($raw, 100.0);   // the link is dead
        $this->assertSame(0, $wo->invoices()->count());
    }

    public function test_nothing_can_be_filed_or_asked_while_the_owner_has_the_job_stopped(): void
    {
        [$wo, $raw] = $this->job(true);
        RentalOwnerHold::withoutGlobalScopes()->forceCreate([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'stopped_by_contact_id' => $this->landlord->id, 'reason' => 'Wait', 'stopped_via' => 'link', 'stopped_at' => now(),
        ]);
        $this->postInvoice($raw, 100.0)->assertSessionHasErrors('work_order');
        $this->post(route('rentals.secure-link.extra', $raw), ['extra_amount' => 10, 'reason' => 'Because'])->assertSessionHasErrors('work_order');
        $this->assertSame(0, $wo->invoices()->count());
        $this->assertSame(0, RentalWorkOrderVariation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
    }

    public function test_another_contractors_link_cannot_file_on_this_job(): void
    {
        [$wo, $raw] = $this->job(true);
        $other = $this->supplier('Other Co', 'other@example.invalid');
        $wo->forceFill(['agency_service_provider_id' => $other->id])->save();   // the job was given to someone else: the old link is dead
        $this->postInvoice($raw, 100.0);
        $this->assertSame(0, $wo->invoices()->count());
    }
}
