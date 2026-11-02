<?php

namespace App\Services\Prospecting\MicWork;

/**
 * Wording and colours for "Buyer wants vs property has" (mic-work-tab.md 13.18), read from the row's `buyer_fit` as the match engine gave it:
 *   buyer_fit = { first_name, score, tier, fields: [{key, label, wants, has, state}], must_haves: [{label, state}], deal_breakers: [{label, state}] }
 *   state: ok | miss | unknown | na   (a deal-breaker is ok when the property does NOT have it, miss when it does)
 * Nothing here judges a fit; it only counts and words the states it is given.
 */
final class BuyerFitLabel
{
    // text colours that keep 4.5:1 on the card's grey surface (measured, 1 Nov contrast pass)
    public const COLORS = ['ok' => '#166534', 'miss' => '#b91c1c', 'unknown' => '#92400e', 'na' => 'var(--text-secondary)'];

    public const MARKS = ['ok' => '✓', 'miss' => '✗', 'unknown' => '?', 'na' => ''];

    public static function state(?string $s): string
    {
        return isset(self::COLORS[$s]) ? $s : 'na';
    }

    public static function color(?string $s): string
    {
        return self::COLORS[self::state($s)];
    }

    public static function mark(?string $s): string
    {
        return self::MARKS[self::state($s)];
    }

    /** @return array<string, mixed>|null the field of that key */
    public static function field(array $fit, string $key): ?array
    {
        foreach ($fit['fields'] ?? [] as $f) {
            if (($f['key'] ?? null) === $key) {
                return $f;
            }
        }

        return null;
    }

    /** the small chip after the price / the type: "≤ R1.0m ✓"; null when the engine gave no such field or it does not apply */
    public static function chip(array $fit, string $key): ?array
    {
        $f = self::field($fit, $key);
        if (! $f || self::state($f['state'] ?? null) === 'na' || trim((string) ($f['wants'] ?? '')) === '') {
            return null;
        }
        $st = self::state($f['state']);

        return ['text' => trim($f['wants'] . ' ' . self::mark($st)), 'color' => self::color($st), 'title' => self::fieldTitle($fit, $f)];
    }

    /** "Alex wants 3 bedrooms or more - this has 2" style hover text, from the parts the row gives */
    public static function fieldTitle(array $fit, array $f): string
    {
        $bits = [];
        if (trim((string) ($f['wants'] ?? '')) !== '') {
            $bits[] = ($fit['first_name'] ?? 'The buyer') . ' wants ' . $f['wants'];
        }
        if (trim((string) ($f['has'] ?? '')) !== '') {
            $bits[] = 'this has ' . $f['has'];
        }

        return ($f['label'] ?? '') . ($bits ? ': ' . implode(' - ', $bits) : '');
    }

    /**
     * The badge in the right panel: "Fits Alex · 5 of 7 · check pool, pets".
     * Counted over the fields, must-haves and deal-breakers that apply (state not na); "check" lists the unknown ones, "misses" the ones that do not fit.
     *
     * @return array{text: string, tone: string, ok: int, total: int}
     */
    public static function summary(array $fit): array
    {
        $items = [];
        foreach (['fields', 'must_haves', 'deal_breakers'] as $group) {
            foreach ($fit[$group] ?? [] as $it) {
                if (self::state($it['state'] ?? null) !== 'na') {
                    $items[] = $it;
                }
            }
        }
        $by = fn (string $st) => array_values(array_filter($items, fn ($it) => self::state($it['state'] ?? null) === $st));
        $labels = fn (array $list) => implode(', ', array_slice(array_map(fn ($it) => mb_strtolower((string) ($it['label'] ?? '')), $list), 0, 3));
        $ok = count($by('ok'));
        $miss = $by('miss');
        $unknown = $by('unknown');
        $text = 'Fits ' . ($fit['first_name'] ?? 'buyer') . ' · ' . $ok . ' of ' . count($items)
            . ($miss ? ' · misses ' . $labels($miss) : '')
            . ($unknown ? ' · check ' . $labels($unknown) : '');

        return ['text' => $text, 'tone' => $miss ? 'miss' : ($unknown ? 'unknown' : 'ok'), 'ok' => $ok, 'total' => count($items)];
    }
}
