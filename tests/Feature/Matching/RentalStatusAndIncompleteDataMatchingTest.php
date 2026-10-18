<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\P24Suburb;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\ClientMatchResolver;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live incident (2026-09-10): contact 18900 / match 671 — a rental wishlist
 * (2-bed Apartment/Townhouse, R4 000-R10 000, nine South Coast suburbs)
 * returned ZERO properties on
 * /corex/contacts/18900/matches/671/results, while the agency held live
 * to-let stock that plainly fitted.
 *
 * FOUR independent defects, all in MatchingService, each capable of emptying
 * the page on its own. Each has a test below.
 *
 *  1. STATUS_BY_LISTING_TYPE was a WHITELIST of the statuses each listing
 *     intent was allowed to carry, and the rental list never contained
 *     `to_let` — CoreX's own canonical on-market rental status. A whitelist
 *     fails CLOSED, so 19 of agency 1's 26 live rentals were discarded before
 *     scoring. `under_offer`, `on_show` and every agency-defined status went
 *     the same way. Now a blacklist (WRONG_INTENT_STATUSES) that only excludes
 *     the OTHER market's statuses.
 *
 *  2. NON_MATCHABLE_STATUSES was a hand-maintained copy of
 *     Property::OFF_MARKET_STATUSES that had drifted — missing `prospecting`
 *     and `not_selling`, so unmandated ingested stock was matchable. Now
 *     derived from the model constant.
 *
 *  3. propertiesForMatch() widened the price bound by RELAXED_PRICE_BAND in
 *     SQL and then called score() with NO band, so the price hard gate re-cut
 *     at the exact stated ceiling and returned 0. The relaxed near-miss
 *     surfacing of .ai/specs/matches.md §5.1 had never actually worked.
 *
 *  4. The SQL numeric pre-filter tolerated NULL but not 0, while score() has
 *     always read 0 as "incomplete listing data, not a mismatch". A
 *     captured-but-unpriced listing was deleted by the pre-filter as though it
 *     cost nothing and so fell below every buyer's minimum. This is what hid
 *     the one property that genuinely fitted match 671 (a 2-bed in St Michaels
 *     On Sea, score 60, price not yet captured).
 */
final class RentalStatusAndIncompleteDataMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Tenant',
        ]);

        // properties.p24_suburb_id and p24_suburbs.p24_city_id are real foreign keys (the snapshot has no
        // rows), so the ids this file points at — suburb 8, city 376 (KZN, where Margate lives) and city 999
        // (a same-named suburb in ANOTHER province) — have to exist.
        $country = \App\Models\P24Country::firstOrCreate(['p24_id' => 1], ['name' => 'South Africa']);
        $kzn = \App\Models\P24Province::create(['p24_id' => 4, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $gp  = \App\Models\P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'Gauteng']);
        \App\Models\P24City::forceCreate(['id' => 376, 'p24_id' => 376, 'p24_province_id' => $kzn->id, 'name' => 'Margate']);
        \App\Models\P24City::forceCreate(['id' => 999, 'p24_id' => 999, 'p24_province_id' => $gp->id, 'name' => 'Johannesburg']);
        P24Suburb::forceCreate(['id' => 8, 'name' => 'Test Suburb', 'slug' => 'test-suburb', 'p24_city_id' => 376]);
    }

    /** A live to-let flat in the buyer's suburb. */
    private function rental(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Test rental',
            'status'        => 'to_let',
            'listing_type'  => 'rental',
            'category'      => 'Residential',
            'property_type' => 'Apartment / Flat',
            'price'         => 7000,
            // A rental's price is its monthly rent: effectivePrice() reads rental_amount for rental
            // stock (price is the SALE column) — keep the two in step so the fixture means what it says.
            'rental_amount' => ($overrides['price'] ?? 7000) ?: null, // 0 = "not captured yet" -> NULL
            'beds'          => 2,
            'p24_suburb_id' => 8,
        ], $overrides));
    }

    /** The buyer's wishlist, mirroring live match 671. */
    private function wishlist(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'rental',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'category'           => 'Residential',
            'property_types'     => ['Apartment / Flat', 'Townhouse'],
            'price_min'          => 4000,
            'price_max'          => 10000,
            'beds_min'           => 2,
            'p24_suburb_ids'     => [8],
        ], $overrides));
    }

    private function resolve(ContactMatch $match): \Illuminate\Support\Collection
    {
        return app(ClientMatchResolver::class)->resolve($match, includeHidden: true);
    }

    // ---- Defect 1 — the status whitelist ---------------------------------

    public function test_a_to_let_rental_reaches_the_buyers_match_results(): void
    {
        $property = $this->rental(['status' => 'to_let']);

        $ids = $this->resolve($this->wishlist())->pluck('id')->all();

        $this->assertContains(
            $property->id,
            $ids,
            'to_let is CoreX\'s canonical on-market rental status and must never be filtered out of a rental match'
        );
    }

    public function test_an_under_offer_listing_is_never_offered_to_a_buyer(): void
    {
        // Johan's ruling, 2026-09-10. under_offer stays ON MARKET everywhere
        // else in CoreX (it is deliberately NOT in
        // Property::OFF_MARKET_STATUSES) — this exclusion is matching-only: a
        // buyer is never shown stock that already has an offer on it.
        $property = $this->rental(['status' => 'under_offer']);

        $this->assertNotContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
        $this->assertTrue(
            in_array('under_offer', Property::OFF_MARKET_STATUSES, true) === false,
            'the matching-only exclusion must not have leaked into the model-wide on-market definition'
        );
    }

    public function test_an_agency_defined_status_not_on_the_allow_list_is_excluded(): void
    {
        // AT-Core-Matches (2026-09-29), Johan's ruling — REVERSES this test's
        // former assertion. Core Matches moved from a blacklist (fails OPEN —
        // an unknown status matched by default) to an allow-list (fails
        // CLOSED — an unknown status is excluded by default). A status this
        // class has never heard of is now exactly the case that must NOT
        // match, unless an agency explicitly adds it to its own configured
        // list (AgencyContactSettings::core_matches_allowed_statuses).
        //
        // withoutEvents(): PropertyObserver's OWN, separate, unrelated
        // write-side vocabulary guard (AT-307, PropertyObserver.php:139-145)
        // refuses to PERSIST a status outside this agency's vocabulary at
        // all — a real bug-class guard for a real user/API/import write, but
        // orthogonal to this test's actual question (does the MATCHER treat
        // an unlisted status as excluded), and it would block this fixture
        // from ever existing to test against. Bypassed here, for this
        // fixture only, exactly like a status already sitting in the
        // database from before that guard existed would (that data is real
        // and the matcher must still handle it correctly). external_id and
        // branch_id are supplied explicitly because withoutEvents() also
        // skips the creating-event that normally auto-fills both.
        $property = Property::withoutEvents(fn () => $this->rental([
            'status' => 'available_immediately',
            'external_id' => (string) \Illuminate\Support\Str::uuid(),
            'branch_id' => $this->agent->branch_id,
        ]));

        $this->assertNotContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    public function test_a_rental_match_never_surfaces_a_listing_on_a_sale_status(): void
    {
        // The belt-and-braces cross-check this blacklist replaced must survive.
        $property = $this->rental(['status' => 'for_sale']);

        $this->assertNotContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    // ---- Defect 2 — off-market truth is one list -------------------------

    public function test_off_market_statuses_are_derived_from_the_property_model(): void
    {
        // AT-Core-Matches (2026-09-29), Johan's ruling — 'expired' carved out
        // of this loop: it is off-market on the Property model (still true,
        // untouched) but is now DELIBERATELY on the Core Matches allow-list
        // (Johan: "only active + expired + other agency stock"). Every OTHER
        // off-market status stays non-matchable, unchanged.
        foreach (array_diff(Property::OFF_MARKET_STATUSES, ['expired']) as $status) {
            $this->assertFalse(
                MatchingService::isMatchableStatus($status),
                "{$status} is off-market on the Property model and must be non-matchable here too"
            );
        }
    }

    public function test_expired_is_off_market_but_matchable_core_matches_specific_exception(): void
    {
        $this->assertContains('expired', Property::OFF_MARKET_STATUSES, 'expired must stay off-market for every OTHER consumer (display, syndication)');
        $this->assertTrue(MatchingService::isMatchableStatus('expired'), 'but Core Matches specifically shows expired stock, per Johan\'s ruling');
    }

    public function test_prospecting_stock_is_never_offered_to_a_buyer(): void
    {
        // We do not hold a mandate on prospecting stock at all.
        $property = $this->rental(['status' => 'prospecting']);

        $this->assertNotContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    public function test_a_let_out_rental_is_never_offered_to_a_buyer(): void
    {
        $property = $this->rental(['status' => 'let_out']);

        $this->assertNotContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    // ---- Defect 3 — the relaxed price band actually relaxes ---------------

    public function test_a_property_just_over_budget_survives_as_a_decayed_near_miss(): void
    {
        // R11 500 against a R10 000 ceiling: inside the +30% band, so spec
        // §5.1 says surface it with a decayed score, not delete it.
        $property = $this->rental(['price' => 11500]);

        $resolved = $this->resolve($this->wishlist());

        $this->assertContains($property->id, $resolved->pluck('id')->all());
        $this->assertLessThan(
            100,
            (int) $resolved->firstWhere('id', $property->id)->match_score,
            'a near-miss must be DECAYED, not paid full price marks — handing score() a '
            . 'band without decaying inside it would score an over-budget property 100'
        );
    }

    public function test_a_property_inside_the_stated_budget_still_scores_full_price_marks(): void
    {
        // The decay must bite only OUTSIDE the buyer's stated range.
        $property = $this->rental(['price' => 7000]);

        $resolved = $this->resolve($this->wishlist());

        $this->assertSame(100, (int) $resolved->firstWhere('id', $property->id)->match_score);
    }

    public function test_a_property_far_over_budget_is_still_excluded(): void
    {
        // R20 000 against a R10 000 ceiling is outside the band — the band is
        // a tolerance, not an amnesty.
        $property = $this->rental(['price' => 20000]);

        $this->assertNotContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    public function test_the_sql_band_and_the_scorer_gate_use_one_number(): void
    {
        // Guards the exact drift that broke this: a price at the very edge of
        // the SQL band must also clear score()'s gate.
        $edge = (int) floor(10000 * (1 + MatchingService::RELAXED_PRICE_BAND));
        $property = $this->rental(['price' => $edge]);

        $this->assertGreaterThan(
            0,
            app(MatchingService::class)->score($property, $this->wishlist(), MatchingService::RELAXED_PRICE_BAND),
            'the scorer must gate on the same band the candidate query widened by'
        );
    }

    // ---- Defect 4 — incomplete data is not a mismatch ---------------------

    public function test_a_listing_with_no_price_captured_still_matches(): void
    {
        // The live case: price 0 means "not captured yet", not "free".
        $property = $this->rental(['price' => 0]);

        $this->assertContains(
            $property->id,
            $this->resolve($this->wishlist())->pluck('id')->all(),
            'a 0 price is incomplete listing data and must never be read as below the buyer\'s minimum'
        );
    }

    public function test_a_listing_with_no_bed_count_captured_still_matches(): void
    {
        $property = $this->rental(['beds' => 0]);

        $this->assertContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    public function test_the_sql_prefilter_and_the_scorer_agree_about_missing_data(): void
    {
        // The defect in one assertion: anything score() is willing to score
        // must survive the candidate query that feeds it.
        $property = $this->rental(['price' => 0, 'beds' => 0]);
        $match    = $this->wishlist();

        $this->assertGreaterThanOrEqual(
            MatchingService::MIN_SCORE_TO_DISPLAY,
            app(MatchingService::class)->score($property, $match, MatchingService::RELAXED_PRICE_BAND)
        );
        $this->assertContains($property->id, $this->resolve($match)->pluck('id')->all());
    }

    // ---- The batched counts path must agree with the single-match path ----

    public function test_the_oversight_counts_page_agrees_with_the_results_page(): void
    {
        // propertyCountsForMatches() is a hand-kept PHP mirror of
        // propertiesForMatch()'s WHERE clauses; every fix above had to land in
        // both. This asserts they did not drift apart again.
        $this->rental(['status' => 'to_let']);
        $this->rental(['price' => 0]);
        $this->rental(['price' => 11500]);
        $this->rental(['status' => 'let_out']);   // must not count
        $this->rental(['status' => 'for_sale']);  // must not count

        $match  = $this->wishlist();
        $counts = app(MatchingService::class)->propertyCountsForMatches(collect([$match]));

        $this->assertSame(
            $this->resolve($match)->count(),
            $counts[$match->id]['total'],
            'the counts page and the results page must never disagree about how many properties match'
        );
    }

    // ---- Johan's ruling, 2026-09-10 — parent-area suburbs -----------------

    /** Creates the Margate / Margate Beach pair in one city, as P24 files them. */
    private function seedMargate(): array
    {
        $parent = P24Suburb::forceCreate(['name' => 'Margate', 'slug' => 'margate', 'p24_city_id' => 376]);
        $child  = P24Suburb::forceCreate(['name' => 'Margate Beach', 'slug' => 'margate-beach', 'p24_city_id' => 376]);

        return [$parent, $child];
    }

    public function test_a_beachfront_wishlist_also_matches_its_parent_suburb(): void
    {
        // The live case: the buyer ticked "Margate Beach"; the 2-bed at R6 940
        // that plainly suited her is filed under plain "Margate".
        [$parent, $child] = $this->seedMargate();
        $property = $this->rental(['p24_suburb_id' => $parent->id]);

        $ids = $this->resolve($this->wishlist(['p24_suburb_ids' => [$child->id]]))->pluck('id')->all();

        $this->assertContains($property->id, $ids);
    }

    public function test_the_parent_area_rule_runs_child_to_parent_only(): void
    {
        // A buyer who ticks the broad "Margate" is NOT handed the beachfront
        // sub-suburbs. Deliberately asymmetric — a separate decision.
        [$parent, $child] = $this->seedMargate();
        $property = $this->rental(['p24_suburb_id' => $child->id]);

        $ids = $this->resolve($this->wishlist(['p24_suburb_ids' => [$parent->id]]))->pluck('id')->all();

        $this->assertNotContains($property->id, $ids);
    }

    public function test_parent_widening_never_crosses_a_city_boundary(): void
    {
        // p24_suburbs holds the same NAME in more than one province — see
        // P24Suburb::lookup()'s Melville note. A same-named suburb in another
        // city must never be pulled in.
        $child     = P24Suburb::forceCreate(['name' => 'Margate Beach', 'slug' => 'margate-beach', 'p24_city_id' => 376]);
        $elsewhere = P24Suburb::forceCreate(['name' => 'Margate', 'slug' => 'margate-gp', 'p24_city_id' => 999]);
        $property  = $this->rental(['p24_suburb_id' => $elsewhere->id]);

        $ids = $this->resolve($this->wishlist(['p24_suburb_ids' => [$child->id]]))->pluck('id')->all();

        $this->assertNotContains($property->id, $ids);
    }

    public function test_widening_matches_whole_words_only(): void
    {
        // "Ram" must never be treated as the parent of "Ramsgate".
        $ram      = P24Suburb::forceCreate(['name' => 'Ram', 'slug' => 'ram', 'p24_city_id' => 376]);
        $ramsgate = P24Suburb::forceCreate(['name' => 'Ramsgate', 'slug' => 'ramsgate', 'p24_city_id' => 376]);

        $this->assertNotContains(
            $ram->id,
            MatchingService::suburbIdsWithParentAreas([$ramsgate->id]),
            'prefix matching is word-boundary, never character-boundary'
        );
    }

    public function test_a_single_word_suburb_widens_to_nothing(): void
    {
        $ramsgate = P24Suburb::forceCreate(['name' => 'Ramsgate', 'slug' => 'ramsgate', 'p24_city_id' => 376]);

        $this->assertSame([$ramsgate->id], MatchingService::suburbIdsWithParentAreas([$ramsgate->id]));
    }

    public function test_an_open_wishlist_is_unaffected_by_widening(): void
    {
        $this->assertSame([], MatchingService::suburbIdsWithParentAreas([]));
    }
}
