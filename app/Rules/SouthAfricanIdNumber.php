<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase A.2.5 — South African 13-digit ID number validator.
 *
 * Format reference (Home Affairs):
 *   positions 1-6   YYMMDD     date of birth
 *   positions 7-10  SSSS       4-digit gender sequence (0000-4999 = F, 5000-9999 = M)
 *   position  11    C          citizenship (0 = SA citizen, 1 = permanent resident)
 *   position  12    A          historical / always 8 since 1980s
 *   position  13    Z          Luhn-derived check digit
 *
 * The check digit uses a Luhn-modified algorithm: odd-position digits
 * are summed straight; even-position digits are concatenated, multiplied
 * by 2, and their digits summed. Final tens-complement gives the check.
 *
 * This validator accepts null/empty (the field is optional everywhere).
 * Non-null values are stripped of whitespace before checks.
 *
 * Public helpers (used by the controllers after validation):
 *   dateOfBirth() — Carbon-able 'YYYY-MM-DD' or null
 *   gender()      — 'F' | 'M' | null
 *
 * Both helpers operate on a value the caller has already validated; they
 * do NOT re-run the checksum. Use within validated() blocks.
 */
final class SouthAfricanIdNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') return;
        $id = preg_replace('/\s+/', '', (string) $value);

        if (!preg_match('/^\d{13}$/', $id)) {
            $fail('The :attribute must be exactly 13 digits.');
            return;
        }
        if (!self::dateValid($id)) {
            $fail('The :attribute does not contain a valid date of birth (YYMMDD).');
            return;
        }
        if (!self::luhnValid($id)) {
            $fail('The :attribute checksum is invalid.');
        }
    }

    /** Compute the date-of-birth from a 13-digit ID. Returns 'YYYY-MM-DD' or null. */
    public static function dateOfBirth(string $id): ?string
    {
        $id = preg_replace('/\s+/', '', $id);
        if (!preg_match('/^\d{13}$/', $id)) return null;
        $yy = (int) substr($id, 0, 2);
        $mm = (int) substr($id, 2, 2);
        $dd = (int) substr($id, 4, 2);
        // Century rollover heuristic: SA IDs issued from 1900s; ages > 110
        // are vanishingly rare in agency data, so treat YY > (current YY + 5)
        // as 1900s, else 2000s. Tweakable.
        $thisYear = (int) date('y');
        $century  = ($yy > $thisYear + 5) ? 1900 : 2000;
        $year = $century + $yy;
        if (!checkdate($mm, $dd, $year)) return null;
        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }

    /** Returns 'F' / 'M' / null. */
    public static function gender(string $id): ?string
    {
        $id = preg_replace('/\s+/', '', $id);
        if (!preg_match('/^\d{13}$/', $id)) return null;
        $sequence = (int) substr($id, 6, 4);
        return $sequence < 5000 ? 'F' : 'M';
    }

    private static function dateValid(string $id): bool
    {
        return self::dateOfBirth($id) !== null;
    }

    /**
     * Luhn-modified checksum used on SA IDs. Algorithm:
     *   - Take odd-position digits (1,3,5,7,9,11), sum them.
     *   - Take even-position digits (2,4,6,8,10,12), join into one number,
     *     multiply by 2, then sum the digits of the product.
     *   - Add both sums. The check digit (position 13) is the value that
     *     makes the total a multiple of 10.
     */
    private static function luhnValid(string $id): bool
    {
        $odd = 0;
        $even = '';
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $id[$i];
            if ($i % 2 === 0) {
                $odd += $d;
            } else {
                $even .= $d;
            }
        }
        $evenProduct = (string) ((int) $even * 2);
        $evenSum = 0;
        for ($i = 0, $n = strlen($evenProduct); $i < $n; $i++) {
            $evenSum += (int) $evenProduct[$i];
        }
        $total = $odd + $evenSum;
        $check = (10 - ($total % 10)) % 10;
        return $check === (int) $id[12];
    }
}
