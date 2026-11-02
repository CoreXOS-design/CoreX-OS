<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\BuyerMatchRules;
use App\Services\Matching\MatchingService;
use App\Services\Prospecting\MicWork\MicWorkList;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.18 - row['buyer_fit']: "Buyer wants vs property has" for the selected buyer, straight from MatchingService::fit() (the same pass the filters use).
 */
final class BuyerFitTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Contact $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        BuyerMatchRules::flush();
        $this->setUpAgency();
        $this->buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => 'Alex', 'last_name' => 'Swanepoel',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => 'alex-' . Str::random(5) . '@e.co.za',
        ]);
    }

    private function wishlist(array $extra = []): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'contact_id' => $this->buyer->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale', 'is_primary' => true,
            'price_max' => 1_000_000, 'category' => 'Residential', 'property_types' => ['House', 'Townhouse'], 'beds_min' => 3, 'baths_min' => 2, 'garages_min' => 1,
        ], $extra));
    }

    private function advert(array $extra = []): array
    {
        $file = (int) app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => (string) random_int(4, 900), 'street_name' => 'Emtunzi', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => 'P24-' . Str::random(8)])->id;
        $id = (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'tracked_property_id' => $file, 'captured_by_user_id' => $this->agent->id, 'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8), 'portal_url' => 'https://x.test/' . Str::random(5),
            'address' => '4 Emtunzi', 'suburb' => 'Margate', 'listing_type' => 'sale', 'price' => 950_000, 'bedrooms' => 2, 'bathrooms' => 2, 'garages' => 1, 'property_type' => 'Townhouse',
            'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return [$file, $id];
    }

    private function fit(int $file): ?array
    {
        return app(MicWorkList::class)->forFiles([$file], $this->agency->id, $this->buyer->id)[0]['buyer_fit'] ?? null;
    }

    private function state(array $fit, string $key): array
    {
        return collect($fit['fields'])->firstWhere('key', $key);
    }

    public function test_no_buyer_no_fit(): void
    {
        $this->wishlist();
        [$file] = $this->advert();
        $this->assertNull(app(MicWorkList::class)->forFiles([$file], $this->agency->id)[0]['buyer_fit']);
    }

    public function test_a_two_bed_townhouse_against_a_three_bed_wishlist_shows_the_miss_the_hits_and_what_is_not_stated(): void
    {
        $this->wishlist();
        [$file] = $this->advert();
        $fit = $this->fit($file);

        $this->assertSame('Alex', $fit['first_name']);
        $this->assertSame(0, $fit['score'], 'a hard miss (2 beds against 3+) is not a match');
        $this->assertSame(['price', 'type', 'suburb', 'beds', 'baths', 'garages', 'parking', 'erf', 'floor'], array_column($fit['fields'], 'key'));

        $this->assertEquals(['wants' => '≤ R1.0m', 'has' => 'R950k', 'state' => 'ok'], array_intersect_key($this->state($fit, 'price'), array_flip(['wants', 'has', 'state'])));
        $this->assertEquals(['wants' => 'House · Townhouse', 'has' => 'Townhouse', 'state' => 'ok'], array_intersect_key($this->state($fit, 'type'), array_flip(['wants', 'has', 'state'])));
        $this->assertEquals(['wants' => '3+', 'has' => '2', 'state' => 'miss'], array_intersect_key($this->state($fit, 'beds'), array_flip(['wants', 'has', 'state'])));
        $this->assertEquals(['wants' => '2+', 'has' => '2', 'state' => 'ok'], array_intersect_key($this->state($fit, 'baths'), array_flip(['wants', 'has', 'state'])));
        $this->assertSame('na', $this->state($fit, 'suburb')['state'], 'the wishlist names no suburb');
        $this->assertSame('na', $this->state($fit, 'erf')['state']);
        $this->assertSame('na', $this->state($fit, 'floor')['state']);
        $this->assertSame('na', $this->state($fit, 'parking')['state']);
    }

    public function test_a_fact_the_advert_lacks_is_unknown_not_a_miss_and_the_verdict_agrees_with_the_filters(): void
    {
        $this->wishlist(['erf_size_min' => 500, 'price_max' => 1_000_000]);
        [$file, $advertId] = $this->advert(['bedrooms' => 3, 'erf_size_m2' => null]);
        $fit = $this->fit($file);

        $this->assertSame('unknown', $this->state($fit, 'erf')['state']);
        $this->assertSame('500+ m²', $this->state($fit, 'erf')['wants']);
        $this->assertContains('erf size', $fit['unverified']);
        $this->assertGreaterThan(0, $fit['score']);
        $this->assertLessThan(MatchingService::TIER_STRONG_MIN, $fit['score'], 'unverified cannot be strong');

        // the same advert through the cache: the same score, because the same pass decided it
        app(\App\Services\PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
        $this->assertSame($fit['score'], (int) DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $advertId)->value('score'));
    }

    public function test_deal_breakers_and_must_haves_are_listed_with_their_state(): void
    {
        $this->wishlist(['deal_breakers' => ['pool'], 'must_have_features' => ['garden', 'braai']]);
        [$file] = $this->advert(['bedrooms' => 3, 'features' => json_encode(['garden', 'pool'])]);
        $fit = $this->fit($file);

        $this->assertSame([['label' => 'Pool', 'state' => 'miss']], $fit['deal_breakers']);
        $this->assertSame([['label' => 'Garden', 'state' => 'ok'], ['label' => 'Braai', 'state' => 'miss']], $fit['must_haves']);
        $this->assertSame(0, $fit['score']);

        [$file2] = $this->advert(['bedrooms' => 3, 'features' => null]);
        $unknown = $this->fit($file2);
        $this->assertSame('unknown', $unknown['deal_breakers'][0]['state']);
        $this->assertSame('unknown', $unknown['must_haves'][0]['state']);
    }

    public function test_the_best_of_the_buyers_wishlists_is_the_one_shown(): void
    {
        $this->wishlist(['beds_min' => 4]);                                  // misses
        $second = $this->wishlist(['is_primary' => false, 'beds_min' => 2]); // fits
        [$file] = $this->advert();
        $fit = $this->fit($file);
        $this->assertSame($second->id, $fit['wishlist_id']);
        $this->assertGreaterThanOrEqual(80, $fit['score']);
        $this->assertSame('ok', $this->state($fit, 'beds')['state']);
    }
}
