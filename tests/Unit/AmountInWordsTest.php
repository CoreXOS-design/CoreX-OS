<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\TestCase;

/**
 * HD-4 — amount-in-words for e-sign documents, to the HFC house rule (Johan, 2026-07-15):
 * figures round to WHOLE RANDS, round-half-up, and read "… Rand" — never cents, never "only".
 */
final class AmountInWordsTest extends TestCase
{
    public function test_a_whole_rand_amount_reads_in_rands_with_no_cents_clause(): void
    {
        $this->assertSame('One million two hundred and fifty thousand Rand', AmountInWords::rands(1_250_000));
    }

    /** THE HOUSE RULE: cents never appear — the figure is rounded to whole rands, round-half-up. */
    public function test_cents_are_rounded_away_half_up(): void
    {
        $this->assertSame('One million two hundred and fifty thousand Rand', AmountInWords::rands(1_250_000.49));
        // .50 rounds UP to the next rand — and there is still no cents clause. (The word-gen is
        // preserved as-is from the two legacy copies, hence "thousand one", not "thousand and one";
        // the "and"-placement is a pre-existing quirk HD-4 deliberately does NOT restyle — a legal
        // word-change is its own fidelity decision, flagged in the plan for Johan.)
        $this->assertSame('One million two hundred and fifty thousand one Rand', AmountInWords::rands(1_250_000.50));
    }

    /** A lease escalation that lands on a fraction is rounded the same way (Johan's example). */
    public function test_a_fractional_escalation_rounds_to_rands(): void
    {
        // R8,500 + 8.5% = R9,222.50 → R9,223.
        $this->assertSame('Nine thousand two hundred and twenty-three Rand', AmountInWords::rands(8500 * 1.085));
    }

    public function test_it_accepts_a_string_amount_and_null(): void
    {
        $this->assertSame('Eight thousand five hundred Rand', AmountInWords::rands('8500'));
        $this->assertSame('Eight thousand five hundred and one Rand', AmountInWords::rands('8500.50'));
        $this->assertSame('Zero Rand', AmountInWords::rands(null));
    }

    public function test_zero_and_empty_read_zero_rand(): void
    {
        $this->assertSame('Zero Rand', AmountInWords::rands(0));
        $this->assertSame('Zero Rand', AmountInWords::rands(''));
        // A nonsensical negative is floored to Zero Rand rather than crashing the document.
        $this->assertSame('Zero Rand', AmountInWords::rands(-100));
    }

    public function test_scales_from_small_to_millions(): void
    {
        $this->assertSame('One Rand', AmountInWords::rands(1));
        $this->assertSame('Nineteen Rand', AmountInWords::rands(19));
        $this->assertSame('Twenty-one Rand', AmountInWords::rands(21));
        $this->assertSame('One hundred Rand', AmountInWords::rands(100));
        $this->assertSame('One hundred and five Rand', AmountInWords::rands(105));
        $this->assertSame('Two thousand fifty Rand', AmountInWords::rands(2050)); // legacy word-gen: no "and" here (preserved)
        $this->assertSame('Three million Rand', AmountInWords::rands(3_000_000));
    }

    /** No output ever contains a cents word or the legalism "only". */
    public function test_output_never_mentions_cents_or_only(): void
    {
        foreach ([0, 1, 99.99, 1234.56, 1_250_000.50, 8500 * 1.085] as $amount) {
            $words = strtolower(AmountInWords::rands($amount));
            $this->assertStringNotContainsString('cent', $words);
            $this->assertStringNotContainsString('only', $words);
            $this->assertStringContainsString('rand', $words);
        }
    }
}
