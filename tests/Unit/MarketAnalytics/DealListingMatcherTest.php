<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\Support\DealListingMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DealListingMatcher pure helpers.
 *
 * All tests run in-memory with no DB access.
 * The DB-dependent buildDomResolutionMap() is tested via integration / tinker.
 */
class DealListingMatcherTest extends TestCase
{
    private function matcher(): DealListingMatcher
    {
        return new DealListingMatcher();
    }

    // =========================================================================
    // Constants pinned
    // =========================================================================

    public function test_match_version_constant_is_pinned(): void
    {
        $this->assertSame('deal_listing_match_v1', DealListingMatcher::MATCH_VERSION);
    }

    public function test_score_threshold_constant_is_80(): void
    {
        $this->assertSame(80, DealListingMatcher::SCORE_THRESHOLD);
    }

    public function test_max_listing_age_days_constant_is_365(): void
    {
        $this->assertSame(365, DealListingMatcher::MAX_LISTING_AGE_DAYS);
    }

    public function test_price_tolerance_pct_constant_is_20_pct(): void
    {
        $this->assertSame(0.20, DealListingMatcher::PRICE_TOLERANCE_PCT);
    }

    // =========================================================================
    // normalizeAddress
    // =========================================================================

    public function test_normalize_lowercases_input(): void
    {
        $tokens = $this->matcher()->normalizeAddress('12 MAIN STREET');
        $this->assertSame(['12', 'main', 'street'], $tokens);
    }

    public function test_normalize_strips_punctuation(): void
    {
        $tokens = $this->matcher()->normalizeAddress('12 Main St, Cape Town');
        $this->assertContains('12', $tokens);
        $this->assertContains('main', $tokens);
        $this->assertContains('st', $tokens);
        $this->assertContains('cape', $tokens);
        $this->assertContains('town', $tokens);
        // Comma should not appear as a standalone token
        $this->assertNotContains(',', $tokens);
    }

    public function test_normalize_deduplicates_tokens(): void
    {
        $tokens = $this->matcher()->normalizeAddress('sea sea sea view');
        $this->assertCount(2, $tokens);
        $this->assertContains('sea', $tokens);
        $this->assertContains('view', $tokens);
    }

    public function test_normalize_sorts_tokens_alphabetically(): void
    {
        $tokens = $this->matcher()->normalizeAddress('Zebra Alpha Mango');
        $this->assertSame(['alpha', 'mango', 'zebra'], $tokens);
    }

    public function test_normalize_empty_string_returns_empty_array(): void
    {
        $this->assertSame([], $this->matcher()->normalizeAddress(''));
    }

    public function test_normalize_whitespace_only_returns_empty_array(): void
    {
        $this->assertSame([], $this->matcher()->normalizeAddress('   '));
    }

    public function test_normalize_strips_hyphens(): void
    {
        // "Sea-Point" → tokens should not include the hyphen
        $tokens = $this->matcher()->normalizeAddress('Sea-Point Road');
        $this->assertNotContains('-', $tokens);
        $this->assertContains('road', $tokens);
    }

    public function test_normalize_numbers_are_preserved(): void
    {
        $tokens = $this->matcher()->normalizeAddress('42 Ocean Drive');
        $this->assertContains('42', $tokens);
    }

    // =========================================================================
    // tokenSetOverlap
    // =========================================================================

    public function test_overlap_identical_sets_returns_100(): void
    {
        $tokens = ['12', 'main', 'street'];
        $this->assertSame(100, $this->matcher()->tokenSetOverlap($tokens, $tokens));
    }

    public function test_overlap_disjoint_sets_returns_0(): void
    {
        $this->assertSame(0, $this->matcher()->tokenSetOverlap(
            ['alpha', 'beta'],
            ['gamma', 'delta'],
        ));
    }

    public function test_overlap_partial_overlap_jaccard(): void
    {
        // a = [a, b, c], b = [b, c, d] → ∩ = {b,c}, ∪ = {a,b,c,d} → 2/4 = 50
        $result = $this->matcher()->tokenSetOverlap(['a', 'b', 'c'], ['b', 'c', 'd']);
        $this->assertSame(50, $result);
    }

    public function test_overlap_empty_first_set_returns_0(): void
    {
        $this->assertSame(0, $this->matcher()->tokenSetOverlap([], ['a', 'b']));
    }

    public function test_overlap_empty_second_set_returns_0(): void
    {
        $this->assertSame(0, $this->matcher()->tokenSetOverlap(['a', 'b'], []));
    }

    public function test_overlap_single_shared_token(): void
    {
        // ∩ = {main}, ∪ = {main, street} → 1/2 = 50
        $result = $this->matcher()->tokenSetOverlap(['main'], ['main', 'street']);
        $this->assertSame(50, $result);
    }

