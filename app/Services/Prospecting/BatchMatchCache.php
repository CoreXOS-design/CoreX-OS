<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedProperty;

/**
 * Request-scoped, write-through preload cache for
 * TrackedPropertyMatchOrCreateService::matchOrCreate()'s batched entry point.
 *
 * Built once per import batch (e.g. one captured portal page) from a handful
 * of bulk queries via primeCacheForBatch(), then consulted instead of the
 * per-listing queries for Strategy 1 (source-ref) and the suburb-scoped
 * candidate pool shared by Strategies 3/4/5. A listing created or enriched
 * mid-batch is written back into the cache (rememberTp/primeRef) so later
 * listings in the SAME batch see it immediately, exactly as a fresh
 * per-listing query would.
 *
 * Strategy 0's GPS sub-match and Strategy 2 (GPS) are deliberately NOT
 * covered: the caller this exists for (ProspectingApiController::import)
 * never supplies GPS facts, so batching GPS has zero payoff here and would
 * add unproven risk for no gain.
 */
final class BatchMatchCache
{
    /** "{sourceType}|{sourceRef}" (both lowercased) => tracked_property_id */
    private array $refIndex = [];

    /** suburb_normalised => tracked_property_id => TrackedProperty */
    private array $tpBySuburb = [];

    /** suburb_normalised => true once its pool has been preloaded (vs needs a live fallback) */
    private array $suburbsPreloaded = [];

    /** tracked_property_id => TrackedProperty (freshest known copy across the batch) */
    private array $tpById = [];

    /**
     * "{streetNumberLower}|{streetNameLower}|{suburbNormalised}" => list of
     * ['tp_id' => int, 'confidence' => string, 'is_primary' => bool]
     * (Strategy 0 Match A — tracked_property_addresses structured lookup.)
     */
    private array $addressIndex = [];

    /** suburb_normalised => true once its address rows have been preloaded */
    private array $addressSuburbsPreloaded = [];

    public function __construct(public readonly int $agencyId)
    {
    }

    // ───────────────────────── Strategy 1: source-ref ─────────────────────────

    public function primeRef(string $sourceType, string $sourceRef, int $trackedPropertyId): void
    {
        $this->refIndex[$this->refKey($sourceType, $sourceRef)] = $trackedPropertyId;
    }

    public function lookupRef(string $sourceType, string $sourceRef): ?int
    {
        return $this->refIndex[$this->refKey($sourceType, $sourceRef)] ?? null;
    }

    private function refKey(string $sourceType, string $sourceRef): string
    {
        // Collation is utf8mb4_unicode_ci (case-insensitive) — fold to lowercase
        // so this in-memory lookup agrees with the DB's own comparison.
        return mb_strtolower(trim($sourceType)).'|'.mb_strtolower(trim($sourceRef));
    }

    // ───────────────────────── TP identity map ─────────────────────────

    public function rememberTp(TrackedProperty $tp): void
    {
        $this->tpById[(int) $tp->id] = $tp;

        $suburbKey = (string) ($tp->suburb_normalised ?? '');
        if ($suburbKey !== '') {
            $this->tpBySuburb[$suburbKey][(int) $tp->id] = $tp;
        }
    }

    public function getTp(int $id): ?TrackedProperty
    {
        return $this->tpById[$id] ?? null;
    }

    // ───────────────────────── Strategies 3/4/5 suburb pool ─────────────────────────

    /** @param TrackedProperty[] $trackedProperties id-ascending */
    public function primeSuburbPool(string $suburbNormalised, array $trackedProperties): void
    {
        $this->suburbsPreloaded[$suburbNormalised] = true;
        foreach ($trackedProperties as $tp) {
            $this->tpBySuburb[$suburbNormalised][(int) $tp->id] = $tp;
            $this->tpById[(int) $tp->id] = $tp;
        }
    }

    public function suburbPreloaded(string $suburbNormalised): bool
    {
        return isset($this->suburbsPreloaded[$suburbNormalised]);
    }

    /**
     * @return TrackedProperty[] id-ascending — mirrors the InnoDB secondary-index
     *         scan order on (agency_id, suburb_normalised) that the original
     *         un-ordered `->limit(50)` query relies on in practice.
     */
    public function suburbPool(string $suburbNormalised): array
    {
        $pool = $this->tpBySuburb[$suburbNormalised] ?? [];
        ksort($pool);

        return array_values($pool);
    }

    // ───────────────────────── Strategy 0 Match A: address-history ─────────────────────────

    /** @param array<int, array{tp_id: int, street_number: ?string, street_name: ?string, confidence: string, is_primary: bool}> $rows */
    public function primeAddress(string $suburbNormalised, array $rows): void
    {
        $this->addressSuburbsPreloaded[$suburbNormalised] = true;
        foreach ($rows as $row) {
            $key = $this->addressKey((string) $row['street_number'], (string) $row['street_name'], $suburbNormalised);
            $this->addressIndex[$key][] = $row;
        }
    }

    public function addressSuburbPreloaded(string $suburbNormalised): bool
    {
        return isset($this->addressSuburbsPreloaded[$suburbNormalised]);
    }

    /**
     * Best tracked_property_id for this exact (street_number, street_name,
     * suburb) tuple, or null if none. Ties broken the same way the original
     * SQL does: FIELD(confidence,'verified','high','medium','low') then
     * is_primary DESC.
     */
    public function bestAddressMatch(string $streetNumber, string $streetName, string $suburbNormalised): ?int
    {
        $rows = $this->addressIndex[$this->addressKey($streetNumber, $streetName, $suburbNormalised)] ?? [];
        if (empty($rows)) {
            return null;
        }

        $order = ['verified' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($rows, function ($a, $b) use ($order) {
            $ca = $order[$a['confidence']] ?? 4;
            $cb = $order[$b['confidence']] ?? 4;

            return $ca <=> $cb ?: (($b['is_primary'] ? 1 : 0) <=> ($a['is_primary'] ? 1 : 0));
        });

        return (int) $rows[0]['tp_id'];
    }

    /**
     * Write-through — call after appendIngestedAddressToHistory() inserts or
     * bumps a tracked_property_addresses row, so a LATER listing in the same
     * batch sees it without a fresh query.
     */
    public function rememberAddressRow(string $streetNumber, string $streetName, string $suburbNormalised, int $tpId, string $confidence, bool $isPrimary): void
    {
        $key = $this->addressKey($streetNumber, $streetName, $suburbNormalised);
        $rows = $this->addressIndex[$key] ?? [];
        foreach ($rows as $i => $row) {
            if ((int) $row['tp_id'] === $tpId) {
                $rows[$i] = ['tp_id' => $tpId, 'street_number' => $streetNumber, 'street_name' => $streetName, 'confidence' => $confidence, 'is_primary' => $isPrimary];
                $this->addressIndex[$key] = $rows;
                $this->addressSuburbsPreloaded[$suburbNormalised] = true;

                return;
            }
        }
        $rows[] = ['tp_id' => $tpId, 'street_number' => $streetNumber, 'street_name' => $streetName, 'confidence' => $confidence, 'is_primary' => $isPrimary];
        $this->addressIndex[$key] = $rows;
        $this->addressSuburbsPreloaded[$suburbNormalised] = true;
    }

    private function addressKey(string $streetNumber, string $streetName, string $suburbNormalised): string
    {
        return mb_strtolower(trim($streetNumber)).'|'.mb_strtolower(trim($streetName)).'|'.$suburbNormalised;
    }
}
