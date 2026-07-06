<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\DataDictionary;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * AT-177 / WS0 — cross-field date ordering (spec §2.1 "occupation ≥ transfer").
 *
 * Single-field date VALIDITY lives in {@see DataType} (is this a parseable date). ORDERING
 * between two fields is a separate concern the linter (L5) and fill-time validation call
 * once both values are known — it cannot be judged from one field alone.
 *
 * Null-safe by design: if either side is missing/unparseable, ordering is treated as NOT
 * violated (there is nothing to compare yet) — requiredness is enforced elsewhere.
 */
final class DateOrdering
{
    /** Does `earlier` fall on or before `later`? (Non-strict.) */
    public static function holds(?string $earlier, ?string $later): bool
    {
        $a = self::parse($earlier);
        $b = self::parse($later);
        if ($a === null || $b === null) {
            return true;
        }

        return $a->lessThanOrEqualTo($b);
    }

    /** Does `earlier` fall strictly before `later`? */
    public static function strictlyBefore(?string $earlier, ?string $later): bool
    {
        $a = self::parse($earlier);
        $b = self::parse($later);
        if ($a === null || $b === null) {
            return true;
        }

        return $a->lessThan($b);
    }

    public static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
