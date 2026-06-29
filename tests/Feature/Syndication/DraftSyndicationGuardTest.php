<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Draft syndication guard — a property still in 'draft' must never be publishable
 * to ANY portal/website. The user reported pushing a listing to P24 that silently
 * failed because they forgot to move it off draft; the guard now blocks the
 * enable/activate path on P24, Private Property AND the agency website with a
 * clear "set to Active first" error (422, error=listing_draft).
 *
 * The draft check runs BEFORE the marketing-readiness gate, so a draft surfaces
 * the precise draft message rather than a generic compliance block.
 */
class DraftSyndicationGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private AgencyApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid(), 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);

        $minted = AgencyApiKey::mintSecret();
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);

        $this->actingAs($this->user);
    }

    private function makeProperty(string $status): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    // ── P24 ──────────────────────────────────────────────────────────────

    public function test_p24_toggle_blocks_a_draft_listing(): void
    {
        $p = $this->makeProperty('draft');

        $this->postJson(route('corex.properties.p24-syndication.toggle', $p))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft'])
            ->assertJsonFragment(['property_status' => 'draft']);

        // Nothing was enabled.
        $this->assertFalse((bool) $p->fresh()->p24_syndication_enabled);
    }

    public function test_p24_toggle_does_not_draft_block_an_active_listing(): void
    {
        $p = $this->makeProperty('active');

        // May still be blocked by the compliance gate, but NEVER by the draft gate.
        $this->postJson(route('corex.properties.p24-syndication.toggle', $p))
            ->assertJsonMissing(['error' => 'listing_draft']);
    }

    // ── Private Property ─────────────────────────────────────────────────

    public function test_pp_toggle_blocks_a_draft_listing(): void
    {
        $p = $this->makeProperty('draft');

        $this->postJson(route('corex.properties.syndication.toggle', $p))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft']);

        $this->assertFalse((bool) $p->fresh()->pp_syndication_enabled);
    }

    public function test_pp_toggle_does_not_draft_block_an_active_listing(): void
    {
        $p = $this->makeProperty('active');

        $this->postJson(route('corex.properties.syndication.toggle', $p))
            ->assertJsonMissing(['error' => 'listing_draft']);
    }

    // ── Agency website ───────────────────────────────────────────────────

    public function test_website_toggle_blocks_a_draft_listing(): void
    {
        $p = $this->makeProperty('draft');

        $this->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft'])
            ->assertJsonFragment(['property_status' => 'draft']);

        $this->assertDatabaseMissing('property_website_syndication', [
            'property_id' => $p->id, 'agency_api_key_id' => $this->key->id, 'enabled' => 1,
        ]);
    }

    public function test_website_activate_blocks_a_draft_listing(): void
    {
        $p = $this->makeProperty('draft');

        $this->postJson(route('corex.properties.website-syndication.activate', [$p, $this->key]))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'listing_draft']);
    }

    public function test_website_toggle_allows_an_active_listing(): void
    {
        // Website has no compliance gate, so an active listing enables cleanly —
        // proving the draft guard is the only thing that was blocking.
        $p = $this->makeProperty('active');

        $this->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => true]);
    }

    public function test_draft_can_still_be_disabled_on_website(): void
    {
        // Guard only fires when ENABLING. A listing that somehow sits enabled
        // while draft must still be removable (disabling is always allowed).
        $p = $this->makeProperty('active');
        // Enable while active…
        $this->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))->assertOk();
        // …then it gets reverted to draft.
        $p->update(['status' => 'draft']);

        // Toggling again = disable → must succeed despite draft status.
        $this->postJson(route('corex.properties.website-syndication.toggle', [$p, $this->key]))
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => false]);
    }
}
