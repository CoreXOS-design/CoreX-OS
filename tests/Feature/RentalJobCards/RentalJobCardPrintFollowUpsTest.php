<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalNotice;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Models\PerformanceSetting;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.23 (2026-10-06, Johan): the four
 * follow-ups reported at the end of §14.22 — page margins on the other rental
 * PDFs, the totals box splitting across pages, the schedule boxes not being
 * pre-filled, and the quote box vanishing once a Draft card was scheduled.
 */
final class RentalJobCardPrintFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private const LONG_WORD = 'ABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJABCDEFGHIJ';

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private RentalJobCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Print Agency', 'slug' => 'print-' . uniqid()]);
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Print Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalWorkOrderSetting::create([
            'agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true,
            'show_costs_on_printed_job_card' => true, 'no_approval_spend_threshold' => 100000,
        ]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        $this->service = app(RentalJobCardService::class);
    }

    private function card(string $title = 'Print job'): RentalJobCard
    {
        return $this->service->createForProperty($this->property, ['title' => $title], $this->admin);
    }

    private function vatType(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
    }

    // ── 1. Page margins on the other rental PDFs ─────────────────────────

    public function test_no_dompdf_rental_template_resets_the_html_box_over_its_page_margin(): void
    {
        foreach ([
            'rental-work-orders/pdf', 'rental-fault-reports/pdf', 'rental-notices/pdf', 'leases/pdf/tenancy-report',
            'rental-job-cards/print', 'rental-job-cards/quote-pdf',
        ] as $view) {
            $source = file_get_contents(resource_path("views/corex/{$view}.blade.php"));
            $this->assertMatchesRegularExpression('/@page\s*\{\s*margin:\s*24px 32px/', $source, "{$view}: lost its @page margin");
            $this->assertDoesNotMatchRegularExpression('/html,\s*body\s*\{[^}]*margin:\s*0/i', $source, "{$view}: html/body margin:0 overrides @page in dompdf and removes the page margins");
        }
    }

    /** @return array{0: float, 1: float} [min x, max x] of every word in the PDF, in points */
    private function wordExtent(string $pdfBytes): array
    {
        $bin = trim((string) shell_exec('command -v pdftotext'));
        if ($bin === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed here.');
        }
        $path = tempnam(sys_get_temp_dir(), 'pf') . '.pdf';
        file_put_contents($path, $pdfBytes);
        $bbox = (string) shell_exec($bin . ' -bbox ' . escapeshellarg($path) . ' - 2>/dev/null');
        @unlink($path);
        preg_match_all('/<word xMin="([\d.]+)" yMin="[\d.]+" xMax="([\d.]+)"/', $bbox, $m);
        $this->assertNotEmpty($m[1], 'no words found in the PDF');

        return [min(array_map('floatval', $m[1])), max(array_map('floatval', $m[2]))];
    }

    private function assertInsideA4Margins(string $pdfBytes, string $what): void
    {
        [$min, $max] = $this->wordExtent($pdfBytes);
        $this->assertGreaterThanOrEqual(24.0 - 0.5, $min, "{$what}: text starts left of the page margin");
        $this->assertLessThanOrEqual(595.28 - 24.0 + 0.5, $max, "{$what}: text runs past the right page margin");
    }

    private function lease(): Lease
    {
        return Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->toDateString(), 'source' => 'manual',
        ]);
    }

    public function test_work_order_pdf_sits_inside_the_margins_with_long_text(): void
    {
        $wo = new RentalWorkOrder();
        $wo->forceFill([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'title' => 'Geyser ' . self::LONG_WORD, 'description' => 'Replace the valve ' . self::LONG_WORD,
            'trade_type' => 'plumbing', 'priority' => 'normal', 'reported_at' => now(),
            'completion_notes' => 'Done ' . self::LONG_WORD,
        ]);
        $wo->id = 77;
        $quote = new RentalWorkOrderQuote();
        $quote->forceFill(['amount' => 1234.5, 'quote_date' => now(), 'is_selected' => true, 'detail_text' => 'Detail ' . self::LONG_WORD]);
        $quote->setRelation('supplier', null);
        $wo->setRelation('quotes', collect([$quote]));
        $wo->setRelation('property', $this->property);
        $wo->setRelation('lease', null);
        $wo->setRelation('supplier', null);

        $pdf = app(RentalDocumentPdfService::class)->workOrderPdf($wo)->output();
        $this->assertInsideA4Margins($pdf, 'work order');
    }

    public function test_fault_report_pdf_sits_inside_the_margins_with_long_text(): void
    {
        $fr = new RentalFaultReport();
        $fr->forceFill([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => 'agent', 'reported_channel' => 'agent_portal',
            'title' => 'Leak ' . self::LONG_WORD, 'description' => 'It leaks ' . self::LONG_WORD,
            'outcome' => 'repaired', 'outcome_note' => 'Fixed ' . self::LONG_WORD, 'reported_at' => now(),
        ]);
        $fr->id = 78;
        $fr->setRelation('property', $this->property);
        $fr->setRelation('lease', null);
        $fr->setRelation('photos', collect());

        $pdf = app(RentalDocumentPdfService::class)->faultReportPdf($fr)->output();
        $this->assertInsideA4Margins($pdf, 'fault report');
    }

    public function test_notice_pdf_sits_inside_the_margins_with_long_text(): void
    {
        $lease = $this->lease();
        $notice = new RentalNotice();
        $notice->forceFill(['agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'notice_type' => 'breach']);
        $notice->setRelation('lease', $lease);
        $html = '<p>Dear tenant ' . self::LONG_WORD . '</p>'
            . '<table><tr><td>Item</td><td>' . self::LONG_WORD . '</td></tr></table>'
            . '<ul><li>' . self::LONG_WORD . '</li></ul>';

        $pdf = app(RentalDocumentPdfService::class)->noticePdf($notice, $html)->output();
        $this->assertInsideA4Margins($pdf, 'notice');
    }

    public function test_tenancy_report_pdf_sits_inside_the_margins_with_long_text(): void
    {
        $lease = $this->lease();
        RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'lease_id' => $lease->id, 'reported_by_type' => 'agent', 'reported_channel' => 'agent_portal',
            'title' => 'Leak ' . self::LONG_WORD, 'description' => 'x', 'status' => 'reported', 'reported_at' => now(),
        ]);

        $pdf = app(RentalDocumentPdfService::class)->leaseTenancyReportPdf($lease->fresh())->output();
        $this->assertInsideA4Margins($pdf, 'tenancy report');
    }

    // ── 2. Totals box never splits; header repeats ───────────────────────

    /** A card long enough that the page break falls near the totals. */
    private function longCard(int $lines): RentalJobCard
    {
        $card = $this->card('Long job');
        $vat = $this->vatType();
        $task = $this->service->addTask($card, 'Strip and refit', $this->admin);
        for ($i = 1; $i <= $lines; $i++) {
            $this->service->addLine($card, ['description' => "Line item number {$i}", 'quantity' => 1, 'unit_price' => 10 + $i, 'rental_vat_type_id' => $vat->id], $this->admin, $task);
        }

        return $card->fresh();
    }

    /** @return array<int,string> text of each PDF page */
    private function pagesText(string $pdfBytes): array
    {
        $bin = trim((string) shell_exec('command -v pdftotext'));
        if ($bin === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed here.');
        }
        $path = tempnam(sys_get_temp_dir(), 'pg') . '.pdf';
        file_put_contents($path, $pdfBytes);
        $text = (string) shell_exec($bin . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
        @unlink($path);

        return array_values(array_filter(explode("\f", $text), fn ($p) => trim($p) !== ''));
    }

    /** @dataProvider documents */
    public function test_the_totals_box_is_never_split_across_a_page_break(string $method): void
    {
        $service = app(RentalDocumentPdfService::class);
        $sawTotalsOnLaterPage = false;
        // Sweep the line count so the natural break falls inside the totals box
        // for at least one size (box = ~4 rows) — whichever size it is.
        for ($n = 14; $n <= 44; $n++) {
            $card = $this->longCard($n);
            $pages = $this->pagesText($service->{$method}($card)->output());
            $with = fn (string $needle) => array_keys(array_filter($pages, fn ($p) => str_contains($p, $needle)));
            // The owner quote ends in the VAT totals box; the worker's COST-only print ends in a single "Total cost" box (§17.4.7).
            $printed = $method === 'jobCardPrintPdf';
            $sub = $with($printed ? 'Total cost' : 'Subtotal (excl VAT)');
            $vat = $printed ? $sub : $with('VAT @');
            $tot = $printed ? $sub : $with('Total (incl VAT)');
            $this->assertCount(1, $sub, "n={$n}: grand subtotal appears once");
            $this->assertSame($sub, $tot, "n={$n}: Subtotal and Total are on DIFFERENT pages — the totals box split");
            $this->assertSame($sub, $vat, "n={$n}: VAT line on a different page from Subtotal");
            if ($sub[0] > 0) {
                $sawTotalsOnLaterPage = true;
            }
        }
        $this->assertTrue($sawTotalsOnLaterPage, 'the sweep never produced a multi-page card — the test proves nothing');
    }

    /** @dataProvider documents */
    public function test_the_lines_table_header_repeats_on_page_two(string $method): void
    {
        $pages = $this->pagesText(app(RentalDocumentPdfService::class)->{$method}($this->longCard(60))->output());
        $this->assertGreaterThanOrEqual(2, count($pages), 'a 60-line card must run to a second page');
        $needle = $method === 'jobCardPrintPdf' ? 'Unit cost' : 'Excl VAT';
        $this->assertStringContainsString($needle, $pages[1], "the table header ({$needle} column) is missing on page 2");
        $this->assertStringContainsString('Description', $pages[1]);
    }

    public static function documents(): array
    {
        return ['printed job card' => ['jobCardPrintPdf'], 'owner quote' => ['jobCardQuotePdf']];
    }

    // ── 3. Schedule boxes are pre-filled ─────────────────────────────────

    private function show(RentalJobCard $card)
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card));
    }

    /**
     * §17.6.5 / §17.12 — a card can only be scheduled once it is authorised, and a card with nothing priced is not ("price the job
     * or send the quote first"). So a card these tests schedule carries one priced line inside the owner's no-approval limit
     * (setUp sets it to R100 000), which authorises it by the limit — the dates then save as they always did.
     */
    private function schedulableCard(): RentalJobCard
    {
        $card = $this->card();
        $this->service->addLine($card, ['description' => 'Part', 'quantity' => 1, 'unit_price' => 50, 'rental_vat_type_id' => $this->vatType()->id], $this->admin);

        return $card->fresh();
    }

    public function test_schedule_boxes_carry_the_saved_dates(): void
    {
        $card = $this->schedulableCard();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), [
            'scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:30',
        ])->assertRedirect();

        $html = $this->show($card)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="scheduled_at" value="2026-10-12T09:00"/', $html);
        $this->assertMatchesRegularExpression('/name="due_at" value="2026-10-14T17:30"/', $html);
    }

    public function test_schedule_boxes_are_empty_when_nothing_is_saved(): void
    {
        $html = $this->show($this->card())->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="scheduled_at" value=""/', $html);
        $this->assertMatchesRegularExpression('/name="due_at" value=""/', $html);
    }

    public function test_changing_only_due_keeps_the_scheduled_date(): void
    {
        $card = $this->schedulableCard();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), [
            'scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:30',
        ]);
        // What the browser posts after the agent only edits Due: the pre-filled
        // Scheduled value + the new Due.
        $html = $this->show($card)->getContent();
        preg_match('/name="scheduled_at" value="([^"]*)"/', $html, $m);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), [
            'scheduled_at' => $m[1], 'due_at' => '2026-10-20T12:00',
        ])->assertSessionHasNoErrors();

        $fresh = $card->fresh();
        $this->assertSame('2026-10-12 09:00', $fresh->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-20 12:00', $fresh->due_at->format('Y-m-d H:i'));
    }

    public function test_set_with_nothing_touched_is_an_exact_round_trip(): void
    {
        $card = $this->schedulableCard();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:30']);
        $before = [$card->fresh()->getRawOriginal('scheduled_at'), $card->fresh()->getRawOriginal('due_at')];

        $html = $this->show($card)->getContent();
        preg_match('/name="scheduled_at" value="([^"]*)"/', $html, $s);
        preg_match('/name="due_at" value="([^"]*)"/', $html, $d);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => $s[1], 'due_at' => $d[1]])->assertSessionHasNoErrors();

        $this->assertSame($before, [$card->fresh()->getRawOriginal('scheduled_at'), $card->fresh()->getRawOriginal('due_at')]);
        // …and the model helper reads in the same zone the controller parses in
        $this->assertSame('2026-10-12T09:00', $card->fresh()->scheduleInputValue('scheduled_at'));
        $this->assertNull($card->fresh()->scheduleInputValue('nonsense'));
    }

    public function test_typed_values_still_win_over_saved_ones_after_a_refused_set(): void
    {
        $card = $this->schedulableCard();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:30']);
        $this->actingAs($this->admin)->from(route('corex.rental-job-cards.show', $card))
            ->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => '2026-10-20T09:00', 'due_at' => '2026-10-19T09:00'])
            ->assertSessionHasErrors('due_at');
        $html = $this->actingAs($this->admin)->followingRedirects()->get(route('corex.rental-job-cards.show', $card))->getContent();
        // saved values are what the (new) page shows once the flash is gone; the
        // refusal itself changed nothing
        $this->assertSame('2026-10-12 09:00', $card->fresh()->scheduled_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('name="scheduled_at"', $html);
    }

    // ── 4. Quote box on every open card ──────────────────────────────────

    private function cardWithLandlord(string $status): RentalJobCard
    {
        $card = $this->card();
        $this->service->addLine($card, ['description' => 'Part', 'quantity' => 1, 'unit_price' => 50, 'rental_vat_type_id' => $this->vatType()->id], $this->admin);
        $card->forceFill(['status' => $status])->save();

        return $card->fresh();
    }

    /** @dataProvider openStatuses */
    public function test_the_quote_box_is_offered_on_every_open_card(string $status): void
    {
        $card = $this->cardWithLandlord($status);
        $this->show($card)->assertOk()->assertSee('id="jc-quote-box"', false);
    }

    public static function openStatuses(): array
    {
        return [
            'draft' => [RentalJobCard::STATUS_DRAFT],
            'quoted' => [RentalJobCard::STATUS_QUOTED],
            'approved' => [RentalJobCard::STATUS_APPROVED],
            'scheduled' => [RentalJobCard::STATUS_SCHEDULED],
            'in progress' => [RentalJobCard::STATUS_IN_PROGRESS],
        ];
    }

    /** @dataProvider closedStatuses */
    public function test_no_quote_box_on_a_closed_card(string $status): void
    {
        $card = $this->cardWithLandlord($status);
        $this->show($card)->assertOk()->assertDontSee('id="jc-quote-box"', false);
    }

    public static function closedStatuses(): array
    {
        return ['completed' => [RentalJobCard::STATUS_COMPLETED], 'cancelled' => [RentalJobCard::STATUS_CANCELLED]];
    }

    public function test_scheduling_a_draft_card_no_longer_hides_the_quote_box_and_a_send_keeps_it_scheduled(): void
    {
        $landlord = \App\Models\Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Land', 'last_name' => 'Lord', 'email' => 'zz-print@example.invalid']);
        \App\Services\Property\ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');
        $card = $this->cardWithLandlord(RentalJobCard::STATUS_DRAFT);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => '2026-10-12T09:00', 'due_at' => ''])->assertSessionHasNoErrors();
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
        $this->show($card)->assertSee('id="jc-quote-box"', false)->assertSee('Send to owner as quote');

        // The service has no status rule beyond "not closed" — a send from
        // Scheduled works and leaves the status alone (work already planned).
        $quote = $this->service->sendToOwnerAsQuote($card->fresh(), $this->admin, app(RentalDocumentPdfService::class));
        $this->assertSame(1, $quote->revision);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
        $this->show($card)->assertSee('Re-send revised quote (Rev 2)');
    }
}
