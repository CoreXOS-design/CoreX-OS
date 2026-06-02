<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 2 (the /api/v1/website/* read endpoints).
 *
 * Proves listings are filtered to the key's enabled syndication pivot rows,
 * agents to show_on_website (tenant-safe), agency branding/settings surface,
 * the resource shapes hide PII, cross-agency isolation holds, and the catalog
 * groups everything under a "Website" section.
 *
 * Spec: .ai/specs/agency-public-api.md §5, §6.5, §11
 */
class Phase2WebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private AgencyApiKey $key;
    private string $token;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty', 'slug' => 'coastal-realty', 'website_enabled' => true,
            'website_tagline' => 'Your coast, your home', 'website_url' => 'https://coastal.example',
            'website_social_facebook' => 'coastalrealty', 'button_color' => '#0ea5e9',
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'name' => 'Thandi Mbeki', 'show_on_website' => true,
        ]);
        // Hidden agent — must never surface.
        User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'name' => 'Hidden Harry', 'show_on_website' => false,
        ]);

        $minted = AgencyApiKey::mintSecret();
        $this->token = $minted['plaintext'];
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Coastal Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [
                AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_AGENTS_READ, AgencyApiKey::SCOPE_AGENCY_READ,
            ],
        ]);
    }

    public function test_ping_returns_key_context(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/website/ping')
            ->assertOk()
            ->assertJson(['ok' => true, 'agency_id' => $this->agency->id, 'website' => 'Coastal Website']);
    }

    public function test_agency_endpoint_returns_branding_and_settings(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertOk()
            ->assertJsonPath('data.name', 'Coastal Realty')
            ->assertJsonPath('data.tagline', 'Your coast, your home')
            ->assertJsonPath('data.website_url', 'https://coastal.example')
            ->assertJsonPath('data.social.facebook', 'coastalrealty')
            ->assertJsonPath('data.branding.button_color', '#0ea5e9');
    }

    public function test_listings_returns_only_syndicated_enabled_for_this_key(): void
    {
        $live   = $this->makeProperty('Sea-view stunner', 'active', 2495000);
        $offSite = $this->makeProperty('Not syndicated', 'active', 1200000);
        $disabled = $this->makeProperty('Toggled off', 'active', 999000);

        $this->syndicate($live, true);
        $this->syndicate($disabled, false); // pivot exists but disabled
        // $offSite has no pivot row at all.

        $resp = $this->withToken($this->token)->getJson('/api/v1/website/listings')->assertOk();

        $titles = collect($resp->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Sea-view stunner'));
        $this->assertFalse($titles->contains('Not syndicated'));
        $this->assertFalse($titles->contains('Toggled off'));
        $this->assertCount(1, $resp->json('data'));
    }

    public function test_listing_detail_by_id_and_reference_and_hides_pii(): void
    {
        $p = $this->makeProperty('Sea-view stunner', 'active', 2495000);
        $this->syndicate($p, true);

        $byId = $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertOk();
        $byId->assertJsonPath('data.price_display', 'R 2,495,000')
            ->assertJsonPath('data.agent.name', 'Thandi Mbeki');
        // No owner/PII leakage in the public shape.
        $this->assertArrayNotHasKey('agent_id', $byId->json('data'));
        $this->assertArrayNotHasKey('address_internal_note', $byId->json('data'));

        // By external reference too.
        $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->external_id}")
            ->assertOk()->assertJsonPath('data.id', $p->id);
    }

    public function test_non_syndicated_listing_detail_is_404(): void
    {
        $p = $this->makeProperty('Hidden listing', 'active', 1000000);
        // no syndication row
        $this->withToken($this->token)->getJson("/api/v1/website/listings/{$p->id}")->assertStatus(404);
    }

    public function test_agents_returns_only_show_on_website(): void
    {
        $resp = $this->withToken($this->token)->getJson('/api/v1/website/agents')->assertOk();
        $names = collect($resp->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Thandi Mbeki'));
        $this->assertFalse($names->contains('Hidden Harry'));
        // Agent card excludes the FFC number (compliance).
        $this->assertArrayNotHasKey('ffc_number', $resp->json('data.0'));
    }

    public function test_cross_agency_isolation(): void
    {
        // Agency B with its own enabled listing + visible agent.
        $agencyB = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront', 'website_enabled' => true]);
        $branchB = Branch::create(['agency_id' => $agencyB->id, 'name' => 'B']);
        $agentB = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'role' => 'agent', 'name' => 'B Agent', 'show_on_website' => true]);
        $mintB = AgencyApiKey::mintSecret();
        $keyB = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyB->id, 'name' => 'B site', 'key_prefix' => $mintB['prefix'], 'secret_hash' => $mintB['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
        $propB = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyB->id, 'agent_id' => $agentB->id, 'branch_id' => $branchB->id,
            'external_id' => (string) Str::uuid(), 'title' => 'B listing', 'suburb' => 'Ramsgate',
            'property_type' => 'house', 'status' => 'active', 'price' => 800000,
        ]);
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agencyB->id, 'property_id' => $propB->id, 'agency_api_key_id' => $keyB->id, 'enabled' => true,
        ]);

        // Agency A's key sees none of B's data.
        $this->withToken($this->token)->getJson('/api/v1/website/listings')
            ->assertOk()->assertJsonCount(0, 'data');
        $names = collect($this->withToken($this->token)->getJson('/api/v1/website/agents')->json('data'))->pluck('name');
        $this->assertFalse($names->contains('B Agent'));
    }

    public function test_scope_enforced_on_listings(): void
    {
        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Agents-only', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [AgencyApiKey::SCOPE_AGENTS_READ],
        ]);
        $this->withToken($minted['plaintext'])->getJson('/api/v1/website/listings')->assertStatus(403);
    }

    public function test_catalog_groups_website_routes_under_website_section(): void
    {
        $admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);

        $this->actingAs($admin)->get('/admin/api')
            ->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->has('Website'));
    }

    // ---- helpers -----------------------------------------------------------

    private function makeProperty(string $title, string $status, int $price): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => $title, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => $price,
            'beds' => 3, 'baths' => 2, 'published_at' => now(),
        ]);
    }

    private function syndicate(Property $p, bool $enabled): void
    {
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'agency_api_key_id' => $this->key->id,
            'enabled' => $enabled, 'status' => $enabled ? PropertyWebsiteSyndication::STATUS_ACTIVE : PropertyWebsiteSyndication::STATUS_DEACTIVATED,
        ]);
    }
}
