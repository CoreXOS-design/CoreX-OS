<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\MicAgencySetting;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\BuyerMatchRules;
use App\Services\Matching\MatchingService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/mic-work-tab.md section 13.15 - ONE set of buyer-match rules for MIC (adverts) and Core Matches (own stock).
 * The wishlist is Alex Swanepoel's (contact 18742, wishlist 702): sale, up to R1,000,000, Residential, House / Apartment / Townhouse,
 * with nothing else stated; plus a House-only twin, to prove a Townhouse is not a House.
 */
final class BuyerMatchRulesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        BuyerMatchRules::flush();
        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency-' . Str::random(5)]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'created_by_user_id' => $this->agent->id,
            'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => 'Alex', 'last_name' => 'Swanepoel',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => 'alex-' . Str::random(5) . '@e.co.za',
        ]);
    }

    private function wishlist(array $extra = []): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'contact_id' => $this->buyer->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_max' => 1_000_000, 'category' => 'Residential', 'open_to_auction' => true,
            'property_type' => 'House', 'property_types' => ['House', 'Apartment / Flat', 'Townhouse'],
        ], $extra));
    }

    private function houseOnly(): ContactMatch
    {
        return $this->wishlist(['property_types' => ['House']]);
    }

    private function stock(array $extra = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'title' => 'Stock', 'status' => 'active',
            'listing_type' => 'sale', 'price' => 900_000, 'beds' => 3, 'baths' => 2, 'garages' => 1,
            'property_type' => 'House', 'category' => 'Residential',
        ], $extra));
    }

    private function advert(array $extra = []): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->agent->id, 'portal_source' => 'p24',
            'portal_ref' => 'ref-' . Str::random(8), 'portal_url' => 'https://example.com/' . Str::random(6),
            'address' => Str::random(6) . ' Test Road', 'suburb' => 'Uvongo', 'listing_type' => 'sale',
            'price' => 900_000, 'bedrooms' => 3, 'bathrooms' => 2, 'garages' => 1, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function ownStockScore(ContactMatch $m, Property $p): int
    {
        return app(MatchingService::class)->score($p, $m->fresh());
    }

    private function advertScore(int $advertId): ?int
    {
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);

        $row = DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->where('prospecting_listing_id', $advertId)->first();

        return $row ? (int) $row->score : null;
    }

    public function test_alexs_wishlist_takes_a_house_a_townhouse_and_a_flat_in_range_at_full_strength(): void
    {
        $m = $this->wishlist();
        foreach (['House' => 900_000, 'Townhouse' => 950_000, 'Apartment / Flat' => 700_000] as $type => $price) {
            $this->assertSame(100, $this->ownStockScore($m, $this->stock(['property_type' => $type, 'price' => $price])), $type);
        }
    }

    public function test_a_townhouse_is_not_a_house_when_the_wishlist_says_house_only(): void
    {
        $m = $this->houseOnly();
        $this->assertSame(0, $this->ownStockScore($m, $this->stock(['property_type' => 'Townhouse', 'price' => 950_000])));
        $this->assertSame(100, $this->ownStockScore($m, $this->stock(['property_type' => 'House', 'price' => 950_000])));
        $this->assertSame(0, $this->ownStockScore($m, $this->stock(['property_type' => 'Vacant Land / Plot', 'price' => 500_000])));
    }

    public function test_price_over_the_maximum_is_allowed_only_up_to_the_agency_tolerance_and_decays(): void
    {
        $m = $this->wishlist();
        $within = $this->ownStockScore($m, $this->stock(['price' => 1_050_000]));
        $this->assertGreaterThan(0, $within, 'R1.05m is inside the default 10% tolerance');
        $this->assertLessThan(80, $within, 'but it is not "strong": the price is past the buyer\'s maximum');
        $this->assertSame(0, $this->ownStockScore($m, $this->stock(['price' => 1_200_000])), 'R1.2m is out');
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['price_min' => 800_000, 'price_max' => 1_000_000]), $this->stock(['price' => 790_000])), 'the minimum is never relaxed');
    }

    public function test_the_tolerance_is_the_agency_setting_not_a_constant(): void
    {
        $m = $this->wishlist();
        $p = $this->stock(['price' => 1_050_000]);
        $this->assertGreaterThan(0, $this->ownStockScore($m, $p));

        MicAgencySetting::saveValues($this->agency->id, ['price_tolerance_pct' => 0]);
        $this->assertSame(0, $this->ownStockScore($m, $p), 'tolerance 0: nothing above the maximum');

        MicAgencySetting::saveValues($this->agency->id, ['price_tolerance_pct' => 25]);
        $this->assertGreaterThan(0, $this->ownStockScore($m, $this->stock(['price' => 1_200_000])), 'tolerance 25: R1.2m is in');
        MicAgencySetting::saveValues($this->agency->id, ['price_tolerance_pct' => 'x']);
        $this->assertSame(25, MicAgencySetting::for($this->agency->id)->priceTolerancePct(), 'a bad value is dropped, the stored one stays');
    }

    public function test_a_deal_breaker_present_excludes_and_an_unknown_feature_list_is_unverified_not_strong(): void
    {
        $m = $this->houseOnly();
        $m->update(['deal_breakers' => ['pool']]);
        $this->assertSame(0, $this->ownStockScore($m, $this->stock(['features_json' => ['pool', 'garden']])), 'deal-breaker present');
        $this->assertSame(100, $this->ownStockScore($m, $this->stock(['features_json' => ['garden']])), 'features known, no pool');

        $unknown = app(MatchingService::class)->assess($this->stock(['features_json' => null]), $m->fresh());
        $this->assertContains('features', $unknown['unverified']);
        $this->assertLessThan(MatchingService::TIER_STRONG_MIN, $unknown['score'], 'cannot be strong on a deal-breaker we could not check');
        $this->assertGreaterThan(0, $unknown['score']);
    }

    public function test_an_unknown_type_is_not_excluded_but_is_unverified_and_cannot_be_strong(): void
    {
        $m = $this->houseOnly();
        $a = app(MatchingService::class)->assess($this->stock(['property_type' => '']), $m->fresh());
        $this->assertContains('property type', $a['unverified']);
        $this->assertGreaterThan(0, $a['score']);
        $this->assertLessThan(MatchingService::TIER_STRONG_MIN, $a['score']);
    }

    public function test_beds_baths_garages_sizes_and_auction_are_hard_when_set(): void
    {
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['beds_min' => 4]), $this->stock(['beds' => 3])));
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['bedrooms_max' => 3]), $this->stock(['beds' => 5])));
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['baths_min' => 3]), $this->stock(['baths' => 2])));
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['garages_min' => 2]), $this->stock(['garages' => 1])));
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['erf_size_min' => 800]), $this->stock(['erf_size_m2' => 500])));
        $this->assertSame(0, $this->ownStockScore($this->wishlist(['open_to_auction' => false]), $this->stock(['status' => 'on_auction'])));
        $this->assertGreaterThan(0, $this->ownStockScore($this->wishlist(['beds_min' => 3]), $this->stock(['beds' => 3])));
        // no bed count captured: kept, but unverified
        $a = app(MatchingService::class)->assess($this->stock(['beds' => 0]), $this->wishlist(['beds_min' => 3])->fresh());
        $this->assertContains('bedrooms', $a['unverified']);
        $this->assertLessThan(MatchingService::TIER_STRONG_MIN, $a['score']);
    }

    public function test_listing_type_and_category_are_hard(): void
    {
        $m = $this->wishlist();
        $this->assertSame(0, $this->ownStockScore($m, $this->stock(['listing_type' => 'rental', 'rental_amount' => 9000, 'price' => 0])));
        $this->assertSame(0, $this->ownStockScore($m, $this->stock(['category' => 'Commercial'])));
    }

    public function test_the_core_match_list_and_the_mic_cache_agree_on_the_same_cases(): void
    {
        $m = $this->houseOnly();
        $cases = [
            'house 900k'        => [['property_type' => 'House', 'price' => 900_000], ['property_type' => 'House', 'price' => 900_000], true],
            'townhouse 950k'    => [['property_type' => 'Townhouse', 'price' => 950_000], ['property_type' => 'Townhouse', 'price' => 950_000], false],
            'house 1.05m'       => [['property_type' => 'House', 'price' => 1_050_000], ['property_type' => 'House', 'price' => 1_050_000], true],
            'house 1.2m'        => [['property_type' => 'House', 'price' => 1_200_000], ['property_type' => 'House', 'price' => 1_200_000], false],
            'unknown type 900k' => [['property_type' => '', 'price' => 900_000], ['property_type' => null, 'price' => 900_000], true],
            'flat 900k'         => [['property_type' => 'Apartment / Flat', 'price' => 900_000], ['property_type' => 'Apartment', 'price' => 900_000], false],
        ];
        $stockIds = [];
        $advertIds = [];
        foreach ($cases as $label => [$own, $adv]) {
            $stockIds[$label] = $this->stock($own)->id;
            $advertIds[$label] = $this->advert($adv + ['bedrooms' => 3]);
        }

        $core = app(MatchingService::class)->propertiesForMatch($m->fresh(), ['agent_id' => null, 'include_hidden' => false]);
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);

        foreach ($cases as $label => [, , $expected]) {
            $inCore = $core->contains('id', $stockIds[$label]);
            $inMic = DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)
                ->where('prospecting_listing_id', $advertIds[$label])->where('score', '>=', 50)->exists();
            $this->assertSame($expected, $inCore, "Core Matches: $label");
            $this->assertSame($expected, $inMic, "MIC: $label");
            if ($inCore && $inMic) {
                $scoreCore = (int) $core->firstWhere('id', $stockIds[$label])->match_score;
                $scoreMic = (int) DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $advertIds[$label])->value('score');
                $this->assertSame($scoreCore, $scoreMic, "same score on both screens: $label");
            }
        }
    }

    public function test_the_mic_cache_records_why_a_match_is_unverified(): void
    {
        $this->houseOnly();
        $id = $this->advert(['property_type' => null]);
        $this->assertNotNull($this->advertScore($id));
        $row = DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $id)->first();
        $this->assertContains('property type', json_decode($row->matched_features, true)['unverified'] ?? []);
        $this->assertLessThan(80, (int) $row->score);
    }
}