    public function test_overlap_returns_integer(): void
    {
        $result = $this->matcher()->tokenSetOverlap(['a', 'b'], ['a']);
        $this->assertIsInt($result);
    }

    // =========================================================================
    // score
    // =========================================================================

    public function test_score_identical_addresses_no_price_is_100(): void
    {
        $s = $this->matcher()->score('12 Main Street', '12 Main Street');
        $this->assertSame(100, $s);
    }

    public function test_score_price_bonus_added_when_within_20_pct(): void
    {
        // Deal price = 1,000,000 ; listing price_cents = 100,000,000 → listing = 1,000,000 → ±0%
        $s = $this->matcher()->score('12 Main Street', '12 Main Street', 1_000_000.0, 100_000_000);
        $this->assertSame(110, $s);
    }

    public function test_score_no_price_bonus_when_price_outside_20_pct(): void
    {
        // Deal = 1,000,000 ; listing = 2,000,000 → 100% apart
        $s = $this->matcher()->score('12 Main Street', '12 Main Street', 1_000_000.0, 200_000_000);
        $this->assertSame(100, $s);
    }

    public function test_score_at_exactly_20_pct_boundary_gives_bonus(): void
    {
        // Deal = 1,000,000 ; listing = 1,200,000 (20% above) → exactly at boundary → bonus applies
        $s = $this->matcher()->score('12 Main Street', '12 Main Street', 1_000_000.0, 120_000_000);
        $this->assertSame(110, $s);
    }

    public function test_score_no_price_bonus_when_deal_price_zero(): void
    {
        $s = $this->matcher()->score('12 Main Street', '12 Main Street', 0.0, 100_000_000);
        $this->assertSame(100, $s);
    }

    public function test_score_no_price_bonus_when_listing_price_null(): void
    {
        $s = $this->matcher()->score('12 Main Street', '12 Main Street', 1_000_000.0, null);
        $this->assertSame(100, $s);
    }

    public function test_score_no_price_bonus_when_listing_price_zero_cents(): void
    {
        $s = $this->matcher()->score('12 Main Street', '12 Main Street', 1_000_000.0, 0);
        $this->assertSame(100, $s);
    }

    public function test_score_below_threshold_for_unrelated_addresses(): void
    {
        $s = $this->matcher()->score('12 Main Street Seapoint', '99 Kloof Road Bantry Bay');
        $this->assertLessThan(DealListingMatcher::SCORE_THRESHOLD, $s);
    }

    public function test_score_empty_deal_address_returns_0_base(): void
    {
        $s = $this->matcher()->score('', '12 Main Street');
        $this->assertSame(0, $s);
    }

    public function test_score_empty_listing_address_returns_0_base(): void
    {
        $s = $this->matcher()->score('12 Main Street', '');
        $this->assertSame(0, $s);
    }

    public function test_score_is_deterministic(): void
    {
        $m  = $this->matcher();
        $s1 = $m->score('12 Main Street', '12 Main St', 900_000.0, 95_000_000);
        $s2 = $m->score('12 Main Street', '12 Main St', 900_000.0, 95_000_000);
        $this->assertSame($s1, $s2);
    }

    // =========================================================================
    // computeDomDays
    // =========================================================================

    public function test_dom_days_computes_correct_days(): void
    {
        // 2024-01-01 to 2024-04-11 = 101 days
        $dom = $this->matcher()->computeDomDays('2024-04-11', '2024-01-01');
        $this->assertSame(101, $dom);
    }

    public function test_dom_days_zero_when_same_date(): void
    {
        $dom = $this->matcher()->computeDomDays('2024-06-01', '2024-06-01');
        $this->assertSame(0, $dom);
    }

    public function test_dom_days_null_when_listed_after_sold(): void
    {
        $dom = $this->matcher()->computeDomDays('2024-01-01', '2024-04-11');
        $this->assertNull($dom);
    }

    public function test_dom_days_null_on_malformed_sold_date(): void
    {
        $dom = $this->matcher()->computeDomDays('not-a-date', '2024-01-01');
        $this->assertNull($dom);
    }

    public function test_dom_days_null_on_malformed_listed_date(): void
    {
        $dom = $this->matcher()->computeDomDays('2024-01-01', 'bad');
        $this->assertNull($dom);
    }

    public function test_dom_days_is_deterministic(): void
    {
        $m  = $this->matcher();
        $d1 = $m->computeDomDays('2024-06-30', '2024-03-15');
        $d2 = $m->computeDomDays('2024-06-30', '2024-03-15');
        $this->assertSame($d1, $d2);
    }

    public function test_dom_days_crosses_leap_year(): void
    {
        // 2024 is a leap year; 2024-01-01 to 2024-12-31 = 365 days
        $dom = $this->matcher()->computeDomDays('2024-12-31', '2024-01-01');
        $this->assertSame(365, $dom);
    }

