<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AT-177 — structural validator for practitioner reference codes: PPRA registration
 * numbers and Fidelity Fund Certificate (FFC) numbers.
 *
 * PPRA/FFC numbers have no single published check-digit scheme, so this is a deliberate
 * STRUCTURAL sanity check, NOT a registry lookup: it rejects obvious garbage (too short,
 * no digits, stray symbols) without falsely rejecting real numbers (BUILD_STANDARD warns
 * against over-strict format rejects). Real numbers are alphanumeric, may carry "/", "-",
 * or spaces as separators, contain at least one digit, and run 4–20 chars.
 *
 * Accepts null/empty (the field is optional; callers guard requiredness separately).
 */
final class PpraNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! self::isValid((string) $value)) {
            $fail('The :attribute is not a valid PPRA/FFC reference (expect 4–20 letters and digits).');
        }
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $s = trim($value);
        if ($s === '') {
            return false;
        }

        // Only letters, digits, and the common separators / - space.
        if (! preg_match('/^[A-Za-z0-9\/\- ]+$/', $s)) {
            return false;
        }

        // Must contain at least one digit.
        if (! preg_match('/\d/', $s)) {
            return false;
        }

        // Length of the significant (separator-stripped) code: 4–20.
        $core = preg_replace('/[\/\- ]/', '', $s) ?? '';

        return strlen($core) >= 4 && strlen($core) <= 20;
    }
}
