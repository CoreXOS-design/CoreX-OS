<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\DataDictionary;

use App\Rules\PpraNumber;
use App\Rules\SouthAfricanIdNumber;
use App\Support\Money\Zar;

/**
 * AT-177 / WS0 — the typed validators of the CoreX real-estate Data Dictionary (spec §2.1).
 *
 * Validation lives on the dictionary ENTRY via its data_type, so the SAME rule fires at
 * compile, at fill, and at sign. Each case validates the FORMAT of a non-empty value and
 * returns a {@see ValidationResult}. Emptiness is treated as valid here (nothing to
 * format-check) — requiredness is a Field/linter concern, cross-field date ordering is
 * {@see DateOrdering}. Existing rules are REUSED, never duplicated:
 *   - sa_id   → App\Rules\SouthAfricanIdNumber (Luhn + DOB)
 *   - zar_money → App\Support\Money\Zar
 *   - ppra_no / ffc_no → App\Rules\PpraNumber
 */
enum DataType: string
{
    case ZarMoney = 'zar_money';
    case SaId = 'sa_id';
    case PpraNo = 'ppra_no';
    case FfcNo = 'ffc_no';
    case Date = 'date';
    case ErfNumber = 'erf_number';
    case TitleDeed = 'title_deed';
    case SchemeName = 'scheme_name';
    case UnitNo = 'unit_no';
    case Gps = 'gps';
    case FullName = 'full_name';
    case MaritalStatus = 'marital_status';
    case Text = 'text';

    /**
     * Validate the FORMAT of a value. Empty → valid (skip); requiredness is enforced by the
     * Field/linter. `$params` are the entry's `validation` overrides (may tighten only, L5).
     *
     * @param array<string,mixed> $params
     */
    public function validate(?string $value, array $params = []): ValidationResult
    {
        $raw = $value === null ? '' : trim($value);
        if ($raw === '') {
            return ValidationResult::valid(null);
        }

        return match ($this) {
            self::ZarMoney => $this->validateMoney($raw),
            self::SaId => SouthAfricanIdNumber::isValid($raw)
                ? ValidationResult::valid(preg_replace('/\s+/', '', $raw))
                : ValidationResult::invalid('Enter a valid 13-digit South African ID number.'),
            self::PpraNo, self::FfcNo => PpraNumber::isValid($raw)
                ? ValidationResult::valid($raw)
                : ValidationResult::invalid('Enter a valid PPRA/FFC reference (4–20 letters and digits).'),
            self::Date => $this->validateDate($raw, $params),
            self::ErfNumber => $this->validateCode($raw, 'erf number', 1, 30),
            self::TitleDeed => $this->validateCode($raw, 'title deed number', 3, 30),
            self::UnitNo => $this->validateCode($raw, 'unit number', 1, 12, requireDigit: false),
            self::Gps => $this->validateGps($raw),
            self::SchemeName => $this->validateText($raw, $params, 2, 200),
            self::FullName => $this->validateFullName($raw),
            self::MaritalStatus => $this->validateMaritalStatus($raw, $params),
            self::Text => $this->validateText($raw, $params, (int) ($params['min'] ?? 0), (int) ($params['max'] ?? 5000)),
        };
    }

    /** Format a stored value for display (used by the render layer later). */
    public function display(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return match ($this) {
            self::ZarMoney => Zar::format($value),
            default => trim($value),
        };
    }

    private function validateMoney(string $value): ValidationResult
    {
        $parsed = Zar::parse($value);
        if ($parsed === null) {
            return ValidationResult::invalid('Enter a valid Rand amount, e.g. R 1,250,000.');
        }
        if ($parsed < 0) {
            return ValidationResult::invalid('Amount cannot be negative.');
        }

        return ValidationResult::valid($parsed);
    }

