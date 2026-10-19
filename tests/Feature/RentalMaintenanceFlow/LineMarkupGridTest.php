<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\User;
use App\Support\RentalJobCardLineGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * AT-466 (Johan, QA1 job card 100, PLUMB-01 20 % markup item, cost R789.50):
 *  1. a markup (R or %) typed on a line works the selling out from the COST and never makes it "set by hand" - only a figure typed in the
 *     Selling box does; a line added from a markup item prices itself on add (789.50 -> 947.40);
 *  2. the line grid: header, saved lines, add row and edit row share one set of tracks, wide enough for R99,999.99;
 *  3. the description is the agent's own words - the catalogue name is only a default / placeholder.
 */
final class LineMarkupGridTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private User $user;
    private RentalJobCard $card;
    private RentalCatalogueItem $plumb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Line Markup');
        $this->user = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.price', 'rental_job_cards.view_costs', 'rental_catalogue.view']);
        $this->card = $this->emptyCard();
        $this->plumb = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'PLUMB-01', 'description' => 'Plumbing', 'sort_order' => 1,
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'cost_excl' => 0, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup',
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function add(array $over = [])
    {
        return $this->actingAs($this->user)->post(route('corex.rental-job-cards.lines.store', $this->card), $over + [
            'rental_catalogue_item_id' => $this->plumb->id, 'type' => 'part', 'unit' => 'Each', 'quantity' => 1,
        ]);
    }

    private function edit(RentalJobCardLine $line, array $over = [])
    {
        $line->refresh();

        // what the edit row posts: every box as shown, then whatever the agent changed
        return $this->actingAs($this->user)->put(route('corex.rental-job-cards.lines.update', [$this->card, $line]), $over + [
            'rental_catalogue_item_id' => $line->rental_catalogue_item_id, 'description' => $line->description, 'type' => $line->type, 'unit' => $line->unit, 'quantity' => $line->quantity,
            'unit_cost' => $line->unit_cost, 'unit_price' => $line->unit_price, 'markup_type' => $line->markup_type, 'markup_value' => $line->markup_value,
        ]);
    }

    private function lastLine(): RentalJobCardLine
    {
        return RentalJobCardLine::where('rental_job_card_id', $this->card->id)->latest('id')->firstOrFail();
    }

    // ── 1. markup is a markup, selling by hand is selling by hand ───────────────────────

    public function test_a_line_added_from_the_markup_item_prices_itself_on_add(): void
    {
        $this->add(['unit_cost' => '789.50', 'description' => ''])->assertSessionHasNoErrors();

        $line = $this->lastLine();
        $this->assertSame('947.40', (string) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $line->selling_basis);
    }

    public function test_a_markup_typed_on_the_add_row_works_from_the_cost_and_is_not_set_by_hand(): void
    {
        $this->add(['unit_cost' => '789.50', 'markup_type' => 'amount', 'markup_value' => '10'])->assertSessionHasNoErrors();

        $line = $this->lastLine();
        $this->assertSame('799.50', (string) $line->unit_price, 'cost + R10, not R10');
        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $line->selling_basis);
        $this->assertNotSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);

        $this->add(['unit_cost' => '100', 'markup_type' => 'percent', 'markup_value' => '50'])->assertSessionHasNoErrors();
        $this->assertSame('150.00', (string) $this->lastLine()->unit_price);
    }

    public function test_a_markup_typed_on_the_edit_row_works_from_the_cost_and_is_not_set_by_hand(): void
    {
        $this->add(['unit_cost' => '789.50'])->assertSessionHasNoErrors();
        $line = $this->lastLine();
        $this->assertSame('947.40', (string) $line->unit_price);

        // the Selling box is posted back as shown (947.40): that is not a typed price
        $this->edit($line, ['markup_type' => 'amount', 'markup_value' => '10'])->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame('799.50', (string) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $line->selling_basis);

        $this->edit($line, ['markup_type' => 'percent', 'markup_value' => '20'])->assertSessionHasNoErrors();
        $this->assertSame('947.40', (string) $line->fresh()->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $line->fresh()->selling_basis);
    }

    public function test_a_markup_typed_on_a_line_that_was_set_by_hand_takes_the_hand_flag_off(): void
    {
        $this->add(['unit_cost' => '789.50', 'unit_price' => '10'])->assertSessionHasNoErrors();
        $line = $this->lastLine();
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
        $this->assertSame('10.00', (string) $line->unit_price);

        $this->edit($line, ['markup_type' => 'percent', 'markup_value' => '20'])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame('947.40', (string) $line->unit_price);
        $this->assertNotSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
    }

    public function test_only_a_figure_typed_in_the_selling_box_sets_the_price_by_hand(): void
    {
        $this->add(['unit_cost' => '789.50'])->assertSessionHasNoErrors();
        $line = $this->lastLine();

        $this->edit($line, ['unit_price' => '1000'])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame('1000.00', (string) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
    }

    // ── 2. one grid ──────────────────────────────────────────────────────────────────────

    public function test_every_variant_of_the_grid_has_one_track_per_header_label_and_room_for_99_999_99(): void
    {
        foreach ([[true, true, true], [true, false, true], [true, true, false], [true, false, false], [false, false, false]] as [$prices, $vat, $cost]) {
            $cols = RentalJobCardLineGrid::columns($prices, $vat, $cost);
            $labels = RentalJobCardLineGrid::labels($prices, $vat, $cost);
            $this->assertCount(count($cols), $labels, "tracks and header labels line up (prices={$prices} vat={$vat} cost={$cost})");

            foreach ($labels as $i => $label) {
                if (RentalJobCardLineGrid::isNumericLabel($label)) {
                    $this->assertGreaterThanOrEqual(64, (int) $cols[$i], "{$label} track too narrow");
                }
            }
            // Description is the widest: the only flexible track
            $this->assertSame(1, count(array_filter($cols, fn ($c) => str_contains($c, '1fr'))));
            $this->assertStringContainsString('1fr', $cols[1], 'the description is the flexible column');
        }

        // the money columns are at least 92px wide where a cost is shown: R99,999.99 at 12px is ~66px of text + 18px padding
        $cols = RentalJobCardLineGrid::columns(true, true, true);
        $labels = RentalJobCardLineGrid::labels(true, true, true);
        foreach (['Cost', 'Selling', 'Margin'] as $money) {
            $this->assertGreaterThanOrEqual(92, (int) $cols[array_search($money, $labels, true)]);
        }
    }

    public function test_the_screen_gives_header_lines_and_add_row_the_same_edges_and_right_aligns_the_amounts(): void
    {
        $this->add(['unit_cost' => '789.50'])->assertSessionHasNoErrors();

        $page = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk();
        $html = $page->getContent();

        $this->assertStringContainsString('class="jc-lines-head"', $html, 'the header carries the same 11px inset as a saved line');
        $this->assertStringContainsString('.jc-lines-head { padding: 0 11px; }', $html);
        $this->assertStringContainsString('.jc-addrow { margin: 6px -9px 0;', $html, 'the add row grid starts where a saved line does');
        $this->assertStringContainsString('min-width: 980px', $html, 'a narrow window scrolls the lines sideways instead of squeezing the columns');

        // the same grid-template-columns on the header, every saved line and the add rows (General and task buckets)
        $grid = RentalJobCardLineGrid::gridStyle(true, false, true);
        $this->assertGreaterThanOrEqual(3, substr_count($html, $grid), 'header + a saved line + an add row all use the one track list');
        $this->assertStringContainsString('text-align:right', $html);
        $this->assertStringContainsString('height:30px', $html, 'controls share one height');
    }

    // ── 3. description is free text ──────────────────────────────────────────────────────

    public function test_a_blank_description_defaults_to_the_catalogue_name_and_typed_words_are_kept_on_add_and_edit(): void
    {
        $this->add(['unit_cost' => '100', 'description' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Plumbing', $this->lastLine()->description, 'blank = the catalogue name as the default');

        $this->add(['unit_cost' => '100', 'description' => 'Plumbing parts — tap x 1'])->assertSessionHasNoErrors();
        $line = $this->lastLine();
        $this->assertSame('Plumbing parts — tap x 1', $line->description);

        $this->edit($line, ['description' => 'Plumbing parts — 2 x elbow joints'])->assertSessionHasNoErrors();
        $this->assertSame('Plumbing parts — 2 x elbow joints', $line->fresh()->description);

        // blanking it on edit does not wipe what the quote prints
        $this->edit($line, ['description' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Plumbing parts — 2 x elbow joints', $line->fresh()->description);
    }

    public function test_picking_an_item_offers_its_name_as_a_placeholder_and_never_overwrites_the_agents_words(): void
    {
        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();

        $this->assertStringContainsString('d.placeholder = item.description;', $html);
        $this->assertStringNotContainsString("if (d) d.value = item.description;", $html, 'the old unconditional pre-fill is gone');
        // the Selling box never carries the markup hint any more - that goes in the markup box
        $this->assertStringContainsString('data-markup-hint', $html);
        $this->assertStringContainsString('name="markup_type"', $html);
    }

    // ── the catch-up for card 100 ────────────────────────────────────────────────────────

    public function test_the_command_takes_a_mistyped_hand_selling_off_and_re_derives_it(): void
    {
        $this->add(['unit_cost' => '789.50', 'unit_price' => '10'])->assertSessionHasNoErrors();
        $byHand = $this->lastLine();
        $this->add(['unit_cost' => '100', 'unit_price' => '20'])->assertSessionHasNoErrors(); // R20 = the item's markup value: also a mistyped markup
        $alsoMistyped = $this->lastLine();
        $this->add(['unit_cost' => '100', 'unit_price' => '555'])->assertSessionHasNoErrors(); // a real price typed by hand: never touched
        $real = $this->lastLine();

        $this->artisan('rentals:rederive-catalogue-markup-lines', ['--job-card' => $this->card->id, '--clear-hand' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('10.00', (string) $byHand->fresh()->unit_price, 'a dry run changes nothing');

        $this->artisan('rentals:rederive-catalogue-markup-lines', ['--job-card' => $this->card->id, '--clear-hand' => true, '--line' => $byHand->id])->assertSuccessful();

        $this->assertSame('947.40', (string) $byHand->fresh()->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $byHand->fresh()->selling_basis);
        $this->assertSame('120.00', (string) $alsoMistyped->fresh()->unit_price, 'a hand selling equal to the markup value is a mistyped markup');
        $this->assertSame('555.00', (string) $real->fresh()->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $real->fresh()->selling_basis);
    }
}
