<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\User;
use App\Services\Rentals\CatalogueImport\RentalCatalogueImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.4.4 — `rental_catalogue_items.default_cost`: the catalogue form, the CSV/XLSX import
 * (an optional Cost column APPENDED so older 7-column files still read), and the prefill of a new job card line's cost.
 * Cost is shown and settable only with `rental_job_cards.view_costs`, in the same VAT basis as the price (stored excl).
 */
final class CatalogueDefaultCostTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Catalogue Cost');
        $this->card = $this->emptyCard();
    }

    private function formPayload(array $over = []): array
    {
        return $over + [
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'COST-1', 'description' => 'Costed item',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 200, 'default_cost' => 150, 'is_active' => 1,
        ];
    }

    private function userWith(array $keys): User
    {
        return $this->agentHolding(array_merge(['rental_catalogue.view', 'rental_catalogue.manage'], $keys));
    }

    // ── the form ──────────────────────────────────────────────────────────────────────────

    public function test_the_form_offers_a_cost_only_to_someone_who_can_see_costs(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);
        $without = $this->userWith([]);

        $this->actingAs($with)->get(route('corex.rental-catalogue-items.create'))->assertOk()->assertSee('data-catalogue-cost', false)->assertSee('Default cost');
        $this->actingAs($without)->get(route('corex.rental-catalogue-items.create'))->assertOk()->assertDontSee('data-catalogue-cost', false);
    }

    public function test_a_cost_is_saved_with_the_item_and_shown_on_edit(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);

        $this->actingAs($with)->post(route('corex.rental-catalogue-items.store'), $this->formPayload())->assertRedirect(route('corex.rental-catalogue-items.index'));

        $item = RentalCatalogueItem::where('code', 'COST-1')->firstOrFail();
        $this->assertEquals(150.00, (float) $item->default_cost);
        $this->actingAs($with)->get(route('corex.rental-catalogue-items.edit', $item))->assertOk()->assertSee('value="150.00"', false);
    }

    public function test_the_cost_is_optional_and_never_negative(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);

        $this->actingAs($with)->post(route('corex.rental-catalogue-items.store'), $this->formPayload(['code' => 'NO-COST', 'default_cost' => '']))->assertRedirect();
        $this->assertNull(RentalCatalogueItem::where('code', 'NO-COST')->firstOrFail()->default_cost);

        $this->actingAs($with)->post(route('corex.rental-catalogue-items.store'), $this->formPayload(['code' => 'NEG', 'default_cost' => -1]))->assertSessionHasErrors('default_cost');
        $this->assertNull(RentalCatalogueItem::where('code', 'NEG')->first());
    }

    public function test_someone_who_cannot_see_costs_can_neither_set_nor_wipe_one(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);
        $without = $this->userWith([]);
        $this->actingAs($with)->post(route('corex.rental-catalogue-items.store'), $this->formPayload())->assertRedirect();
        $item = RentalCatalogueItem::where('code', 'COST-1')->firstOrFail();

        // a hand-made POST carrying a cost, and an edit that simply omits the field
        $this->actingAs($without)->put(route('corex.rental-catalogue-items.update', $item), $this->formPayload(['default_cost' => 1, 'description' => 'Renamed']))->assertRedirect();
        $this->actingAs($without)->post(route('corex.rental-catalogue-items.store'), $this->formPayload(['code' => 'SNEAK', 'default_cost' => 999]))->assertRedirect();

        $this->assertEquals(150.00, (float) $item->fresh()->default_cost);
        $this->assertSame('Renamed', $item->fresh()->description);
        $this->assertNull(RentalCatalogueItem::where('code', 'SNEAK')->firstOrFail()->default_cost);
    }

    public function test_an_incl_vat_agency_types_the_cost_incl_and_it_is_stored_excl(): void
    {
        $this->pricingWorld('Catalogue Cost Incl', [], true, Agency::VAT_CAPTURE_INCL);
        $with = $this->userWith(['rental_job_cards.view_costs']);

        $this->actingAs($with)->post(route('corex.rental-catalogue-items.store'), $this->formPayload(['code' => 'INCL', 'default_price' => 230, 'default_cost' => 115, 'default_rental_vat_type_id' => $this->standardVat()->id]))->assertRedirect();

        $item = RentalCatalogueItem::where('code', 'INCL')->firstOrFail();
        $this->assertEquals(200.00, (float) $item->default_price);
        $this->assertEquals(100.00, (float) $item->default_cost, 'same VAT basis as the price: stored excl');

        // ...and a new job card line prefills it back in the agency's capture mode (incl)
        $card = $this->emptyCard();
        $line = $this->officeLine($card, ['rental_catalogue_item_id' => $item->id, 'description' => 'From catalogue']);
        $this->assertEquals(115.00, (float) $line->unit_cost);
    }

    // ── prefill ──────────────────────────────────────────────────────────────────────────

    public function test_an_office_line_from_an_item_prefills_the_cost_and_a_typed_cost_wins(): void
    {
        $item = $this->catalogueItem('PREFILL', 'part', null, 42.50);

        $auto = $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id, 'description' => 'Auto cost']);
        $typed = $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id, 'description' => 'Typed cost', 'unit_cost' => 10]);

        $this->assertEquals(42.50, (float) $auto->unit_cost);
        $this->assertEquals(42.50, (float) $auto->unit_price, 'no markup (0 %) so it prices at cost');
        $this->assertEquals(10.00, (float) $typed->unit_cost);
    }

    public function test_an_item_with_no_cost_leaves_the_line_with_no_cost_never_a_zero(): void
    {
        $item = $this->catalogueItem('NOCOST', 'part', 99.00, null);

        $line = $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id, 'description' => 'No cost item']);

        $this->assertNull($line->unit_cost);
        $this->assertEquals(99.00, (float) $line->unit_price, 'the catalogue price still prices it (rule 5)');
    }

    // ── import ───────────────────────────────────────────────────────────────────────────

    private function csv(array $rows, bool $withCostHeader = true): UploadedFile
    {
        $header = ['Code', 'Description', 'Type', 'Unit', 'VAT type', 'Price (excl VAT)', 'Price (incl VAT)'];
        if ($withCostHeader) {
            $header[] = 'Cost (excl VAT)';
        }
        $lines = [implode(',', $header)];
        foreach ($rows as $r) {
            $lines[] = implode(',', $r);
        }

        return UploadedFile::fake()->createWithContent('catalogue.csv', implode("\n", $lines) . "\n");
    }

    private function runImport(User $user, UploadedFile $file): void
    {
        $response = $this->actingAs($user)->post(route('corex.rental-catalogue-items.import.upload'), ['file' => $file, 'on_duplicate' => 'update']);
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->actingAs($user)->get($url)->assertOk();
        preg_match('#/([0-9a-f-]{36})/preview#', $url, $m);
        $this->actingAs($user)->post(route('corex.rental-catalogue-items.import.confirm', $m[1]))->assertRedirect(route('corex.rental-catalogue-items.index'));
    }

    public function test_the_import_reads_the_appended_cost_column_for_someone_who_can_set_costs(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);

        $this->runImport($with, $this->csv([
            ['CSV-1', 'First', 'Part', 'Each', '', '100', '', '60'],
            ['CSV-2', 'Second', 'Labour', 'Each', '', '', '', ''],
            ['CSV-3', 'Third', 'Part', 'Each', '', '50', '', 'R 1 250.50'],
        ]));

        $this->assertEquals(60.00, (float) RentalCatalogueItem::where('code', 'CSV-1')->firstOrFail()->default_cost);
        $this->assertNull(RentalCatalogueItem::where('code', 'CSV-2')->firstOrFail()->default_cost, 'blank = no cost');
        $this->assertEquals(1250.50, (float) RentalCatalogueItem::where('code', 'CSV-3')->firstOrFail()->default_cost, 'R / spaces absorbed like the price column');
    }

    public function test_the_import_ignores_the_cost_column_for_everyone_else_and_a_blank_cost_never_wipes_a_stored_one(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);
        $without = $this->userWith([]);
        $this->runImport($with, $this->csv([['KEEP', 'Keeper', 'Part', 'Each', '', '10', '', '33']]));

        $this->runImport($without, $this->csv([['KEEP', 'Keeper renamed', 'Part', 'Each', '', '10', '', '999'], ['NEW-NO', 'New', 'Part', 'Each', '', '10', '', '888']]));

        $this->assertEquals(33.00, (float) RentalCatalogueItem::where('code', 'KEEP')->firstOrFail()->default_cost, 'not theirs to change');
        $this->assertSame('Keeper renamed', RentalCatalogueItem::where('code', 'KEEP')->firstOrFail()->description);
        $this->assertNull(RentalCatalogueItem::where('code', 'NEW-NO')->firstOrFail()->default_cost);

        $this->runImport($with, $this->csv([['KEEP', 'Keeper', 'Part', 'Each', '', '10', '', '']]));
        $this->assertEquals(33.00, (float) RentalCatalogueItem::where('code', 'KEEP')->firstOrFail()->default_cost, 'a blank cost cell leaves the stored cost alone');
    }

    public function test_an_older_seven_column_file_still_imports(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);

        $this->runImport($with, $this->csv([['OLD-1', 'Old format', 'Part', 'Each', '', '77', '']], false));

        $item = RentalCatalogueItem::where('code', 'OLD-1')->firstOrFail();
        $this->assertEquals(77.00, (float) $item->default_price);
        $this->assertNull($item->default_cost);
    }

    public function test_a_bad_cost_is_a_per_row_error_not_a_crash(): void
    {
        $with = $this->userWith(['rental_job_cards.view_costs']);

        $response = $this->actingAs($with)->post(route('corex.rental-catalogue-items.import.upload'), ['file' => $this->csv([['BAD', 'Bad cost', 'Part', 'Each', '', '10', '', 'lots']]), 'on_duplicate' => 'update']);
        $page = $this->actingAs($with)->get($response->headers->get('Location'))->assertOk()->getContent();

        $this->assertStringContainsString('Cost (excl VAT) &#039;lots&#039; is not a number', $page);
        $this->assertNull(RentalCatalogueItem::where('code', 'BAD')->first());
    }

    public function test_the_template_has_the_cost_column_only_for_someone_who_can_set_costs(): void
    {
        $agency = Agency::withoutGlobalScopes()->find($this->agency->id);
        $service = app(RentalCatalogueImportTemplateService::class);

        $withSheet = $service->build($agency, true)->getSheet(0);
        $withoutSheet = $service->build($agency, false)->getSheet(0);

        $this->assertSame('Cost (excl VAT)', $withSheet->getCell('H1')->getValue());
        $this->assertNull($withoutSheet->getCell('H1')->getValue());
        $this->assertSame('Price (incl VAT)', $withoutSheet->getCell('G1')->getValue(), 'the first seven columns are unchanged');
    }
}
