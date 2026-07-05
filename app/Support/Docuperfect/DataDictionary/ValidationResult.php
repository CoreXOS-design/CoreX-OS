<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\DataDictionary;

/**
 * AT-177 / WS0 — the outcome of validating a value against a Data Dictionary entry.
 * `normalised` carries the canonicalised value (e.g. parsed float, trimmed string) when
 * the caller wants to store the cleaned form.
 */
final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $message = null,
        public readonly mixed $normalised = null,
    ) {
    }

    public static function valid(mixed $normalised = null): self
    {
        return new self(true, null, $normalised);
    }

    public static function invalid(string $message): self
    {
        return new self(false, $message);
    }
}
