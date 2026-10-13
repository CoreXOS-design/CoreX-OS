<?php

namespace App\Services\RoadToSold\Support;

use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\SettingsService;

/**
 * The settings the list builder reads, as one plain value object.
 *
 * Lane 1 owns the real settings (RoadToSold\SettingsService, table rts_settings) and this class reads
 * them straight from it - there is no fallback: a settings failure is an error the caller sees, never a
 * quiet drop to different numbers. The constructor defaults exist for tests and one-off overrides only and
 * are read FROM the settings' own default tables (RtsSetting::SCALAR_DEFAULTS / LIST_DEFAULTS), not repeated here.
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
        public readonly int $newOnMarketDays = RtsSetting::SCALAR_DEFAULTS['new_on_market_days'],
        public readonly int $longOnMarketDays = RtsSetting::SCALAR_DEFAULTS['long_on_market_days'],
        public readonly int $ownerKnownQuantity = RtsSetting::SCALAR_DEFAULTS['owner_known_quantity'],
        public readonly int $stuckStockMinDaysOnMarket = RtsSetting::SCALAR_DEFAULTS['stuck_stock_min_days'],
        public readonly int $stuckStockMaxViews = RtsSetting::SCALAR_DEFAULTS['stuck_stock_max_views'],
        public readonly int $stuckStockMaxLeads = RtsSetting::SCALAR_DEFAULTS['stuck_stock_max_leads'],
        public readonly int $hotPocketLookbackDays = RtsSetting::SCALAR_DEFAULTS['hot_pocket_lookback_days'],
        public readonly int $priceDropLookbackDays = RtsSetting::SCALAR_DEFAULTS['price_drop_lookback_days'],
        public readonly int $stuckStockLookbackDays = RtsSetting::SCALAR_DEFAULTS['stuck_stock_lookback_days'],
        public readonly int $addToDayMax = RtsSetting::SCALAR_DEFAULTS['add_to_day_max'],
        public readonly int $scanPageSize = RtsSetting::SCALAR_DEFAULTS['scan_page_size'],
        public readonly int $hotPocketWeightBuyers = RtsSetting::SCALAR_DEFAULTS['hot_pocket_weight_buyers'],
        public readonly int $hotPocketWeightLeads = RtsSetting::SCALAR_DEFAULTS['hot_pocket_weight_leads'],
        public readonly int $hotPocketWeightSales = RtsSetting::SCALAR_DEFAULTS['hot_pocket_weight_sales'],
        /** @var string[] */
        public readonly array $buyerMatchTiers = RtsSetting::LIST_DEFAULTS['buyer_match_tiers'],
        /** @var string[] */
        public readonly array $buyerActiveStates = RtsSetting::LIST_DEFAULTS['active_buyer_states'],
        /** @var string[] */
        public readonly array $onMarketStatuses = RtsSetting::LIST_DEFAULTS['on_market_statuses'],
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
