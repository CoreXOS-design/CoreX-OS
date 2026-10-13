<?php

namespace App\Services\RoadToSold\Support;

use App\Models\User;
use App\Services\RoadToSold\SettingsService;

/**
 * The settings the list builder reads, as one plain value object.
 *
 * Lane 1 owns the real settings (RoadToSold\SettingsService, table rts_settings) and this class reads
 * them straight from it - there is no fallback: a settings failure is an error the caller sees, never a
 * quiet drop to different numbers. The constructor defaults exist for tests and one-off overrides only
 * and equal the settings' standard values.
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
        public readonly int $addToDayMax = 100,
        public readonly int $scanPageSize = 200,
        public readonly int $hotPocketWeightBuyers = 1,
        public readonly int $hotPocketWeightLeads = 1,
        public readonly int $hotPocketWeightSales = 1,
        /** @var string[] */
        public readonly array $buyerMatchTiers = ['perfect', 'strong'],
        /** @var string[] */
        public readonly array $buyerActiveStates = ['new', 'warm'],
        /** @var string[] */
        public readonly array $onMarketStatuses = ['active', 'for_sale', 'on_show'],
    ) {
    }

    public function selectionEnabled(string $key): bool
    {
        return in_array($key, $this->enabledSelectionKeys, true);
    }

    public static function forUser(User $user, string $track = 'sales'): self
    {
        $s = SettingsService::forUser($user, $track);

        return new self(
            lockAgentsToAreas: $s->lockAgentsToAreas(),
            rollsOver: $s->rollsOver(),
            enabledSelectionKeys: array_values($s->enabledSelectionKeys()),
            newOnMarketDays: $s->newOnMarketDays(),
            longOnMarketDays: $s->longOnMarketDays(),
            ownerKnownQuantity: $s->ownerKnownQuantity(),
            stuckStockMinDaysOnMarket: $s->stuckStockMinDaysOnMarket(),
            stuckStockMaxViews: $s->stuckStockMaxViews(),
            stuckStockMaxLeads: $s->stuckStockMaxLeads(),
            hotPocketLookbackDays: $s->hotPocketLookbackDays(),
            priceDropLookbackDays: $s->priceDropLookbackDays(),
            stuckStockLookbackDays: $s->stuckStockLookbackDays(),
            addToDayMax: $s->addToDayMax(),
            scanPageSize: $s->scanPageSize(),
            hotPocketWeightBuyers: $s->hotPocketWeightBuyers(),
            hotPocketWeightLeads: $s->hotPocketWeightLeads(),
            hotPocketWeightSales: $s->hotPocketWeightSales(),
            buyerMatchTiers: $s->buyerMatchTiers(),
            buyerActiveStates: $s->activeBuyerStates(),
            onMarketStatuses: $s->onMarketStatuses(),
        );
    }

    /** A copy with some values replaced (tests, and callers that need a one-off override). */
    public function with(array $overrides): self
    {
        $values = get_object_vars($this);

        return new self(...array_merge($values, $overrides));
    }
}
