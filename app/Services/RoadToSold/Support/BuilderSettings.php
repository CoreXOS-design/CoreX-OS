<?php

namespace App\Services\RoadToSold\Support;

use App\Models\User;

/**
 * The settings the list builder reads, as one plain value object.
 *
 * Lane 1 owns the real settings (RoadToSold\SettingsService, table rts_settings); this class reads
 * them through that service when it exists and falls back to the documented defaults when it does
 * not, so lane 2 never blocks on lane 1 and never hard-codes a value the agency can change.
 * The three look-back windows are not in lane 1's list yet — they are read if the service has the
 * getter and default otherwise (spec §9.7).
 */
final class BuilderSettings
{
    public const DEFAULT_SELECTION_ORDER = [
        'my_buyers_sourcing', 'agency_buyers_sourcing', 'hot_pockets', 'price_drops', 'new_on_market',
        'long_on_market', 'owner_known', 'my_expired_stock', 'my_stock_not_selling',
    ];

    /** @param string[] $enabledSelectionKeys ordered */
    public function __construct(
        public readonly bool $lockAgentsToAreas = false,
        public readonly bool $rollsOver = false,
        public readonly array $enabledSelectionKeys = self::DEFAULT_SELECTION_ORDER,
        public readonly int $newOnMarketDays = 14,
        public readonly int $longOnMarketDays = 90,
        public readonly int $ownerKnownQuantity = 3,
        public readonly int $stuckStockMinDaysOnMarket = 60,
        public readonly int $stuckStockMaxViews = 30,
        public readonly int $stuckStockMaxLeads = 2,
        public readonly int $hotPocketLookbackDays = 180,
        public readonly int $priceDropLookbackDays = 30,
        public readonly int $stuckStockLookbackDays = 30,
    ) {
    }

    public function selectionEnabled(string $key): bool
    {
        return in_array($key, $this->enabledSelectionKeys, true);
    }

    public static function forUser(User $user, string $track = 'sales'): self
    {
        $class = '\\App\\Services\\RoadToSold\\SettingsService';
        if (! class_exists($class)) {
            return new self();
        }

        $s = $class::forUser($user, $track);
        $opt = static fn (string $method, int $default): int => method_exists($s, $method) ? (int) $s->{$method}() : $default;

        return new self(
            lockAgentsToAreas: (bool) $s->lockAgentsToAreas(),
            rollsOver: (bool) $s->rollsOver(),
            enabledSelectionKeys: array_values((array) $s->enabledSelectionKeys()),
            newOnMarketDays: (int) $s->newOnMarketDays(),
            longOnMarketDays: (int) $s->longOnMarketDays(),
            ownerKnownQuantity: (int) $s->ownerKnownQuantity(),
            stuckStockMinDaysOnMarket: (int) $s->stuckStockMinDaysOnMarket(),
            stuckStockMaxViews: (int) $s->stuckStockMaxViews(),
            stuckStockMaxLeads: (int) $s->stuckStockMaxLeads(),
            hotPocketLookbackDays: $opt('hotPocketLookbackDays', 180),
            priceDropLookbackDays: $opt('priceDropLookbackDays', 30),
            stuckStockLookbackDays: $opt('stuckStockLookbackDays', 30),
        );
    }

    /** A copy with some values replaced (tests, and callers that need a one-off override). */
    public function with(array $overrides): self
    {
        $values = get_object_vars($this);

        return new self(...array_merge($values, $overrides));
    }
}
