<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-360 — buyers pipeline "View Matches" button.
 *
 * Regression guard for the added action: it must link to the EXACT same
 * match-results route Core Matches' own "View Matches" uses
 * (corex.contacts.matches.results), scoped to THIS buyer's contact id and
 * their resolved wishlist (primary, or most recent if none is primary) — in
 * both the List view and the Kanban view. Also guards the empty-state case:
 * a buyer with no wishlist gets no "View Matches" link at all, not a link to
 * an empty results screen.
 */
final class BuyerPipelineViewMatchesLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_list_view_links_view_matches_to_the_buyers_primary_wishlist_results_route(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $other = $this->match($agencyId, $buyer->id, ['is_primary' => false]);
        $primary = $this->match($agencyId, $buyer->id, ['is_primary' => true]);

        $resp = $this->actingAs($agent)->get(
            route('command-center.buyers.pipeline', ['view' => 'list', 'scope' => 'agency'])
        );

        $resp->assertStatus(200);
        $resp->assertSee(route('corex.contacts.matches.results', [$buyer, $primary]), false);
        // Must not point at the non-primary wishlist's results.
        $resp->assertDontSee(route('corex.contacts.matches.results', [$buyer, $other]), false);
    }

    public function test_kanban_view_links_view_matches_to_the_buyers_primary_wishlist_results_route(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $other = $this->match($agencyId, $buyer->id, ['is_primary' => false]);
        $primary = $this->match($agencyId, $buyer->id, ['is_primary' => true]);

        $resp = $this->actingAs($agent)->get(
            route('command-center.buyers.pipeline', ['view' => 'kanban', 'scope' => 'agency'])
        );

        $resp->assertStatus(200);
        $resp->assertSee(route('corex.contacts.matches.results', [$buyer, $primary]), false);
        $resp->assertDontSee(route('corex.contacts.matches.results', [$buyer, $other]), false);
    }

    public function test_view_matches_link_falls_back_to_most_recent_wishlist_when_none_is_primary(): void
    {
        // The pipeline's eager-loaded Contact::matches() relation orders via
        // ->latest() (created_at DESC, not updated_at) — force a distinct
        // created_at so the "most recent" fallback is deterministic rather
        // than relying on two same-second inserts tie-breaking consistently.
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $older = $this->match($agencyId, $buyer->id, ['is_primary' => false]);
        $older->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();
        $newer = $this->match($agencyId, $buyer->id, ['is_primary' => false]);
        // ContactMatchObserver (D1) auto-promotes a contact's FIRST-EVER
        // wishlist to is_primary=true regardless of what's passed in — a raw
        // update (bypassing the observer) is the only way to construct a
        // genuine "no wishlist is primary" scenario to isolate the fallback.
        DB::table('contact_matches')->whereIn('id', [$older->id, $newer->id])->update(['is_primary' => false]);

        $resp = $this->actingAs($agent)->get(
            route('command-center.buyers.pipeline', ['view' => 'list', 'scope' => 'agency'])
        );

        $resp->assertStatus(200);
        $resp->assertSee(route('corex.contacts.matches.results', [$buyer, $newer]), false);
        $resp->assertDontSee(route('corex.contacts.matches.results', [$buyer, $older]), false);
    }

    public function test_view_matches_link_absent_for_a_buyer_with_no_wishlist(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        // No ContactMatch created — the empty-guard case.

        $resp = $this->actingAs($agent)->get(
            route('command-center.buyers.pipeline', ['view' => 'list', 'scope' => 'agency'])
        );

        $resp->assertStatus(200);
        $resp->assertDontSee('View Matches');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} [agencyId, agent] */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);

        return [$agencyId, $agent];
    }

    private function buyer(int $agencyId, int $agentId): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Bea', 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'bea-' . Str::random(5) . '@example.co.za',
        ]);
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_min' => 500_000, 'price_max' => 900_000,
        ], $extra));
    }
}
