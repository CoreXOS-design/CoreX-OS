<?php

namespace App\Services\RoadToSold\Reasons;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Road to Sold v3 - the line that leads every intake row and prospect card: WHY this property, with the numbers and the names
 * (road-to-sold.md 22.5, ruling R4). Pure wording, no database, no agency named anywhere.
 *
 * Every builder answers ['line' => the sentence shown, 'facts' => the structured numbers and names behind it]. The caller stores both on the list
 * item (and the prospect) at the moment the property is offered, so the reason is what it was WHEN OFFERED - it does not change when the
 * market does. Buyer names are first names only; the agency-buyer line also says which agent owns the buyer (Johan, 13 Oct).
 */
final class ReasonLine
{
    /** R120k, R1.2m, R950 - the short money the lines use. */
    public static function money(int|float $amount): string
    {
        $n = (int) round($amount);
        if ($n >= 1_000_000) {
            return 'R' . rtrim(rtrim(number_format($n / 1_000_000, 2, '.', ''), '0'), '.') . 'm';
        }
        if ($n >= 1_000) {
            return 'R' . number_format((int) round($n / 1_000), 0, '.', '') . 'k';
        }

        return 'R' . $n;
    }

    /** "Piet, Sanet, Jo" or "Piet, Sanet, Jo and 2 more". @param string[] $names */
    public static function names(array $names, int $show = 3): string
    {
        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== ''));
        $shown = array_slice($names, 0, $show);
        $more = count($names) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '');
    }

    /** "4 days", "1 day", "today". */
    public static function days(int $days): string
    {
        return $days <= 0 ? 'today' : $days . ' ' . ($days === 1 ? 'day' : 'days');
    }

    public static function first(?string $name): string
    {
        return trim(explode(' ', trim((string) $name))[0]);
    }

    /**
     * "3 of your buyers match: Piet, Sanet, Jo".
     *
     * @param  array<int,array{id:int,name:string,score?:?int}> $buyers
     */
    public static function myBuyers(array $buyers): array
    {
        $n = count($buyers);
        $names = array_map(fn ($b) => self::first($b['name']), $buyers);
        $line = $n === 1 ? '1 of your buyers matches: ' . self::names($names) : "{$n} of your buyers match: " . self::names($names);

        return ['line' => $line, 'facts' => ['kind' => 'my_buyers', 'count' => $n, 'buyers' => array_slice($buyers, 0, 10), 'best_match' => max(array_map(fn ($b) => (int) ($b['score'] ?? 0), $buyers) ?: [0])]];
    }

    /**
     * "Agency has 2 buyers (Piet · agent Anna, Sanet · agent Jo)".
     *
     * @param  array<int,array{id:int,name:string,agent:?string,score?:?int}> $buyers
     */
    public static function agencyBuyers(array $buyers): array
    {
        $n = count($buyers);
        $each = array_map(fn ($b) => self::first($b['name']) . (! empty($b['agent']) ? ' · agent ' . self::first($b['agent']) : ''), $buyers);

        return [
            'line' => 'Agency has ' . $n . ' ' . ($n === 1 ? 'buyer' : 'buyers') . ' (' . self::names($each) . ')',
            'facts' => ['kind' => 'agency_buyers', 'count' => $n, 'buyers' => array_slice($buyers, 0, 10), 'best_match' => max(array_map(fn ($b) => (int) ($b['score'] ?? 0), $buyers) ?: [0])],
        ];
    }

    /** "Hot pocket: 12 sales / 180 days" (buyers and leads join the line when the pocket has them). @param array{buyers?:int,leads?:int,sales?:int,label?:?string,name?:?string} $pocket */
    public static function hotPocket(array $pocket, int $windowDays): array
    {
        $parts = [];
        foreach (['sales' => 'sale', 'leads' => 'lead', 'buyers' => 'buyer'] as $k => $word) {
            $v = (int) ($pocket[$k] ?? 0);
            if ($v > 0) {
                $parts[] = $v . ' ' . $word . ($v === 1 ? '' : 's');
            }
        }

        return [
            'line' => 'Hot pocket: ' . ($parts ? implode(', ', $parts) : 'busy') . ' / ' . $windowDays . ' days',
            'facts' => ['kind' => 'hot_pocket', 'sales' => (int) ($pocket['sales'] ?? 0), 'leads' => (int) ($pocket['leads'] ?? 0), 'buyers' => (int) ($pocket['buyers'] ?? 0),
                'window_days' => $windowDays, 'area' => $pocket['label'] ?? $pocket['name'] ?? null],
        ];
    }

    /** "Price drop R120k on 3 Oct". */
    public static function priceDrop(int $old, int $new, CarbonInterface|string $on): array
    {
        $on = Carbon::parse($on);

        return [
            'line' => 'Price drop ' . self::money(max(0, $old - $new)) . ' on ' . $on->format('j M'),
            'facts' => ['kind' => 'price_drop', 'old' => $old, 'new' => $new, 'amount' => max(0, $old - $new), 'pct' => $old > 0 ? round(($old - $new) * 100 / $old, 1) : null, 'on' => $on->toDateString()],
        ];
    }

    /** "New on market 4 days" ("first seen" when the portal gave no listed date). */
    public static function newOnMarket(int $days, bool $dateKnown, ?string $portal = null): array
    {
        return [
            'line' => 'New on market ' . self::days($days) . ($dateKnown ? '' : ' (first seen by us)'),
            'facts' => ['kind' => 'new_on_market', 'days' => $days, 'listed_date_known' => $dateKnown, 'portal' => $portal],
        ];
    }

    /** "Long on market 112 days". */
    public static function longOnMarket(int $days, bool $dateKnown, ?int $price = null): array
    {
        return [
            'line' => 'Long on market ' . ($dateKnown ? '' : 'at least ') . self::days($days) . ($dateKnown ? '' : ' (first seen by us)'),
            'facts' => ['kind' => 'long_on_market', 'days' => $days, 'listed_date_known' => $dateKnown, 'price' => $price],
        ];
    }

    /** "Owner on file: Piet Botha, last called 12 Sep". */
    public static function ownerKnown(?string $owner, ?CarbonInterface $lastCalled = null): array
    {
        $line = $owner ? 'Owner on file: ' . $owner : 'Owner on file';
        if ($lastCalled) {
            $line .= ', last called ' . $lastCalled->format('j M');
        }

        return ['line' => $line, 'facts' => ['kind' => 'owner_known', 'owner' => $owner, 'last_called' => $lastCalled?->toDateString()]];
    }

    /** "Your mandate expired 12 Oct". */
    public static function myExpired(?CarbonInterface $on, bool $stillLive = false): array
    {
        return [
            'line' => ($on ? 'Your mandate expired ' . $on->format('j M') : 'Your mandate has expired') . ($stillLive ? ' (still showing as live)' : ''),
            'facts' => ['kind' => 'my_expired', 'expired_on' => $on?->toDateString(), 'still_live' => $stillLive],
        ];
    }

    /** "Your listing: 61 days on market, 14 views, 0 enquiries in 30 days". */
    public static function notSelling(int $days, int $views, int $leads, int $windowDays): array
    {
        return [
            'line' => "Your listing: {$days} days on market, {$views} " . ($views === 1 ? 'view' : 'views') . ", {$leads} " . ($leads === 1 ? 'enquiry' : 'enquiries') . " in {$windowDays} days",
            'facts' => ['kind' => 'not_selling', 'days' => $days, 'views' => $views, 'leads' => $leads, 'window_days' => $windowDays],
        ];
    }

    /** "Expired with Pam Golding 12 Oct" (the portal's name when the agency is not known). */
    public static function expiredCompetitor(?string $agency, string $portalLabel, CarbonInterface|string $on): array
    {
        $on = Carbon::parse($on);
        $with = trim((string) $agency) !== '' ? 'with ' . trim((string) $agency) : 'on ' . $portalLabel;

        return [
            'line' => "Expired {$with} " . $on->format('j M'),
            'facts' => ['kind' => 'expired_competitor', 'agency' => trim((string) $agency) ?: null, 'portal' => $portalLabel, 'withdrawn_on' => $on->toDateString()],
        ];
    }

    /** "You found this on 12 Oct (Drive an Area)". */
    public static function foundByYou(CarbonInterface|string|null $on, ?string $task): array
    {
        $on = $on ? Carbon::parse($on) : null;

        return [
            'line' => 'You found this' . ($on ? ' on ' . $on->format('j M') : '') . ($task ? " ({$task})" : ''),
            'facts' => ['kind' => 'found_by_you', 'found_on' => $on?->toDateString(), 'task' => $task],
        ];
    }
}
