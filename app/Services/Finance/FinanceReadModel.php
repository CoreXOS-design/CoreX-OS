<?php

namespace App\Services\Finance;

use App\Models\FinanceAuditItem;
use App\Models\FinanceAuditRun;
use App\Models\FinanceComputedValue;
use App\Models\Scopes\AgencyScope;

/**
 * Read-only access to Finance Engine rollup values.
 * No computation, no side effects.
 *
 * Primary source: finance_computed_values (canonical read model, populated by RollupService).
 * Fallback source: latest complete audit run's finance_audit_items (when computed_values absent).
 *
 * All three lookup methods take an explicit $agencyId and filter on it directly, WITHOUT
 * relying on the Eloquent AgencyScope global scope (which resolves the tenant from
 * Auth::user() and is bypassed entirely for unscoped owner-role accounts). Callers such as
 * CompanyPerformanceService already carry their own explicit $agencyId — often for an admin
 * viewing a specific agency's dashboard while remaining an "unscoped owner" in Auth terms —
 * so the read model must honour that parameter rather than whatever (or nothing) the global
 * scope would infer. A null $agencyId means "no single-agency scope requested"; since the
 * engine does not (yet) aggregate cross-agency totals, callers get an empty result and are
 * expected to fall back to a directly-scoped raw computation for platform-wide views.
 */
class FinanceReadModel
{
    /**
     * Build a definition_key => value map for a specific agent + period, scoped to one agency.
     *
     * Reads from finance_computed_values first (canonical).
     * Falls back to the latest complete audit run's audit_items when computed_values is empty.
     * Numeric values are returned as float; JSON values as array.
     * Returns an empty array when neither source has data for the period, or when $agencyId
     * is null (no engine-backed single-agency view is available for a null scope).
     */
    public function getAgentPeriodMap(int $userId, string $period, ?int $agencyId = null): array
    {
        if ($agencyId === null) {
            return [];
        }

        // --- Primary: finance_computed_values ---
        $rows = FinanceComputedValue::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('entity_type', 'agent_period')
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
        $run = FinanceAuditRun::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('period', $period)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return [];
        }

        $items = FinanceAuditItem::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('audit_run_id', $run->id)
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
     * Build a definition_key => value map for a specific branch + period, scoped to one agency.
     *
     * Reads from finance_computed_values first (canonical).
     * Falls back to the latest complete audit run's audit_items when computed_values is empty.
     * Returns a result array:
     *   data        - definition_key => float|array map
     *   source      - 'computed_values' | 'audit_fallback' | 'empty'
     *   audit_run_id - int|null (set when using audit_fallback)
     * Returns 'empty' when $agencyId is null (no engine-backed single-agency view available).
     */
    public function getBranchPeriodMap(int $branchId, string $period, ?int $agencyId = null): array
    {
        if ($agencyId === null) {
            return ['data' => [], 'source' => 'empty', 'audit_run_id' => null];
        }

        // --- Primary: finance_computed_values ---
        $rows = FinanceComputedValue::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('entity_type', 'branch_period')
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
        $run = FinanceAuditRun::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('period', $period)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return ['data' => [], 'source' => 'empty', 'audit_run_id' => null];
        }

        $items = FinanceAuditItem::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('audit_run_id', $run->id)
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
     * Build a definition_key => value map for the company + period, scoped to one agency.
     *
     * Reads from finance_computed_values first (canonical, entity_id=1 within the agency).
     * Falls back to the latest complete audit run's audit_items when computed_values is empty.
     * Returns a result array:
     *   data        - definition_key => float|array map
     *   source      - 'computed_values' | 'audit_fallback' | 'empty'
     *   audit_run_id - int|null (set when using audit_fallback)
     * Returns 'empty' when $agencyId is null (no engine-backed single-agency view available;
     * entity_id=1 is shared platform-wide across agencies, so an unscoped read here would
     * otherwise return whichever single agency's row happened to exist for the period).
     */
    public function getCompanyPeriodMap(string $period, ?int $agencyId = null): array
    {
        if ($agencyId === null) {
            return ['data' => [], 'source' => 'empty', 'audit_run_id' => null];
        }

        // --- Primary: finance_computed_values ---
        $rows = FinanceComputedValue::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('entity_type', 'company_period')
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
        $run = FinanceAuditRun::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('period', $period)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return ['data' => [], 'source' => 'empty', 'audit_run_id' => null];
        }

        $items = FinanceAuditItem::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('audit_run_id', $run->id)
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
