<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Selection 5 — new on market. Listed within the agency's "new" window (default 14 days), by the real
 * listed date when CoreX has one (listing-page captures, an agent's correction), otherwise by the day CoreX
 * first captured the advert (Johan, 13 Oct: the portals' search tiles carry no date, so the selection must
 * never be empty for want of one). The row says which it is — "Listed" vs "First seen by us" — so an
 * agent is never told an advert is new on the strength of a capture date without knowing that is what it is.
 * Newest first.
 */
final class NewOnMarketSelection extends MicPoolSelection
{
    public function key(): string
    {
        return 'new_on_market';
    }

    public function label(): string
    {
        return 'New on the market';
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $day = Carbon::instance($ctx->date)->startOfDay();
        $days = max(0, $ctx->settings->newOnMarketDays);
        $dateCut = $day->copy()->subDays($days)->toDateString();
        $tsCut = $day->copy()->subDays($days)->toDateTimeString();

        $q->where(function ($w) use ($dateCut, $tsCut) {
            $w->where(fn ($a) => $a->whereNotNull('pl.listed_date')->where('pl.listed_date', '>=', $dateCut))
              ->orWhere(fn ($b) => $b->whereNull('pl.listed_date')->where('pl.first_seen_at', '>=', $tsCut));
        });
        $q->selectRaw('COALESCE(pl.listed_date, DATE(pl.first_seen_at)) AS on_market_since');
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderByDesc('on_market_since');
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        $days = (int) Carbon::parse($row->on_market_since)->startOfDay()->diffInDays(Carbon::instance($ctx->date)->startOfDay());
        $when = $days === 0 ? 'today' : "{$days} " . ($days === 1 ? 'day' : 'days') . ' ago';

        return $row->listed_date ? "Listed {$when}" : "First seen by us {$when} (listed date not known)";
    }
}
