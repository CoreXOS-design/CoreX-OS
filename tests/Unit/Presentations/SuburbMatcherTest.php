<?php

declare(strict_types=1);

namespace Tests\Unit\Presentations;

use App\Support\Presentations\SuburbMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Suburb-match bug repro: subject "Uvongo Beach" never matched comp
 * "uvongo" — directional str_contains + exact LOWER(suburb)= killed
 * the pool. SuburbMatcher normalises the trailing locality suffix and
 * compares roots. Tests pin both the desired matches and the
 * non-matches that prove distinct suburbs stay distinct.
 */
final class SuburbMatcherTest extends TestCase
{
    public function test_normalise_strips_trailing_locality_suffixes(): void
    {
        $this->assertSame('uvongo',         SuburbMatcher::normaliseSuburbToken('Uvongo Beach'));
        $this->assertSame('uvongo',         SuburbMatcher::normaliseSuburbToken('Uvongo'));
        $this->assertSame('margate',        SuburbMatcher::normaliseSuburbToken('Margate North'));
        $this->assertSame('margate',        SuburbMatcher::normaliseSuburbToken('Margate'));
        $this->assertSame('port shepstone', SuburbMatcher::normaliseSuburbToken('Port Shepstone South'));
        $this->assertSame('port shepstone', SuburbMatcher::normaliseSuburbToken('Port Shepstone'));
        $this->assertSame('shelly',         SuburbMatcher::normaliseSuburbToken('Shelly Beach'));
    }

    public function test_normalise_is_case_insensitive_and_collapses_whitespace(): void
    {
        $this->assertSame('uvongo', SuburbMatcher::normaliseSuburbToken('  UVONGO   BEACH  '));
        $this->assertSame('uvongo', SuburbMatcher::normaliseSuburbToken("uvongo\tbeach"));
    }

    public function test_normalise_recursive_suffix_stripping(): void
    {
        // Two stackable suffixes: drop both, leaving the locality root.
        $this->assertSame('margate', SuburbMatcher::normaliseSuburbToken('Margate Beach North'));
    }

    public function test_normalise_leaves_single_word_suffix_intact(): void
    {
        // "Beach" alone is not stripped — there's no locality root behind it.
        $this->assertSame('beach', SuburbMatcher::normaliseSuburbToken('Beach'));
    }

    public function test_normalise_returns_empty_for_null_or_blank(): void
    {
        $this->assertSame('', SuburbMatcher::normaliseSuburbToken(null));
        $this->assertSame('', SuburbMatcher::normaliseSuburbToken(''));
        $this->assertSame('', SuburbMatcher::normaliseSuburbToken('   '));
    }

    // ── matches() — must reconcile subject↔comp asymmetry ───────────────

    public function test_uvongo_matches_uvongo_beach_both_directions(): void
    {
        $this->assertTrue(SuburbMatcher::matches('Uvongo', 'Uvongo Beach'));
        $this->assertTrue(SuburbMatcher::matches('Uvongo Beach', 'Uvongo'));
        $this->assertTrue(SuburbMatcher::matches('uvongo', 'Uvongo Beach'));
        $this->assertTrue(SuburbMatcher::matches('UVONGO BEACH', 'uvongo'));
    }

    public function test_margate_north_matches_margate(): void
    {
        $this->assertTrue(SuburbMatcher::matches('Margate North', 'Margate'));
        $this->assertTrue(SuburbMatcher::matches('Margate', 'Margate North'));
    }

    public function test_distinct_suburbs_do_not_match(): void
    {
        $this->assertFalse(SuburbMatcher::matches('Uvongo', 'Ramsgate'));
        $this->assertFalse(SuburbMatcher::matches('Uvongo Beach', 'Ramsgate'));
        $this->assertFalse(SuburbMatcher::matches('Margate', 'Shelly Beach'));
        $this->assertFalse(SuburbMatcher::matches('Port Edward', 'Port Shepstone'));
    }

    public function test_distinct_suburbs_do_not_match_via_substring_within_word(): void
    {
        // Defensive: SuburbMatcher uses whitespace-bounded contains.
        // "uvong" must not match "uvongo" via prefix-substring leakage.
        $this->assertFalse(SuburbMatcher::matches('uvong', 'uvongo'));
    }

    public function test_either_side_null_or_blank_is_no_match(): void
    {
        $this->assertFalse(SuburbMatcher::matches(null, 'Uvongo'));
        $this->assertFalse(SuburbMatcher::matches('Uvongo', null));
        $this->assertFalse(SuburbMatcher::matches('', 'Uvongo'));
        $this->assertFalse(SuburbMatcher::matches('Uvongo', ''));
    }

    public function test_exact_root_match(): void
    {
        $this->assertTrue(SuburbMatcher::matches('Uvongo', 'Uvongo'));
        $this->assertTrue(SuburbMatcher::matches('Port Shepstone', 'Port Shepstone'));
    }

    public function test_unknown_suffix_still_matches_via_token_subset_fallback(): void
    {
        // "Resort" is not in our curated suffix list yet — but the
        // token-contains fallback should still match when one side is
        // a strict subset of the other. Belt-and-braces for future
        // unknown SA suffixes.
        $this->assertTrue(SuburbMatcher::matches('Uvongo', 'Uvongo Resort'));
        $this->assertTrue(SuburbMatcher::matches('Port Shepstone', 'Port Shepstone Heights'));
    }
}
