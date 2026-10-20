<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

/**
 * Layout fingerprint of a market report: the handful of phrases that identify a
 * publisher's layout (its name/signature, its table column headings, its fixed
 * section headings), compared against a PDF's text.
 *
 * Phrases are normalised — lower-case, every run of digits becomes '#', everything
 * that is not a letter or '#' collapses to one space — so a heading still matches
 * when the page number, the date or the spacing differs. Spec: mic-complete-spec §8.9.
 */
final class LayoutFingerprint
{
    public const MIN_ANCHORS = 3;
    public const MAX_ANCHORS = 12;

    public static function normalise(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\d+/u', '#', $s) ?? $s;
        $s = preg_replace('/[^\p{L}#]+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** @param array<int, string> $anchors already-normalised phrases */
    public static function hash(array $anchors): string
    {
        $sorted = array_values(array_unique($anchors));
        sort($sorted);

        return hash('sha256', implode('|', $sorted));
    }

    /**
     * Share (0..1) of the anchors found in the text. 1.0 = every phrase present,
     * which is an exact fingerprint match.
     *
     * @param array<int, string> $anchors
     */
    public static function presentShare(array $anchors, string $text): float
    {
        if ($anchors === []) {
            return 0.0;
        }
        $haystack = ' ' . self::normalise($text) . ' ';
        $found = 0;
        foreach ($anchors as $anchor) {
            if ($anchor !== '' && str_contains($haystack, ' ' . $anchor . ' ')) {
                $found++;
            }
        }

        return $found / count($anchors);
    }

    /**
     * Pick the anchors for a new layout from the AI's own hints, keeping only phrases
     * that really are in the PDF's text and are specific enough to mean something.
     * Returns [] when the hints are too weak to identify a layout safely.
     *
     * @param  array<string, mixed> $hints  the AI's `layout` block
     * @return array<int, string> normalised anchors
     */
    public static function anchorsFromHints(array $hints, string $text): array
    {
        $raw = [];
        $signature = $hints['publisher_signature'] ?? null;
        if (is_string($signature)) {
            $raw[] = $signature;
        }
        foreach (['column_headings', 'header_phrases'] as $key) {
            foreach ((array) ($hints[$key] ?? []) as $phrase) {
                if (is_string($phrase)) {
                    $raw[] = $phrase;
                }
            }
        }

        $haystack = ' ' . self::normalise($text) . ' ';
        $anchors = [];
        foreach ($raw as $phrase) {
            $n = self::normalise($phrase);
            $letters = preg_replace('/[^\p{L}]+/u', '', $n) ?? '';
            if (mb_strlen($letters) < 4) {
                continue; // too short to identify anything ("no", "r/m", …)
            }
            if (substr_count($n, '#') > 1) {
                continue; // numeric-heavy: a date, an amount or an address, not a heading
            }
            if (!str_contains($haystack, ' ' . $n . ' ')) {
                continue; // the AI named it but the PDF text does not carry it
            }
            $anchors[$n] = $n;
        }

        $anchors = array_values($anchors);
        if (count($anchors) < self::MIN_ANCHORS) {
            return [];
        }

        return array_slice($anchors, 0, self::MAX_ANCHORS);
    }
}
