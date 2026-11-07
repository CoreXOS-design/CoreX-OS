<?php

namespace App\Support;

/** Picks readable text for a coloured background (WCAG contrast) - the public auction site takes its button colour from the agency, whatever it is. */
final class ColorContrast
{
    /** '#ffffff' or a near-black, whichever reads better on $hex (#rgb or #rrggbb). Anything unparseable gets white. */
    public static function textOn(?string $hex, string $dark = '#101828'): string
    {
        $l = self::luminance($hex);

        return $l === null ? '#ffffff' : (self::ratio($l, 1.0) >= self::ratio($l, self::luminance($dark) ?? 0.0) ? '#ffffff' : $dark);
    }

    private static function ratio(float $a, float $b): float
    {
        [$hi, $lo] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(?string $hex): ?float
    {
        $h = ltrim(trim((string) $hex), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $h)) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
            return null;
        }
        $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
