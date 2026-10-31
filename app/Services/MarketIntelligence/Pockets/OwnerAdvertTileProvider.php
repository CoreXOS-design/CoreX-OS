<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Pockets;

use App\Models\User;
use App\Services\PermissionService;
use App\Services\Prospecting\MicWork\Contracts\TileProvider;
use Illuminate\Database\Query\Builder;

/**
 * Tile 2 "Owner selling privately" (mic-work-tab.md section 1, AT-519). Files of THIS agency with a LIVE advert placed by the owner
 * (`advertiser_type = owner`, written by cc3's capture: P24 "listed by owner" image, PP "LISTED PRIVATELY"), not our own stock.
 * Read from the stored `mic_file_stats.owner_advert` / `claimed` flags - never recounted on page load. A click filters the list to these
 * files; the list shows them UNCLAIMED FIRST, then longest on the market first (`claimed ASC, dom_days DESC` on the same stored table).
 */
final class OwnerAdvertTileProvider implements TileProvider
{
    public function __construct(private readonly TileService $tiles)
    {
    }

    public function tile(): int
    {
        return 2;
    }

    public function summary(int $agencyId, User $user): array
    {
        if (PermissionService::marketIntelligenceScope($user) === 'none') {
            return ['count' => null, 'reason' => 'No access', 'note' => null];
        }
        $r = $this->base($user)->selectRaw('COUNT(*) as n, SUM(f.claimed = 0) as unclaimed')->first();
        $n = (int) $r->n;
        $unclaimed = (int) $r->unclaimed;
        if ($n === 0) {
            return ['count' => 0, 'reason' => 'No owners advertising privately right now', 'note' => null];
        }

        return [
            'count'  => $n,
            'reason' => sprintf('%d owner%s advertising privately', $n, $n === 1 ? '' : 's'),
            'note'   => sprintf('%d not yet claimed', $unclaimed),
        ];
    }

    public function propertyIds(int $agencyId, User $user): ?Builder
    {
        return $this->base($user)->select('f.tracked_property_id');
    }

    private function base(User $user): Builder
    {
        return $this->tiles->visibleFiles($user)->where('f.has_active_advert', 1)->where('f.own_stock', 0)->where('f.owner_advert', 1);
    }
}
