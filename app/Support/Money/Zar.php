<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Canonical South African Rand (ZAR) money helper.
 *
 * The codebase previously formatted ZAR inline in several places
 * (`'R ' . number_format(...)`) with no single source — AT-177 fixes that class by
 * introducing ONE formatter/parser/validator. Format is `R 1,250,000.00`
 * (thousands separator ",", decimal "."), matching the CoreX SA convention.
 */
final class Zar
{
    public const VAT_RATE = 0.15; // South African VAT, 15%

    /** Format a numeric value as ZAR, e.g. 1250000 → "R 1,250,000.00". */
    public static function format(int|float|string $value, int $decimals = 2): string
    {
        $number = is_string($value) ? (self::parse($value) ?? 0.0) : (float) $value;

        return 'R ' . number_format($number, $decimals, '.', ',');
    }

    /** Whole-rand format, no cents: 1250000 → "R 1,250,000". */
    public static function formatWhole(int|float|string $value): string
    {
        return self::format($value, 0);
    }

    /**
     * Parse a user-entered ZAR string to a float. Accepts "R 1,250,000.00",
     * "1250000", "R1 250 000,00" (space thousands / comma decimal). Returns null if
     * the input contains no parseable number.
     */
    public static function parse(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        // Strip currency symbol and whitespace.
        $s = preg_replace('/[Rr\s\x{00A0}]/u', '', $raw) ?? '';

        // Normalise separators: if both "," and "." appear, the LAST one is the decimal.
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace('.', '', $s)          // "1.250.000,00" → euro style
                : str_replace(',', '', $s);         // "1,250,000.00" → SA/US style
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',')) {
            // Only commas: treat as thousands unless it looks like a decimal (one comma, ≤2 trailing).
            $s = preg_match('/,\d{1,2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return null;
        }

        return (float) $s;
    }

    /** Is this a parseable, non-negative ZAR amount? (Empty is NOT valid here — callers guard optionality.) */
    public static function isValid(?string $value): bool
    {
        $parsed = self::parse($value);

        return $parsed !== null && $parsed >= 0.0;
    }

    /** VAT-exclusive → VAT-inclusive. */
    public static function withVat(float $exclusive, float $rate = self::VAT_RATE): float
    {
        return round($exclusive * (1 + $rate), 2);
    }

    /** The VAT portion of a VAT-inclusive amount. */
    public static function vatPortionOfInclusive(float $inclusive, float $rate = self::VAT_RATE): float
    {
        return round($inclusive - ($inclusive / (1 + $rate)), 2);
    }
}
