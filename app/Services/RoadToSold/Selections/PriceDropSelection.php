<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Selection 4 — price drops. A listing whose MOST RECENT price change inside the look-back window was
 * a drop (prospecting_price_history, new < old). Most recent first, then the biggest drop.
 */
final class PriceDropSelection extends MicPoolSelection
{
    public function key(): string
    {
        return 'price_drops';
    }

    public function label(): string
    {
        return 'Price drops';
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $since = Carbon::instance($ctx->date)->startOfDay()->subDays(max(1, $ctx->settings->priceDropLookbackDays))->toDateTimeString();

        $q->join('prospecting_price_history as ph', function ($j) use ($since) {
            $j->on('ph.prospecting_listing_id', '=', 'pl.id')
              ->whereRaw(
                  'ph.id = (SELECT h2.id FROM prospecting_price_history h2 WHERE h2.prospecting_listing_id = pl.id
                             AND h2.changed_at >= ? ORDER BY h2.changed_at DESC, h2.id DESC LIMIT 1)',
                  [$since]
              );
        })
        ->whereRaw('ph.new_price < ph.old_price')
        ->whereRaw('ph.old_price > 0')
        ->whereRaw('ph.new_price IS NOT NULL')
        ->addSelect('ph.old_price', 'ph.new_price', 'ph.changed_at')
        ->selectRaw('ROUND((ph.old_price - ph.new_price) * 100 / ph.old_price, 1) AS drop_pct');
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderByDesc('ph.changed_at')->orderByDesc('drop_pct');
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        return 'Price dropped ' . rtrim(rtrim(number_format((float) $row->drop_pct, 1), '0'), '.') . '% on '
            . Carbon::parse($row->changed_at)->format('j M')
            . ' (R ' . number_format((int) $row->old_price) . ' to R ' . number_format((int) $row->new_price) . ')';
    }
}
