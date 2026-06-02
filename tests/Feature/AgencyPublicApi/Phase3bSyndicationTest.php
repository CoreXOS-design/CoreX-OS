<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 3b (website syndication: per-property toggle + bulk-activate).
 *
 * Spec: .ai/specs/agency-public-api.md §6.5
 */
class Phase3bSyndicationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private AgencyApiKey $key;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);

        $minted = AgencyApiKey::mintSecret();
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
    }

    public function test_toggle_enables_then_disables_the_pivot(): void
    {
        $p = $this->makeProperty('active');

        // First toggle → enabled.
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))
            ->assertOk()->assertJson(['enabled' => true, 'status' => 'active', 'website' => 'Main Website']);

        $this->assertDatabaseHas('property_website_syndication', [
            'property_id' => $p->id, 'agency_api_key_id' => $this->key->id, 'enabled' => 1,
        ]);

        // Second toggle → disabled.
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))
            ->assertOk()->assertJson(['enabled' => false, 'status' => 'deactivated']);
    }

    public function test_toggle_rejects_key_from_another_agency(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other']);
        $mint = AgencyApiKey::mintSecret();
        $foreignKey = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $other->id, 'name' => 'Foreign', 'key_prefix' => $mint['prefix'],
            'secret_hash' => $mint['hash'], 'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
        $p = $this->makeProperty('active');

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.website-syndication.toggle', [$p, $foreignKey]))
            ->assertStatus(404);
    }

    public function test_bulk_activate_enables_only_active_listings_and_is_idempotent(): void
    {
        $a1 = $this->makeProperty('active');
        $a2 = $this->makeProperty('active');
        $draft = $this->makeProperty('draft');
        $sold = $this->makeProperty('sold');

        $svc = app(WebsiteSyndicationService::class);

        $first = $svc->bulkActivateActive($this->key);
        $this->assertSame(2, $first['enabled']);
        $this->assertSame(0, $first['already_live']);

        // Draft + sold are NOT enabled.
        $this->assertDatabaseMissing('property_website_syndication', ['property_id' => $draft->id, 'enabled' => 1]);
        $this->assertDatabaseMissing('property_website_syndication', ['property_id' => $sold->id, 'enabled' => 1]);

        // Idempotent re-run: nothing new, both already live.
        $second = $svc->bulkActivateActive($this->key);
        $this->assertSame(0, $second['enabled']);
        $this->assertSame(2, $second['already_live']);
    }

    public function test_bulk_activate_via_admin_route(): void
    {
        $this->makeProperty('active');
        $this->makeProperty('active');

        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.bulk-activate', [$this->agency, $this->key]))
            ->assertRedirect();

        $this->assertSame(2, PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('agency_api_key_id', $this->key->id)->where('enabled', true)->count());
    }

    public function test_bulk_activated_listings_appear_in_the_website_api(): void
    {
        $this->makeProperty('active');
        $this->makeProperty('active');

        app(WebsiteSyndicationService::class)->bulkActivateActive($this->key);

        // The same key, now pulling listings, sees exactly the 2 activated.
        $minted = $this->keyToken();
        $this->withToken($minted)->getJson('/api/v1/website/listings')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    // ---- helpers -----------------------------------------------------------

    private function makeProperty(string $status): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    /** Re-mint a usable token for $this->key (the original plaintext isn't retained). */
    private function keyToken(): string
    {
        $minted = AgencyApiKey::mintSecret();
        $this->key->forceFill(['key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash']])->save();
        return $minted['plaintext'];
    }
}
