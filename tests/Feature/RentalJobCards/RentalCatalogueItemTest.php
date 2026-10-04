<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalCatalogueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-442 — the agency's own parts & labour catalogue. Full CRUD +
 * agency isolation + archive/restore (BUILD_STANDARD §1a/§8).
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
    }

    public function test_the_happy_path_creates_an_item(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'type' => RentalCatalogueItem::TYPE_PART, 'name' => 'Geyser element', 'unit' => 'each', 'default_price' => 450,
        ])->assertRedirect(route('corex.rental-catalogue-items.index'));

        $item = RentalCatalogueItem::firstWhere('name', 'Geyser element');
        $this->assertNotNull($item);
        $this->assertSame($this->agencyA->id, $item->agency_id);
        $this->assertSame('450.00', (string) $item->default_price);
    }

    public function test_default_price_is_optional_and_omitting_it_does_not_500(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'type' => RentalCatalogueItem::TYPE_LABOUR, 'name' => 'Plumber call-out', 'unit' => 'hour',
        ])->assertRedirect();

        $item = RentalCatalogueItem::firstWhere('name', 'Plumber call-out');
        $this->assertNull($item->default_price);
    }

    public function test_required_fields_reject_cleanly_when_empty(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.store'), [
            'type' => '', 'name' => '', 'unit' => '',
        ])->assertSessionHasErrors(['type', 'name', 'unit']);
    }

    public function test_edit_and_update(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'type' => RentalCatalogueItem::TYPE_PART,
            'name' => 'Tap washer', 'unit' => 'each', 'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)->put(route('corex.rental-catalogue-items.update', $item), [
            'type' => RentalCatalogueItem::TYPE_PART, 'name' => 'Tap washer (brass)', 'unit' => 'each', 'default_price' => 15,
        ])->assertRedirect();

        $this->assertSame('Tap washer (brass)', $item->fresh()->name);
    }

    public function test_archive_and_restore(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'type' => RentalCatalogueItem::TYPE_PART,
            'name' => 'Ballcock valve', 'unit' => 'each', 'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)->delete(route('corex.rental-catalogue-items.archive', $item))->assertRedirect();
        $this->assertSoftDeleted($item);

        $this->actingAs($this->adminA)->post(route('corex.rental-catalogue-items.restore', $item->id))->assertRedirect();
        $this->assertNotSoftDeleted($item->fresh());
    }

    public function test_an_agency_only_ever_sees_its_own_catalogue(): void
    {
        RentalCatalogueItem::create([
            'agency_id' => $this->agencyA->id, 'type' => RentalCatalogueItem::TYPE_PART,
            'name' => 'Agency A item', 'unit' => 'each', 'sort_order' => 1, 'created_by_user_id' => $this->adminA->id,
        ]);
        $itemB = RentalCatalogueItem::create([
            'agency_id' => $this->agencyB->id, 'type' => RentalCatalogueItem::TYPE_PART,
            'name' => 'Agency B item', 'unit' => 'each', 'sort_order' => 1, 'created_by_user_id' => $this->adminB->id,
        ]);

        $response = $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.index'));

        $response->assertOk();
        $response->assertDontSee('Agency B item');

        // Direct-URL access by ID is blocked, not just absent from the menu.
        $this->actingAs($this->adminA)->get(route('corex.rental-catalogue-items.edit', $itemB))->assertNotFound();
    }
}
