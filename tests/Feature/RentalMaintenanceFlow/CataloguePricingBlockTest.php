<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\CatalogueItemPricing;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.38 — the catalogue item's Cost / Markup / Selling block: the shared maths (the same
 * rules the screen runs in Alpine), what the form stores, the agency default markup, the job-card prefill, the list columns,
 * and the standing rule that the crew is never handed a selling price.
 */
final class CataloguePricingBlockTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private User $user;

    private function world(bool $vat = true): void
    {
        $this->pricingWorld('Catalogue Pricing', [], $vat);
        $this->user = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage', 'rental_job_cards.view_costs']);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'PB-1', 'description' => 'Pricing block item',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_rental_vat_type_id' => $this->agency->vat_registered ? $this->standardVat()->id : null,
            'is_active' => 1,
        ];
    }

    private function store(array $over = [], ?User $as = null): RentalCatalogueItem
    {
        $payload = $this->payload($over);
        $this->actingAs($as ?? $this->user)->post(route('corex.rental-catalogue-items.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('corex.rental-catalogue-items.index'));

        return RentalCatalogueItem::where('code', $payload['code'])->firstOrFail();
    }

    // ── the maths ─────────────────────────────────────────────────────────────────────────

    public function test_cost_85_with_20_percent_markup_at_standard_vat(): void
    {
        $r = app(CatalogueItemPricing::class)->resolve(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20], 15.0, 'excl', 'markup');

        $this->assertSame(85.0, $r['cost_excl']);
        $this->assertSame(97.75, $r['cost_incl']);
        $this->assertSame(102.0, $r['selling_excl']);
        $this->assertSame(117.3, $r['selling_incl']);
    }

    public function test_typing_the_selling_incl_recalculates_excl_and_the_markup_from_cost(): void
    {
        $r = app(CatalogueItemPricing::class)->resolve(['cost_excl' => 85, 'selling_incl' => 115, 'markup_type' => 'percent', 'markup_value' => 20], 15.0, 'excl', 'selling_incl');

        $this->assertSame(100.0, $r['selling_excl']);
        $this->assertSame(115.0, $r['selling_incl']);
        $this->assertSame(17.65, round($r['markup_value'], 2));
        $this->assertSame(17.6471, $r['markup_value'], 'kept to 4 decimals');
    }

    public function test_the_rand_markup_is_worked_on_cost_excl_and_converting_the_unit_never_moves_the_selling_price(): void
    {
        $p = app(CatalogueItemPricing::class);
        $r = $p->resolve(['cost_excl' => 85, 'markup_type' => 'rand', 'markup_value' => 17], 15.0, 'excl', 'markup');
        $this->assertSame(102.0, $r['selling_excl']);

        $this->assertSame(20.0, $p->convertMarkup(85.0, 17.0, 'rand', 'percent'));
        $this->assertSame(17.0, $p->convertMarkup(85.0, 20.0, 'percent', 'rand'));
    }

    public function test_no_vat_makes_excl_equal_incl_on_every_row(): void
    {
        $r = app(CatalogueItemPricing::class)->resolve(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20], 0.0, 'excl', 'markup');

        $this->assertSame([85.0, 85.0, 102.0, 102.0], [$r['cost_excl'], $r['cost_incl'], $r['selling_excl'], $r['selling_incl']]);
    }

    public function test_typing_the_cost_incl_works_back_to_excl_and_a_custom_rate_is_honoured(): void
    {
        $r = app(CatalogueItemPricing::class)->resolve(['cost_incl' => 110, 'markup_type' => 'rand', 'markup_value' => 0], 10.0, 'incl', 'markup');

        $this->assertSame([100.0, 110.0, 100.0, 110.0], [$r['cost_excl'], $r['cost_incl'], $r['selling_excl'], $r['selling_incl']]);
    }

    public function test_a_selling_price_below_cost_is_allowed(): void
    {
        $r = app(CatalogueItemPricing::class)->resolve(['cost_excl' => 100, 'selling_excl' => 80, 'markup_type' => 'percent'], 15.0, 'excl', 'selling_excl');

        $this->assertSame(80.0, $r['selling_excl']);
        $this->assertSame(-20.0, $r['markup_value']);
    }

    public function test_a_selling_price_with_no_cost_has_no_markup(): void
    {
        $r = app(CatalogueItemPricing::class)->resolve(['selling_excl' => 50, 'markup_type' => 'percent', 'markup_value' => 20], 15.0, 'excl', 'selling_excl');

        $this->assertNull($r['markup_value']);
        $this->assertNull($r['cost_excl']);
    }

    // ── what the form stores ─────────────────────────────────────────────────────────────

    public function test_catalogue_item_scenario_cost_85_markup_20_standard_vat_then_selling_incl_115(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);

        $this->assertSame(['85.00', '97.75', '102.00', '117.30'], [(string) $item->cost_excl, (string) $item->cost_incl, (string) $item->selling_excl, (string) $item->selling_incl]);
        $this->assertSame('percent', $item->markup_type);
        $this->assertSame('20.0000', (string) $item->markup_value);
        $this->assertSame('102.00', (string) $item->default_price, 'legacy column kept in step');
        $this->assertSame('85.00', (string) $item->default_cost, 'legacy column kept in step');

        $this->actingAs($this->user)->put(route('corex.rental-catalogue-items.update', $item), $this->payload([
            'cost_excl' => 85, 'selling_incl' => 115, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'selling_incl',
            'default_rental_vat_type_id' => $this->standardVat()->id,
        ]))->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('100.00', (string) $item->selling_excl);
        $this->assertSame('115.00', (string) $item->selling_incl);
        $this->assertSame('17.65', number_format((float) $item->markup_value, 2));
    }

    public function test_only_the_field_last_edited_is_trusted_by_the_server(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 100, 'cost_incl' => 1, 'selling_excl' => 200, 'selling_incl' => 99999, 'pricing_driver' => 'selling_excl']);

        $this->assertSame('115.00', (string) $item->cost_incl, 'cost incl worked out from cost excl, not taken from the forged box');
        $this->assertSame('230.00', (string) $item->selling_incl);
    }

    public function test_a_no_vat_type_stores_excl_equal_incl(): void
    {
        $this->world();
        $noVat = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_FIXED)->firstOrFail();
        $item = $this->store(['cost_excl' => 85, 'selling_excl' => 100, 'default_rental_vat_type_id' => $noVat->id]);

        $this->assertSame(['85.00', '85.00', '100.00', '100.00'], [(string) $item->cost_excl, (string) $item->cost_incl, (string) $item->selling_excl, (string) $item->selling_incl]);
    }

    public function test_a_custom_vat_rate_is_used_and_only_kept_for_the_custom_type(): void
    {
        $this->world();
        $custom = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_CUSTOM_PER_LINE)->firstOrFail();
        $item = $this->store(['selling_excl' => 100, 'default_rental_vat_type_id' => $custom->id, 'default_custom_vat_rate' => 10]);
        $this->assertSame('110.00', (string) $item->selling_incl);
        $this->assertSame('10.00', (string) $item->default_custom_vat_rate);

        $this->actingAs($this->user)->put(route('corex.rental-catalogue-items.update', $item), $this->payload([
            'selling_excl' => 100, 'default_rental_vat_type_id' => $this->standardVat()->id, 'default_custom_vat_rate' => 10,
        ]))->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->default_custom_vat_rate);
        $this->assertSame('115.00', (string) $item->fresh()->selling_incl);
    }

    public function test_numbers_must_not_be_negative_but_selling_below_cost_is_saved(): void
    {
        $this->world();
        foreach (['cost_excl', 'selling_excl', 'selling_incl', 'markup_value'] as $field) {
            $this->actingAs($this->user)->post(route('corex.rental-catalogue-items.store'), $this->payload(['code' => 'NEG-' . $field, $field => -1]))->assertSessionHasErrors($field);
        }

        $item = $this->store(['code' => 'LOSS', 'cost_excl' => 100, 'selling_excl' => 80]);
        $this->assertSame('80.00', (string) $item->selling_excl);
        $this->assertSame('-20.0000', (string) $item->markup_value);
    }

    public function test_commas_and_spaces_in_a_typed_amount_are_absorbed(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => '1 250,50', 'selling_excl' => '2 000,00']);

        $this->assertSame('1250.50', (string) $item->cost_excl);
        $this->assertSame('2000.00', (string) $item->selling_excl);
    }

    public function test_an_agency_that_is_not_vat_registered_holds_excl_equal_incl(): void
    {
        $this->world(false);
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);

        $this->assertSame(['85.00', '85.00', '102.00', '102.00'], [(string) $item->cost_excl, (string) $item->cost_incl, (string) $item->selling_excl, (string) $item->selling_incl]);
    }

    public function test_someone_who_cannot_see_costs_never_gets_cost_or_markup_on_the_page_and_cannot_change_them(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);
        $without = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage']);

        $page = $this->actingAs($without)->get(route('corex.rental-catalogue-items.edit', $item))->assertOk();
        $page->assertDontSee('name="cost_excl"', false)->assertDontSee('name="markup_value"', false)->assertSee('name="selling_excl"', false);
        $init = $page->viewData('pricingInit');
        $this->assertNull($init['cost_excl']);
        $this->assertNull($init['markup_value']);
        $this->assertStringNotContainsString('85', json_encode(array_intersect_key($init, array_flip(['cost_excl', 'cost_incl', 'markup_value']))));

        // a hand-made POST carrying a cost and a markup is ignored; the selling price they type recalculates the markup from the STORED cost
        $this->actingAs($without)->put(route('corex.rental-catalogue-items.update', $item), $this->payload([
            'code' => 'PB-1', 'cost_excl' => 1, 'markup_value' => 99, 'selling_excl' => 170, 'pricing_driver' => 'selling_excl',
        ]))->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('85.00', (string) $item->cost_excl);
        $this->assertSame('170.00', (string) $item->selling_excl);
        $this->assertSame('100.00', number_format((float) $item->markup_value, 2));
    }

    public function test_the_edit_and_create_screens_render_the_pricing_block(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'selling_excl' => 102]);

        foreach ([route('corex.rental-catalogue-items.create'), route('corex.rental-catalogue-items.edit', $item)] as $url) {
            $this->actingAs($this->user)->get($url)->assertOk()
                ->assertSee('Excl VAT')->assertSee('Incl VAT')->assertSee('Cost')->assertSee('Markup')->assertSee('Selling')
                ->assertSee('name="pricing_driver"', false)->assertSee('name="default_rental_vat_type_id"', false);
        }
    }

    public function test_a_failed_save_comes_back_with_what_was_typed(): void
    {
        $this->world();
        $create = route('corex.rental-catalogue-items.create');
        $this->actingAs($this->user)->from($create)->post(route('corex.rental-catalogue-items.store'), $this->payload(['code' => '', 'cost_excl' => '77.70', 'selling_excl' => '88.80']))
            ->assertRedirect($create)->assertSessionHasErrors('code');

        $this->actingAs($this->user)->get($create)->assertOk()->assertSee('77.70', false)->assertSee('88.80', false);
    }

    // ── the legacy columns ───────────────────────────────────────────────────────────────

    public function test_a_writer_that_only_knows_the_old_columns_is_carried_across(): void
    {
        $this->world();
        $item = $this->catalogueItem('LEGACY', 'part', 100.0, 50.0);
        $item->forceFill(['default_rental_vat_type_id' => $this->standardVat()->id])->save();

        $item = $item->fresh();
        $this->assertSame(['100.00', '50.00'], [(string) $item->selling_excl, (string) $item->cost_excl]);
        $this->assertSame('percent', $item->markup_type);
        $this->assertSame('100.0000', (string) $item->markup_value);

        $item->update(['default_price' => 120, 'default_cost' => 60]);
        $item->refresh();
        $this->assertSame(['120.00', '60.00', '100.0000'], [(string) $item->selling_excl, (string) $item->cost_excl, (string) $item->markup_value]);
        $this->assertSame('138.00', (string) $item->selling_incl);
    }

    // ── agency defaults ──────────────────────────────────────────────────────────────────

    public function test_a_new_item_starts_at_the_agency_default_markup(): void
    {
        $this->world();
        $mgr = $this->agentHolding(['rental_work_orders.manage_settings', 'rental_catalogue.view', 'rental_catalogue.manage']);
        $init = $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.create'))->assertOk()->viewData('pricingInit');
        $this->assertSame(['percent', 0.0, 'markup'], [$init['markup_type'], $init['markup_value'], $init['driver']], 'neutral until the agency sets its own');

        $this->actingAs($mgr)->post(route('corex.settings.rental-work-orders.catalogue-markup'), ['catalogue_default_markup_type' => 'rand', 'catalogue_default_markup_value' => '12.5'])
            ->assertRedirect(route('corex.settings.rental-work-orders.edit'))->assertSessionHasNoErrors();

        $init = $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.create'))->viewData('pricingInit');
        $this->assertSame(['rand', 12.5], [$init['markup_type'], $init['markup_value']]);
    }

    public function test_the_default_markup_saver_is_has_guarded_validated_and_per_agency(): void
    {
        $this->world();
        $this->user = $this->agentHolding(['rental_work_orders.manage_settings']);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['catalogue_default_markup_type' => 'rand', 'catalogue_default_markup_value' => 7]);

        // a post that carries neither key changes nothing
        $this->actingAs($this->user)->post(route('corex.settings.rental-work-orders.catalogue-markup'), [])->assertSessionHasErrors('catalogue_default_markup_value');
        // only the value posted: the type is left alone
        $this->actingAs($this->user)->post(route('corex.settings.rental-work-orders.catalogue-markup'), ['catalogue_default_markup_value' => '9'])->assertSessionHasNoErrors();
        $this->assertSame(['rand', 9.0], [RentalWorkOrderSetting::catalogueMarkupTypeFor($this->agency->id), RentalWorkOrderSetting::catalogueMarkupValueFor($this->agency->id)]);

        $this->actingAs($this->user)->post(route('corex.settings.rental-work-orders.catalogue-markup'), ['catalogue_default_markup_type' => 'bogus'])->assertSessionHasErrors('catalogue_default_markup_type');
        $this->actingAs($this->user)->post(route('corex.settings.rental-work-orders.catalogue-markup'), ['catalogue_default_markup_value' => '-1'])->assertSessionHasErrors('catalogue_default_markup_value');

        // another agency is untouched and reads the neutral default
        $this->assertSame(['percent', 0.0], [RentalWorkOrderSetting::catalogueMarkupTypeFor(999999), RentalWorkOrderSetting::catalogueMarkupValueFor(999999)]);
    }

    // ── job cards ───────────────────────────────────────────────────────────────────────

    public function test_a_job_card_line_prefills_cost_and_selling_excl_and_the_vat_type_from_the_item(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 85, 'markup_type' => 'percent', 'markup_value' => 20, 'pricing_driver' => 'markup']);
        $card = $this->emptyCard();

        $line = $this->officeLine($card, ['rental_catalogue_item_id' => $item->id, 'description' => 'From catalogue']);

        $this->assertEquals(85.00, (float) $line->unit_cost);
        $this->assertEquals(102.00, (float) $line->unit_price);
        $this->assertSame($this->standardVat()->id, $line->rental_vat_type_id);
    }

    // ── the list ────────────────────────────────────────────────────────────────────────

    public function test_the_list_shows_cost_excl_and_selling_excl_sortable_and_hides_cost_from_those_who_cannot_see_it(): void
    {
        $this->world();
        $this->store(['code' => 'CHEAP', 'cost_excl' => 10, 'selling_excl' => 30]);
        $this->store(['code' => 'DEAR', 'cost_excl' => 20, 'selling_excl' => 20]);

        $page = $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.index', ['sort' => 'cost_excl']))->assertOk();
        $page->assertSee('Cost excl')->assertSee('Selling excl')->assertSee('R10.00')->assertSee('R30.00')->assertSeeInOrder(['CHEAP', 'DEAR']);
        $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.index', ['sort' => 'selling_excl']))->assertOk()->assertSeeInOrder(['DEAR', 'CHEAP']);

        $without = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage']);
        $this->actingAs($without)->get(route('corex.rental-catalogue-items.index', ['sort' => 'cost_excl']))->assertOk()
            ->assertDontSee('Cost excl')->assertDontSee('R10.00')->assertSee('Selling excl')->assertSee('R30.00');
    }

    public function test_every_amount_box_on_the_pricing_block_selects_its_whole_content_on_focus(): void
    {
        $this->world();
        $item = $this->store(['cost_excl' => 0, 'selling_excl' => 0]);

        foreach ([route('corex.rental-catalogue-items.create'), route('corex.rental-catalogue-items.edit', $item)] as $url) {
            $html = $this->actingAs($this->user)->get($url)->assertOk()->getContent();
            foreach (['cost_excl', 'cost_incl', 'markup_value', 'selling_excl', 'selling_incl'] as $name) {
                $this->assertSame(1, preg_match('/<input[^>]*name="' . $name . '"[^>]*>/', $html, $m), "{$name} box not found");
                $this->assertStringContainsString('@focus="selectAll($event)"', $m[0], "{$name} must select all on focus");
                $this->assertStringContainsString('@mouseup="keepSelected($event)"', $m[0], "{$name} must keep the selection on click");
            }
        }
    }

    public function test_a_labour_item_with_no_cost_shows_set_cost_as_a_link_to_edit_and_nothing_else_does(): void
    {
        $this->world();
        $labourType = RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'labour')->firstOrFail()->id;
        $labour = $this->store(['code' => 'HOURLY', 'rental_catalogue_item_type_id' => $labourType, 'selling_excl' => 450]);
        $this->store(['code' => 'PART-NOCOST', 'selling_excl' => 20]);
        $this->store(['code' => 'LAB-COSTED', 'rental_catalogue_item_type_id' => $labourType, 'cost_excl' => 200, 'selling_excl' => 450]);

        $page = $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.index'))->assertOk();
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, '— set cost'), 'only the labour item with an empty cost');
        $this->assertStringContainsString('href="' . route('corex.rental-catalogue-items.edit', $labour) . '"', $html);
        $this->assertMatchesRegularExpression('/<a href="[^"]*rental-catalogue-items\/' . $labour->id . '\/edit"[^>]*>— set cost<\/a>/', $html);

        // someone who cannot see costs is told nothing about them
        $without = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage']);
        $this->actingAs($without)->get(route('corex.rental-catalogue-items.index'))->assertOk()->assertDontSee('set cost');

        // someone who can see costs but not manage the catalogue sees the words without a link
        $viewer = $this->agentHolding(['rental_catalogue.view', 'rental_job_cards.view_costs']);
        $viewerHtml = $this->actingAs($viewer)->get(route('corex.rental-catalogue-items.index'))->assertOk()->assertSee('set cost')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*>— set cost<\/a>/', $viewerHtml);
    }

    // ── the crew ────────────────────────────────────────────────────────────────────────

    public function test_the_crew_is_never_handed_a_catalogue_items_selling_price_or_markup(): void
    {
        $this->world();
        // distinctive figures so a leak cannot be mistaken for chance
        $item = $this->store(['cost_excl' => 4321.11, 'selling_excl' => 8765.43, 'markup_type' => 'percent']);
        $card = $this->emptyCard();
        $this->officeLine($card, ['rental_catalogue_item_id' => $item->id, 'description' => 'Catalogue part for the crew']);

        $payload = app(CrewJobService::class)->payload($card->fresh(), $this->crewCtx($card));
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach (['8765.43', '8,765.43', '10080.24', '10,080.24', '"selling', '"markup', '"unit_price', '"line_total', '"cost_incl', '"selling_excl', '"selling_incl'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "crew payload leaks {$needle}");
        }
        $this->assertStringContainsString('Catalogue part for the crew', $json, 'the crew still sees the work');

        $catalogue = $payload['blocks']['pricing']['catalogue'] ?? [];
        $this->assertNotEmpty($catalogue);
        foreach ($catalogue as $row) {
            // the crew may carry the item's COST (a labour rate x hours is the crew's own figure) - never a selling price, a markup or a margin
            $this->assertSame([], array_values(array_diff(array_keys($row), ['id', 'label', 'description', 'kind', 'unit', 'cost'])), 'no key beyond names, unit and the cost');
            $this->assertNotSame('8765.43', $row['cost'] ?? null, 'the selling price is never offered as the cost');
        }

        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);
        $html = $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->getContent();
        foreach (['8765.43', '8,765.43', '10080.24', '10,080.24', 'R8 765', 'R8,765'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "crew page leaks {$needle}");
        }
    }
}