    // =========================================================================
    // streetNumberBonus
    // =========================================================================

    public function test_street_number_bonus_matching_numbers_returns_15(): void
    {
        $m  = $this->matcher();
        $tA = $m->normalizeAddress('12 Ocean Drive Ballito');
        $tB = $m->normalizeAddress('12 Ocean Drive, Ballito');
        $this->assertSame(DealListingMatcher::STREET_NUMBER_MATCH_BONUS, $m->streetNumberBonus($tA, $tB));
    }

    public function test_street_number_bonus_different_numbers_returns_minus_20(): void
    {
        $m  = $this->matcher();
        $tA = $m->normalizeAddress('12 Ocean Drive Ballito');
        $tB = $m->normalizeAddress('99 Ocean Drive Ballito');
        $this->assertSame(DealListingMatcher::STREET_NUMBER_MISMATCH_PENALTY, $m->streetNumberBonus($tA, $tB));
    }

    public function test_street_number_bonus_missing_in_first_returns_0(): void
    {
        $m  = $this->matcher();
        $tA = $m->normalizeAddress('Ocean Drive Ballito');
        $tB = $m->normalizeAddress('12 Ocean Drive Ballito');
        $this->assertSame(0, $m->streetNumberBonus($tA, $tB));
    }

    public function test_street_number_bonus_missing_in_second_returns_0(): void
    {
        $m  = $this->matcher();
        $tA = $m->normalizeAddress('12 Ocean Drive Ballito');
        $tB = $m->normalizeAddress('Ocean Drive Ballito');
        $this->assertSame(0, $m->streetNumberBonus($tA, $tB));
    }

    public function test_street_number_bonus_both_empty_returns_0(): void
    {
        $m = $this->matcher();
        $this->assertSame(0, $m->streetNumberBonus([], []));
    }

    public function test_street_number_bonus_constant_values_are_pinned(): void
    {
        $this->assertSame(15, DealListingMatcher::STREET_NUMBER_MATCH_BONUS);
        $this->assertSame(-20, DealListingMatcher::STREET_NUMBER_MISMATCH_PENALTY);
    }

    // =========================================================================
    // externalIdBonus
    // =========================================================================

    public function test_external_id_bonus_returns_25_when_id_in_file_no(): void
    {
        $bonus = $this->matcher()->externalIdBonus(
            dealFileNo:         'PC-12345',
            dealRemarks:        null,
            listingExternalId:  'PC-12345',
            listingExternalRef: null,
        );
        $this->assertSame(DealListingMatcher::EXTERNAL_ID_MATCH_BONUS, $bonus);
    }

    public function test_external_id_bonus_returns_25_when_ref_in_remarks(): void
    {
        $bonus = $this->matcher()->externalIdBonus(
            dealFileNo:         null,
            dealRemarks:        'Linked to listing ref ABC-999 transferred',
            listingExternalId:  null,
            listingExternalRef: 'ABC-999',
        );
        $this->assertSame(DealListingMatcher::EXTERNAL_ID_MATCH_BONUS, $bonus);
    }

    public function test_external_id_bonus_is_case_insensitive(): void
    {
        $bonus = $this->matcher()->externalIdBonus(
            dealFileNo:         'abc-999',
            dealRemarks:        null,
            listingExternalId:  'ABC-999',
            listingExternalRef: null,
        );
        $this->assertSame(DealListingMatcher::EXTERNAL_ID_MATCH_BONUS, $bonus);
    }

    public function test_external_id_bonus_returns_0_when_no_match(): void
    {
        $bonus = $this->matcher()->externalIdBonus(
            dealFileNo:         'PC-99999',
            dealRemarks:        'Some unrelated remarks',
            listingExternalId:  'PC-11111',
            listingExternalRef: 'REF-22222',
        );
        $this->assertSame(0, $bonus);
    }

    public function test_external_id_bonus_returns_0_when_listing_ids_are_null(): void
    {
        $bonus = $this->matcher()->externalIdBonus(
            dealFileNo:         'PC-12345',
            dealRemarks:        'Some remarks',
            listingExternalId:  null,
            listingExternalRef: null,
        );
        $this->assertSame(0, $bonus);
    }

    public function test_external_id_bonus_returns_0_when_listing_ids_are_empty(): void
    {
        $bonus = $this->matcher()->externalIdBonus(
            dealFileNo:         'PC-12345',
            dealRemarks:        null,
            listingExternalId:  '',
            listingExternalRef: '',
        );
        $this->assertSame(0, $bonus);
    }

    public function test_external_id_bonus_constant_is_pinned(): void
    {
        $this->assertSame(25, DealListingMatcher::EXTERNAL_ID_MATCH_BONUS);
    }
}
