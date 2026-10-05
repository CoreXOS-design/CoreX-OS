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
use Tests\TestCase;

/**
 * AT-442 — the agency's own parts & labour catalogue. Full CRUD +
 * agency isolation + archive/restore (BUILD_STANDARD §1a/§8).
 *
 * Pastel-style enhancement, 2026-10-05 — type/unit now pick from agency
 * lists; default_price is always stored excl-VAT and the create/edit form
 * converts a posted incl-VAT amount down before saving. §3 below.
 *
 * 2026-10-05 round 2 (Johan QA1 finding A) — `name` split into a short
 * `code` (agency-unique among active items) + full `description`, so
 * picking an item on a job card no longer leaves the description to be
 * typed by hand. §4 below.
 */
final class RentalCatalogueItemTest extends TestCase
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
        $this->agencyA = Agency::create(['name' => 'RCI Agency A', 'slug' => 'rci-a-' . uniqid()]);
        $this->agencyB = Agency::create(['name' => 'RCI Agency B', 'slug' => 'rci-b-' . uniqid()]);
        $branchA = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agencyA->id]);
        $branchB = Branch::forceCreate(['name' => 'Cape Town', 'agency_id' => $this->agencyB->id]);
        $this->adminA = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin']);
        $this->adminB = User::factory()->create(['agency_id' => $this->agencyB->id, 'branch_id' => $branchB->id, 'role' => 'admin']);

        // Agency::create() does not fire AgencyCreated in tests — seed
        // explicitly, same convention every other per-agency list's tests use.
        RentalCatalogueItemType::seedDefaultsFor($this->agencyA->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agencyA->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agencyB->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agencyB->id);
        RentalVatType::seedDefaultsFor($this->agencyA->id);
    }

    private function partTypeId(Agency $agency): int
    {
        return RentalCatalogueItemType::where('agency_id', $agency->id)->where('kind', 'part')->firstOrFail()->id;
    }

    private function labourTypeId(Agency $agency): int
    {
        return RentalCatalogueItemType::where('agency_id', $agency->id)->where('kind', 'labour')->firstOrFail()->id;
    }

    private function eachUnitId(Agency $agency): int
    {
        return RentalCatalogueUnit::where('agency_id', $agency->id)->where('name', 'Each')->firstOrFail()->id;
    }

    public function test_the_happy_path_creates_an_item(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'GEYSER-EL', 'description' => 'Geyser element',
            'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA), 'default_price' => 450,
        ])->assertRedirect(route('corex.rental-catalogue-items.index'));

        $item = RentalCatalogueItem::firstWhere('code', 'GEYSER-EL');
        $this->assertNotNull($item);
        $this->assertSame('Geyser element', $item->description);
        $this->assertSame($this->agencyA->id, $item->agency_id);
        $this->assertSame('450.00', (string) $item->default_price);
        $this->assertSame('part', $item->kind());
    }

    public function test_default_price_is_optional_and_omitting_it_does_not_500(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->labourTypeId($this->agencyA),
            'code' => 'PLUMB-CO', 'description' => 'Plumber call-out',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agencyA->id)->where('name', 'Hour')->firstOrFail()->id,
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('code', 'PLUMB-CO');
        $this->assertNull($item->default_price);
    }

    public function test_required_fields_reject_cleanly_when_empty(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => '', 'code' => '', 'description' => '', 'rental_catalogue_unit_id' => '',
        ])->assertSessionHasErrors(['rental_catalogue_item_type_id', 'code', 'description', 'rental_catalogue_unit_id']);
    }

    public function test_edit_and_update(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'TAP-WASH', 'description' => 'Tap washer', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)->put(route('corex.rental-catalogue-items.update', $item), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'TAP-WASH', 'description' => 'Tap washer (brass)',
            'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA), 'default_price' => 15,
        ])->assertRedirect();

        $this->assertSame('Tap washer (brass)', $item->fresh()->description);
    }

    public function test_archive_and_restore(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'BALLCOCK', 'description' => 'Ballcock valve', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)->delete(route('corex.rental-catalogue-items.archive', $item))->assertRedirect();
        $this->assertSoftDeleted($item);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.restore', $item->id))->assertRedirect();
        $this->assertNotSoftDeleted($item->fresh());
    }

    public function test_an_agency_only_ever_sees_its_own_catalogue(): void
    {
        RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'A-ITEM', 'description' => 'Agency A item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);
        $itemB = RentalCatalogueItem::create([
            'agency_id' => $this->agencyB->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyB),
            'code' => 'B-ITEM', 'description' => 'Agency B item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyB),
            'sort_order' => 1, 'created_by_user_id' => $this->adminB->id,
        ]);

        $response = $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.index'));

        $response->assertOk();
        $response->assertDontSee('Agency B item');

        // Direct-URL access by ID is blocked, not just absent from the menu.
        $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.edit', $itemB))->assertNotFound();
    }

    public function test_type_and_unit_from_another_agency_are_rejected(): void
    {
        // Resolved BEFORE actingAs() — the agency scope would otherwise
        // filter agency B's own rows out from under adminA's own session.
        $agencyBTypeId = $this->partTypeId($this->agencyB);
        $agencyATypeId = $this->partTypeId($this->agencyA);
        $agencyAUnitId = $this->eachUnitId($this->agencyA);
        $agencyBUnitId = $this->eachUnitId($this->agencyB);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $agencyBTypeId,
            'code' => 'XAG-1', 'description' => 'Cross-agency item', 'rental_catalogue_unit_id' => $agencyAUnitId, 'default_price' => 10,
        ])->assertSessionHasErrors(['rental_catalogue_item_type_id']);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $agencyATypeId,
            'code' => 'XAG-2', 'description' => 'Cross-agency item', 'rental_catalogue_unit_id' => $agencyBUnitId, 'default_price' => 10,
        ])->assertSessionHasErrors(['rental_catalogue_unit_id']);
    }

    // ── §3 — VAT-aware default price, Pastel-style ───────────────────────

    public function test_not_vat_registered_stores_the_plain_price_unconverted(): void
    {
        $this->agencyA->update(['vat_registered' => false]);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'NO-VAT', 'description' => 'No-VAT item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA), 'default_price' => 100,
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('code', 'NO-VAT');
        $this->assertSame('100.00', (string) $item->default_price);
    }

    public function test_excl_capture_mode_stores_the_typed_amount_unconverted(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $standard = RentalVatType::where('agency_id', $this->agencyA->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
        \App\Models\PerformanceSetting::set('vat_rate', '15', $this->agencyA->id);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'EXCL-1', 'description' => 'Excl-mode item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'default_price' => 100, 'default_rental_vat_type_id' => $standard->id,
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('code', 'EXCL-1');
        $this->assertSame('100.00', (string) $item->default_price);
    }

    public function test_incl_capture_mode_converts_the_typed_amount_down_to_excl(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        $standard = RentalVatType::where('agency_id', $this->agencyA->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
        \App\Models\PerformanceSetting::set('vat_rate', '15', $this->agencyA->id);

        // 115 incl @ 15% = 100 excl.
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'INCL-1', 'description' => 'Incl-mode item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'default_price' => 115, 'default_rental_vat_type_id' => $standard->id,
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('code', 'INCL-1');
        $this->assertSame('100.00', (string) $item->default_price);
    }

    public function test_no_vat_type_selected_stores_the_typed_amount_unconverted_even_in_incl_mode(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        $noVat = RentalVatType::where('agency_id', $this->agencyA->id)->where('rate_mode', RentalVatType::RATE_MODE_FIXED)->firstOrFail();

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'NO-VAT-T', 'description' => 'No-VAT-type item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'default_price' => 100, 'default_rental_vat_type_id' => $noVat->id,
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('code', 'NO-VAT-T');
        $this->assertSame('100.00', (string) $item->default_price);
    }

    public function test_custom_vat_rate_converts_incl_down_to_excl_using_the_typed_rate(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        $custom = RentalVatType::where('agency_id', $this->agencyA->id)->where('rate_mode', RentalVatType::RATE_MODE_CUSTOM_PER_LINE)->firstOrFail();

        // 110 incl @ 10% = 100 excl.
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'CUSTOM-1', 'description' => 'Custom-rate item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'default_price' => 110, 'default_rental_vat_type_id' => $custom->id, 'default_custom_vat_rate' => 10,
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('code', 'CUSTOM-1');
        $this->assertSame('100.00', (string) $item->default_price);
        $this->assertSame('10.00', (string) $item->default_custom_vat_rate);
    }

    public function test_list_shows_excl_vat_type_and_incl_columns_when_registered(): void
    {
        $this->agencyA->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        \App\Models\PerformanceSetting::set('vat_rate', '15', $this->agencyA->id);
        $standard = RentalVatType::where('agency_id', $this->agencyA->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();

        RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'LISTED-1', 'description' => 'Listed item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'default_price' => 100, 'default_rental_vat_type_id' => $standard->id,
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $response = $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.index'));

        $response->assertOk();
        $response->assertSee('LISTED-1');
        $response->assertSee('Listed item');
        $response->assertSee('R100.00');
        $response->assertSee('R115.00');
        $response->assertSee('Standard VAT');
    }

    // ── §4 — code/description (2026-10-05, Johan QA1 finding A) ──────────

    public function test_code_must_be_unique_per_agency_among_active_items(): void
    {
        RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'DUP-01', 'description' => 'First item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'DUP-01', 'description' => 'Second item', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
        ])->assertSessionHasErrors(['code']);
    }

    public function test_the_same_code_is_allowed_in_a_different_agency(): void
    {
        RentalCatalogueItem::create([
            'agency_id' => $this->agencyB->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyB),
            'code' => 'SHARED-01', 'description' => 'Agency B version', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyB),
            'sort_order' => 1, 'created_by_user_id' => $this->adminB->id,
        ]);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'SHARED-01', 'description' => 'Agency A version', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
        ])->assertRedirect(route('corex.rental-catalogue-items.index'));

        $this->assertNotNull(RentalCatalogueItem::where('agency_id', $this->agencyA->id)->where('code', 'SHARED-01')->first());
    }

    public function test_an_archived_items_code_can_be_reused(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'REUSE-01', 'description' => 'Original', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);
        $item->archive();

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'REUSE-01', 'description' => 'Replacement', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
        ])->assertRedirect(route('corex.rental-catalogue-items.index'));

        $this->assertNotNull(RentalCatalogueItem::where('code', 'REUSE-01')->where('description', 'Replacement')->first());
    }

    public function test_editing_an_item_can_keep_its_own_code(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'KEEP-01', 'description' => 'Keeps its own code', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)->put(route('corex.rental-catalogue-items.update', $item), [
            'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'KEEP-01', 'description' => 'Keeps its own code, updated', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_search_matches_code_or_description(): void
    {
        RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'SEARCH-CODE', 'description' => 'Findable by code', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);
        RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'rental_catalogue_item_type_id' => $this->partTypeId($this->agencyA),
            'code' => 'OTHER-01', 'description' => 'Findable by its own description text', 'rental_catalogue_unit_id' => $this->eachUnitId($this->agencyA),
            'sort_order' => 2, 'created_by_user_id' => $this->adminA->id,
        ]);

        $byCode = $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.index', ['q' => 'SEARCH-CODE']));
        $byCode->assertOk()->assertSee('Findable by code')->assertDontSee('Findable by its own description');

        $byDescription = $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.index', ['q' => 'description text']));
        $byDescription->assertOk()->assertSee('OTHER-01')->assertDontSee('SEARCH-CODE');
    }
}
