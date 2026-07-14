<?php

namespace Tests\Feature\Admin;

use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-246 — the P24 Suburb Mappings screen keeps its flat identity and gains a
 * Region column that reads THROUGH the suburb's P24 town (towns.region → alias),
 * edited at TOWN level (applies to the whole town). Proof case: Albersville →
 * Port Shepstone → Ray Nkonyeni → "Hibiscus Coast".
 */
class P24SuburbRegionColumnTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $cityId;
    private int $townId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        PermissionService::clearCache();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(4), 'slug' => 'hfc-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        RolePermission::insert([
            ['role' => 'admin', 'permission_key' => 'manage_p24', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();

        // P24 read-only hierarchy: country ← province ← city(town) ← suburb.
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 1, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 4, 'p24_country_id' => $countryId, 'name' => 'KwaZulu Natal', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90403, 'p24_province_id' => $provId, 'name' => 'Port Shepstone',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Agency town mirrors the P24 town, carries the region (MDB municipality).
        $this->townId = (int) DB::table('towns')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Port Shepstone', 'slug' => 'port-shepstone',
            'p24_city_id' => $this->cityId, 'region' => 'Ray Nkonyeni',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Agency alias renames the municipality for its market.
        DB::table('region_aliases')->insert([
            'agency_id' => $this->agencyId, 'municipality' => 'Ray Nkonyeni', 'alias' => 'Hibiscus Coast',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The suburb — note its OWN region column is stale ("Umzumbe"); the screen
        // must ignore it and read through the town.
        DB::table('p24_suburbs')->insert([
            'name' => 'Albersville', 'slug' => 'albersville', 'p24_id' => 10882,
            'p24_city_id' => $this->cityId, 'region' => 'Umzumbe', 'confirmed' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'role' => 'admin', 'is_active' => true,
        ]);
    }

    /** The screen renders the read-through town-region column + alias + affordance. */
    public function test_region_column_reads_through_the_town_with_alias(): void
    {
        $resp = $this->actingAs($this->admin())->get(route('admin.p24-suburbs.index'));

        $resp->assertStatus(200);
        $resp->assertSee('Albersville', false);                       // the flat suburb row (identity kept)
        $resp->assertSee('Port Shepstone', false);                    // P24 town (read-through)
        $resp->assertSee('KwaZulu Natal', false);                     // province (read-only)
        $resp->assertSee('Hibiscus Coast', false);                    // region shows the ALIAS, not "Ray Nkonyeni" raw
        $resp->assertSee('applies to all of Port Shepstone', false);  // town-scoped affordance
    }

    /** Editing region is town-level — it moves the town (and thus every suburb in it). */
    public function test_save_town_region_applies_to_the_whole_town(): void
    {
        $resp = $this->actingAs($this->admin())
            ->put(route('admin.p24-suburbs.town-region', $this->townId), ['region' => 'Umdoni']);

        $resp->assertRedirect();
        $this->assertDatabaseHas('towns', ['id' => $this->townId, 'region' => 'Umdoni']);
        // A newly-added alias row lets the new municipality be renamed later.
        $this->assertDatabaseHas('region_aliases', ['agency_id' => $this->agencyId, 'municipality' => 'Umdoni']);
    }

    /** update() clobber guard — a row Save must NOT reset the suburb's legacy region. */
    public function test_row_save_does_not_clobber_region(): void
    {
        $suburb = DB::table('p24_suburbs')->where('slug', 'albersville')->first();

        $resp = $this->actingAs($this->admin())->put(route('admin.p24-suburbs.update', $suburb->id), [
            'name' => 'Albersville', 'p24_id' => 10882, 'confirmed' => '1',
            // note: NO region field (the row no longer edits region)
        ]);

        $resp->assertRedirect();
        // Pre-fix this would have been forced to 'kzn-south-coast'.
        $this->assertDatabaseHas('p24_suburbs', ['id' => $suburb->id, 'region' => 'Umzumbe']);
    }

    /** Province filter narrows to that province's suburbs (P24 hierarchy). */
    public function test_province_filter_works(): void
    {
        $provId = (int) DB::table('p24_provinces')->where('name', 'KwaZulu Natal')->value('id');
        $resp = $this->actingAs($this->admin())
            ->get(route('admin.p24-suburbs.index', ['province' => $provId]));
        $resp->assertStatus(200);
        $resp->assertSee('Albersville', false);
    }

    /**
     * A suburb WITH a P24 city but NO agency town (the 88% case — Durban North) is
     * NOT a dead end: it renders an assignable region dropdown, and assigning it
     * materialises the agency town and applies to the whole town.
     */
    public function test_city_without_agency_town_is_assignable_and_materialises_the_town(): void
    {
        $provId = (int) DB::table('p24_provinces')->where('name', 'KwaZulu Natal')->value('id');
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90331, 'p24_province_id' => $provId, 'name' => 'Durban North',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Suburb has the city, but there is NO towns row for it.
        $subId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Durban North', 'slug' => 'durban-north-x', 'p24_id' => 6077,
            'p24_city_id' => $cityId, 'region' => null, 'confirmed' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, DB::table('towns')->where('agency_id', $this->agencyId)->where('p24_city_id', $cityId)->count());

        // Screen: the row is assignable (city-region form present), NOT a dead end.
        $resp = $this->actingAs($this->admin())->get(route('admin.p24-suburbs.index', ['q' => 'Durban North']));
        $resp->assertStatus(200);
        $resp->assertSee('id="cityrgn-' . $subId . '"', false);   // an assignable region control
        $resp->assertSee('Durban North', false);
        $resp->assertDontSee('no P24 town', false);               // no dead-end message

        // Assigning materialises the agency town + sets the region.
        $put = $this->actingAs($this->admin())
            ->put(route('admin.p24-suburbs.city-region', $cityId), ['region' => 'eThekwini']);
        $put->assertRedirect();
        $this->assertDatabaseHas('towns', [
            'agency_id' => $this->agencyId, 'p24_city_id' => $cityId, 'name' => 'Durban North', 'region' => 'eThekwini',
        ]);
    }

    /** The Add New Suburb form no longer ships the stale hardcoded region field. */
    public function test_add_form_has_no_hardcoded_region(): void
    {
        $resp = $this->actingAs($this->admin())->get(route('admin.p24-suburbs.index'));
        $resp->assertStatus(200);
        $resp->assertDontSee('kzn-south-coast', false);
    }

    /** Truly-townless suburb (no P24 city) keeps a marked suburb-level fallback that persists. */
    public function test_townless_suburb_uses_suburb_level_fallback(): void
    {
        $subId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Manual Place', 'slug' => 'manual-place', 'p24_id' => null,
            'p24_city_id' => null, 'region' => null, 'confirmed' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resp = $this->actingAs($this->admin())->get(route('admin.p24-suburbs.index', ['q' => 'Manual Place']));
        $resp->assertStatus(200);
        $resp->assertSee('suburb-level (no P24 town)', false);

        // A row Save with a region writes the suburb-level region (fallback).
        $put = $this->actingAs($this->admin())->put(route('admin.p24-suburbs.update', $subId), [
            'name' => 'Manual Place', 'region' => 'Ray Nkonyeni',
        ]);
        $put->assertRedirect();
        $this->assertDatabaseHas('p24_suburbs', ['id' => $subId, 'region' => 'Ray Nkonyeni']);
    }
}
