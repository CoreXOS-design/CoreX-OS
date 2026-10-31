<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\MicWork\Contracts\TileProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tile 3 "Under offer, sold or gone" (mic-work-tab.md section 1, contract 11.3.F). Properties of THIS agency whose sale advert went under offer, sold,
 * or came off the portal (`gone`, `probably_sold`, from the Gone rule) in the last N days (agency setting, default 30). A click filters the list to them.
 */
final class GoneTileProvider implements TileProvider
{
    private const STATUSES = [
        ProspectingListing::PORTAL_STATUS_UNDER_OFFER, ProspectingListing::PORTAL_STATUS_SOLD,
        ProspectingListing::PORTAL_STATUS_GONE, ProspectingListing::PORTAL_STATUS_PROBABLY_SOLD,
    ];

    public function tile(): int
    {
        return 3;
    }

    public function summary(int $agencyId, User $user): array
    {
        $days = MicTileSettings::goneTileDays($agencyId);
        $per = $this->base($agencyId, $days)->selectRaw('l.portal_status as st, COUNT(DISTINCT l.tracked_property_id) as n')->groupBy('l.portal_status')->pluck('n', 'st');
        $total = (int) $this->base($agencyId, $days)->distinct()->count('l.tracked_property_id');
        $parts = [];
        foreach ([['under_offer', 'under offer'], ['probably_sold', 'probably sold'], ['sold', 'sold'], ['gone', 'gone']] as [$k, $label]) {
            if (($per[$k] ?? 0) > 0) {
                $parts[] = $per[$k] . ' ' . $label;
            }
        }

        return [
            'count'  => $total,
            'reason' => $total . ' ' . ($total === 1 ? 'property' : 'properties') . ' went under offer, sold or off the portal in the last ' . $days . ' days',
            'note'   => $parts ? implode(' · ', $parts) : null,
        ];
    }

    public function propertyIds(int $agencyId, User $user): ?Builder
    {
        $days = MicTileSettings::goneTileDays($agencyId);

        return DB::table('tracked_properties as tp')->where('tp.agency_id', $agencyId)
            ->whereIn('tp.id', $this->base($agencyId, $days)->select('l.tracked_property_id'))->select('tp.id');
    }

    private function base(int $agencyId, int $days): Builder
    {
        return DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.listing_type', 'sale')
            ->whereIn('l.portal_status', self::STATUSES)
            ->whereRaw('COALESCE(l.portal_status_changed_at, l.off_market_at) >= ?', [now()->subDays($days)]);
    }
}
