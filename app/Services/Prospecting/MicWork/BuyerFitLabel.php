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

    /** the one small tick after the price / the type: ✓ ok, ✗ miss, ? unknown; null when the engine gave no such field or the wishlist does not state it */
    public static function tick(array $fit, string $key): ?array
    {
        $f = self::field($fit, $key);
        if (! $f || self::state($f['state'] ?? null) === 'na') {
            return null;
        }
        $st = self::state($f['state']);

        return ['mark' => self::mark($st), 'color' => self::color($st), 'title' => self::fieldTitle($fit, $f)];
    }

    /** the colour of a fact the buyer stated (beds, baths ...) so the facts line itself shows the verdict; null when not stated */
    public static function factColor(array $fit, string $key): ?string
    {
        $f = self::field($fit, $key);

        return $f && self::state($f['state'] ?? null) !== 'na' ? self::color($f['state']) : null;
    }

    /**
     * The items of the "Jan wants" line: only what the wishlist states (state not na, something to say), in the order beds, baths, garages, parking, price, type,
     * suburb, erf, floor, then the must-haves and deal-breakers. Each: text (with its tick), state, colour, hover title.
     *
     * @return list<array{key: string, text: string, state: string, color: string, title: string}>
     */
    public static function wantsItems(array $fit): array
    {
        $out = [];
        foreach (['beds', 'baths', 'garages', 'parking', 'price', 'type', 'suburb', 'erf', 'floor'] as $key) {
            $f = self::field($fit, $key);
            $wants = trim((string) ($f['wants'] ?? ''));
            if (! $f || self::state($f['state'] ?? null) === 'na' || $wants === '') {
                continue;
            }
            $text = match ($key) {
                'beds' => $wants . ' beds', 'baths' => $wants . ' baths', 'garages' => $wants . ' garages', 'parking' => $wants . ' parking',
                'erf' => 'erf ' . $wants, 'floor' => 'floor ' . $wants,
                'type' => str_replace(' · ', ' or ', $wants),
                default => $wants,
            };
            $st = self::state($f['state']);
            $out[] = ['key' => $key, 'text' => trim($text . ' ' . self::mark($st)), 'state' => $st, 'color' => self::color($st), 'title' => self::fieldTitle($fit, $f)];
        }
        foreach (['must_haves' => '', 'deal_breakers' => 'no '] as $group => $prefix) {
            foreach ($fit[$group] ?? [] as $it) {
                $st = self::state($it['state'] ?? null);
                if ($st === 'na' || trim((string) ($it['label'] ?? '')) === '') {
                    continue;
                }
                $out[] = ['key' => $group, 'text' => trim($prefix . $it['label'] . ' ' . self::mark($st)), 'state' => $st, 'color' => self::color($st), 'title' => ($group === 'must_haves' ? 'Must-have: ' : 'Deal-breaker: ') . $it['label']];
            }
        }

        return $out;
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
     * The chip on the buyer row: "Jan · Fits 4 of 4 ✓ · check pets". Counted over the DECIDED states (ok + miss) of the fields, must-haves and deal-breakers that apply;
     * "check" lists the unknown ones, "misses" the ones that do not fit (two names, then "+N"; the popover lists every one).
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
        $labels = fn (array $list) => implode(', ', array_slice(array_map(fn ($it) => mb_strtolower((string) ($it['label'] ?? '')), $list), 0, 2)) . (count($list) > 2 ? ' +' . (count($list) - 2) : '');
        $ok = count($by('ok'));
        $miss = $by('miss');
        $unknown = $by('unknown');
        $total = $ok + count($miss);
        $parts = [];
        if ($total > 0) {
            $parts[] = 'Fits ' . $ok . ' of ' . $total . (! $miss ? ' ✓' : '');
        }
        if ($miss) {
            $parts[] = 'misses ' . $labels($miss);
        }
        if ($unknown) {
            $parts[] = 'check ' . $labels($unknown);
        }

        return ['text' => implode(' · ', array_merge([$fit['first_name'] ?? 'Buyer'], $parts)), 'tone' => $miss ? 'miss' : ($unknown ? 'unknown' : 'ok'), 'ok' => $ok, 'total' => $total];
    }
}
