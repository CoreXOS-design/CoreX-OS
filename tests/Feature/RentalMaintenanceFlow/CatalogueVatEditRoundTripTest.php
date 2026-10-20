<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use App\Models\User;
use App\Services\Rentals\RentalJobCardVatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * Parts & Labour Catalogue EDIT screen, save round trip. The stored figures are always excl VAT (selling_excl / cost_excl); since
 * §17.38 the screen shows and posts BOTH excl and incl, so a plain Save with nothing touched must leave every stored figure
 * unchanged to the cent, whatever the agency's VAT capture mode and rate (the 2026-10-07 bug: 100.00 -> 86.96 -> 75.62 ...).
 * Spec: .ai/specs/rental-work-orders.md §14.31, §17.38.
 */
final class CatalogueVatEditRoundTripTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private User $user;

    private function world(string $mode): void
    {
        $this->pricingWorld('Catalogue VAT ' . $mode, [], true, $mode);
        $this->user = $this->agentHolding(['rental_catalogue.view', 'rental_catalogue.manage', 'rental_job_cards.view_costs']);
    }

    private function item(string $code, float $excl, ?float $cost, ?RentalVatType $type = null, ?float $customRate = null): RentalCatalogueItem
    {
        return RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => $code, 'description' => $code . ' item', 'default_price' => $excl, 'default_cost' => $cost, 'sort_order' => 1,
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_rental_vat_type_id' => ($type ?? $this->standardVat())->id,
            'default_custom_vat_rate' => $customRate,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    /** What the edit screen seeds its boxes with. */
    private function shown(RentalCatalogueItem $item): array
    {
        return $this->actingAs($this->user)->get(route('corex.rental-catalogue-items.edit', $item))->assertOk()->viewData('pricingInit');
    }

    /** A plain Save: the browser posts back exactly what the form showed, the driver being the selling excl it loaded with. */
    private function saveUnchanged(RentalCatalogueItem $item, ?string $customRate = null): void
    {
        $shown = $this->shown($item);
        $this->actingAs($this->user)->put(route('corex.rental-catalogue-items.update', $item), [
            'rental_catalogue_item_type_id' => $item->rental_catalogue_item_type_id,
            'code' => $item->code, 'description' => $item->description,
            'rental_catalogue_unit_id' => $item->rental_catalogue_unit_id,
            'cost_excl' => $shown['cost_excl'], 'cost_incl' => $shown['cost_incl'],
            'selling_excl' => $shown['selling_excl'], 'selling_incl' => $shown['selling_incl'],
            'markup_type' => $shown['markup_type'], 'markup_value' => $shown['markup_value'],
            'pricing_driver' => $shown['driver'], 'cost_basis' => 'excl',
            'default_rental_vat_type_id' => $item->default_rental_vat_type_id,
            'default_custom_vat_rate' => $customRate ?? $item->default_custom_vat_rate,
            'is_active' => 1,
        ])->assertRedirect(route('corex.rental-catalogue-items.index'));
    }

    public function test_typing_incl_figures_stores_excl_and_ten_plain_saves_never_move_them(): void
    {
        $this->world(Agency::VAT_CAPTURE_INCL);

        // Create typing R115.00 incl selling (and R57.50 cost incl) -> stored excl 100.00 / 50.00.
        $this->actingAs($this->user)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'INCL-1', 'description' => 'Incl item',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'selling_incl' => '115.00', 'cost_incl' => '57.50', 'pricing_driver' => 'selling_incl', 'cost_basis' => 'incl',
            'default_rental_vat_type_id' => $this->standardVat()->id, 'is_active' => 1,
        ])->assertRedirect();

        $item = RentalCatalogueItem::where('code', 'INCL-1')->firstOrFail();
        $this->assertSame('100.00', (string) $item->selling_excl);
        $this->assertSame('50.00', (string) $item->cost_excl);
        $this->assertSame('100.00', (string) $item->default_price, 'legacy column kept in step');

        $shown = $this->shown($item);
        $this->assertSame([115.0, 57.5], [$shown['selling_incl'], $shown['cost_incl']]);

        for ($i = 1; $i <= 10; $i++) {
            $this->saveUnchanged($item);
            $item->refresh();
            $this->assertSame('100.00', (string) $item->selling_excl, "selling moved on plain save #{$i}");
            $this->assertSame('50.00', (string) $item->cost_excl, "cost moved on plain save #{$i}");
        }
    }

    public function test_odd_cent_prices_and_a_custom_rate_are_stable_over_ten_saves(): void
    {
        $this->world(Agency::VAT_CAPTURE_INCL);
        $custom = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_CUSTOM_PER_LINE)->firstOrFail();

        $cases = [
            ['STD-A', 86.96, 43.48, null, null],
            ['STD-B', 0.01, 0.01, null, null],
            ['STD-C', 33.33, 12.35, null, null],
            ['STD-D', 99999.99, 88888.88, null, null],
            ['CUS-A', 123.45, 67.89, $custom, 7.5],
            ['CUS-B', 19.99, 5.01, $custom, 13.37],
        ];
        foreach ($cases as [$code, $excl, $cost, $type, $rate]) {
            $item = $this->item($code, $excl, $cost, $type, $rate);
            for ($i = 1; $i <= 10; $i++) {
                $this->saveUnchanged($item, $rate !== null ? (string) $rate : null);
                $item->refresh();
                $this->assertSame(number_format($excl, 2, '.', ''), (string) $item->selling_excl, "{$code} selling moved on save #{$i}");
                $this->assertSame(number_format($cost, 2, '.', ''), (string) $item->cost_excl, "{$code} cost moved on save #{$i}");
            }
        }
    }

    public function test_every_excl_cent_survives_the_incl_display_and_back_at_several_rates(): void
    {
        // The edit screen shows round(excl x (1+r)) and a save stores round(shown / (1+r)); the pair must be the identity on every 2dp amount.
        $vat = app(RentalJobCardVatService::class);
        foreach ([15.0, 7.5, 14.0, 0.5, 33.33] as $rate) {
            for ($cents = 0; $cents <= 300000; $cents++) {
                $excl = $cents / 100;
                $shown = round($excl * (1 + $rate / 100), 2);
                $back = $vat->splitAmount($shown, $rate, Agency::VAT_CAPTURE_INCL)['excl'];
                if (abs($back - $excl) > 0.0001) {
                    $this->fail("rate {$rate}%: excl {$excl} -> shown {$shown} -> saved {$back}");
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_agency_capture_mode_changes_neither_what_is_shown_nor_what_is_stored(): void
    {
        $this->world(Agency::VAT_CAPTURE_EXCL);
        $item = $this->item('SWITCH-1', 100.00, 50.00);
        $before = $this->shown($item);
        $this->assertSame([100.0, 115.0, 50.0, 57.5], [$before['selling_excl'], $before['selling_incl'], $before['cost_excl'], $before['cost_incl']]);

        $this->agency->update(['vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        $this->assertSame($before, $this->shown($item->fresh()));

        $this->saveUnchanged($item);
        $this->assertSame('100.00', (string) $item->fresh()->selling_excl);
        $this->assertSame('50.00', (string) $item->fresh()->cost_excl);
    }

    public function test_job_card_prefill_from_the_catalogue_does_not_drift_either(): void
    {
        // The other conversion on the same stored figure (catalogue item -> job card line, in the agency's capture mode): pure function of the
        // stored excl value, never written back, so repeated reads return the same amount.
        $this->world(Agency::VAT_CAPTURE_INCL);
        $item = $this->item('LINE-1', 86.96, 43.48);
        $vat = app(RentalJobCardVatService::class);

        $first = $vat->catalogueDefaultPriceForLine($item, $this->agency->fresh());
        $this->assertSame(100.0, $first);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $vat->catalogueDefaultPriceForLine($item->fresh(), $this->agency->fresh()));
        }
        $this->assertSame(50.0, $vat->catalogueDefaultCostForLine($item, $this->agency->fresh()));
    }
}
