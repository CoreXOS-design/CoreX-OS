<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Selection 5 — new on market. Listed within the agency's "new" window (default 14 days).
 *
 * Needs a REAL listed date (business rule): a first-capture date cannot prove an advert is new — a
 * suburb added to capture last week would flood this list with years-old adverts. Until the extension
 * sends dates, or an agent corrects one, this selection is honestly empty.
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
        $cutoff = Carbon::instance($ctx->date)->startOfDay()->subDays(max(0, $ctx->settings->newOnMarketDays))->toDateString();

        $q->whereNotNull('pl.listed_date')->where('pl.listed_date', '>=', $cutoff);
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderByDesc('pl.listed_date');
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        $days = (int) Carbon::parse($row->listed_date)->startOfDay()->diffInDays(Carbon::instance($ctx->date)->startOfDay());

        return $days === 0 ? 'Listed today' : "Listed {$days} " . ($days === 1 ? 'day' : 'days') . ' ago';
    }
}
