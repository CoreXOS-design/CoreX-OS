<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-363 — buyer-detail Wishlists tab condensed redesign (regression guard).
 *
 * Covers the three shipped display changes:
 *   1. Each wishlist shows a match-count badge. The count is resolved via the
 *      same ClientMatchResolver call AT-360 already uses (never a duplicated
 *      matching query) — but a non-default (not-yet-expanded) wishlist's full
 *      match CARD GRID is never built/rendered on initial load, only its
 *      count. That's the actual "cheap" claim this build makes: bounded by
 *      the number of wishlists, not by pre-rendering every wishlist's full
 *      property list up front. (A true SQL-COUNT-only path would additionally
 *      require refactoring the shared, multi-lane MatchingService — flagged
 *      to Johan as a possible follow-up, not done here.)
 *   2. The lazy per-wishlist match endpoint renders the same match-card grid
 *      the accordion injects on expand.
 *   3. The old single "Top Matches for Primary Wishlist" block is gone.
 */
final class BuyerDetailWishlistRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The buyer-detail view extends the corex layout (@vite); no built
        // manifest exists in the test env — stub Vite so the HTML renders.
        $this->withoutVite();
    }

    public function test_wishlists_tab_renders_compact_rows_with_match_count_badges_and_drops_old_top_matches_block(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);

        $wishlist = $this->match($agencyId, $buyer->id, [
            'is_primary' => true,
            'price_min' => 1_500_000, 'price_max' => 2_000_000,
            'p24_suburb_ids' => [$suburbId],
        ]);
        $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000]);

        $resp = $this->actingAs($agent)->get(route('command-center.buyers.show', $buyer) . '?tab=wishlists');

        $resp->assertStatus(200);
        // Compact-row accordion control present (this wishlist's toggle).
        $resp->assertSee("toggleWishlistMatches({$wishlist->id})", false);
        // Match-count badge with the real, non-zero count.
        $resp->assertSee('1 match', false);
        // The old tall-card bottom block is gone.
        $resp->assertDontSee('Top Matches for Primary Wishlist');
    }

    public function test_wishlist_component_script_never_leaks_as_visible_page_text(): void
    {
        // Regression guard for a real, live-caught bug: the whole Alpine
        // component used to live inline in the x-data="..." HTML attribute.
        // Its own HTML-string literals (e.g. '<div class="…">Loading
        // matches…</div>') carry double quotes that collide with x-data's
        // own double-quote delimiter — the browser closes the attribute
        // early and dumps the rest of the script as a visible text node on
        // the page (Johan saw this live on a real buyer). A plain
        // "200 + markers present" check is worthless against this class of
        // bug — the leaked JS IS text containing those markers. This test
        // asserts the DOM structure itself: the component's JS/HTML-string
        // fragments may appear ONLY inside a real <script> block, never in
        // the surrounding page markup.
        // Include a real matching Property — NOT just a wishlist — so the
        // Schedule Viewing picker's "Continue to schedule" button (which
        // legitimately renders @click="continueToSchedule()", a bare method
        // INVOCATION) actually appears on the page. Testing against an empty
        // $matched would let a same-named-substring bug slip through: the
        // leak markers below must be fragments that exist ONLY inside the
        // function BODIES, never in a legitimate @click="…()" call.
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $wishlist = $this->match($agencyId, $buyer->id, [
            'is_primary' => true, 'price_min' => 1_500_000, 'price_max' => 2_000_000,
            'p24_suburb_ids' => [$suburbId],
        ]);
        $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000]);

        $resp = $this->actingAs($agent)->get(route('command-center.buyers.show', $buyer) . '?tab=wishlists');
        $resp->assertStatus(200);
        $html = $resp->getContent();

        // Sanity: the picker's "Continue to schedule" button — a legitimate
        // bare method CALL — really is on this page, so the leak-check below
        // is exercised against the exact button that caused the false
        // positive during manual QA1 verification, not an empty branch.
        $resp->assertSee('continueToSchedule()', false);

        // The component must be registered in a real <script> block …
        $resp->assertSee("Alpine.data('buyerWishlists'", false);
        // … and the root element's x-data must be a single, well-formed
        // call — data only, no inline object/function bodies.
        $this->assertMatchesRegularExpression(
            '/x-data="buyerWishlists\([^"]*\)"/',
            $html,
            'x-data must be a single well-formed buyerWishlists(...) call with no stray quotes breaking out of it'
        );

        // Structural proof: strip every <script>…</script> block and confirm
        // none of the component's JS IMPLEMENTATION fragments leak into the
        // remaining body markup as visible text — this is exactly what would
        // have failed on the broken version (the leaked script WAS in this
        // remainder). Every marker here exists ONLY inside a function body,
        // never in a legitimate @click="…()" invocation elsewhere on the page.
        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        foreach ([
            'toggleWishlistMatches(id)',   // the function's own parameter name
            'Loading matches',              // the innerHTML placeholder text
            'el.innerHTML',                 // only appears inside the function body
            'cfg.calendarBaseUrl',          // only appears inside continueToSchedule()'s body
        ] as $leak) {
            $this->assertStringNotContainsString(
                $leak, $withoutScripts,
                "\"{$leak}\" leaked outside <script> — the quote-collision bug is back"
            );
        }
    }

    public function test_match_count_badge_is_accurate_but_non_default_wishlists_full_card_grid_is_not_pre_built(): void
    {
        // Asserted at the CONTROLLER/view-data level rather than raw HTML: the
        // page also embeds an unrelated, pre-existing aggregate — the Schedule
        // Viewing picker's JSON payload (built from ALL of the buyer's active
        // wishlists combined, via BuyerIntelligenceService::getMatchedProperties)
        // — which legitimately contains every wishlist's matches regardless of
        // accordion state. That's an existing feature this build didn't touch,
        // so a plain "page doesn't contain the address" HTML assertion would
        // false-fail against it. The real "cheap" claim AT-363 makes is at the
        // controller: only the default-expanded wishlist's full mapped match
        // list is built (expandedWishlistMatches); every other wishlist gets
        // only its count (wishlistMatchCounts), never a mapped/rendered list.
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);

        // Primary (default-expanded) wishlist — 1 matching property.
        $primary = $this->match($agencyId, $buyer->id, [
            'is_primary' => true,
            'price_min' => 1_500_000, 'price_max' => 2_000_000,
            'p24_suburb_ids' => [$suburbId],
        ]);
        $primaryProperty = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'title' => 'Primary Match House',
        ]);

        // Non-primary wishlist — a DIFFERENT matching property.
        $secondary = $this->match($agencyId, $buyer->id, [
            'is_primary' => false,
            'price_min' => 3_000_000, 'price_max' => 4_000_000,
            'p24_suburb_ids' => [$suburbId],
        ]);
        $secondaryProperty = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 3_500_000, 'title' => 'Secondary Match House',
        ]);

        $this->actingAs($agent);
        $request = \Illuminate\Http\Request::create(
            route('command-center.buyers.show', $buyer), 'GET', ['tab' => 'wishlists']
        );
        $request->setUserResolver(fn () => $agent);
        $view = app(\App\Http\Controllers\CommandCenter\BuyerDetailController::class)->show($request, $buyer);
        $data = $view->getData();

        // Both wishlists get an accurate count — the count computation itself
        // is not skipped for the non-default one.
        $this->assertSame(1, $data['wishlistMatchCounts'][$primary->id]);
        $this->assertSame(1, $data['wishlistMatchCounts'][$secondary->id]);

        // Only the default-expanded (primary) wishlist's full match list was
        // built — the non-default one's matches were never mapped/rendered.
        $this->assertSame($primary->id, $data['defaultExpandedWishlistId']);
        $this->assertTrue(
            collect($data['expandedWishlistMatches'])->pluck('id')->contains($primaryProperty->id)
        );
        $this->assertFalse(
            collect($data['expandedWishlistMatches'])->pluck('id')->contains($secondaryProperty->id),
            'the non-default wishlist\'s property must not be pre-built into the initial render'
        );

        // Sanity: the underlying resolver genuinely finds it (so a later
        // lazy-fetch would surface it) — this isn't secretly failing to match.
        $resolved = app(\App\Services\Matching\ClientMatchResolver::class)->resolve($secondary->refresh(), false);
        $this->assertTrue($resolved->pluck('id')->contains($secondaryProperty->id));
    }

    public function test_lazy_wishlist_matches_endpoint_returns_200_with_rendered_match_card_html(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $wishlist = $this->match($agencyId, $buyer->id, [
            'is_primary' => false,
            'price_min' => 1_500_000, 'price_max' => 2_000_000,
            'p24_suburb_ids' => [$suburbId],
        ]);
        $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'title' => 'Lazy Loaded House',
        ]);

        $resp = $this->actingAs($agent)->get(
            route('command-center.buyers.wishlists.matches', [$buyer, $wishlist])
        );

        $resp->assertStatus(200);
        $resp->assertSee('Lazy Loaded House');
        // Keeps the AT-360 route as the "view everything" escape hatch.
        $resp->assertSee(route('corex.contacts.matches.results', [$buyer, $wishlist]), false);
    }

    public function test_lazy_wishlist_matches_endpoint_403s_when_wishlist_belongs_to_a_different_contact(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $buyerA = $this->buyer($agencyId, $agent->id);
        $buyerB = $this->buyer($agencyId, $agent->id);
        $wishlistForA = $this->match($agencyId, $buyerA->id, ['price_min' => 500_000]);

        $resp = $this->actingAs($agent)->get(
            route('command-center.buyers.wishlists.matches', [$buyerB, $wishlistForA])
        );

        $resp->assertStatus(403);
    }

    // ── Helpers (mirrors the scenario-building pattern used across the
    //     Buyers test suite — CanonicalEngineSurfacesTest, etc.) ──────────

    /** @return array{0:int,1:User,2:int} [agencyId, agent, p24SuburbId] */
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
        $suburbId = $this->seedP24Suburb();

        return [$agencyId, $agent, $suburbId];
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
        ], $extra));
    }

    private function property(int $agencyId, int $agentId, int $suburbId, array $extra = []): Property
    {
        return Property::create(array_merge([
            'external_id'   => (string) Str::uuid(),
            'title'         => 'Test Property ' . Str::random(5),
            'agent_id'      => $agentId,
            'branch_id'     => $agencyId,
            'agency_id'     => $agencyId,
            'listing_type'  => 'sale',
            'status'        => 'active',
            'published_at'  => now(),
            'suburb'        => 'Uvongo',
            'p24_suburb_id' => $suburbId,
        ], $extra));
    }

    private function seedP24Suburb(): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 999999), 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_city_id' => $cityId, 'name' => 'Uvongo',
            'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
