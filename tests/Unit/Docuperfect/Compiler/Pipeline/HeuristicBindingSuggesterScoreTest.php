<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Pipeline;

use App\Services\Docuperfect\Compiler\Pipeline\HeuristicBindingSuggester;
use PHPUnit\Framework\TestCase;

/**
 * WS4-E Gate 4 — the pure scoring behind binding suggestions (no DB).
 */
final class HeuristicBindingSuggesterScoreTest extends TestCase
{
    public function test_strong_synonym_and_label_matches_score_high(): void
    {
        $this->assertGreaterThanOrEqual(0.8, HeuristicBindingSuggester::score('Purchase Price', 'the sum of R', 'purchase_price', 'Purchase Price'));
        $this->assertGreaterThanOrEqual(0.7, HeuristicBindingSuggester::score('Seller ID Number', '', 'seller_id_number', 'Seller ID Number'));
    }

    public function test_unrelated_entries_score_zero(): void
    {
        $this->assertSame(0.0, HeuristicBindingSuggester::score('Purchase Price', '', 'gps', 'GPS Coordinates'));
        $this->assertSame(0.0, HeuristicBindingSuggester::score('Purchase Price', '', 'deposit', 'Deposit'));
    }

    public function test_the_best_key_outranks_a_sibling(): void
    {
        $price = HeuristicBindingSuggester::score('Purchase Price', '', 'purchase_price', 'Purchase Price');
        $rental = HeuristicBindingSuggester::score('Purchase Price', '', 'monthly_rental', 'Monthly Rental');
        $this->assertGreaterThan($rental, $price);
    }

    public function test_empty_label_scores_zero(): void
    {
        $this->assertSame(0.0, HeuristicBindingSuggester::score('', '', 'purchase_price', 'Purchase Price'));
    }
}
