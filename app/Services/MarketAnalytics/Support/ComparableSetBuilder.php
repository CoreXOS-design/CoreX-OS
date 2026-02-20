<?php

namespace App\Services\MarketAnalytics\Support;

use App\Services\MarketAnalytics\Contracts\SoldTransactionsSource;
use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;

/**
 * Builds a deterministic, stable-sorted set of comparable sold transactions.
 *
 * Post-filters applied after the adapter fetch (in this order):
 *   1. property_type exact match  — skipped if adapter row has null property_type
 *   2. bedrooms ±1               — skipped if filter->bedrooms is null OR row->bedrooms is null
 *   3. sold_price_inc ±20%       — skipped if $priceTargetIncVat is null
 *
 * Stable sort key: [sold_date ASC, sold_price_inc ASC, row_hash ASC]
 *
 * comps_hash = sha256(json_encode(ordered array of row_hashes))
 */
class ComparableSetBuilder
{
    public function __construct(
        private SoldTransactionsSource $source,
    ) {}

    public function build(
        SoldTransactionsFilter $filter,
        ?float                 $priceTargetIncVat = null,
    ): ComparableSet {
        $rows = $this->source->getRecords($filter)->all();

        // Post-filter 1: property_type exact (case-insensitive)
        // Only applied when the filter has a type AND the row has one (nulls pass through).
        $filterType = strtolower($filter->propertyType);
        $rows = array_values(array_filter($rows, function (array $row) use ($filterType) {
            $rowType = $row['property_type'] ?? null;
            return $rowType === null || strtolower($rowType) === $filterType;
        }));

        // Post-filter 2: bedrooms ±1 (only when both filter AND row provide bedrooms)
        if ($filter->bedrooms !== null) {
            $targetBeds = $filter->bedrooms;
            $rows = array_values(array_filter($rows, function (array $row) use ($targetBeds) {
                $rowBeds = $row['bedrooms'] ?? null;
                return $rowBeds === null || abs((int)$rowBeds - $targetBeds) <= 1;
            }));
        }

        // Post-filter 3: price ±20% of target (only when target supplied)
        if ($priceTargetIncVat !== null && $priceTargetIncVat > 0) {
            $low  = $priceTargetIncVat * 0.80;
            $high = $priceTargetIncVat * 1.20;
            $rows = array_values(array_filter($rows, function (array $row) use ($low, $high) {
                $price = isset($row['sold_price_inc']) ? (float)$row['sold_price_inc'] : null;
                return $price !== null && $price >= $low && $price <= $high;
            }));
        }

        // Compute row_hash for each row (hash of all data fields, keys sorted, before hash is added)
        $rows = array_map(function (array $row): array {
            $canonical = $row;
            ksort($canonical);
            $row['row_hash'] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
            return $row;
        }, $rows);

        // Stable sort: sold_date ASC → sold_price_inc ASC → row_hash ASC
        usort($rows, static function (array $a, array $b): int {
            $dateCmp = strcmp((string)($a['sold_date'] ?? ''), (string)($b['sold_date'] ?? ''));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            $priceA = (float)($a['sold_price_inc'] ?? 0);
            $priceB = (float)($b['sold_price_inc'] ?? 0);
            if ($priceA !== $priceB) {
                return $priceA <=> $priceB;
            }

            return strcmp((string)($a['row_hash'] ?? ''), (string)($b['row_hash'] ?? ''));
        });

        // comps_hash = sha256 of the ordered list of row_hashes
        $rowHashes = array_column($rows, 'row_hash');
        $compsHash = hash('sha256', json_encode($rowHashes, JSON_THROW_ON_ERROR));

        return new ComparableSet($compsHash, $rows);
    }
}
