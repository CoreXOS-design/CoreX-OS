<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Amount → words, for E-SIGN DOCUMENT figures.
 *
 * ── HFC house rule (Johan, 2026-07-15), and it is a DOCUMENT-LAYER rule, not accounting-wide ──
 * CoreX documents never show cents. A figure is rounded to whole rands — numeric AND in words —
 * ROUND-HALF-UP (R1,250,000.50 → R1,250,001). A lease escalation that lands on a fraction is
 * rounded the same way. The ONLY place cents survive is rental UTILITY invoices, which are the
 * accounting side, not this. So the amount-in-words on a mandate / OTP / lease reads
 * "One million two hundred and fifty thousand Rand" — no cents clause, no "only".
 *
 * This is the single converter for that. Both `WebTemplateDataService` and `ESignWizardController`
 * carried their own byte-identical copy of the number-to-words algorithm (BUILD_STANDARD §6 — two
 * copies of one rule is a latent divergence); they now both delegate here.
 *
 * Casing follows the established rendered convention (sentence-case words + capital "Rand", as the
 * template sample data shows), so this changes what documents say ONLY by rounding-and-appending
 * "Rand" — it does not restyle the existing words.
 *
 * Reconciliation note: when the CDS-parsed `deal.amount_words` (what the page literally says) is
 * checked against this computed value, compare at RAND precision — both sides are whole rands, so a
 * sub-rand difference is never a real disagreement.
 */
final class AmountInWords
{
    /**
     * "One million two hundred and fifty thousand Rand", rounded half-up to whole rands.
     */
    public static function rands(int|float|string|null $amount): string
    {
        $rands = (int) round((float) $amount, 0, PHP_ROUND_HALF_UP);

        if ($rands <= 0) {
            // Zero is the only non-positive a document figure can legitimately be; a negative is a
            // caller bug, and rendering "Zero Rand" is a safe, non-crashing floor for both.
            return 'Zero Rand';
        }

        return ucfirst(self::toWords($rands)) . ' Rand';
    }

    /** The bare number in words, sentence-case, no currency word. */
    private static function toWords(int $number): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
                 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
                 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $convert = function (int $n) use (&$convert, $ones, $tens): string {
            if ($n < 20) return $ones[$n];
            if ($n < 100) return $tens[(int) ($n / 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
            if ($n < 1000) return $ones[(int) ($n / 100)] . ' hundred' . ($n % 100 ? ' and ' . $convert($n % 100) : '');
            if ($n < 1000000) return $convert((int) ($n / 1000)) . ' thousand' . ($n % 1000 ? ' ' . $convert($n % 1000) : '');
            return $convert((int) ($n / 1000000)) . ' million' . ($n % 1000000 ? ' ' . $convert($n % 1000000) : '');
        };

        return $convert($number);
    }
}
