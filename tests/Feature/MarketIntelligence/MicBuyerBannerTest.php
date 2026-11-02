<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\MicWork\MicBuyerBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 13.14: the "Prospecting for a buyer" banner - the buyer, the wishlist MIC matched on as chips, the Core Match / Edit criteria pop-ups. */
final class MicBuyerBannerTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Contact $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true, 'name' => 'Shawn Du Bois']);
        $this->buyer = $this->contact($this->agency->id, 'Alex', 'Swanepoel');
    }

    private function contact(int $agencyId, string $first, string $last): Contact
    {
        return Contact::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id, 'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => $first, 'last_name' => $last, 'phone' => '082' . random_int(1000000, 9999999), 'email' => Str::random(6) . '@e.co.za']);
    }

    private function wishlist(Contact $c, array $extra = []): ContactMatch
    {
        // a wishlist's suburb names are derived from its p24_suburb_ids on save, so the suburb has to exist
        static $suburbId = null;
        $suburbId = DB::table('p24_suburbs')->where('slug', 'margate')->value('id')
            ?? DB::table('p24_suburbs')->insertGetId(['name' => 'Margate', 'slug' => 'margate', 'p24_id' => 970001, 'created_at' => now(), 'updated_at' => now()]);
        $created = $extra['created_at'] ?? null;
        unset($extra['created_at']);
        $w = ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $c->agency_id, 'contact_id' => $c->id, 'created_by_user_id' => $this->admin->id, 'status' => 'active', 'is_primary' => true, 'listing_type' => 'sale',
            'category' => 'residential', 'property_types' => ['House'], 'price_max' => 1_000_000, 'beds_min' => 2, 'p24_suburb_ids' => [$suburbId],
        ], $extra));
        if ($created) { // created_at is not mass-assignable
            $w->newQuery()->withoutGlobalScopes()->whereKey($w->id)->update(['created_at' => $created]);
            $w->refresh();
        }

        return $w;
    }

    /** an advert the buyer is cached against (this is what puts the buyer in the picker and the list) */
    private function advertFor(Contact $c): ProspectingListing
    {
        $l = ProspectingListing::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . random_int(100000000, 999999999), 'portal_url' => 'https://p.test/x', 'address' => '1 Test Road', 'suburb' => 'Margate',
            'property_type' => 'Townhouse', 'price' => 900_000, 'bedrooms' => 2, 'captured_by_user_id' => $this->admin->id, 'first_seen_at' => now()->subDays(5), 'last_seen_at' => now(), 'is_active' => true,
        ]);
        // the listing observer may already have cached this buyer against the advert (a wishlist exists): either way the row is there at 90
        DB::table('prospecting_buyer_matches')->updateOrInsert(['prospecting_listing_id' => $l->id, 'contact_id' => $c->id], ['agency_id' => $this->agency->id, 'score' => 90, 'tier' => 'strong', 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $l;
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', array_merge(['view' => 'adverts', 'buyer_id' => $this->buyer->id], $q)))->assertOk()->getContent();
    }

    public function test_the_banner_shows_the_buyer_the_wishlist_as_chips_and_the_two_pop_up_buttons(): void
    {
        $w = $this->wishlist($this->buyer, ['created_at' => '2026-09-15 10:00:00']);
        $this->advertFor($this->buyer);
        $h = $this->page();

        $this->assertStringContainsString('data-qa="buyer-banner"', $h);
        $this->assertMatchesRegularExpression('/Prospecting for <a href="[^"]*' . $this->buyer->id . '[^"]*" data-qa="buyer-banner-name"[^>]*>Alex Swanepoel<\/a>/', $h, 'the name links to the contact');
        // line 2: the chips, in the Core Match header order, then the saved line
        $line2 = substr($h, strpos($h, 'data-qa="buyer-banner-line2"'), 3000);
        $order = ['For Sale', 'UP TO R 1,000,000', 'Residential', 'House', 'Margate', '2+ beds', 'Primary wishlist'];
        $at = -1;
        foreach ($order as $part) {
            $pos = strpos($line2, $part);
            $this->assertNotFalse($pos, $part);
            $this->assertGreaterThan($at, $pos, "{$part} in order");
            $at = $pos;
        }
        $this->assertStringContainsString('saved 15 Sep 2026', $line2);
        $this->assertStringContainsString('by Shawn Du Bois', $line2);
        $this->assertStringNotContainsString('data-qa="buyer-banner-wishlists"', $h, 'one wishlist: no "N wishlists" link');
        $this->assertStringContainsString('class="ds-badge ds-badge-pill ds-badge-info"', $h, 'the price chip is the same badge style as the Core Match header');
        // the count line stays, the buttons are the real routes (same size classes), Clear buyer is there
        $this->assertMatchesRegularExpression('/[0-9,]+ propert(y|ies) matching — strongest match first \(Core Matches score\)/', $h);
        $results = route('corex.contacts.matches.results', [$this->buyer, $w]);
        $edit = route('corex.contacts.matches.edit', [$this->buyer, $w]);
        $this->assertStringContainsString("open('{$results}'", $h);
        $this->assertStringContainsString("open('{$edit}'", $h);
        foreach (['buyer-banner-core-match', 'buyer-banner-edit', 'buyer-banner-clear'] as $qa) {
            $this->assertMatchesRegularExpression('/class="corex-btn-outline text-xs no-underline inline-flex items-center flex-shrink-0" data-qa="' . $qa . '"/', $h, $qa);
        }
        $this->assertStringContainsString('function micBuyerPop()', $h, 'the pop-up script is on the page (outside the swapped fragment)');
        $this->assertSame(1, substr_count($h, 'function micBuyerPop()'));
    }

    public function test_with_several_wishlists_the_primary_one_is_shown_and_the_count_links_to_the_contact(): void
    {
        $this->wishlist($this->buyer, ['is_primary' => false, 'category' => 'commercial', 'property_types' => ['Office'], 'created_at' => now()->addDay()]);
        $primary = $this->wishlist($this->buyer, ['is_primary' => true]);
        $this->advertFor($this->buyer);
        $h = $this->page();

        $this->assertStringContainsString(route('corex.contacts.matches.results', [$this->buyer, $primary]), $h, 'the pop-up opens the primary wishlist, not the newest');
        $this->assertStringContainsString('Primary wishlist', $h);
        $this->assertMatchesRegularExpression('/<a href="[^"]*tab=matches" data-qa="buyer-banner-wishlists"[^>]*>2 wishlists<\/a>/', $h);
        $this->assertStringNotContainsString('>Office<', $h);
    }

    public function test_a_wishlist_with_no_suburb_says_any_suburb_and_long_lists_are_cut_with_the_full_text_in_the_title(): void
    {
        $this->wishlist($this->buyer, ['p24_suburb_ids' => [], 'must_have_features' => ['pool', 'garden', 'braai', 'alarm', 'solar', 'fibre'], 'deal_breakers' => ['busy_road']]);
        $chips = app(MicBuyerBanner::class)->forBuyer($this->buyer->id, $this->agency->id, true)['chips'];
        $labels = array_column($chips, 0);
        $this->assertContains('Any suburb', $labels);
        $this->assertContains('Must have: Pool, Garden, Braai, Alarm +2', $labels);
        $this->assertContains('Avoid: Busy Road', $labels);
        $cut = collect($chips)->firstWhere(0, 'Must have: Pool, Garden, Braai, Alarm +2');
        $this->assertSame('Must have: Pool, Garden, Braai, Alarm, Solar, Fibre', $cut[2]);
    }

    public function test_the_buttons_need_core_match_access_and_another_agencys_buyer_has_no_banner(): void
    {
        $this->wishlist($this->buyer);
        $none = app(MicBuyerBanner::class)->forBuyer($this->buyer->id, $this->agency->id, false);
        $this->assertNull($none['results_url']);
        $this->assertNull($none['edit_url']);
        $this->assertNotEmpty($none['chips'], 'the chips still show');

        $this->assertNull(app(MicBuyerBanner::class)->forBuyer($this->buyer->id, $this->agency->id + 9999, true), "another agency's buyer is not found");
    }

    public function test_a_buyer_with_no_active_wishlist_says_so_and_the_core_match_page_can_be_framed(): void
    {
        $this->advertFor($this->buyer);
        $this->assertStringContainsString('This buyer has no active wishlist to match on.', $this->page());

        // the pop-up is an iframe of our own origin: the Core Match page must not forbid framing, and the embed bar must not say "Road to Sold"
        $w = $this->wishlist($this->buyer);
        $res = $this->actingAs($this->admin)->get(route('corex.contacts.matches.results', [$this->buyer, $w]) . '?embed=1')->assertOk();
        $this->assertFalse($res->headers->has('X-Frame-Options'), 'no X-Frame-Options');
        $this->assertStringNotContainsString('frame-ancestors', (string) $res->headers->get('Content-Security-Policy'));
        $html = $res->getContent();
        $this->assertStringContainsString('data-embed-done>Done</button>', $html);
        $this->assertStringNotContainsString('back to Road to Sold', $html);
        $rts = $this->actingAs($this->admin)->get(route('corex.contacts.matches.results', [$this->buyer, $w]) . '?embed=1&rts_stage=3')->assertOk()->getContent();
        $this->assertStringContainsString('Done - back to Road to Sold', $rts, 'the Road to Sold pop-up keeps its wording');
    }
}
