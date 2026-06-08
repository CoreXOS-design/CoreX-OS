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
 * Agency Public API — Phase 6 (branches / offices on the website).
 *
 * Proves the /api/v1/website/branches endpoints return each branch's trading
 * identity (trading name, address, phone override, email, logo), nest only the
 * show_on_website agents that fall under the branch, count only the listings
 * syndicated-and-enabled for the key, honour the branches:read scope, stay
 * tenant-isolated, and that ?branch_id= filters agents + listings. Also that
 * the agency endpoint surfaces the website_show_branches content toggle.
 *
 * Spec: .ai/specs/agency-public-api.md §5.1
 */
class Phase6BranchesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private AgencyApiKey $key;
    private string $token;
    private Branch $coast;
    private Branch $inland;
    private User $coastAgent;
    private User $inlandAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty', 'slug' => 'coastal-realty',
            'website_enabled' => true, 'website_show_branches' => true,
        ]);

        $this->coast = Branch::create([
            'agency_id' => $this->agency->id, 'name' => 'Margate Office',
            'trading_name' => 'Coastal Realty Margate', 'address' => '12 Marina Drive, Margate',
            'phone' => '039 312 0000', 'phone_label' => 'Office', 'email' => 'margate@coastal.example',
            'ppra_number' => 'PPRA-MGT-1',
        ]);
        $this->inland = Branch::create([
            'agency_id' => $this->agency->id, 'name' => 'Port Shepstone Office',
            // No trading_name — must fall back to the branch name.
        ]);

        $this->coastAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->coast->id,
            'role' => 'agent', 'name' => 'Thandi Mbeki', 'show_on_website' => true,
        ]);
        // Hidden agent in the same branch — must never nest.
        User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->coast->id,
            'role' => 'agent', 'name' => 'Hidden Harry', 'show_on_website' => false,
        ]);
        $this->inlandAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->inland->id,
            'role' => 'agent', 'name' => 'Sipho Dlamini', 'show_on_website' => true,
        ]);

        $minted = AgencyApiKey::mintSecret();
        $this->token = $minted['plaintext'];
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Coastal Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [
                AgencyApiKey::SCOPE_BRANCHES_READ,
                AgencyApiKey::SCOPE_AGENTS_READ,
                AgencyApiKey::SCOPE_LISTINGS_READ,
                AgencyApiKey::SCOPE_AGENCY_READ,
            ],
        ]);
    }

    public function test_branches_index_returns_trading_identity_and_nested_agents(): void
    {
        $resp = $this->withToken($this->token)->getJson('/api/v1/website/branches')->assertOk();

        $coast = collect($resp->json('data'))->firstWhere('id', $this->coast->id);
        $this->assertSame('Coastal Realty Margate', $coast['trading_name']);
        $this->assertSame('12 Marina Drive, Margate', $coast['address']);
        $this->assertSame('039 312 0000', $coast['phone']);
        $this->assertSame('margate@coastal.example', $coast['email']);
        $this->assertSame('PPRA-MGT-1', $coast['ppra_number']);

        // Only the visible agent nests; the hidden one is gone.
        $this->assertSame(1, $coast['agent_count']);
        $names = collect($coast['agents'])->pluck('name');
        $this->assertTrue($names->contains('Thandi Mbeki'));
        $this->assertFalse($names->contains('Hidden Harry'));
    }

    public function test_branch_without_trading_name_falls_back_to_branch_name(): void
    {
        $resp = $this->withToken($this->token)->getJson('/api/v1/website/branches')->assertOk();
        $inland = collect($resp->json('data'))->firstWhere('id', $this->inland->id);

        $this->assertSame('Port Shepstone Office', $inland['trading_name']);
    }

    public function test_branch_listing_count_only_counts_enabled_syndication_for_key(): void
    {
        $live     = $this->makeProperty($this->coast, 'Sea-view stunner');
        $disabled = $this->makeProperty($this->coast, 'Toggled off');
        $noPivot  = $this->makeProperty($this->coast, 'Not syndicated');
        $this->syndicate($live, true);
        $this->syndicate($disabled, false);
        // $noPivot has no syndication row at all.

        $resp = $this->withToken($this->token)->getJson('/api/v1/website/branches')->assertOk();
        $coast = collect($resp->json('data'))->firstWhere('id', $this->coast->id);

        $this->assertSame(1, $coast['listing_count']);
    }

    public function test_branch_id_filter_on_agents_and_listings(): void
    {
        $coastListing  = $this->makeProperty($this->coast, 'Coast home');
        $inlandListing = $this->makeProperty($this->inland, 'Inland home');
        $this->syndicate($coastListing, true);
        $this->syndicate($inlandListing, true);

        // Agents filtered to the inland branch.
        $agentNames = collect(
            $this->withToken($this->token)->getJson("/api/v1/website/agents?branch_id={$this->inland->id}")->json('data')
        )->pluck('name');
        $this->assertTrue($agentNames->contains('Sipho Dlamini'));
        $this->assertFalse($agentNames->contains('Thandi Mbeki'));

        // Listings filtered to the coast branch.
        $titles = collect(
            $this->withToken($this->token)->getJson("/api/v1/website/listings?branch_id={$this->coast->id}")->json('data')
        )->pluck('title');
        $this->assertTrue($titles->contains('Coast home'));
        $this->assertFalse($titles->contains('Inland home'));
    }

    public function test_show_detail_404_for_other_agency_branch(): void
    {
        $agencyB = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront', 'website_enabled' => true]);
        $branchB = Branch::create(['agency_id' => $agencyB->id, 'name' => 'B Office']);

        $this->withToken($this->token)->getJson("/api/v1/website/branches/{$branchB->id}")->assertStatus(404);
    }

    public function test_cross_agency_isolation_on_index(): void
    {
        $agencyB = Agency::create(['name' => 'Beachfront', 'slug' => 'beachfront', 'website_enabled' => true]);
        Branch::create(['agency_id' => $agencyB->id, 'name' => 'B Office']);

        $ids = collect($this->withToken($this->token)->getJson('/api/v1/website/branches')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->coast->id));
        $this->assertSame(2, $ids->count()); // only this agency's two branches
    }

    public function test_scope_enforced_on_branches(): void
    {
        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'No-branches', 'key_prefix' => $minted['prefix'],
            'secret_hash' => $minted['hash'], 'scopes' => [AgencyApiKey::SCOPE_AGENTS_READ],
        ]);
        $this->withToken($minted['plaintext'])->getJson('/api/v1/website/branches')->assertStatus(403);
    }

    public function test_agency_endpoint_exposes_show_branches_toggle(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertOk()->assertJsonPath('data.show.branches', true);

        // Reset the resolved guard so the next request re-reads the key + its
        // agency from the DB (within one test the container persists and would
        // otherwise serve the previously-resolved, now-stale agency).
        $this->agency->update(['website_show_branches' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/website/agency')
            ->assertOk()->assertJsonPath('data.show.branches', false);
    }

    // ---- helpers -----------------------------------------------------------

    private function makeProperty(Branch $branch, string $title): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->coastAgent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => $title, 'suburb' => 'Margate',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
            'beds' => 3, 'baths' => 2, 'published_at' => now(),
        ]);
    }

    private function syndicate(Property $p, bool $enabled): void
    {
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'agency_api_key_id' => $this->key->id,
            'enabled' => $enabled,
            'status' => $enabled ? PropertyWebsiteSyndication::STATUS_ACTIVE : PropertyWebsiteSyndication::STATUS_DEACTIVATED,
        ]);
    }
}
