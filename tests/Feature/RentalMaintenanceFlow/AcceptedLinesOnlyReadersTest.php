<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\RentalCrewScheduleService;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalJobCardVatService;
use App\Services\Rentals\RentalReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.4.6 — "Only `accepted` lines count anywhere." One assertion per reader the spec lists:
 * recalcTotal, the VAT breakdown/snapshot, the quote content signature, the quote PDF, the worker print, the crew payload's
 * materials/labour, the crew page's "what to load", the jobCards() report, the send-quote precondition and the mobile API.
 *
 * The card carries one ACCEPTED line (a part, R100 selling, R60 cost) and — in turn — a line that is `awaiting_office`, a
 * `rejected` one, a `crew_draft` and a `declined_by_owner` one, each with distinctive figures so a leak cannot be mistaken.
 */
final class AcceptedLinesOnlyReadersTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Accepted Only', [], true);
        $this->card = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addHours(2)]);
        $this->officeLine($this->card, ['description' => 'Real part', 'type' => 'part', 'unit' => 'Each', 'quantity' => 1, 'unit_cost' => 60, 'unit_price' => 100, 'rental_vat_type_id' => $this->standardVat()->id]);
        $this->card = $this->card->fresh();
    }

    /** A non-accepted line with figures nobody could mistake: cost 777.77, selling 9,999.99. */
    private function pendingLine(string $state, string $description = 'GHOST LINE'): RentalJobCardLine
    {
        $line = $this->officeLine($this->card, ['description' => $description, 'type' => 'part', 'unit' => 'Each', 'quantity' => 1, 'unit_cost' => 777.77, 'unit_price' => 9999.99, 'rental_vat_type_id' => $this->standardVat()->id]);
        $line->forceFill(['office_status' => $state, 'origin' => RentalJobCardLine::ORIGIN_CREW_EXTRA])->save();
        $this->card->recalcTotal();
        $this->card = $this->card->fresh();

        return $line->fresh();
    }

    /** @return array<string, array{0: string}> */
    public static function states(): array
    {
        return [
            'awaiting the office' => [RentalJobCardLine::OFFICE_AWAITING],
            'rejected' => [RentalJobCardLine::OFFICE_REJECTED],
            'crew draft' => [RentalJobCardLine::OFFICE_CREW_DRAFT],
            'declined by the owner' => [RentalJobCardLine::OFFICE_DECLINED_BY_OWNER],
        ];
    }

    #[DataProvider('states')]
    public function test_recalc_total_counts_selling_and_cost_of_accepted_lines_only(string $state): void
    {
        $this->pendingLine($state);

        $this->assertEquals(100.00, (float) $this->card->total_amount);
        $this->assertEquals(60.00, (float) $this->card->total_cost);
    }

    #[DataProvider('states')]
    public function test_the_vat_breakdown_and_snapshot_ignore_other_lines(string $state): void
    {
        $ghost = $this->pendingLine($state);
        $vat = app(RentalJobCardVatService::class);

        $b = $vat->breakdown($this->card->load('lines'));
        $this->assertEquals(100.00, $b['subtotalExcl']);
        $this->assertEquals(115.00, $b['totalIncl']);
        $this->assertArrayNotHasKey($ghost->id, $b['lineFigures']);
        $this->assertEquals(115.00, $vat->inclusiveTotal($this->card->fresh('lines')), 'the amount sent to the owner');

        $vat->snapshot($this->card->fresh('lines'));
        $this->assertNull($ghost->fresh()->vat_rate_snapshot, 'a line that does not count is never frozen');
        $this->assertNull($ghost->fresh()->vat_excl_snapshot);

        // and the per-line refresh refuses it too (a frozen card edits one line at a time)
        $vat->refreshLineSnapshot($this->card->fresh(), $ghost->fresh());
        $this->assertNull($ghost->fresh()->vat_rate_snapshot);

        $cost = $vat->costBreakdown($this->card->fresh('lines'));
        $this->assertEquals(60.00, $cost['subtotalExcl'], 'cost VAT breakdown: accepted lines only');
        $this->assertSame(1, $cost['costedLines']);
    }

    #[DataProvider('states')]
    public function test_the_quote_content_signature_does_not_move_for_a_line_that_is_not_on_the_quote(string $state): void
    {
        $before = $this->card->quoteContentSignature();
        $this->pendingLine($state);

        $this->assertSame($before, $this->card->fresh()->quoteContentSignature(), 'must not flip "changed since sent"');
    }

    #[DataProvider('states')]
    public function test_the_quote_pdf_html_carries_accepted_lines_only(string $state): void
    {
        $this->pendingLine($state);

        $html = $this->renderedQuoteHtml();

        $this->assertStringContainsString('Real part', $html);
        $this->assertStringNotContainsString('GHOST LINE', $html);
        $this->assertStringNotContainsString('9,999.99', $html);
        $this->assertStringNotContainsString('777.77', $html);
    }

    #[DataProvider('states')]
    public function test_the_worker_print_html_carries_accepted_lines_only(string $state): void
    {
        $this->pendingLine($state);
        \App\Models\RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['show_costs_on_printed_job_card' => true]);

        $html = $this->renderedPrintHtml();

        $this->assertStringContainsString('Real part', $html);
        $this->assertStringNotContainsString('GHOST LINE', $html);
        $this->assertStringNotContainsString('777.77', $html);
    }

    #[DataProvider('states')]
    public function test_the_crew_payload_materials_and_labour_list_accepted_lines_only(string $state): void
    {
        $this->pendingLine($state);

        $payload = app(CrewJobService::class)->payload($this->card, $this->crewCtx($this->card, showCosts: true));

        $descriptions = collect($payload['materials'])->pluck('description')->all();
        $this->assertSame(['Real part'], $descriptions);
        $this->assertSame('60.00', $payload['cost_total'], 'the crew total covers accepted lines only');
    }

    #[DataProvider('states')]
    public function test_the_crew_pages_what_to_load_counts_accepted_lines_only(string $state): void
    {
        $this->pendingLine($state);

        $page = app(RentalCrewScheduleService::class)->schedule($this->agency->id, $this->crew->id);

        $this->assertCount(1, $page['materials']);
        $this->assertSame('Real part', $page['materials'][0]['description']);
        $this->assertSame('1', $page['materials'][0]['quantity']);
    }

    #[DataProvider('states')]
    public function test_the_job_cards_report_ignores_other_lines(string $state): void
    {
        $this->pendingLine($state);
        $this->pendingLine($state, 'SECOND GHOST');

        $report = app(RentalReportService::class)->jobCards($this->admin, []);
        $row = collect($report['rows'])->firstWhere('_model.id', $this->card->id);

        $this->assertNotNull($row);
        $this->assertStringContainsString('Real part', $row['parts_used']);
        $this->assertStringNotContainsString('GHOST', $row['parts_used']);
        $this->assertEquals(100.00, $row['total_excl']);
        $this->assertEquals(115.00, $row['total_incl']);
    }

    #[DataProvider('states')]
    public function test_a_card_whose_only_line_is_not_accepted_cannot_be_sent_as_a_quote(string $state): void
    {
        $empty = $this->emptyCard();
        $line = $this->officeLine($empty, ['description' => 'Only line', 'unit_cost' => 10]);
        $line->forceFill(['office_status' => $state, 'origin' => RentalJobCardLine::ORIGIN_CREW_EXTRA])->save();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Add at least one line');
        app(RentalJobCardService::class)->sendToOwnerAsQuote($empty->fresh(), $this->admin, app(RentalDocumentPdfService::class));
    }

    #[DataProvider('states')]
    public function test_the_mobile_api_lists_accepted_lines_only(string $state): void
    {
        $this->pendingLine($state);

        $json = $this->actingAs($this->admin)->getJson('/api/v1/mobile/rental-job-cards/' . $this->card->id)->assertOk()->json();
        $lines = $json['data']['lines'] ?? $json['lines'] ?? [];

        $this->assertSame(['Real part'], collect($lines)->pluck('description')->all());
    }

    public function test_the_task_subtotal_reads_accepted_lines_only(): void
    {
        $task = app(RentalJobCardService::class)->addTask($this->card, 'Fix it', $this->admin);
        $real = app(RentalJobCardService::class)->addLine($this->card, ['description' => 'In task', 'type' => 'part', 'quantity' => 1, 'unit_price' => 40], $this->admin, $task);
        $ghost = app(RentalJobCardService::class)->addLine($this->card, ['description' => 'GHOST', 'type' => 'part', 'quantity' => 1, 'unit_price' => 5000], $this->admin, $task);
        $ghost->forceFill(['office_status' => RentalJobCardLine::OFFICE_AWAITING])->save();

        $this->assertEquals(40.00, $task->fresh()->subtotal());
        $this->assertEquals(40.00, $task->fresh('lines')->subtotal(), 'same answer from a loaded relation');
        $this->assertTrue($task->acceptedLines()->pluck('id')->contains($real->id));
        $this->assertFalse($task->acceptedLines()->pluck('id')->contains($ghost->id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** Render the quote Blade exactly as RentalDocumentPdfService does, without dompdf. */
    private function renderedQuoteHtml(): string
    {
        $pdf = app(RentalDocumentPdfService::class)->jobCardQuotePdf($this->card->fresh());

        return $pdf->getDomPDF()->outputHtml() ?: $this->fail('no HTML');
    }

    private function renderedPrintHtml(): string
    {
        $pdf = app(RentalDocumentPdfService::class)->jobCardPrintPdf($this->card->fresh());

        return $pdf->getDomPDF()->outputHtml() ?: $this->fail('no HTML');
    }
}
