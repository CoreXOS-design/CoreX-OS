<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalWorkOrderOwnerMail;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.11 (R6) — the estimate wording printed on every owner quote: the built-in default, the
 * agency's own words, "Restore default", the snapshot taken when a quote is sent (a later edit never changes what the owner was
 * shown), and WHERE it is printed — the owner quote PDF and the quote mail — and where it is not.
 */
final class QuoteEstimateTermTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Estimate Term');
        $this->card = $this->emptyCard();
        $this->officeLine($this->card, ['description' => 'Pipe', 'unit_cost' => 100, 'unit_price' => 150]);
        $this->card = $this->card->fresh();
    }

    private function save(array $data)
    {
        return $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.quote-estimate-term'), $data);
    }

    private function visibleText(string $html): string
    {
        return strip_tags(preg_replace('#<style.*?</style>#si', '', $html));
    }

    // ── the setting ──────────────────────────────────────────────────────────────────────

    public function test_the_default_is_the_neutral_built_in_wording_with_no_agency_name_in_it(): void
    {
        $term = RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id);

        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, $term);
        $this->assertStringContainsString('This quote is an estimate.', $term);
        $this->assertStringNotContainsStringIgnoringCase('home finders', $term);
        $this->assertStringNotContainsStringIgnoringCase('hfc', $term);
        $this->assertLessThanOrEqual(2000, mb_strlen($term));
    }

    public function test_an_agency_can_rewrite_it_and_restore_the_default(): void
    {
        $this->save(['quote_estimate_term' => 'Prices are estimates only.'])->assertRedirect(route('corex.settings.rental-work-orders.edit'))->assertSessionHas('success');
        $this->assertSame('Prices are estimates only.', RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id));

        $this->save(['quote_estimate_term' => 'ignored', 'restore_default' => 1])->assertRedirect();
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->value('quote_estimate_term'));
        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id));
    }

    public function test_blank_or_identical_to_the_default_is_stored_as_null_so_the_agency_keeps_following_the_built_in_text(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['quote_estimate_term' => 'custom']);
        $this->save(['quote_estimate_term' => '   '])->assertRedirect();
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->value('quote_estimate_term'));

        $this->save(['quote_estimate_term' => RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM])->assertRedirect();
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->value('quote_estimate_term'));
    }

    public function test_it_is_capped_at_2000_characters_and_a_missing_field_is_an_error_not_a_silent_save(): void
    {
        $this->save(['quote_estimate_term' => str_repeat('x', 2001)])->assertSessionHasErrors('quote_estimate_term');
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->value('quote_estimate_term'));

        $this->save([])->assertSessionHasErrors('quote_estimate_term');
    }

    public function test_saving_needs_the_work_order_settings_permission(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create']);

        $this->actingAs($agent)->post(route('corex.settings.rental-work-orders.quote-estimate-term'), ['quote_estimate_term' => 'Mine'])->assertForbidden();
        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id));
    }

    public function test_the_settings_page_shows_the_wording_the_restore_button_and_the_default_markups(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['default_parts_markup_percent' => 22.5, 'default_labour_markup_percent' => 0]);

        $this->actingAs($this->admin)->get(route('corex.settings.rental-work-orders.edit'))->assertOk()
            ->assertSee('Estimate wording on owner quotes')->assertSee('Restore default')
            ->assertSee('name="default_parts_markup_percent" value="22.5"', false)
            ->assertSee('Currently using the standard wording', false);
    }

    // ── snapshot + where it prints ───────────────────────────────────────────────────────

    public function test_sending_a_quote_snapshots_the_wording_onto_the_quote_and_a_later_edit_never_changes_it(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['quote_estimate_term' => 'Wording on the day the quote went out.']);

        $quote = app(RentalJobCardService::class)->sendToOwnerAsQuote($this->card, $this->admin, app(RentalDocumentPdfService::class));
        $this->assertSame('Wording on the day the quote went out.', $quote->fresh()->term_text);

        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['quote_estimate_term' => 'A completely different wording later.']);
        $this->assertSame('Wording on the day the quote went out.', $quote->fresh()->term_text, 'a settings edit never rewrites a quote already sent');

        // the NEXT revision picks up the new wording
        $this->officeLine($this->card->fresh(), ['description' => 'More', 'unit_price' => 10]);
        $rev2 = app(RentalJobCardService::class)->sendToOwnerAsQuote($this->card->fresh(), $this->admin, app(RentalDocumentPdfService::class));
        $this->assertSame('A completely different wording later.', $rev2->fresh()->term_text);
        $this->assertSame('Wording on the day the quote went out.', $quote->fresh()->term_text);
    }

    public function test_the_wording_is_printed_on_the_owner_quote_pdf_and_the_snapshot_not_the_live_setting_is_what_prints(): void
    {
        $html = app(RentalDocumentPdfService::class)->jobCardQuotePdf($this->card, 1, 'Snapshot wording for the owner.')->getDomPDF()->outputHtml();
        $this->assertStringContainsString('Snapshot wording for the owner.', $this->visibleText($html));

        // no explicit snapshot (e.g. a reprint call) falls back to the wording in force now
        $now = app(RentalDocumentPdfService::class)->jobCardQuotePdf($this->card)->getDomPDF()->outputHtml();
        $this->assertStringContainsString('This quote is an estimate.', $this->visibleText($now));
    }

    public function test_the_wording_is_printed_on_the_quote_mail_body(): void
    {
        Mail::fake();
        // Build 2 (§17.16): the quote reaches the owner as RentalOwnerQuoteMail through the agency mailbox path (the plain
        // RentalWorkOrderOwnerMail is retired) — the wording is printed on THAT mail body.
        $mailbox = new class extends \App\Services\Rentals\RentalMailDispatcher {
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void
            {
                $this->sent[] = $mail;
            }
        };
        $this->app->instance(\App\Services\Rentals\RentalMailDispatcher::class, $mailbox);
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['quote_estimate_term' => 'Mail wording: final cost may differ.']);

        app(RentalJobCardService::class)->sendToOwnerAsQuote($this->card, $this->admin, app(RentalDocumentPdfService::class));

        $quoteMails = array_values(array_filter($mailbox->sent, fn ($m) => $m instanceof \App\Mail\Rentals\RentalOwnerQuoteMail));
        $this->assertCount(1, $quoteMails);
        $this->assertStringContainsString('Mail wording: final cost may differ.', $quoteMails[0]->render());
        Mail::assertNothingQueued();
    }

    public function test_the_wording_is_not_printed_on_the_worker_print_or_the_completed_notice(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['quote_estimate_term' => 'Never on the worker copy.']);
        $quote = app(RentalJobCardService::class)->sendToOwnerAsQuote($this->card, $this->admin, app(RentalDocumentPdfService::class));

        $print = app(RentalDocumentPdfService::class)->jobCardPrintPdf($this->card->fresh())->getDomPDF()->outputHtml();
        $this->assertStringNotContainsString('Never on the worker copy.', $print);

        $completed = (new RentalWorkOrderOwnerMail($this->card->fresh()->workOrder, RentalWorkOrderOwnerMail::STAGE_COMPLETED, 'Jane'))->render();
        $this->assertStringNotContainsString('Never on the worker copy.', $completed, 'the final notice is not an estimate');
        $this->assertNotNull($quote->term_text);
    }

    public function test_a_second_agency_prints_its_own_wording_and_never_the_first_agencys(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['quote_estimate_term' => 'First agency wording.']);
        $first = $this->agency->id;

        $this->pricingWorld('Cape Town Rentals', ['quote_estimate_term' => 'Cape Town wording: estimate only.']);
        $card = $this->emptyCard();
        $this->officeLine($card, ['description' => 'Pipe', 'unit_price' => 150]);

        $quote = app(RentalJobCardService::class)->sendToOwnerAsQuote($card, $this->admin, app(RentalDocumentPdfService::class));

        $this->assertSame('Cape Town wording: estimate only.', $quote->term_text);
        $this->assertSame('First agency wording.', RentalWorkOrderSetting::quoteEstimateTermFor($first));
    }
}
