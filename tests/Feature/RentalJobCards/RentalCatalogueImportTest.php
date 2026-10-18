<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.19 — catalogue bulk import: template
 * download, upload -> dry-run preview with per-row errors, confirm,
 * duplicate-by-code update-or-skip, agency isolation.
 */
final class RentalCatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agencyA = Agency::create(['name' => 'RCI Import A', 'slug' => 'rci-imp-a-' . uniqid()]);
        $this->agencyB = Agency::create(['name' => 'RCI Import B', 'slug' => 'rci-imp-b-' . uniqid()]);
        $branchA = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agencyA->id]);
        $branchB = Branch::forceCreate(['name' => 'Cape Town', 'agency_id' => $this->agencyB->id]);
        $this->adminA = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin']);
        $this->adminB = User::factory()->create(['agency_id' => $this->agencyB->id, 'branch_id' => $branchB->id, 'role' => 'admin']);

        RentalCatalogueItemType::seedDefaultsFor($this->agencyA->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agencyA->id);
        RentalVatType::seedDefaultsFor($this->agencyA->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agencyB->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agencyB->id);
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = ['Code,Description,Type,Unit,VAT type,Price (excl VAT),Price (incl VAT)'];
        foreach ($rows as $row) {
            $cells = array_map(function ($v) {
                $v = (string) ($v ?? '');

                return str_contains($v, ',') ? '"' . $v . '"' : $v;
            }, $row);
            $lines[] = implode(',', $cells);
        }

        return UploadedFile::fake()->createWithContent('items.csv', implode("\n", $lines));
    }

    public function test_template_downloads(): void
    {
        $this->actingAs($this->adminA)
            ->get(route('corex.rental-catalogue-items.import.template'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_upload_previews_a_new_item_without_creating_it(): void
    {
        $file = $this->csv([
            ['GEYSER-EL', 'Geyser element', 'Part', 'Each', '', '450', ''],
        ]);

        $response = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);

        $response->assertRedirect();
        $this->assertNull(RentalCatalogueItem::firstWhere('code', 'GEYSER-EL'));

        $preview = $this->get($response->headers->get('Location'));
        $preview->assertOk();
        $preview->assertSee('GEYSER-EL');
        $preview->assertSee('New');
        $this->assertNull(RentalCatalogueItem::firstWhere('code', 'GEYSER-EL'));
    }

    public function test_confirm_creates_the_previewed_rows(): void
    {
        $file = $this->csv([
            ['GEYSER-EL', 'Geyser element', 'Part', 'Each', '', '450', ''],
            ['CALLOUT', 'Call-out fee', 'Labour', 'Call-out', '', '250', ''],
        ]);

        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $previewUrl = $upload->headers->get('Location');
        $confirmUrl = str_replace('/preview', '/confirm', $previewUrl);
        $this->post($confirmUrl)->assertRedirect(route('corex.rental-catalogue-items.index'));

        $geyser = RentalCatalogueItem::firstWhere('code', 'GEYSER-EL');
        $this->assertNotNull($geyser);
        $this->assertSame('Geyser element', $geyser->description);
        $this->assertSame('450.00', (string) $geyser->default_price);
        $this->assertSame('part', $geyser->kind());

        $callout = RentalCatalogueItem::firstWhere('code', 'CALLOUT');
        $this->assertNotNull($callout);
        $this->assertSame('labour', $callout->kind());
    }

    public function test_duplicate_code_updates_when_update_chosen(): void
    {
        $existing = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agencyA->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'GEYSER-EL', 'description' => 'Old description',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agencyA->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 100, 'is_active' => true, 'sort_order' => 1,
        ]);

        $file = $this->csv([
            ['GEYSER-EL', 'New description', 'Part', 'Each', '', '500', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $previewUrl = $upload->headers->get('Location');
        $this->get($previewUrl)->assertSee('Will update');

        $this->post(str_replace('/preview', '/confirm', $previewUrl));

        $existing->refresh();
        $this->assertSame('New description', $existing->description);
        $this->assertSame('500.00', (string) $existing->default_price);
        $this->assertSame(1, RentalCatalogueItem::where('agency_id', $this->agencyA->id)->where('code', 'GEYSER-EL')->count());
    }

    public function test_duplicate_code_skips_when_skip_chosen(): void
    {
        $existing = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agencyA->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'GEYSER-EL', 'description' => 'Untouched description',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agencyA->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 100, 'is_active' => true, 'sort_order' => 1,
        ]);

        $file = $this->csv([
            ['GEYSER-EL', 'Should not land', 'Part', 'Each', '', '999', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'skip',
        ]);
        $previewUrl = $upload->headers->get('Location');
        $this->get($previewUrl)->assertSee('Already exists');

        $this->post(str_replace('/preview', '/confirm', $previewUrl));

        $existing->refresh();
        $this->assertSame('Untouched description', $existing->description);
        $this->assertSame('100.00', (string) $existing->default_price);
    }

    public function test_unknown_type_is_a_per_row_error_and_is_never_created(): void
    {
        $file = $this->csv([
            ['BADTYPE-1', 'Something', 'Subcontractor', 'Each', '', '100', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $previewUrl = $upload->headers->get('Location');
        $preview = $this->get($previewUrl);
        $preview->assertSee('Error');
        $preview->assertSee('Unknown type');

        $this->post(str_replace('/preview', '/confirm', $previewUrl));
        $this->assertNull(RentalCatalogueItem::firstWhere('code', 'BADTYPE-1'));
    }

    public function test_duplicate_code_within_the_same_file_is_an_error_on_the_second_row(): void
    {
        $file = $this->csv([
            ['DUPE-1', 'First', 'Part', 'Each', '', '100', ''],
            ['DUPE-1', 'Second', 'Part', 'Each', '', '200', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $previewUrl = $upload->headers->get('Location');
        $this->get($previewUrl)->assertSee('Duplicate code within this file');

        $this->post(str_replace('/preview', '/confirm', $previewUrl));
        $this->assertSame(1, RentalCatalogueItem::where('agency_id', $this->agencyA->id)->where('code', 'DUPE-1')->count());
        $this->assertSame('First', RentalCatalogueItem::firstWhere('code', 'DUPE-1')->description);
    }

    public function test_incl_vat_price_converts_down_to_excl_same_as_the_single_item_form(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $vatType = RentalVatType::where('agency_id', $this->agencyA->id)->where('name', 'Standard VAT')->firstOrFail();

        $file = $this->csv([
            ['VATROW-1', 'Priced incl', 'Part', 'Each', 'Standard VAT', '', '115'],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $previewUrl = $upload->headers->get('Location');
        $this->post(str_replace('/preview', '/confirm', $previewUrl));

        $item = RentalCatalogueItem::firstWhere('code', 'VATROW-1');
        $this->assertNotNull($item);
        $this->assertSame($vatType->id, $item->default_rental_vat_type_id);
        // 115 incl at 15% -> 100.00 excl.
        $this->assertSame('100.00', (string) $item->default_price);
    }

    public function test_a_blank_vat_type_column_means_no_vat_type_not_the_agency_default(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);

        $file = $this->csv([
            ['NOVAT-1', 'No vat type picked', 'Part', 'Each', '', '100', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $this->post(str_replace('/preview', '/confirm', $upload->headers->get('Location')));

        $item = RentalCatalogueItem::firstWhere('code', 'NOVAT-1');
        $this->assertNull($item->default_rental_vat_type_id);
    }

    public function test_a_preview_token_from_another_agency_cannot_be_viewed(): void
    {
        $file = $this->csv([
            ['CROSSAG-1', 'Should not leak', 'Part', 'Each', '', '100', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $previewUrl = $upload->headers->get('Location');

        $this->actingAs($this->adminB)->get($previewUrl)
            ->assertRedirect(route('corex.rental-catalogue-items.import.index'));

        $this->actingAs($this->adminB)->post(str_replace('/preview', '/confirm', $previewUrl))
            ->assertRedirect(route('corex.rental-catalogue-items.import.index'));

        $this->assertNull(RentalCatalogueItem::where('agency_id', $this->agencyB->id)->where('code', 'CROSSAG-1')->first());
    }

    public function test_blank_optional_price_columns_leave_default_price_null(): void
    {
        $file = $this->csv([
            ['NOPRICE-1', 'No price yet', 'Part', 'Each', '', '', ''],
        ]);
        $upload = $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.import.upload'), [
            'file' => $file, 'on_duplicate' => 'update',
        ]);
        $this->post(str_replace('/preview', '/confirm', $upload->headers->get('Location')));

        $item = RentalCatalogueItem::firstWhere('code', 'NOPRICE-1');
        $this->assertNotNull($item);
        $this->assertNull($item->default_price);
    }
}
