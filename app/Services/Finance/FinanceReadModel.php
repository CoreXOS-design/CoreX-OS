<?php

namespace App\Services\Finance;

use App\Models\FinanceAuditItem;
use App\Models\FinanceAuditRun;
use App\Models\FinanceComputedValue;

/**
 * Read-only access to Finance Engine rollup values.
 * No computation, no side effects.
 *
 * Primary source: finance_computed_values (canonical read model, populated by RollupService).
 * Fallback source: latest complete audit run's finance_audit_items (when computed_values absent).
 */
class FinanceReadModel
{
    /**
     * Build a definition_key => value map for a specific agent + period.
     *
     * Reads from finance_computed_values first (canonical).
     * Falls back to the latest complete audit run's audit_items when computed_values is empty.
     * Numeric values are returned as float; JSON values as array.
     * Returns an empty array when neither source has data for the period.
     */
    public function getAgentPeriodMap(int $userId, string $period): array
    {
        // --- Primary: finance_computed_values ---
        $rows = FinanceComputedValue::where('entity_type', 'agent_period')
            ->where('entity_id', $userId)
            ->where('period', $period)
            ->get(['definition_key', 'value_numeric', 'value_json']);

        if ($rows->isNotEmpty()) {
            $map = [];
            foreach ($rows as $row) {
                if ($row->value_json !== null) {
                    $map[$row->definition_key] = $row->value_json;
                } elseif ($row->value_numeric !== null) {
                    $map[$row->definition_key] = (float) $row->value_numeric;
                }
            }
            return $map;
        }

        // --- Fallback: latest complete audit run's audit_items ---
        $run = FinanceAuditRun::where('period', $period)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return [];
        }

        $items = FinanceAuditItem::where('audit_run_id', $run->id)
            ->where('entity_type', 'agent_period')
            ->where('entity_id', $userId)
            ->get(['definition_key', 'expected_numeric', 'expected_json']);

        $map = [];
        foreach ($items as $item) {
            if ($item->expected_json !== null) {
                $map[$item->definition_key] = $item->expected_json;
            } elseif ($item->expected_numeric !== null) {
                $map[$item->definition_key] = (float) $item->expected_numeric;
            }
        }

        return $map;
    }

    /**
     * Build a definition_key => value map for a specific branch + period.
     *
     * Reads from finance_computed_values first (canonical).
     * Falls back to the latest complete audit run's audit_items when computed_values is empty.
     * Returns a result array:
     *   data        - definition_key => float|array map
     *   source      - 'computed_values' | 'audit_fallback' | 'empty'
     *   audit_run_id - int|null (set when using audit_fallback)
     */
    public function getBranchPeriodMap(int $branchId, string $period): array
    {
        // --- Primary: finance_computed_values ---
        $rows = FinanceComputedValue::where('entity_type', 'branch_period')
            ->where('entity_id', $branchId)
            ->where('period', $period)
            ->get(['definition_key', 'value_numeric', 'value_json']);

        if ($rows->isNotEmpty()) {
            $map = [];
            foreach ($rows as $row) {
                if ($row->value_json !== null) {
                    $map[$row->definition_key] = $row->value_json;
                } elseif ($row->value_numeric !== null) {
                    $map[$row->definition_key] = (float) $row->value_numeric;
                }
            }
            return ['data' => $map, 'source' => 'computed_values', 'audit_run_id' => null];
        }

        // --- Fallback: latest complete audit run's audit_items ---
        $run = FinanceAuditRun::where('period', $period)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return ['data' => [], 'source' => 'empty', 'audit_run_id' => null];
        }

        $items = FinanceAuditItem::where('audit_run_id', $run->id)
            ->where('entity_type', 'branch_period')
            ->where('entity_id', $branchId)
            ->get(['definition_key', 'expected_numeric', 'expected_json']);

        $map = [];
        foreach ($items as $item) {
            if ($item->expected_json !== null) {
                $map[$item->definition_key] = $item->expected_json;
            } elseif ($item->expected_numeric !== null) {
                $map[$item->definition_key] = (float) $item->expected_numeric;
            }
        }

        $source = empty($map) ? 'empty' : 'audit_fallback';
        return ['data' => $map, 'source' => $source, 'audit_run_id' => $run->id];
    }

    /**
     * Build a definition_key => value map for the company + period.
     *
     * Reads from finance_computed_values first (canonical, entity_id=1).
     * Falls back to the latest complete audit run's audit_items when computed_values is empty.
     * Returns a result array:
     *   data        - definition_key => float|array map
     *   source      - 'computed_values' | 'audit_fallback' | 'empty'
     *   audit_run_id - int|null (set when using audit_fallback)
     */
    public function getCompanyPeriodMap(string $period): array
    {
        // --- Primary: finance_computed_values ---
        $rows = FinanceComputedValue::where('entity_type', 'company_period')
            ->where('entity_id', 1)
            ->where('period', $period)
            ->get(['definition_key', 'value_numeric', 'value_json']);

        if ($rows->isNotEmpty()) {
            $map = [];
            foreach ($rows as $row) {
                if ($row->value_json !== null) {
                    $map[$row->definition_key] = $row->value_json;
                } elseif ($row->value_numeric !== null) {
                    $map[$row->definition_key] = (float) $row->value_numeric;
                }
            }
            return ['data' => $map, 'source' => 'computed_values', 'audit_run_id' => null];
        }

        // --- Fallback: latest complete audit run's audit_items ---
        $run = FinanceAuditRun::where('period', $period)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return ['data' => [], 'source' => 'empty', 'audit_run_id' => null];
        }

        $items = FinanceAuditItem::where('audit_run_id', $run->id)
            ->where('entity_type', 'company_period')
            ->where('entity_id', 1)
            ->get(['definition_key', 'expected_numeric', 'expected_json']);

        $map = [];
        foreach ($items as $item) {
            if ($item->expected_json !== null) {
                $map[$item->definition_key] = $item->expected_json;
            } elseif ($item->expected_numeric !== null) {
                $map[$item->definition_key] = (float) $item->expected_numeric;
            }
        }

        $source = empty($map) ? 'empty' : 'audit_fallback';
        return ['data' => $map, 'source' => $source, 'audit_run_id' => $run->id];
    }
}
