<?php

declare(strict_types=1);

namespace App\Support\Presentations;

use App\Models\Property;

/**
 * Single source of truth for "is this SUBJECT field meaningfully set" —
 * shared between CompetitorStockMatchService (the comparable-stock cascade
 * + pre-generate warning) and CmaCoverageService (the Strong/Moderate/Thin
 * data badge). Born from a real incident (Johan, 2026-08-20): the badge
 * said "Strong data" on a property the presentation warning correctly
 * flagged as missing bedrooms/bathrooms/price — two independently-correct
 * pieces of logic disagreeing because each answered a different question
 * about the same fields. This class exists so there is exactly ONE
 * function either surface can call — not a matching rule reimplemented
 * twice, which is how that contradiction was born in the first place.
 *
 * beds/baths/garages/price on `properties` are NOT NULL DEFAULT 0 — the
 * schema cannot distinguish "genuinely zero" from "never entered", so 0 is
 * treated as absent for these fields specifically. Matching-layer decision
 * only; a nullable-column migration that would make this exact is a
 * separate, later call of Johan's.
 */
final class SubjectFieldCompleteness
{
    /**
     * @return string[] subset of ['bedrooms', 'bathrooms', 'price']
     */
    public static function missingSoftInputs(Property $subject): array
    {
        $missing = [];
        if (!self::isSet($subject->beds)) $missing[] = 'bedrooms';
        if (!self::isSet($subject->baths)) $missing[] = 'bathrooms';
        if (!self::isSet((int) ($subject->price ?? 0))) $missing[] = 'price';
        return $missing;
    }

    public static function isSet(int|string|float|null $value): bool
    {
        // baths is cast decimal:1 on the Property model — Laravel's decimal
        // cast returns a STRING ("0.0"), not a native number. beds/garages
        // come through as native ints from their tinyint columns. Accept
        // all three shapes and compare numerically so this one helper
        // covers every soft field without per-field special-casing.
        return $value !== null && (float) $value > 0;
    }

    /**
     * "a, b and c" / "a and b" / "a" — shared so the pre-generate warning
     * and the coverage badge's merged sentence never drift into two
     * different grammars for the same list.
     *
     * @param  string[]  $items
     */
    public static function joinNames(array $items): string
    {
        if (count($items) === 0) return '';
        if (count($items) === 1) return $items[0];
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
