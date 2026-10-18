<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalDocumentPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.4.5 — the printouts. The WORKER print shows COST, never selling, and only when the agency
 * switched costs on; the OWNER quote shows SELLING only and is free of the words "cost" and "margin".
 */
final class JobCardPrintDocumentsTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Print Docs', [], true);
        $this->card = $this->emptyCard();
        // cost 61.31, +20 % line markup => selling 73.57 (x2 = 147.14); VAT 15 %
        $this->officeLine($this->card, ['description' => 'Distinctive part', 'quantity' => 2, 'unit_cost' => 61.31, 'markup_type' => 'percent', 'markup_value' => 20, 'rental_vat_type_id' => $this->standardVat()->id]);
        $this->card = $this->card->fresh();
    }

    private function visibleText(string $html): string
    {
        return strip_tags(preg_replace('#<style.*?</style>#si', '', $html));
    }

    private function print(): string
    {
        return app(RentalDocumentPdfService::class)->jobCardPrintPdf($this->card->fresh())->getDomPDF()->outputHtml();
    }

    private function quote(): string
    {
        return app(RentalDocumentPdfService::class)->jobCardQuotePdf($this->card->fresh(), 1)->getDomPDF()->outputHtml();
    }

    public function test_the_worker_print_has_no_money_at_all_while_the_cost_switch_is_off(): void
    {
        $text = $this->visibleText($this->print());

        $this->assertStringContainsString('Distinctive part', $text);
        $this->assertStringNotContainsString('61.31', $text);
        $this->assertStringNotContainsString('73.57', $text);
        $this->assertStringNotContainsString('Unit cost', $text);
        $this->assertStringNotContainsString('Total cost', $text);
    }

    public function test_the_worker_print_shows_cost_never_selling_when_the_switch_is_on(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['show_costs_on_printed_job_card' => true]);

        $text = $this->visibleText($this->print());

        $this->assertStringContainsString('61.31', $text, 'unit cost');
        $this->assertStringContainsString('122.62', $text, 'cost total (2 x 61.31)');
        $this->assertStringContainsString('Total cost (incl VAT)', $text);
        $this->assertStringContainsString('141.01', $text, '122.62 + 15 % VAT');
        foreach (['73.57', '147.14', '169.21', 'markup', 'Margin', 'margin'] as $selling) {
            $this->assertStringNotContainsString($selling, $text, "'{$selling}' (selling / markup / margin) must never be on the worker copy");
        }
    }

    public function test_the_owner_quote_shows_selling_and_the_words_cost_and_margin_never_appear(): void
    {
        $text = $this->visibleText($this->quote());

        $this->assertStringContainsString('Distinctive part', $text);
        $this->assertStringContainsString('73.57', $text, 'selling unit price');
        $this->assertStringContainsString('147.14', $text, 'selling excl VAT');
        $this->assertStringContainsString('169.21', $text, 'incl VAT');
        $this->assertStringNotContainsString('61.31', $text, 'no cost figure');
        $this->assertStringNotContainsString('122.62', $text);
        $this->assertDoesNotMatchRegularExpression('/\bcost\b|\bmargin\b|\bmarkup\b/i', $text);
    }

    public function test_the_worker_print_for_a_non_vat_agency_has_a_plain_total_cost(): void
    {
        $this->pricingWorld('Print Plain', ['show_costs_on_printed_job_card' => true]);
        $card = $this->emptyCard();
        $this->officeLine($card, ['description' => 'Plain part', 'unit_cost' => 40, 'unit_price' => 55]);

        $text = $this->visibleText(app(RentalDocumentPdfService::class)->jobCardPrintPdf($card->fresh())->getDomPDF()->outputHtml());

        $this->assertStringContainsString('Total cost', $text);
        $this->assertStringNotContainsString('(incl VAT)', $text);
        $this->assertStringContainsString('R40.00', $text);
        $this->assertStringNotContainsString('55.00', $text);
    }
}