    /** @param array<string,mixed> $params */
    private function validateDate(string $value, array $params): ValidationResult
    {
        $date = DateOrdering::parse($value);
        if ($date === null) {
            return ValidationResult::invalid('Enter a valid date.');
        }
        // Carbon SILENTLY ROLLS OVER impossible numeric dates (2026-02-30 → 2026-03-02).
        // Reject calendar-impossible numeric dates outright (BUILD_STANDARD §2).
        if (! self::numericCalendarDateIsReal($value)) {
            return ValidationResult::invalid('Enter a valid calendar date.');
        }
        if (isset($params['after_date']) && ! DateOrdering::strictlyBefore((string) $params['after_date'], $value)) {
            return ValidationResult::invalid('Date must be after ' . $params['after_date'] . '.');
        }
        if (isset($params['before_date']) && ! DateOrdering::strictlyBefore($value, (string) $params['before_date'])) {
            return ValidationResult::invalid('Date must be before ' . $params['before_date'] . '.');
        }

        return ValidationResult::valid($date->toDateString());
    }

    /**
     * For an explicit all-numeric date, is it a REAL calendar date (not a Carbon rollover)?
     * Non-numeric / worded dates ("5 July 2026") that Carbon already parsed are trusted.
     *   - ISO "Y-m-d" / "Y/m/d": checked month-first (unambiguous component order).
     *   - "a-b-YYYY": accepted if EITHER day-first (SA convention) OR month-first is real —
     *     never falsely reject a genuine date, only garbage like 30/30/2026.
     */
    private static function numericCalendarDateIsReal(string $value): bool
    {
        $v = trim($value);
        if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }
        if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])   // day-first
                || checkdate((int) $m[1], (int) $m[2], (int) $m[3]);  // month-first
        }

        return true; // worded / non-numeric — Carbon already validated it
    }

    private function validateCode(string $value, string $label, int $min, int $max, bool $requireDigit = true): ValidationResult
    {
        if (! preg_match('/^[A-Za-z0-9\/\-\. ]+$/', $value)) {
            return ValidationResult::invalid("Enter a valid {$label}.");
        }
        if ($requireDigit && ! preg_match('/\d/', $value)) {
            return ValidationResult::invalid("Enter a valid {$label}.");
        }
        $len = strlen(preg_replace('/[\/\-\. ]/', '', $value) ?? '');
        if ($len < $min || $len > $max) {
            return ValidationResult::invalid("Enter a valid {$label}.");
        }

        return ValidationResult::valid($value);
    }

    private function validateGps(string $value): ValidationResult
    {
        $parts = preg_split('/\s*,\s*/', $value) ?: [];
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return ValidationResult::invalid('Enter GPS as "lat, lng", e.g. -30.7256, 30.4547.');
        }
        $lat = (float) $parts[0];
        $lng = (float) $parts[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ValidationResult::invalid('GPS coordinates are out of range.');
        }

        return ValidationResult::valid($lat . ',' . $lng);
    }

    /** @param array<string,mixed> $params */
    private function validateText(string $value, array $params, int $min, int $max): ValidationResult
    {
        $len = mb_strlen($value);
        if ($len < $min) {
            return ValidationResult::invalid("Enter at least {$min} characters.");
        }
        if ($len > $max) {
            return ValidationResult::invalid("Enter at most {$max} characters.");
        }
        if (isset($params['regex']) && ! preg_match((string) $params['regex'], $value)) {
            return ValidationResult::invalid('The value is not in the required format.');
        }

        return ValidationResult::valid($value);
    }

    private function validateFullName(string $value): ValidationResult
    {
        // Letters (unicode), spaces, hyphens, apostrophes, dots. ≥2 chars. Permissive on purpose.
        if (mb_strlen($value) < 2 || ! preg_match('/^[\p{L}][\p{L}\s\'\.\-]*$/u', $value)) {
            return ValidationResult::invalid('Enter a valid full name.');
        }

        return ValidationResult::valid($value);
    }

    /** @param array<string,mixed> $params */
    private function validateMaritalStatus(string $value, array $params): ValidationResult
    {
        // If the entry declares an options list, enforce membership (case-insensitive);
        // otherwise accept any non-empty label (agency-overridable, avoids false rejects).
        $options = $params['options'] ?? null;
        if (is_array($options) && $options !== []) {
            $needle = mb_strtolower($value);
            foreach ($options as $opt) {
                if (mb_strtolower((string) $opt) === $needle) {
                    return ValidationResult::valid($value);
                }
            }

            return ValidationResult::invalid('Choose a valid marital status.');
        }

        return ValidationResult::valid($value);
    }
}
