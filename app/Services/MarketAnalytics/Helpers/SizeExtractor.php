<?php

namespace App\Services\MarketAnalytics\Helpers;

/**
 * Extracts a property size (m²) from a raw JSON payload field.
 *
 * Tries recognised size keys in order of preference.
 * Strict: numeric values only, clamped to 10–5000 m².
 * Returns null cleanly on missing data, invalid JSON, or out-of-range values.
 */
class SizeExtractor
{
    /**
     * Keys tried in preference order.
     * Compared case-insensitively after spaces → underscore normalisation.
     */
    private const SIZE_KEYS = [
        'floor_area',
        'size',
        'm2',
        'sqm',
        'erf_size',
        'floor_size',
        'area',
    ];

    public const MIN_M2 = 10.0;
    public const MAX_M2 = 5000.0;

    /**
     * Parse a JSON payload string and return a size in m², or null.
     *
     * @param  string|null $payload  Raw JSON text (e.g. listing_import_rows.row_payload)
     */
    public static function fromPayload(?string $payload): ?float
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return null;
        }

        // Normalise keys: lowercase + spaces → underscores
        $normalised = [];
        foreach ($data as $k => $v) {
            $normalised[str_replace(' ', '_', mb_strtolower((string) $k))] = $v;
        }

        foreach (self::SIZE_KEYS as $key) {
            if (!array_key_exists($key, $normalised)) {
                continue;
            }

            $raw = $normalised[$key];

            if (!is_numeric($raw)) {
                continue;
            }

            $value = (float) $raw;

            if ($value >= self::MIN_M2 && $value <= self::MAX_M2) {
                return $value;
            }
        }

        return null;
    }
}
