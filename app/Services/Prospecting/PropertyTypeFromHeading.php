<?php

namespace App\Services\Prospecting;

use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;

/**
 * The property type a portal advert's own heading names ("2 Bedroom Townhouse" -> Townhouse), by WHOLE WORD, leftmost first and the longest word at
 * the same place first - so "Townhouse" is never read as "house" (the extension's old substring match did, and stored 640 Townhouses as House).
 * Used by the ingest (an old extension cannot re-break it) and by the journalled fix command. mic-work-tab.md 13.19.
 */
final class PropertyTypeFromHeading
{
    /** longest first; the label is what the extension stores (first letter capitalised) */
    private const WORDS = [
        'garden cottage', 'granny flat', 'vacant land', 'smallholding', 'agricultural', 'townhouse', 'apartment', 'penthouse', 'commercial', 'industrial',
        'duplex', 'simplex', 'cluster', 'cottage', 'studio', 'office', 'retail', 'house', 'flat', 'farm', 'land',
    ];

    public static function derive(?string $heading): ?string
    {
        $h = mb_strtolower(trim((string) $heading));
        if ($h === '') {
            return null;
        }
        $best = null; // [position, -length, word]
        foreach (self::WORDS as $w) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $h, $m, PREG_OFFSET_CAPTURE) === 1) {
                $cand = [$m[0][1], -strlen($w), $w];
                if ($best === null || $cand < $best) {
                    $best = $cand;
                }
            }
        }

        return $best ? ucfirst($best[2]) : null;
    }

    /**
     * The type to store: the heading's, when it names a type the stored one contradicts (they map to different canonical types) or the stored one is blank;
     * otherwise the stored one unchanged ("Apartment" stored, "Flat" in the heading: the same canonical type, left alone).
     */
    public static function reconcile(?string $stored, ?string $heading, ?int $agencyId = null): ?string
    {
        $derived = self::derive($heading);
        if ($derived === null) {
            return $stored;
        }
        if (trim((string) $stored) === '') {
            return $derived;
        }
        $types = app(PropertyTypeResolver::class);
        $dk = $types->keyFor($derived, $agencyId);
        $sk = $types->keyFor($stored, $agencyId);
        if ($dk === null || $dk === 'other') {
            return $stored; // a word the engine has no canonical type for (cluster, studio...): never overrides
        }

        return $sk !== $dk ? $derived : $stored;
    }
}
