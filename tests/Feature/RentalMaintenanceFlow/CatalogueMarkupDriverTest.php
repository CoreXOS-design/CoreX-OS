<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\User;
use App\Services\Rentals\RentalCrewPricingService;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.39 — a catalogue item is priced by what the user last set: its MARKUP or its fixed SELLING
 * price (`pricing_driver`, stored). A line added from a markup-priced item sells at the LINE's cost + the markup; the catalogue's
 * own (derived, possibly R0.00) selling is never what a markup item prices a line at.
 * Johan's QA1 case (job card 99, item PLUMB-01): item cost 0, markup 20 %, selling 0 -> a line with cost R895.99 must sell at R1,075.19.
 */
final class CatalogueMarkupDriverTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private User $user;

    private RentalJobCard $card;

    private function world(bool $vat = false, string $mode = Agency::VAT_CAPTURE_EXCL): void
    {
        $this->pricingWorld('Markup Driver', [], $vat, $mode);
        $this->user = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage', 'rental_job_cards.view_costs']);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'labour')->firstOrFail()->id,
            'code' => 'PLUMB-01', 'description' => 'Plumbing',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_rental_vat_type_id' => $this->agency->vat_registered ? $this->standardVat()->id : null,
            'is_active' => 1,
        ];
    }

    private function store(array $over = []): RentalCatalogueItem
    {
        $p = $this->payload($over);
        $this->actingAs($this->user)->post(route('corex.rental-catalogue-items.store'), $p)->assertSessionHasNoErrors()->assertRedirect();

        return RentalCatalogueItem::where('code', $p['code'])->firstOrFail();
    }

    /** Johan's item: cost 0, 20 % markup, the selling the screen showed as 0.00. */
    private function plumb(): RentalCatalogueItem
    {
        return $this->store(['cost_excl' => '0.00', 'markup_type' => 'percent', 'markup_value' => '20', 'selling_excl' => '0.00', 'pricing_driver' => 'markup']);
    }

    private function line(RentalCatalogueItem $item, float $cost, ?RentalJobCard $card = null): RentalJobCardLine
    {
        $this->card = $card ?? $this->emptyCard();

        return $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id, 'description' => 'Plumbing job', 'unit_cost' => $cost, 'type' => 'labour']);
    }

    // ── 1. the catalogue save ───────────────────────────────────────────────────────────

    public function test_a_markup_set_last_saves_the_item_with_driver_markup_and_no_real_selling_price(): void
    {
        $this->world();
        $item = $this->plumb();

        $this->assertSame('markup', $item->pricing_driver);
        $this->assertSame('20.0000', (string) $item->markup_value);
        $this->assertSame('percent', $item->markup_type);
        $this->assertNull($item->selling_excl, 'a derived R0.00 is not a price');
        $this->assertNull($item->selling_incl);
        $this->assertNull($item->default_price, 'legacy column follows');
    }

    public function test_a_selling_price_typed_last_saves_the_item_with_driver_selling(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'selling_excl' => 100, 'pricing_driver' => 'selling_excl']);

        $this->assertSame('selling', $item->pricing_driver);
        $this->assertSame('100.00', (string) $item->selling_excl);
        $this->assertSame('17.6471', (string) $item->markup_value);
    }

    public function test_a_markup_with_a_real_cost_keeps_the_markup_as_the_driver(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);

        $this->assertSame('markup', $item->pricing_driver);
        $this->assertSame('102.00', (string) $item->selling_excl);
    }

    public function test_a_markup_with_no_cost_yet_is_still_a_markup_item(): void
    {
        $this->world();
        $item = $this->store(['markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);

        $this->assertSame('markup', $item->pricing_driver);
        $this->assertNull($item->selling_excl);
    }

    public function test_opening_and_saving_a_markup_item_again_does_not_turn_it_into_a_fixed_price_item(): void
    {
        $this->world();
        $item = $this->plumb();

        // what the edit screen loads with...
        $init = $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.edit', $item))->assertOk()->viewData('pricingInit');
        $this->assertSame('markup', $init['driver'], 'the screen loads with the STORED driver, not "selling because a figure exists"');

        // ...and posts straight back unchanged, three times
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->user)->put(route('corex.rental-catalogue-items.update', $item), $this->payload([
                'cost_excl' => '0.00', 'cost_incl' => '0.00', 'selling_excl' => '0.00', 'selling_incl' => '0.00',
                'markup_type' => $init['markup_type'], 'markup_value' => '20.00', 'pricing_driver' => $init['driver'], 'cost_basis' => 'excl',
            ]))->assertSessionHasNoErrors();
            $item->refresh();
            $this->assertSame('markup', $item->pricing_driver, "driver flipped on save #{$i}");
            $this->assertSame('20.0000', (string) $item->markup_value);
            $this->assertNull($item->selling_excl);
        }
    }

    public function test_someone_who_cannot_see_costs_saving_a_markup_item_does_not_flip_it_to_fixed_price(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);
        $without = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage']);

        // renames it, selling box posted back exactly as shown
        $this->actingAs($without)->put(route('corex.rental-catalogue-items.update', $item), $this->payload(['description' => 'Renamed', 'selling_excl' => '102.00', 'selling_incl' => '102.00', 'pricing_driver' => 'selling_excl']))->assertSessionHasNoErrors();
        $this->assertSame('markup', $item->fresh()->pricing_driver);

        // typing a different selling price IS their word
        $this->actingAs($without)->put(route('corex.rental-catalogue-items.update', $item), $this->payload(['selling_excl' => '150.00', 'pricing_driver' => 'selling_excl']))->assertSessionHasNoErrors();
        $this->assertSame('selling', $item->fresh()->pricing_driver);
        $this->assertSame('150.00', (string) $item->fresh()->selling_excl);
    }

    // ── 2. the job card line ────────────────────────────────────────────────────────────

    public function test_johans_case_a_line_from_the_markup_item_at_cost_895_99_sells_at_1075_19(): void
    {
        $this->world();
        $line = $this->line($this->plumb(), 895.99);

        $this->assertSame('895.99', (string) $line->unit_cost);
        $this->assertSame('1075.19', (string) $line->unit_price);
        $this->assertSame('1075.19', (string) $line->line_total);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $line->selling_basis);
        $this->assertSame('markup 20 %', RentalPricingService::basisLabel($line, $this->card));
        $this->assertGreaterThan((float) $line->cost_total, (float) $line->line_total, 'a positive margin');
    }

    public function test_the_line_prices_off_the_catalogue_cost_when_it_has_none_of_its_own(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);
        $line = $this->officeLine($this->emptyCard(), ['rental_catalogue_item_id' => $item->id, 'description' => 'No typed cost', 'type' => 'labour']);

        $this->assertSame('85.00', (string) $line->unit_cost, 'prefilled from the catalogue');
        $this->assertSame('102.00', (string) $line->unit_price);
    }

    public function test_a_rand_markup_adds_the_amount_to_the_lines_cost(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 10, 'markup_type' => 'rand', 'markup_value' => 5, 'pricing_driver' => 'markup']);
        $line = $this->line($item, 100.0);

        $this->assertSame('105.00', (string) $line->unit_price);
        $this->assertSame('+ R5.00', RentalPricingService::basisLabel($line, $this->card));
    }

    public function test_a_rand_markup_is_converted_for_an_agency_that_captures_incl_vat(): void
    {
        $this->world(true, Agency::VAT_CAPTURE_INCL);
        $item = $this->store(['cost_excl' => 10, 'markup_type' => 'rand', 'markup_value' => 5, 'pricing_driver' => 'markup']);
        $line = $this->line($item, 100.0);

        $this->assertSame('105.75', (string) $line->unit_price, 'R5 excl = R5.75 on a line that holds incl-VAT figures');
    }

    public function test_a_fixed_price_item_still_prices_the_line_at_its_catalogue_selling(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'selling_excl' => 450, 'pricing_driver' => 'selling_excl']);
        $line = $this->line($item, 100.0);

        $this->assertSame('450.00', (string) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_PRICE, $line->selling_basis);
        $this->assertSame('catalogue', RentalPricingService::basisLabel($line, $this->card));
    }

    public function test_an_office_edit_of_the_cost_re_derives_the_selling(): void
    {
        $this->world();
        $line = $this->line($this->plumb(), 895.99);

        app(RentalJobCardService::class)->updateLine($this->card, $line, ['unit_cost' => '1000', 'unit_price' => '1075.19'], $this->admin);

        $line->refresh();
        $this->assertSame('1200.00', (string) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $line->selling_basis);
    }

    public function test_a_selling_set_by_hand_survives_a_cost_edit(): void
    {
        $this->world();
        $line = $this->line($this->plumb(), 895.99);

        app(RentalJobCardService::class)->updateLine($this->card, $line, ['unit_price' => '1500'], $this->admin);
        $line->refresh();
        $this->assertSame('1500.00', (string) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);

        app(RentalJobCardService::class)->updateLine($this->card, $line, ['unit_cost' => '2000', 'unit_price' => '1500'], $this->admin);
        $this->assertSame('1500.00', (string) $line->fresh()->unit_price, 'set by hand: a cost edit does not move it');
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->fresh()->selling_basis);
    }

    public function test_a_crew_actual_cost_accepted_re_derives_the_selling(): void
    {
        $this->world();
        // a PART: the crew types what it cost (labour is hours x the catalogue rate, priced by the office)
        $partType = RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id;
        $item = $this->store(['code' => 'PIPE-01', 'rental_catalogue_item_type_id' => $partType, 'cost_excl' => '0.00', 'markup_type' => 'percent', 'markup_value' => '20', 'pricing_driver' => 'markup']);
        $card = $this->emptyCard();
        $line = $this->awaitingLine($card, ['rental_catalogue_item_id' => $item->id, 'unit_cost' => 250, 'type' => 'part', 'description' => 'Crew pipe']);
        $this->assertNull($line->unit_price, 'nothing priced until the office accepts');

        app(RentalCrewPricingService::class)->acceptAll($card, $this->admin);

        $line->refresh();
        $this->assertSame('300.00', (string) $line->unit_price, '250 x 1.20');
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $line->selling_basis);
    }

    public function test_back_to_automatic_returns_to_the_catalogue_markup_not_the_catalogue_selling(): void
    {
        $this->world();
        // a markup item that DOES carry a stored (derived) selling of 102.00 from its own cost of 85
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);
        $this->assertSame('102.00', (string) $item->selling_excl);
        $line = $this->line($item, 100.0);

        app(RentalJobCardService::class)->updateLine($this->card, $line, ['unit_price' => '500'], $this->admin);
        $this->assertSame('500.00', (string) $line->fresh()->unit_price);

        app(RentalJobCardService::class)->updateLine($this->card, $line->fresh(), ['back_to_auto' => true], $this->admin);
        $line->refresh();
        $this->assertSame('120.00', (string) $line->unit_price, 'the line cost 100 + the catalogue 20 % - not the catalogue selling 102');
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $line->selling_basis);
    }

    public function test_the_lines_own_markup_overrides_the_catalogue_markup(): void
    {
        $this->world();
        $line = $this->line($this->plumb(), 100.0);

        app(RentalJobCardService::class)->updateLine($this->card, $line, ['markup_type' => 'percent', 'markup_value' => '50'], $this->admin);

        $this->assertSame('150.00', (string) $line->fresh()->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $line->fresh()->selling_basis);
    }

    public function test_with_no_cost_anywhere_a_markup_item_leaves_the_selling_blank_not_zero(): void
    {
        $this->world();
        $item = $this->store(['markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);
        $line = $this->officeLine($this->emptyCard(), ['rental_catalogue_item_id' => $item->id, 'description' => 'Cost unknown', 'type' => 'labour']);

        $this->assertNull($line->unit_price);
        $this->assertNull($line->line_total);
    }

    // ── the catch-up command ────────────────────────────────────────────────────────────

    public function test_the_command_re_derives_old_catalogue_priced_lines_and_nothing_else(): void
    {
        $this->world();
        // the item as it was when Johan's line was added: a fixed (zero) selling price
        $item = $this->store(['cost_excl' => '0.00', 'selling_excl' => '0.00', 'pricing_driver' => 'selling_excl', 'markup_type' => 'percent', 'markup_value' => 20]);
        $this->assertSame('selling', $item->pricing_driver);
        $card = $this->emptyCard();
        $stale = $this->line($item, 895.99, $card);
        $this->assertSame('0.00', (string) $stale->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_PRICE, $stale->selling_basis);
        $byHand = $this->officeLine($card, ['rental_catalogue_item_id' => $item->id, 'description' => 'By hand', 'unit_cost' => 100, 'unit_price' => 777, 'type' => 'labour']);
        $closedCard = $this->emptyCard();
        $closedLine = $this->line($item, 50.0, $closedCard);
        $closedCard->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        // the item is then (correctly) a markup item
        $this->actingAs($this->user)->put(route('corex.rental-catalogue-items.update', $item), $this->payload([
            'cost_excl' => '0.00', 'markup_type' => 'percent', 'markup_value' => '20', 'pricing_driver' => 'markup',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('markup', $item->fresh()->pricing_driver);

        // refuses to run without saying which cards
        $this->artisan('rentals:rederive-catalogue-markup-lines')->assertFailed();

        // a dry run changes nothing
        $this->artisan('rentals:rederive-catalogue-markup-lines', ['--all' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('0.00', (string) $stale->fresh()->unit_price);

        // the real run, for this card only
        $this->artisan('rentals:rederive-catalogue-markup-lines', ['--job-card' => $card->id])->assertSuccessful();
        $this->assertSame('1075.19', (string) $stale->fresh()->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_CATALOGUE_MARKUP, $stale->fresh()->selling_basis);
        $this->assertSame('777.00', (string) $byHand->fresh()->unit_price, 'a price set by hand is never moved');
        $this->assertSame('0.00', (string) $closedLine->fresh()->unit_price, 'another card is not touched when one card is named');
        $this->assertSame('1075.19', (string) $card->fresh()->lines()->where('id', $stale->id)->value('line_total'));

        // --all still leaves a completed card alone
        $this->artisan('rentals:rederive-catalogue-markup-lines', ['--all' => true])->assertSuccessful();
        $this->assertSame('0.00', (string) $closedLine->fresh()->unit_price, 'a completed card never changes');
    }

    /** Audit 2026-10-20 M4: a card quoted to / approved by the owner is never repriced; a card that is changed says so in its history. */
    public function test_the_command_skips_a_quoted_or_approved_card_and_writes_a_history_line_for_a_card_it_changes(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => '0.00', 'selling_excl' => '0.00', 'pricing_driver' => 'selling_excl', 'markup_type' => 'percent', 'markup_value' => 20]);
        $open = $this->emptyCard();
        $openLine = $this->line($item, 100.0, $open);
        $quoted = $this->emptyCard();
        $quotedLine = $this->line($item, 100.0, $quoted);
        $approved = $this->emptyCard();
        $approvedLine = $this->line($item, 100.0, $approved);
        foreach ([$openLine, $quotedLine, $approvedLine] as $l) {
            $this->assertSame('0.00', (string) $l->unit_price);
        }

        // the owner was sent a quote for one card ...
        \App\Models\RentalWorkOrderQuote::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $quoted->rental_work_order_id, 'rental_job_card_id' => $quoted->id,
            'amount' => 120, 'quote_date' => now()->toDateString(), 'detail_text' => 'x', 'captured_by_user_id' => $this->admin->id,
        ]);
        // ... and approved an amount on another
        $approved->workOrder()->withoutGlobalScopes()->first()->forceFill(['approved_amount' => 120, 'approval_basis' => \App\Models\RentalWorkOrder::BASIS_OWNER_DECISION])->save();
        $totalBefore = [$quoted->fresh()->total_amount, $approved->fresh()->total_amount];

        $this->actingAs($this->user)->put(route('corex.rental-catalogue-items.update', $item), $this->payload([
            'cost_excl' => '0.00', 'markup_type' => 'percent', 'markup_value' => '20', 'pricing_driver' => 'markup',
        ]))->assertSessionHasNoErrors();

        $this->artisan('rentals:rederive-catalogue-markup-lines', ['--all' => true])
            ->expectsOutputToContain("card {$quoted->id}: its quote was sent to the owner")
            ->expectsOutputToContain("card {$approved->id}: the owner approved an amount")
            ->assertSuccessful();

        $this->assertSame('0.00', (string) $quotedLine->fresh()->unit_price, 'a quoted card keeps the price the owner was shown');
        $this->assertSame('0.00', (string) $approvedLine->fresh()->unit_price, 'an approved card keeps the amount the owner approved');
        $this->assertSame($totalBefore, [$quoted->fresh()->total_amount, $approved->fresh()->total_amount]);
        $this->assertSame(0, $quoted->updates()->where('update_type', 'line_priced')->count());

        // the open card WAS changed, and its history says what moved
        $this->assertSame('120.00', (string) $openLine->fresh()->unit_price);
        $history = $open->updates()->where('update_type', 'line_priced')->get();
        $this->assertCount(1, $history);
        $this->assertStringContainsString('Plumbing job', $history->first()->note);
        $this->assertStringContainsString('120', $history->first()->note);
    }

    // ── the legacy writers ──────────────────────────────────────────────────────────────

    public function test_a_writer_that_hands_over_a_price_makes_the_item_a_fixed_price_item(): void
    {
        $this->world();
        $item = $this->catalogueItem('IMPORTED', 'part', 120.0, 60.0);

        $this->assertSame('selling', $item->fresh()->pricing_driver);
        $line = $this->line($item, 70.0);
        $this->assertSame('120.00', (string) $line->unit_price);
    }
}
