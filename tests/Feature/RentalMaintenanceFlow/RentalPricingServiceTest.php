<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.4 (Build 1) — RentalPricingService: the six selling rules IN ORDER, percent and amount
 * rounding, incl-VAT capture, reprice that never touches the office's own words, legacy lines with no cost, margin on the
 * excl-VAT figures, and "lines without cost" so a partial margin is never presented as complete.
 */
final class RentalPricingServiceTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalPricingService $pricing;
    private RentalJobCardService $cards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Pricing Rules');
        $this->pricing = app(RentalPricingService::class);
        $this->cards = app(RentalJobCardService::class);
    }

    public function test_rule_1_a_price_the_office_typed_wins_over_every_markup(): void
    {
        $card = $this->emptyCard();
        $card->forceFill(['markup_all_percent' => 50])->save();
        $line = $this->officeLine($card, ['description' => 'Basin', 'unit_cost' => 100, 'unit_price' => 111, 'quantity' => 2]);

        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
        $this->assertEquals(111.00, (float) $line->unit_price);
        $this->assertEquals(222.00, (float) $line->line_total);
        $this->assertEquals(200.00, (float) $line->cost_total, 'cost_total follows qty x cost');
    }

    public function test_rule_2_line_markup_percent_and_amount(): void
    {
        $card = $this->emptyCard();
        $pct = $this->officeLine($card, ['description' => 'Pct', 'unit_cost' => 100, 'markup_type' => 'percent', 'markup_value' => 25, 'quantity' => 2]);
        $amt = $this->officeLine($card, ['description' => 'Amt', 'unit_cost' => 10, 'markup_type' => 'amount', 'markup_value' => 7, 'quantity' => 3]);

        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $pct->selling_basis);
        $this->assertEquals(125.00, (float) $pct->unit_price);
        $this->assertEquals(250.00, (float) $pct->line_total);

        // an AMOUNT is a set sum on top of the whole LINE (3 x 10 + 7), not per unit; unit price is display only
        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $amt->selling_basis);
        $this->assertEquals(37.00, (float) $amt->line_total);
        $this->assertEquals(12.33, (float) $amt->unit_price);
    }

    public function test_rule_3_the_parts_or_labour_percent_beats_the_all_lines_percent_and_rule_4_covers_the_rest(): void
    {
        $card = $this->emptyCard();
        $card->forceFill(['markup_parts_percent' => 20, 'markup_all_percent' => 10])->save();
        $part = $this->officeLine($card, ['description' => 'Part', 'type' => 'part', 'unit_cost' => 100]);
        $labour = $this->officeLine($card, ['description' => 'Labour', 'type' => 'labour', 'unit_cost' => 100]);

        $this->assertSame(RentalJobCardLine::BASIS_JOB_MARKUP, $part->selling_basis);
        $this->assertEquals(120.00, (float) $part->unit_price, 'parts % (20) beats all-lines % (10)');
        $this->assertEquals(110.00, (float) $labour->unit_price, 'no labour %, so the all-lines % (rule 4) applies');
    }

    public function test_rule_5_a_catalogue_price_beats_the_agency_default_markup_and_needs_no_cost(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['default_parts_markup_percent' => 30]);
        $item = $this->catalogueItem('TAP', 'part', 80.00);
        $card = $this->emptyCard();

        $noCost = $this->officeLine($card, ['rental_catalogue_item_id' => $item->id, 'description' => 'Tap']);
        $withCost = $this->officeLine($card, ['rental_catalogue_item_id' => $item->id, 'description' => 'Tap 2', 'unit_cost' => 50]);

        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_PRICE, $noCost->selling_basis);
        $this->assertEquals(80.00, (float) $noCost->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_PRICE, $withCost->selling_basis, 'rule 5 comes before rule 6');
        $this->assertEquals(80.00, (float) $withCost->unit_price);
    }

    public function test_rule_6_the_agency_default_markup_for_that_kind_of_line(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['default_parts_markup_percent' => 15, 'default_labour_markup_percent' => 40]);
        $card = $this->emptyCard();
        $part = $this->officeLine($card, ['description' => 'Part', 'type' => 'part', 'unit_cost' => 100]);
        $labour = $this->officeLine($card, ['description' => 'Labour', 'type' => 'labour', 'unit_cost' => 100]);

        $this->assertSame(RentalJobCardLine::BASIS_AGENCY_DEFAULT, $part->selling_basis);
        $this->assertEquals(115.00, (float) $part->unit_price);
        $this->assertEquals(140.00, (float) $labour->unit_price);
    }

    public function test_the_neutral_default_prices_at_cost_and_says_no_markup_applied(): void
    {
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Part', 'unit_cost' => 80]);

        $this->assertEquals(80.00, (float) $line->unit_price, 'a fresh agency (0 % default) charges exactly the cost');
        $this->assertSame('no markup applied', RentalPricingService::basisLabel($line, $card));
        $this->assertTrue($this->pricing->resolveSelling($line, $card)->noMarkupApplied);
    }

    public function test_a_line_with_no_cost_and_no_price_is_blank_never_zero(): void
    {
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Mystery']);

        $this->assertNull($line->unit_price);
        $this->assertNull($line->line_total);
        $this->assertNull($line->unit_cost);
        $this->assertNull($line->cost_total, 'no cost is ever back-filled or invented');
    }

    public function test_percent_rounding_is_per_unit_then_per_line(): void
    {
        $card = $this->emptyCard();
        // 33.33 x 1.125 = 37.49625 -> 37.50 per unit; 3 units -> 112.50 (not 112.49)
        $line = $this->officeLine($card, ['description' => 'Round', 'unit_cost' => 33.33, 'markup_type' => 'percent', 'markup_value' => 12.5, 'quantity' => 3]);

        $this->assertEquals(37.50, (float) $line->unit_price);
        $this->assertEquals(112.50, (float) $line->line_total);
        $this->assertEquals(99.99, (float) $line->cost_total);
    }

    public function test_apply_job_markup_reprices_automatic_lines_but_never_manual_or_line_markup_ones(): void
    {
        $card = $this->emptyCard();
        $auto = $this->officeLine($card, ['description' => 'Auto', 'unit_cost' => 100]);
        $manual = $this->officeLine($card, ['description' => 'Manual', 'unit_cost' => 100, 'unit_price' => 300]);
        $own = $this->officeLine($card, ['description' => 'Own', 'unit_cost' => 100, 'markup_type' => 'percent', 'markup_value' => 50]);

        $this->pricing->applyJobMarkup($card, 'all', 20, $this->admin);

        $this->assertEquals(120.00, (float) $auto->fresh()->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_JOB_MARKUP, $auto->fresh()->selling_basis);
        $this->assertEquals(300.00, (float) $manual->fresh()->unit_price, 'a hand-typed price is never repriced');
        $this->assertEquals(150.00, (float) $own->fresh()->unit_price, "a line's own markup is never repriced by a card markup");
        $this->assertEquals(570.00, (float) $card->fresh()->total_amount, 'the card total follows');

        // one history row naming the change, and clearing it logs too
        $this->assertTrue($card->updates()->where('update_type', 'markup_set')->where('note', 'All-lines markup set to 20 %')->exists());
        $this->pricing->applyJobMarkup($card->fresh(), 'all', null, $this->admin);
        $this->assertEquals(100.00, (float) $auto->fresh()->unit_price, 'cleared: back to the agency default (0 %)');
        $this->assertTrue($card->updates()->where('note', 'All-lines markup cleared')->exists());
    }

    public function test_apply_job_markup_refuses_a_negative_a_huge_or_a_wrong_scope(): void
    {
        $card = $this->emptyCard();
        foreach ([[-1, 'parts'], [1001, 'parts']] as [$pct, $scope]) {
            try {
                $this->pricing->applyJobMarkup($card, $scope, (float) $pct, $this->admin);
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('between 0 and 1000', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->pricing->applyJobMarkup($card, 'everything', 5.0, $this->admin);
    }

    public function test_a_closed_card_refuses_a_markup(): void
    {
        $card = $this->emptyCard(['status' => 'completed']);
        $this->expectException(\LogicException::class);
        $this->pricing->applyJobMarkup($card, 'all', 10.0, $this->admin);
    }

    public function test_margin_is_taken_on_the_lines_with_a_cost_and_flags_the_ones_without(): void
    {
        $card = $this->emptyCard();
        $this->officeLine($card, ['description' => 'Costed', 'unit_cost' => 100, 'markup_type' => 'percent', 'markup_value' => 20]);
        // a legacy-style line: a typed price, no cost recorded
        $this->officeLine($card, ['description' => 'Legacy', 'unit_price' => 500]);

        $m = $this->pricing->marginFor($card->fresh('lines'));

        $this->assertEquals(100.00, $m['costExcl']);
        $this->assertEquals(620.00, $m['sellingExcl'], 'the whole job at selling');
        $this->assertEquals(20.00, $m['marginExcl'], 'margin only over the line that has a cost');
        $this->assertEquals(16.7, $m['marginPct']);
        $this->assertSame(1, $m['linesWithoutCost']);
        $this->assertSame(1, $m['marginableLines']);
        $this->assertTrue($m['partial'], 'a partial margin is never presented as complete');
    }

    public function test_a_card_with_no_costs_at_all_has_no_margin_and_says_so(): void
    {
        $card = $this->emptyCard();
        $this->officeLine($card, ['description' => 'Legacy', 'unit_price' => 500]);

        $m = $this->pricing->marginFor($card->fresh('lines'));

        $this->assertSame(0, $m['marginableLines']);
        $this->assertNull($m['marginPct']);
        $this->assertSame(1, $m['linesWithoutCost']);
        $this->assertEquals(0.0, $m['costExcl']);
    }

    public function test_incl_vat_capture_margin_is_read_on_the_excl_figures(): void
    {
        $this->pricingWorld('Pricing Incl', [], true, Agency::VAT_CAPTURE_INCL);
        $card = $this->emptyCard();
        // cost 115 incl (= 100 excl), +20 % -> selling 138 incl (= 120 excl); standard 15 % VAT
        $this->officeLine($card, ['description' => 'Pipe', 'unit_cost' => 115, 'markup_type' => 'percent', 'markup_value' => 20, 'rental_vat_type_id' => $this->standardVat()->id]);

        $m = $this->pricing->marginFor($card->fresh('lines'));

        $this->assertEquals(100.00, $m['costExcl']);
        $this->assertEquals(120.00, $m['sellingExcl']);
        $this->assertEquals(20.00, $m['marginExcl']);
        $this->assertEquals(16.7, $m['marginPct']);
    }

    public function test_pricing_off_for_the_agency_means_no_cost_no_selling_no_margin(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['capture_prices_on_job_cards' => false]);
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Free', 'unit_cost' => 90, 'unit_price' => 120]);

        $this->assertNull($line->unit_cost);
        $this->assertNull($line->unit_price);
        $this->assertNull($line->line_total);
        $this->assertNull($card->fresh()->total_cost);
    }

    public function test_editing_a_line_reprices_it_and_logs_a_priced_row(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['default_parts_markup_percent' => 10]);
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Part', 'unit_cost' => 100]);
        $this->assertEquals(110.00, (float) $line->unit_price);

        // the edit form posts the CURRENT selling price back untouched; only the cost moved
        $this->cards->updateLine($card, $line, ['unit_cost' => 200, 'unit_price' => '110.00', 'quantity' => 2], $this->admin);

        $line->refresh();
        $this->assertEquals(220.00, (float) $line->unit_price, 'an automatic line follows its cost');
        $this->assertEquals(440.00, (float) $line->line_total);
        $this->assertEquals(400.00, (float) $line->cost_total);
        $this->assertSame(RentalJobCardLine::BASIS_AGENCY_DEFAULT, $line->selling_basis);
        $this->assertTrue($card->updates()->where('update_type', 'line_priced')->exists());
    }

    public function test_typing_a_different_price_makes_it_the_offices_own_word_and_back_to_automatic_undoes_it(): void
    {
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Part', 'unit_cost' => 100]);

        $this->cards->updateLine($card, $line, ['unit_price' => '175.00'], $this->admin);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->fresh()->selling_basis);
        $this->assertEquals(175.00, (float) $line->fresh()->unit_price);

        $this->cards->updateLine($card, $line->fresh(), ['back_to_auto' => true], $this->admin);
        $this->assertEquals(100.00, (float) $line->fresh()->unit_price, 'automatic again: the 0 % agency default');
        $this->assertSame(RentalJobCardLine::BASIS_AGENCY_DEFAULT, $line->fresh()->selling_basis);
    }

    public function test_setting_a_line_markup_replaces_a_typed_price(): void
    {
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Part', 'unit_cost' => 100, 'unit_price' => 400]);

        $this->cards->updateLine($card, $line, ['markup_type' => 'percent', 'markup_value' => 30, 'unit_price' => '400.00'], $this->admin);

        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $line->fresh()->selling_basis);
        $this->assertEquals(130.00, (float) $line->fresh()->unit_price);
    }

    public function test_the_card_total_and_total_cost_are_cached_from_accepted_lines(): void
    {
        $card = $this->emptyCard();
        $this->officeLine($card, ['description' => 'A', 'unit_cost' => 10, 'unit_price' => 15, 'quantity' => 2]);
        $this->officeLine($card, ['description' => 'B', 'unit_price' => 100]);

        $card->refresh();
        $this->assertEquals(130.00, (float) $card->total_amount);
        $this->assertEquals(20.00, (float) $card->total_cost, 'cost total is the costed lines only; the legacy line adds nothing');
    }

    public function test_an_existing_line_with_a_typed_price_is_left_exactly_as_it_was(): void
    {
        // the pre-maintenance-flow shape: manual basis, typed price, no cost — nothing may move it.
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['description' => 'Old', 'unit_price' => 450, 'quantity' => 2]);
        $this->pricing->repriceCard($card);
        $this->pricing->applyJobMarkup($card, 'all', 25, $this->admin);

        $line->refresh();
        $this->assertEquals(450.00, (float) $line->unit_price);
        $this->assertEquals(900.00, (float) $line->line_total);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
        $this->assertNull($line->cost_total);
    }
}
