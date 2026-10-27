<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Support\Carbon;

/**
 * Selection 8 — my expired stock. The agent's OWN CoreX listings whose mandate has expired: pitch
 * again. That is a listing whose status is "expired", AND a listing still showing as on the market
 * (an "on the market" status from the agency's settings) whose mandate expiry date has already passed.
 * Listing agent only (properties.agent_id is the viewer). Most recently expired first, with an address first.
 */
final class MyExpiredStockSelection extends OwnStockSelection
{
    public function key(): string
    {
        return 'my_expired_stock';
    }

    public function label(): string
    {
        return 'My expired stock';
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $today = Carbon::instance($ctx->date)->startOfDay()->toDateString();
        $live = $ctx->settings->onMarketStatuses;

        $rows = $this->ownProperties($ctx)
            ->where(fn ($w) => $w->where('p.status', 'expired')
                ->orWhere(fn ($x) => $x->whereIn('p.status', $live)->whereNotNull('p.expiry_date')->where('p.expiry_date', '<', $today)))
            ->select('p.id', 'p.address', 'p.expiry_date', 'p.status')
            ->orderByRaw("(CASE WHEN p.address IS NOT NULL AND p.address <> '' THEN 1 ELSE 0 END) DESC")
            ->orderByRaw('p.expiry_date IS NULL')
            ->orderByDesc('p.expiry_date')
            ->orderByDesc('p.id')
            ->limit($limit * 4 + 20)
            ->get();

        return $this->toCandidates($rows, $ctx, $limit, function ($row) {
            return \App\Services\RoadToSold\Reasons\ReasonLine::myExpired($row->expiry_date ? Carbon::parse($row->expiry_date) : null, $row->status !== 'expired');
        }, $this->key());
    }
}
