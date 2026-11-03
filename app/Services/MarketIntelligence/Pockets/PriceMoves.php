<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Pockets;

use Carbon\Carbon;

/**
 * The real price moves of ONE advert, from its raw price history (AT-519 5.10). Portal captures wobble: a price that goes up and comes back
 * down within a few days (or the other way round) is a capture flip, not a seller decision. So an opposite-direction pair that returns to
 * (about) the earlier level within `$windowDays` days is noise and both rows are dropped; what remains is the net movement.
 * Rows with a NULL / 0 price are glitches and are ignored.
 */
final class PriceMoves
{
    /**
     * @param  iterable<object{old_price:mixed,new_price:mixed,changed_at:mixed}>  $rows  one advert's history, any order
     * @return array{rows: list<array{old:int,new:int,at:string}>, cuts:int, amount:int, last_cut_at:?string, first_price:?int, cuts_30d:int, cuts_90d:int}
     */
    public static function net(iterable $rows, ?Carbon $now = null, int $windowDays = 7): array
    {
        $now ??= Carbon::now();
        $valid = [];
        foreach ($rows as $r) {
            $o = (int) $r->old_price;
            $n = (int) $r->new_price;
            if ($o > 0 && $n > 0 && $o !== $n) {
                $valid[] = ['old' => $o, 'new' => $n, 'at' => (string) $r->changed_at, 'ts' => Carbon::parse($r->changed_at)->getTimestamp()];
            }
        }
        usort($valid, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        $kept = [];
        foreach ($valid as $r) {
            $last = end($kept);
            if ($last !== false
                && (($r['new'] > $r['old']) !== ($last['new'] > $last['old']))                 // opposite direction
                && ($r['ts'] - $last['ts']) <= $windowDays * 86400                              // within the window
                && abs($r['new'] - $last['old']) <= 0.02 * $last['old']) {                      // and back to (about) where it was
                array_pop($kept);      // the pair cancels out
                continue;
            }
            $kept[] = $r;
        }

        $cuts = $amount = $c30 = $c90 = 0;
        $lastCut = null;
        foreach ($kept as $r) {
            if ($r['new'] < $r['old']) {
                $cuts++;
                $amount += $r['old'] - $r['new'];
                $lastCut = $r['at'];
                if ($r['ts'] >= $now->copy()->subDays(30)->getTimestamp()) {
                    $c30++;
                }
                if ($r['ts'] >= $now->copy()->subDays(90)->getTimestamp()) {
                    $c90++;
                }
            }
        }

        return [
            'rows' => array_map(fn ($r) => ['old' => $r['old'], 'new' => $r['new'], 'at' => $r['at']], $kept),
            'cuts' => $cuts, 'amount' => $amount, 'last_cut_at' => $lastCut,
            'first_price' => $kept !== [] ? $kept[0]['old'] : null, 'cuts_30d' => $c30, 'cuts_90d' => $c90,
        ];
    }
}
