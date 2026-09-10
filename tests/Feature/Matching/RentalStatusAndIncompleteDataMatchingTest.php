<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
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

    public function test_an_under_offer_listing_still_reaches_match_results(): void
    {
        // under_offer is NOT in Property::OFF_MARKET_STATUSES — the sale can
        // still fall through and agents want backup buyers on it.
        $property = $this->rental(['status' => 'under_offer']);

        $this->assertContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
    }

    public function test_an_agency_defined_status_is_not_silently_dropped(): void
    {
        // The input-space rule: a status this class has never heard of must
        // fail OPEN, not delete live stock without a word.
        $property = $this->rental(['status' => 'available_immediately']);

        $this->assertContains($property->id, $this->resolve($this->wishlist())->pluck('id')->all());
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
        foreach (Property::OFF_MARKET_STATUSES as $status) {
            $this->assertFalse(
                MatchingService::isMatchableStatus($status),
                "{$status} is off-market on the Property model and must be non-matchable here too"
            );
        }
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
            'a near-miss must be decayed, not scored as a perfect fit'
        );
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
}
