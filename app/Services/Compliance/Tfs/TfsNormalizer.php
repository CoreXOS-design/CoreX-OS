<?php

namespace App\Services\Compliance\Tfs;

/**
 * Normalisation used identically at ingest time and at screen time — the two MUST
 * agree or a real match slips through. Deliberately aggressive on names (favour a
 * flag over a miss) and strict on identifiers (exact after stripping noise).
 */
class TfsNormalizer
{
    /** Names/aliases: uppercase, strip diacritics + punctuation, collapse whitespace. */
    public static function name(?string $value): string
    {
        $value = (string) $value;
        // transliterate accented chars to ASCII (é -> e), drop anything untranslatable
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }
        $value = strtoupper($value);
        $value = preg_replace('/[^A-Z0-9 ]+/', ' ', $value); // punctuation -> space
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    /** Identifiers (ID/passport): uppercase, strip everything but alphanumerics. */
    public static function identifier(?string $value): string
    {
        $value = strtoupper((string) $value);
        return preg_replace('/[^A-Z0-9]+/', '', $value);
    }

    /** Significant name tokens (drops 1-char noise) for token-containment matching. */
    public static function tokens(?string $value): array
    {
        $tokens = array_filter(
            explode(' ', self::name($value)),
            fn ($t) => strlen($t) >= 2
        );
        return array_values(array_unique($tokens));
    }

    /**
     * Parse the FIC comma-joined document string into typed identifiers, e.g.
     *   "National Identification Number, 19670704052, Passport, 420985453, Passport, TB162181"
     * -> [ [national_id,19670704052], [passport,420985453], [passport,TB162181] ]
     * Values may contain spaces (e.g. "Jordan 286062") but not commas.
     */
    public static function parseDocuments(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        $i = 0;
        $labels = [
            'passport'                        => 'passport',
            'national identification number'  => 'national_id',
            'national id'                     => 'national_id',
            'identification number'           => 'national_id',
            'id number'                       => 'national_id',
        ];
        while ($i < count($parts)) {
            $label = strtolower($parts[$i]);
            $type = $labels[$label] ?? null;
            if ($type !== null && isset($parts[$i + 1]) && $parts[$i + 1] !== '') {
                $out[] = ['type' => $type, 'value' => $parts[$i + 1]];
                $i += 2;
            } else {
                // Unlabelled token — treat as an "other" identifier value, don't lose it.
                if ($parts[$i] !== '') {
                    $out[] = ['type' => 'other', 'value' => $parts[$i]];
                }
                $i += 1;
            }
        }
        return $out;
    }

    /**
     * A FIC alias element packs many aliases with UN "quality" markers, e.g.
     *   "Good, Abd al-Rahman, Good, Abu Anas, Low, Abou Wafa"
     *   "a.k.a., al Mansooreen, a.k.a., Army of the Pure"
     * Split on the markers and return the individual alias names (markers dropped).
     * If no marker is present, the whole element is one alias.
     */
    public static function parseAliases(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        // split at each "<marker>," delimiter (Good/Low/High/a.k.a./f.k.a./n.k.a./aka)
        $parts = preg_split(
            '/\s*,?\s*(?:good|low|high|a\.?k\.?a\.?|f\.?k\.?a\.?|n\.?k\.?a\.?)\s*,\s*/i',
            $raw
        );
        $aliases = [];
        foreach ($parts as $p) {
            $p = trim($p, " ,\t\n");
            if ($p !== '') {
                $aliases[] = $p;
            }
        }
        // No marker matched -> treat the whole string as a single alias.
        return $aliases ?: [$raw];
    }

    /** Hard cap a value to a column length (defensive — no real name exceeds this). */
    public static function cap(string $value, int $len): string
    {
        return mb_substr($value, 0, $len);
    }

    /** Parse a date that may be ISO (YYYY-MM-DD) or DD-MM-YYYY. Returns Y-m-d or null. */
    public static function parseDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null; // unknown format (e.g. year-only) — keep raw, don't guess
    }
}
