<?php

namespace App\Services\Presentations;

/**
 * Returns the ordered list of sections that every compiled presentation
 * version must contain.
 *
 * The blueprint is intentionally static for a given version string — the
 * same version always returns the same sections in the same order.
 * This guarantees that two versions compiled at different times are
 * structurally comparable.
 */
class PresentationBlueprintService
{
    public const CURRENT_VERSION = 'v1';

    /**
     * Return the ordered section definitions for the given blueprint version.
     *
     * @return array<int, array{key: string, title: string, order: int}>
     */
    public function getBlueprint(string $version = self::CURRENT_VERSION): array
    {
        return match ($version) {
            'v1' => $this->blueprintV1(),
            default => throw new \InvalidArgumentException("Unknown blueprint version: {$version}"),
        };
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function blueprintV1(): array
    {
        return [
            ['key' => 'cover',            'title' => 'Cover Page',              'order' => 1],
            ['key' => 'property_summary', 'title' => 'Property Summary',        'order' => 2],
            ['key' => 'sold_comps',       'title' => 'Sold Comparables',        'order' => 3],
            ['key' => 'active_stock',     'title' => 'Active Stock',            'order' => 4],
            ['key' => 'market_analytics', 'title' => 'Market Analytics',        'order' => 5],
            ['key' => 'sale_probability', 'title' => 'Sale Probability',        'order' => 6],
            ['key' => 'holding_cost',     'title' => 'Holding Cost Analysis',   'order' => 7],
            ['key' => 'sensitivity',      'title' => 'Price Sensitivity',       'order' => 8],
            ['key' => 'recommendation',   'title' => 'Pricing Recommendation',  'order' => 9],
            ['key' => 'appendix',         'title' => 'Appendix & Data Sources', 'order' => 10],
        ];
    }
}
