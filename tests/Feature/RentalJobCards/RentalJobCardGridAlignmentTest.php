<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;
use App\Support\RentalJobCardLineGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.24 (2026-10-06, Johan): the column
 * header, the saved lines and the add-line row under a task use ONE grid and
 * their text starts on the same x; the end-of-row ✎ / × / + are compact
 * icon-sized controls (the card-wide `button[type=submit]` rule made the ×
 * a 44x36 block).
 */
final class RentalJobCardGridAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Grid Agency', 'slug' => 'grid-' . uniqid()]);
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $branch->id,
            'title' => '1 Grid Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true, 'no_approval_spend_threshold' => 100000]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);

        $service = app(RentalJobCardService::class);
        $this->card = $service->createForProperty($property, ['title' => 'Grid job'], $this->admin);
        $task = $service->addTask($this->card, 'Replace washer', $this->admin);
        $vat = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
        $service->addLine($this->card, ['description' => 'Washer', 'quantity' => 2, 'unit_price' => 15, 'rental_vat_type_id' => $vat->id], $this->admin, $task);
    }

    private function html(): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
    }

    public function test_header_saved_row_and_add_row_use_the_same_grid_style(): void
    {
        $grid = RentalJobCardLineGrid::gridStyle(true, true);
        // header + saved row + add row (+ the General block's three) all carry the one grid style
        $this->assertGreaterThanOrEqual(3, substr_count($this->html(), 'style="' . $grid), 'header, saved row and add row must render from the one grid style string');
        $this->assertSame(
            'minmax(0,100px) minmax(70px,1fr) 90px 64px 50px 84px 84px 36px',
            implode(' ', RentalJobCardLineGrid::columns(true, true)),
        );
    }

    public function test_header_labels_and_saved_values_carry_the_same_text_inset_as_the_inputs(): void
    {
        $inset = RentalJobCardLineGrid::cellStyle();
        $this->assertSame('padding-left: 9px;', $inset); // 8px (px-2) + 1px border of the inputs below
        $html = $this->html();
        // every header label and the saved description/type/unit/qty/price cells
        $this->assertGreaterThanOrEqual(8, substr_count($html, $inset), 'header labels + saved values must carry the inset');
        $this->assertMatchesRegularExpression('/title="Washer"[^>]*style="padding-left: 9px;"/', $html);
    }

    public function test_the_end_of_row_controls_are_compact_icon_controls_that_beat_the_card_wide_submit_rule(): void
    {
        $html = $this->html();

        foreach (['title="Edit line"', 'title="Archive"', 'aria-label="Add line"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Each carries the compact style, with !important on the properties the card-wide rule forces.
        $this->assertMatchesRegularExpression('/title="Archive"[^>]*style="[^"]*width:17px; height:17px;[^"]*padding:0 !important;[^"]*background:transparent !important;/', $html);
        $this->assertMatchesRegularExpression('/title="Edit line"[^>]*style="[^"]*width:17px; height:17px;[^"]*padding:0 !important;/', $html);
        $this->assertMatchesRegularExpression('/aria-label="Add line"[^>]*style="[^"]*width:17px; height:17px;[^"]*padding:0 !important;[^"]*justify-self:end;/', $html);
        // …and the + is no longer the full-size outline button
        $this->assertDoesNotMatchRegularExpression('/aria-label="Add line"[^>]*class="[^"]*corex-btn/', $html);
    }

    public function test_the_add_row_stays_one_line_and_the_action_column_is_the_narrow_last_track(): void
    {
        $cols = RentalJobCardLineGrid::columns(true, true);
        $this->assertSame('36px', end($cols));
        // 17px control fits the 36px track with room to spare for the edit + archive pair (2 x 17 = 34)
        $this->assertLessThanOrEqual(36, 17 * 2);
    }

    public function test_a_closed_card_has_no_end_of_row_controls(): void
    {
        $this->card->forceFill(['status' => RentalJobCard::STATUS_CANCELLED])->save();
        $html = $this->html();
        $this->assertStringNotContainsString('title="Edit line"', $html);
        $this->assertStringNotContainsString('title="Archive"', $html);
        $this->assertStringNotContainsString('aria-label="Add line"', $html);
    }

    // ── §14.25 — the on-screen VAT column never shows a dash ─────────────

    private function vatType(string $mode): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', $mode)->firstOrFail();
    }

    /** A line exactly as a free-text / no-default-VAT catalogue line is stored: no VAT type at all. */
    private function addLineWithoutVatType(string $description, ?float $price, bool $general = false): \App\Models\RentalJobCardLine
    {
        $service = app(RentalJobCardService::class);
        $task = $general ? null : $this->card->tasks()->first();
        $line = $service->addLine($this->card, ['description' => $description, 'quantity' => 1, 'unit_price' => $price], $this->admin, $task);
        $line->forceFill(['rental_vat_type_id' => null, 'custom_vat_rate' => null])->save();

        return $line;
    }

    public function test_a_free_text_line_with_no_vat_type_shows_none_not_a_dash_in_a_task_and_in_general(): void
    {
        $this->addLineWithoutVatType('Free text in task', 40);
        $this->addLineWithoutVatType('Free text in general', 25, true);

        $html = $this->html();
        $this->assertSame(2, substr_count($html, '<span class="truncate" title="None"'), 'both type-less lines show the effective type: None');
        $this->assertStringNotContainsString('title="—"', $html, 'no VAT cell may fall back to a dash');
    }

    public function test_a_line_with_no_price_yet_still_shows_its_effective_vat_type(): void
    {
        $this->addLineWithoutVatType('No price yet', null);
        $this->assertSame(1, substr_count($this->html(), '<span class="truncate" title="None"'));
    }

    public function test_standard_and_custom_lines_show_the_selectors_wording_and_their_rate(): void
    {
        $service = app(RentalJobCardService::class);
        $task = $this->card->tasks()->first();
        $service->addLine($this->card, ['description' => 'Custom one', 'quantity' => 1, 'unit_price' => 100, 'rental_vat_type_id' => $this->vatType(RentalVatType::RATE_MODE_CUSTOM_PER_LINE)->id, 'custom_vat_rate' => 7.5], $this->admin, $task);

        $html = $this->html();
        $this->assertStringContainsString('title="Standard (15%)"', $html); // the setUp line
        $this->assertStringContainsString('title="Custom (7.5%)"', $html);
    }

    public function test_a_frozen_quote_still_words_each_line_the_same_way(): void
    {
        $this->addLineWithoutVatType('Free text frozen', 60);
        app(\App\Services\Rentals\RentalJobCardVatService::class)->snapshot($this->card->fresh());

        $html = $this->html();
        $this->assertStringContainsString('title="Standard (15%)"', $html);
        $this->assertStringContainsString('<span class="truncate" title="None"', $html);
        $this->assertStringNotContainsString('title="—"', $html);
    }

    public function test_the_wording_is_the_selectors_wording(): void
    {
        $this->assertSame('Standard', RentalVatType::shortenName('Standard VAT'));
        $this->assertSame('None', RentalVatType::shortenName('No VAT'));
        $this->assertSame('Custom', RentalVatType::shortenName('Custom'));
        foreach (RentalVatType::where('agency_id', $this->agency->id)->get() as $type) {
            $this->assertSame($type->shortLabel(), RentalVatType::shortenName($type->name));
        }
    }

    public function test_the_printouts_are_untouched_no_vat_type_column_and_the_vat_amount_still_shown(): void
    {
        $bin = trim((string) shell_exec('command -v pdftotext'));
        if ($bin === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed here.');
        }
        \App\Models\RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['show_costs_on_printed_job_card' => true]);
        $this->addLineWithoutVatType('Free text printed', 40);
        $card = $this->card->fresh();

        foreach (['jobCardPrintPdf', 'jobCardQuotePdf'] as $method) {
            $path = tempnam(sys_get_temp_dir(), 'va') . '.pdf';
            file_put_contents($path, app(\App\Services\Rentals\RentalDocumentPdfService::class)->{$method}($card)->output());
            $text = (string) shell_exec($bin . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
            @unlink($path);
            $this->assertStringNotContainsString('VAT type', $text, "{$method}: the printouts carry no VAT type column");
            if ($method === 'jobCardPrintPdf') {
                // §17.4.7 — the worker's printed copy is COST-only: no selling, no VAT breakdown at all.
                $this->assertStringNotContainsString('Excl VAT', $text, 'the worker print carries no VAT breakdown');
                $this->assertStringNotContainsString('R40.00', $text, 'the worker print never shows the selling price');
                continue;
            }
            $this->assertStringContainsString('Excl VAT', $text, "{$method}: Excl VAT column still there");
            $this->assertMatchesRegularExpression('/Free text printed.*R40\.00\s+R40\.00\s+R0\.00/', $text, "{$method}: the type-less line prints its VAT amount (R0.00)");
        }
    }
}
