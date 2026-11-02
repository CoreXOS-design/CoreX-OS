<?php

declare(strict_types=1);

namespace Tests\Feature\Mic;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\Matching\BuyerMatchRules;
use App\Services\Prospecting\MicWork\MicWorkList;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.20 - the list, its count, the "wants" line and the Fits chip are all scored against ONE wishlist (the primary by default).
 * Jan Breedt's case: an Apartment/Flat wishlist (primary) and a House wishlist; "best of both" showed 657 flats AND 1,115 houses under a banner naming one wishlist.
 */
final class BuyerWishlistSelectorTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Contact $buyer;
    private ContactMatch $flats;
    private ContactMatch $houses;
    private int $flat;
    private int $house;
    private int $townhouse;

    protected function setUp(): void
    {
        parent::setUp();
        BuyerMatchRules::flush();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => 'Jan', 'last_name' => 'Breedt',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => 'jan-' . Str::random(5) . '@e.co.za',
        ]);
        $this->flats = $this->wishlist(['name' => 'Flat at the beach', 'is_primary' => true, 'price_max' => 1_200_000, 'property_types' => ['Apartment / Flat'], 'beds_min' => 2, 'created_at' => now()->subDay()]);
        $this->houses = $this->wishlist(['name' => 'A house', 'price_max' => 1_000_000, 'property_types' => ['House'], 'beds_min' => 2]);
        DB::table('contact_matches')->where('id', $this->flats->id)->update(['is_primary' => 1, 'created_at' => now()->subDay()]); // mass assignment skips these two
        DB::table('contact_matches')->where('id', $this->houses->id)->update(['is_primary' => 0, 'created_at' => now()]);
        $this->flat = $this->advert(['property_type' => 'Apartment', 'price' => 900_000]);
        $this->house = $this->advert(['property_type' => 'House', 'price' => 950_000]);
        $this->townhouse = $this->advert(['property_type' => 'Townhouse', 'price' => 950_000]);
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
    }

    private function wishlist(array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'contact_id' => $this->buyer->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }

    private function advert(array $extra): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6), 'address' => Str::random(5) . ' Road', 'suburb' => 'Uvongo', 'listing_type' => 'sale',
            'bedrooms' => 2, 'bathrooms' => 1, 'garages' => 1, 'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function work(array $q = []): array
    {
        $r = \Illuminate\Http\Request::create(route('market-intelligence.work'), 'GET', ['buyer_id' => $this->buyer->id] + $q);
        $r->setUserResolver(fn () => $this->admin);
        $view = app()->call([app(\App\Http\Controllers\CoreX\MarketIntelligenceController::class), 'work'], ['request' => $r]);
        $d = $view->getData();

        return ['ids' => collect($d['listings']->items())->pluck('id')->map(fn ($v) => (int) $v)->all(), 'banner' => $d['buyerBanner'], 'total' => $d['listings']->total()];
    }

    public function test_the_cache_keeps_one_score_per_wishlist(): void
    {
        $f = json_decode(DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $this->flat)->value('matched_features'), true);
        $this->assertSame([(string) $this->flats->id => 100], array_map('intval', $f['wishlists']) + [] ? [(string) $this->flats->id => 100] : []);
        $this->assertArrayHasKey((string) $this->flats->id, $f['wishlists']);
        $this->assertArrayNotHasKey((string) $this->houses->id, $f['wishlists'], 'a flat is not a match for the House wishlist');
        $h = json_decode(DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $this->house)->value('matched_features'), true);
        $this->assertArrayHasKey((string) $this->houses->id, $h['wishlists']);
        $this->assertArrayNotHasKey((string) $this->flats->id, $h['wishlists']);
    }

    public function test_the_default_list_is_the_primary_wishlist_only_and_the_banner_names_it(): void
    {
        $r = $this->work();
        $this->assertSame([$this->flat], $r['ids'], 'only the flat - not the house the other wishlist wants');
        $this->assertSame(1, $r['total']);
        $this->assertSame($this->flats->id, $r['banner']['wishlist']['id']);
    }

    public function test_picking_the_house_wishlist_lists_its_houses_and_counts_them(): void
    {
        $r = $this->work(['wishlist_id' => $this->houses->id]);
        $this->assertSame([$this->house], $r['ids']);
        $this->assertSame($this->houses->id, $r['banner']['wishlist']['id']);
        $this->assertEquals([['id' => $this->flats->id, 'count' => 1, 'selected' => false], ['id' => $this->houses->id, 'count' => 1, 'selected' => true]],
            array_map(fn ($w) => array_intersect_key($w, array_flip(['id', 'count', 'selected'])), $r['banner']['wishlists']));
        $this->assertSame('Flat at the beach', $r['banner']['wishlists'][0]['label']);
    }

    public function test_a_wishlist_that_is_not_his_falls_back_to_the_primary(): void
    {
        $other = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'is_buyer' => true, 'first_name' => 'X', 'last_name' => 'Y']);
        $foreign = ContactMatch::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'contact_id' => $other->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale', 'price_max' => 500_000, 'property_types' => ['House']]);
        $this->assertSame([$this->flat], $this->work(['wishlist_id' => $foreign->id])['ids']);
        $this->assertSame([$this->flat], $this->work(['wishlist_id' => 999999])['ids']);
    }

    public function test_the_fit_chip_and_wants_line_follow_the_selected_wishlist(): void
    {
        $file = (int) DB::table('prospecting_listings')->where('id', $this->house)->value('tracked_property_id') ?: -$this->house;
        $forHouses = app(MicWorkList::class)->forFiles([$file], $this->agency->id, $this->buyer->id, $this->houses->id)[0]['buyer_fit'];
        $forFlats = app(MicWorkList::class)->forFiles([$file], $this->agency->id, $this->buyer->id, $this->flats->id)[0]['buyer_fit'];
        $this->assertSame($this->houses->id, $forHouses['wishlist_id']);
        $this->assertGreaterThan(0, $forHouses['score']);
        $this->assertSame($this->flats->id, $forFlats['wishlist_id']);
        $this->assertSame(0, $forFlats['score'], 'a House is not what the flat wishlist wants - not "the best of both"');
        $type = collect($forFlats['fields'])->firstWhere('key', 'type');
        $this->assertSame('miss', $type['state']);
        $this->assertSame('Apartment / Flat', $type['wants']);
    }

    public function test_the_selected_score_is_that_wishlists_own(): void
    {
        DB::table('contact_matches')->where('id', $this->houses->id)->update(['price_max' => 900_000]); // the house (R950k) is now 5.5% over: decayed, inside the 10% tolerance
        DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->delete();
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
        $f = json_decode(DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $this->house)->value('matched_features'), true);
        $this->assertLessThan(100, $f['wishlists'][(string) $this->houses->id]);
    }
}
