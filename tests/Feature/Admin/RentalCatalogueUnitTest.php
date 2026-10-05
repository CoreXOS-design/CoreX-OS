<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalCatalogueUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pastel-style enhancement, 2026-10-05 — the catalogue UNIT list. Full CRUD
 * (add/rename/archive/restore/reorder) + agency isolation
 * (BUILD_STANDARD §1a/§8).
 */
final class RentalCatalogueUnitTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Catalogue Unit Agency', 'slug' => 'cu-' . uniqid()]);
        $this->other = Agency::create(['name' => 'Other Agency', 'slug' => 'cu-other-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->other->id);
    }

    public function test_seeded_defaults_match_johans_list(): void
    {
        $names = RentalCatalogueUnit::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('name');
        $this->assertSame(
            ['Each', 'Dozen', 'Box', 'Pack', 'Metre', 'm²', 'Litre', 'kg', 'Hour', 'Day', 'Call-out'],
            $names->all()
        );
    }

    public function test_the_happy_path_adds_a_unit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.catalogue-units.store', $this->agency), [
            'name' => 'Roll',
        ])->assertRedirect();

        $unit = RentalCatalogueUnit::firstWhere(['agency_id' => $this->agency->id, 'name' => 'Roll']);
        $this->assertNotNull($unit);
    }

    public function test_required_fields_reject_cleanly_when_empty(): void
    {
        $this->actingAs($this->admin)->post(route('admin.catalogue-units.store', $this->agency), [
            'name' => '',
        ])->assertSessionHasErrors(['name']);
    }

    public function test_rename(): void
    {
        $unit = RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.catalogue-units.update', [$this->agency, $unit]), [
            'name' => 'Unit',
        ])->assertRedirect();

        $this->assertSame('Unit', $unit->fresh()->name);
    }

    public function test_archive_and_restore(): void
    {
        $unit = RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.catalogue-units.archive', [$this->agency, $unit]))->assertRedirect();
        $this->assertSoftDeleted($unit);

        $this->actingAs($this->admin)->post(route('admin.catalogue-units.restore', [$this->agency, $unit->id]))->assertRedirect();
        $this->assertNotSoftDeleted($unit->fresh());
    }

    public function test_reorder(): void
    {
        $ids = RentalCatalogueUnit::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->admin)->post(route('admin.catalogue-units.reorder', $this->agency), ['order' => $reversed])->assertRedirect();

        $reordered = RentalCatalogueUnit::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame($reversed, $reordered);
    }

    public function test_an_agency_cannot_touch_another_agencys_units(): void
    {
        $theirUnit = RentalCatalogueUnit::where('agency_id', $this->other->id)->first();

        // Same shape as AgencyVatSetupTest::test_vat_types_are_agency_isolated()
        // — route-model binding on $catalogueUnit is itself agency-scoped
        // (AgencyScope, via the authenticated admin's own agency), so even
        // naming the OTHER agency in the {agency} segment can't find the
        // other agency's row: it 404s before authorizeAgency() ever runs.
        $resp = $this->actingAs($this->admin)->put(route('admin.catalogue-units.update', [$this->agency, $theirUnit]), [
            'name' => 'Hijacked',
        ]);
        $resp->assertStatus(404);
        $this->assertNotSame('Hijacked', $theirUnit->fresh()->name);
    }
}
