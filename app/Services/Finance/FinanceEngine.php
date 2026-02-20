<?php

namespace App\Services\Finance;

use App\Models\Deal;
use App\Models\FinanceDefinition;
use App\Models\FinanceComputedValue;

/**
 * Finance Engine — entry point for computing and persisting definition values.
 * v0: supports numeric definitions only.
 */
class FinanceEngine
{
    public const ENGINE_VERSION = 'v0';

    /**
     * Compute and persist a definition value for a single deal.
     *
     * @return mixed  The computed value (float or null).
     */
    public function computeDefinition(string $key, int $entityId, ?string $period = null): mixed
    {
        $definition = FinanceDefinition::where('key', $key)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if (!$definition) {
            return null;
        }

        $deal = Deal::find($entityId);
        if (!$deal) {
            return null;
        }

        $value = FinanceComputeService::compute($key, $deal);

        // Persist (upsert so re-runs are idempotent)
        FinanceComputedValue::updateOrCreate(
            [
                'definition_id' => $definition->id,
                'entity_type'   => 'deal',
                'entity_id'     => $entityId,
                'period'        => $period,
            ],
            [
                'definition_key'     => $key,
                'definition_version' => $definition->version,
                'value_numeric'      => $value,
                'engine_version'     => self::ENGINE_VERSION,
                'computed_at'        => now(),
            ]
        );

        return $value;
    }

    /**
     * Ensure a definition row exists (creates with status=active if missing).
     * Safe to call multiple times — idempotent.
     */
    public static function ensureDefinition(
        string $key,
        string $entityType,
        string $valueType,
        string $notes = ''
    ): FinanceDefinition {
        return FinanceDefinition::firstOrCreate(
            ['key' => $key, 'version' => 1],
            [
                'status'      => 'active',
                'entity_type' => $entityType,
                'value_type'  => $valueType,
                'notes'       => $notes,
            ]
        );
    }
}
