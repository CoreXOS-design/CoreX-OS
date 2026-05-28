<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\Map\MapSavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.3.2 — saved-search CRUD tests (M84-M88).
 *
 *   M84 — store creates a user-scoped row with a JSON payload
 *   M85 — index returns only the caller's rows (per-user isolation)
 *   M86 — setting is_default unsets it on the user's prior default
 *   M87 — store rejects a duplicate name for the same user
 *   M88 — destroy soft-deletes and the row drops out of index
 */
final class MapSavedSearchTest extends TestCase
{
    use RefreshDatabase;

    /** M84 — POST creates the row with payload intact. */
    public function test_m84_store_creates_user_scoped_saved_search(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user)->postJson(route('corex.map.saved-searches.store'), [
            'name'           => 'Margate houses 1-2m',
            'filter_payload' => [
                'scope' => 'agency',
                'types' => ['house'],
                'priceMin' => 1_000_000,
                'priceMax' => 2_000_000,
            ],
            'is_default' => false,
        ]);

        $resp->assertCreated();
        $body = $resp->json();
        $this->assertNotEmpty($body['saved_search']['id']);
        $this->assertSame('Margate houses 1-2m', $body['saved_search']['name']);

        $row = MapSavedSearch::withoutGlobalScopes()->find($body['saved_search']['id']);
        $this->assertNotNull($row);
        $this->assertSame((int) $user->agency_id, (int) $row->agency_id);
        $this->assertSame((int) $user->id, (int) $row->user_id);
        $this->assertSame('agency', $row->filter_payload['scope']);
    }

    /** M85 — index isolates by user_id even within the same agency. */
    public function test_m85_index_is_per_user(): void
    {
        $alice = $this->makeUser();
        $bob   = $this->makeUser($alice->agency_id);

        MapSavedSearch::create([
            'agency_id' => $alice->agency_id, 'user_id' => $alice->id,
            'name' => 'Alice search', 'filter_payload' => ['scope' => 'my'], 'is_default' => false,
        ]);
        MapSavedSearch::create([
            'agency_id' => $bob->agency_id, 'user_id' => $bob->id,
            'name' => 'Bob search', 'filter_payload' => ['scope' => 'my'], 'is_default' => false,
        ]);

        $resp = $this->actingAs($alice)->getJson(route('corex.map.saved-searches.index'));
        $resp->assertOk();
        $names = collect($resp->json('saved_searches'))->pluck('name')->all();
        $this->assertContains('Alice search', $names);
        $this->assertNotContains('Bob search', $names,
            'index must NOT leak another user\'s saved searches');
    }

    /** M86 — promoting one search to default unsets the user's prior default. */
    public function test_m86_setting_default_unsets_prior_default(): void
    {
        $user = $this->makeUser();

        $first  = MapSavedSearch::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id,
            'name' => 'First', 'filter_payload' => [], 'is_default' => true,
        ]);
        $second = MapSavedSearch::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id,
            'name' => 'Second', 'filter_payload' => [], 'is_default' => false,
        ]);

        $resp = $this->actingAs($user)->patchJson(
            route('corex.map.saved-searches.update', ['id' => $second->id]),
            ['is_default' => true]
        );
        $resp->assertOk();

        $this->assertFalse((bool) $first->fresh()->is_default,
            'prior default must be cleared');
        $this->assertTrue((bool) $second->fresh()->is_default);
    }

    /** M87 — duplicate name for the same user is rejected with 422. */
    public function test_m87_duplicate_name_per_user_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson(route('corex.map.saved-searches.store'), [
            'name'           => 'My favourites',
            'filter_payload' => ['scope' => 'my'],
        ])->assertCreated();

        $this->actingAs($user)->postJson(route('corex.map.saved-searches.store'), [
            'name'           => 'My favourites',
            'filter_payload' => ['scope' => 'agency'],
        ])->assertStatus(422);
    }

    /** M88 — destroy soft-deletes and the row vanishes from index. */
    public function test_m88_destroy_soft_deletes(): void
    {
        $user = $this->makeUser();
        $row  = MapSavedSearch::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id,
            'name' => 'Doomed', 'filter_payload' => [], 'is_default' => false,
        ]);

        $this->actingAs($user)
            ->deleteJson(route('corex.map.saved-searches.destroy', ['id' => $row->id]))
            ->assertOk();

        $this->assertSoftDeleted('map_saved_searches', ['id' => $row->id]);

        $names = collect(
            $this->actingAs($user)->getJson(route('corex.map.saved-searches.index'))->json('saved_searches')
        )->pluck('name')->all();
        $this->assertNotContains('Doomed', $names);
    }

    /**
     * M89 — v2 saved-search payload round-trips through store + index.
     *
     * Saved-search persistence Phase B added four categories to the JS
     * payload (enabled_layers, display_mode, base_layer, map_view) that
     * the legacy flat-filter shape missed. The controller validates
     * `filter_payload: required|array`, accepts any array shape — this
     * test guards that the new wrapped shape comes back identical.
     */
    public function test_m89_v2_payload_round_trips_intact(): void
    {
        $user = $this->makeUser();

        $payload = [
            'schema_version' => 2,
            'filters' => [
                'scope'        => 'agency',
                'search'       => '',
                'types'        => ['house', 'sectional'],
                'priceMin'     => 1_500_000,
                'priceMax'     => 3_000_000,
                'bedroomsMin'  => 3,
                'listingStatus'=> [],
                'soldWindow'   => '12mo',
            ],
            'enabled_layers' => ['hfc_listings', 'tracked_properties'],
            'display_mode'   => 'both',
            'base_layer'     => 'satellite',
            'map_view'       => ['lat' => -30.85123, 'lng' => 30.39456, 'zoom' => 15],
        ];

        $this->actingAs($user)->postJson(route('corex.map.saved-searches.store'), [
            'name'           => 'Margate sectional 1.5-3m',
            'filter_payload' => $payload,
            'is_default'     => false,
        ])->assertCreated();

        $rows = $this->actingAs($user)
            ->getJson(route('corex.map.saved-searches.index'))
            ->assertOk()
            ->json('saved_searches');

        $this->assertCount(1, $rows);
        $loaded = $rows[0]['filter_payload'];
        $this->assertSame(2, $loaded['schema_version']);
        $this->assertSame(['hfc_listings', 'tracked_properties'], $loaded['enabled_layers']);
        $this->assertSame('both', $loaded['display_mode']);
        $this->assertSame('satellite', $loaded['base_layer']);
        $this->assertSame(-30.85123, $loaded['map_view']['lat']);
        $this->assertSame(30.39456, $loaded['map_view']['lng']);
        $this->assertSame(15, $loaded['map_view']['zoom']);
        $this->assertSame('agency', $loaded['filters']['scope']);
        $this->assertSame(['house', 'sectional'], $loaded['filters']['types']);
        $this->assertSame(3_000_000, $loaded['filters']['priceMax']);
        $this->assertSame('12mo', $loaded['filters']['soldWindow']);
    }

    /**
     * M90 — legacy (flat) payload still loads via the index endpoint with
     * its original shape. The JS load path detects the absence of
     * `schema_version`/`filters` keys and treats the payload as the
     * legacy FILTER_DEFAULTS shape (back-compat).
     */
    public function test_m90_legacy_flat_payload_still_loads(): void
    {
        $user = $this->makeUser();

        // Insert a row with the legacy shape directly (mimics rows saved
        // before the persistence fix shipped).
        DB::table('map_saved_searches')->insert([
            'agency_id' => $user->agency_id,
            'user_id'   => $user->id,
            'name'      => 'Legacy view',
            'filter_payload' => json_encode([
                'scope' => 'agency', 'search' => '', 'types' => ['house'],
                'priceMin' => 1_000_000, 'priceMax' => null,
                'bedroomsMin' => null, 'bedroomsMax' => null,
                'bathroomsMin' => null, 'bathroomsMax' => null,
                'standMin' => null, 'standMax' => null,
                'buildingMin' => null, 'buildingMax' => null,
                'listingStatus' => [], 'soldWindow' => '',
                'domMin' => null, 'domMax' => null,
                'yearFrom' => null, 'yearTo' => null,
            ]),
            'is_default' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->actingAs($user)
            ->getJson(route('corex.map.saved-searches.index'))
            ->assertOk()
            ->json('saved_searches');

        $this->assertCount(1, $rows);
        $loaded = $rows[0]['filter_payload'];
        // Legacy shape — no schema_version, no nested filters key.
        $this->assertArrayNotHasKey('schema_version', $loaded);
        $this->assertArrayNotHasKey('filters', $loaded);
        // The top-level keys ARE the filters themselves.
        $this->assertSame('agency', $loaded['scope']);
        $this->assertSame(['house'], $loaded['types']);
        $this->assertSame(1_000_000, $loaded['priceMin']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeUser(?int $agencyId = null): User
    {
        if ($agencyId === null) {
            $agencyId = (int) DB::table('agencies')->insertGetId([
                'name'       => 'Agency-' . Str::random(6),
                'slug'       => Str::random(8),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('branches')->insert([
                'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // role=super_admin bypasses the permission middleware via Role.is_owner.
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'super_admin',
        ]);
    }
}
