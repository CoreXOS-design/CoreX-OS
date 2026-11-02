<?php

namespace App\Services\RoadToSold;

/**
 * Station 1 "Stock loaded": WHERE a loaded row came from (road-to-sold.md 23.14, 23.22). Every row records it in `reason_facts.source` at the moment it is
 * offered, so the Loaded tile and the stats can say "2 for my buyers, 1 colleague's buyer, 1 hot pocket, 1 fill-up" without re-deriving anything later.
 *
 *   kind          my_buyers | colleague_buyers | hot_pocket | fill_up | follow_up | found_by_you
 *   selection     the stored selection key it came from
 *   buyer_ids     (buyer sources) the buyers behind the row, best match first;  agent_ids: their agents;  scope: branch | agency (colleagues)
 *   area          (hot pocket) the pocket's label
 *   fill          (fill-up) price_drops | long_on_market | expired | new_on_market | ...
 */
final class AllocationSource
{
    public const MY_BUYERS = 'my_buyers';
    public const COLLEAGUE_BUYERS = 'colleague_buyers';
    public const HOT_POCKET = 'hot_pocket';
    public const FILL_UP = 'fill_up';
    public const FOLLOW_UP = 'follow_up';
    public const FOUND_BY_YOU = 'found_by_you';

    /** kind => the plain label the tile and the stats use */
    public const LABELS = [
        self::MY_BUYERS => 'My buyers\' stock',
        self::COLLEAGUE_BUYERS => 'Colleagues\' buyers\' stock',
        self::HOT_POCKET => 'Hot pockets',
        self::FILL_UP => 'Fill-ups in my areas',
        self::FOLLOW_UP => 'Follow-ups',
        self::FOUND_BY_YOU => 'Found by you',
    ];

    public static function kindOf(string $selection): string
    {
        return match ($selection) {
            'my_buyers_sourcing' => self::MY_BUYERS,
            'agency_buyers_sourcing' => self::COLLEAGUE_BUYERS,
            'hot_pockets' => self::HOT_POCKET,
            'follow_up' => self::FOLLOW_UP,
            'found_by_you' => self::FOUND_BY_YOU,
            default => self::FILL_UP,
        };
    }

    /**
     * @param  array<string,mixed>|null  $facts  the structured facts behind the reason line
     * @return array<string,mixed>
     */
    public static function describe(string $selection, ?array $facts = null): array
    {
        $kind = self::kindOf($selection);
        $out = ['kind' => $kind, 'selection' => $selection];
        if (in_array($kind, [self::MY_BUYERS, self::COLLEAGUE_BUYERS], true)) {
            $buyers = is_array($facts['buyers'] ?? null) ? $facts['buyers'] : [];
            $out['buyer_ids'] = array_values(array_map(fn ($b) => (int) ($b['id'] ?? 0), $buyers));
            $out['agent_ids'] = array_values(array_unique(array_filter(array_map(fn ($b) => isset($b['agent_id']) ? (int) $b['agent_id'] : null, $buyers))));
            $out['buyer_count'] = (int) ($facts['count'] ?? count($buyers));
            if (isset($facts['scope'])) {
                $out['scope'] = $facts['scope'];
            }
        } elseif ($kind === self::HOT_POCKET) {
            $out['area'] = $facts['area'] ?? null;
            $out['suburb_id'] = isset($facts['suburb_id']) ? (int) $facts['suburb_id'] : null;
        } elseif ($kind === self::FILL_UP) {
            $out['fill'] = match ($selection) {
                'my_expired_stock', 'expired_competitor' => 'expired',
                default => $selection,
            };
        }

        return $out;
    }
}
