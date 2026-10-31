<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Pockets;

/**
 * Price band slugs from the agency's 4 edges (default 1m/2m/3m/5m -> lt1m, 1to2m, 2to3m, 3to5m, gte5m). A missing price is band `none`.
 */
final class PriceBand
{
    public const NONE = 'none';

    private static function tag(int $rand): string
    {
        return $rand % 1_000_000 === 0 ? ($rand / 1_000_000) . 'm' : (int) round($rand / 1000) . 'k';
    }

    /** @param list<int> $edges @return list<string> in ascending price order */
    public static function keys(array $edges): array
    {
        $t = array_map([self::class, 'tag'], $edges);

        return ['lt' . $t[0], $t[0] . 'to' . $t[1], $t[1] . 'to' . $t[2], $t[2] . 'to' . $t[3], 'gte' . $t[3]];
    }

    /** @param list<int> $edges */
    public static function key(?int $price, array $edges): string
    {
        if ($price === null || $price <= 0) {
            return self::NONE;
        }
        $keys = self::keys($edges);
        foreach ($edges as $i => $edge) {
            if ($price < $edge) {
                return $keys[$i];
            }
        }

        return $keys[4];
    }

    /**
     * [from, to) rand range of a band slug (to = null for the open top band); null for `none`.
     *
     * @param  list<int>  $edges
     * @return array{0:int,1:?int}|null
     */
    public static function range(string $band, array $edges): ?array
    {
        $keys = self::keys($edges);
        $i = array_search($band, $keys, true);
        if ($i === false) {
            return null;
        }

        return [$i === 0 ? 0 : $edges[$i - 1], $i === 4 ? null : $edges[$i]];
    }
}
