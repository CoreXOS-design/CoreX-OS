<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalCatalogueItemType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pastel-style enhancement, 2026-10-05 — the catalogue item TYPE list.
 * Full CRUD (add/rename/archive/restore/reorder) + agency isolation
 * (BUILD_STANDARD §1a/§8), same shape as AgencyVatSetupTest.
 */
final class RentalCatalogueItemTypeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Catalogue Type Agency', 'slug' => 'cit-' . uniqid()]);
        $this->other = Agency::create(['name' => 'Other Agency', 'slug' => 'cit-other-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->other->id);
    }

    public function test_seeded_defaults_are_labour_and_part(): void
    {
        $names = RentalCatalogueItemType::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('name');
        $this->assertSame(['Labour', 'Part'], $names->all());
    }

    public function test_the_happy_path_adds_a_type(): void
    {
        $this->actingAs($this->admin)->post(route('admin.catalogue-item-types.store', $this->agency), [
            'name' => 'Subcontractor', 'kind' => 'labour',
        ])->assertRedirect();

        $type = RentalCatalogueItemType::firstWhere(['agency_id' => $this->agency->id, 'name' => 'Subcontractor']);
        $this->assertNotNull($type);
        $this->assertSame('labour', $type->kind);
    }

    public function test_required_fields_reject_cleanly_when_empty(): void
    {
        $this->actingAs($this->admin)->post(route('admin.catalogue-item-types.store', $this->agency), [
            'name' => '', 'kind' => '',
        ])->assertSessionHasErrors(['name', 'kind']);
    }

    public function test_rename(): void
    {
        $type = RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.catalogue-item-types.update', [$this->agency, $type]), [
            'name' => 'Materials', 'kind' => 'part',
        ])->assertRedirect();

        $this->assertSame('Materials', $type->fresh()->name);
    }

    public function test_archive_and_restore(): void
    {
        $type = RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.catalogue-item-types.archive', [$this->agency, $type]))->assertRedirect();
        $this->assertSoftDeleted($type);
        $this->assertFalse($type->fresh()->is_active);

        $this->actingAs($this->admin)->post(route('admin.catalogue-item-types.restore', [$this->agency, $type->id]))->assertRedirect();
        $this->assertNotSoftDeleted($type->fresh());
        $this->assertTrue($type->fresh()->is_active);
    }

    public function test_reorder(): void
    {
        $ids = RentalCatalogueItemType::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->admin)->post(route('admin.catalogue-item-types.reorder', $this->agency), ['order' => $reversed])->assertRedirect();

        $reordered = RentalCatalogueItemType::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame($reversed, $reordered);
    }

    public function test_an_agency_cannot_touch_another_agencys_types(): void
    {
        $theirType = RentalCatalogueItemType::where('agency_id', $this->other->id)->first();

        // Same shape as AgencyVatSetupTest::test_vat_types_are_agency_isolated()
        // — route-model binding on $catalogueItemType is itself agency-scoped
        // (AgencyScope, via the authenticated admin's own agency), so even
        // naming the OTHER agency in the {agency} segment can't find the
        // other agency's row: it 404s before authorizeAgency() ever runs.
        $resp = $this->actingAs($this->admin)->put(route('admin.catalogue-item-types.update', [$this->agency, $theirType]), [
            'name' => 'Hijacked', 'kind' => 'part',
        ]);
        $resp->assertStatus(404);
        $this->assertNotSame('Hijacked', $theirType->fresh()->name);
    }
}
