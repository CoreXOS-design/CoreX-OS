<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Selection 6 — long on market. On the market MORE than the agency's window (default 90 days), by the
 * real listed date when we have one, else by the day we first captured it (labelled on the row). The
 * fallback can only under-state the age ("first seen at least N days ago" proves "listed at least N days
 * ago"). Longest first.
 */
final class LongOnMarketSelection extends MicPoolSelection
{
    public function key(): string
    {
        return 'long_on_market';
    }

    public function label(): string
    {
        return 'Long on the market';
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $day = Carbon::instance($ctx->date)->startOfDay();
        $days = max(0, $ctx->settings->longOnMarketDays);
        $dateCut = $day->copy()->subDays($days)->toDateString();
        $tsCut = $day->copy()->subDays($days)->toDateTimeString();

        $q->where(function ($w) use ($dateCut, $tsCut) {
            $w->where(fn ($a) => $a->whereNotNull('pl.listed_date')->where('pl.listed_date', '<', $dateCut))
              ->orWhere(fn ($b) => $b->whereNull('pl.listed_date')->where('pl.first_seen_at', '<', $tsCut));
        });
        $q->selectRaw('COALESCE(pl.listed_date, DATE(pl.first_seen_at)) AS on_market_since');
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderBy('on_market_since');
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        $days = (int) Carbon::parse($row->on_market_since)->startOfDay()->diffInDays(Carbon::instance($ctx->date)->startOfDay());

        return $row->listed_date
            ? "On the market for {$days} days"
            : "On the market for at least {$days} days (first seen by us; listed date not known)";
    }
}
