<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Support\Carbon;

/**
 * Selection 8 — my expired stock. The agent's OWN CoreX listings whose mandate has expired: pitch
 * again. Listing agent only (properties.agent_id is the viewer). Most recently expired first,
 * with an address first.
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

        $rows = $this->ownProperties($ctx)
            ->where('p.status', 'expired')
            ->select('p.id', 'p.address', 'p.expiry_date')
            ->orderByRaw("(CASE WHEN p.address IS NOT NULL AND p.address <> '' THEN 1 ELSE 0 END) DESC")
            ->orderByRaw('p.expiry_date IS NULL')
            ->orderByDesc('p.expiry_date')
            ->orderByDesc('p.id')
            ->limit($limit * 4 + 20)
            ->get();

        return $this->toCandidates($rows, $ctx, $limit, function ($row) {
            return $row->expiry_date
                ? 'Your mandate expired on ' . Carbon::parse($row->expiry_date)->format('j M Y') . ' — pitch again'
                : 'Your mandate has expired — pitch again';
        }, $this->key());
    }
}
