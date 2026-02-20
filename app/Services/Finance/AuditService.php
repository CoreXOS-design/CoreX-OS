<?php

namespace App\Services\Finance;

use App\Models\Deal;
use App\Models\FinanceAuditItem;
use App\Models\FinanceAuditRun;
use App\Models\FinanceComputedValue;
use App\Services\Finance\Legacy\CompanyPerformanceLegacyReader;
use App\Services\Finance\RollupService;

/**
 * Runs a finance audit for a given period.
 * Compares engine-computed values against legacy values (Deal model or CompanyPerformanceLegacyReader).
 * Writes results to finance_audit_items; persists computed values to finance_computed_values.
 */
class AuditService
{
    /**
     * Definitions audited using FinanceComputeService::legacy() (Deal model methods).
     */
    private const LEGACY_MODEL_DEFINITIONS = [
        'deal.total_commission_ex_vat',
        'deal.total_commission_inc_vat',
    ];

    /**
     * New numeric definitions using CompanyPerformanceLegacyReader map for actual values.
     * Computed values are also persisted to finance_computed_values for these.
     */
    private const LEGACY_READER_DEFINITIONS_NUMERIC = [
        'deal.company_income_ex_vat.side_listing',
        'deal.company_income_ex_vat.side_selling',
        'deal.company_retained_ex_vat',
    ];

    /**
     * JSON definitions using CompanyPerformanceLegacyReader map for actual values.
     */
    private const LEGACY_READER_DEFINITIONS_JSON = [
        'deal.agent_income_ex_vat.by_agent',
    ];

    /** Difference threshold below which we treat values as matching (float rounding). */
    private const MATCH_TOLERANCE = 0.001;

    /**
     * @param  string   $period   YYYY-MM
     * @param  int      $limit    Max deals to audit in one run.
     * @param  array    $scope    Optional scope constraints (stored in the run row).
     * @param  array    $options  Runtime options (not stored):
     *                              audit_scope   string  'deals'|'rollups'|'all' (default 'deals')
     *                              rollup_roles  string[] ['agent','bm','admin']
     *                              rollup_stages string[] stage list
     *                              entity_id     int|null
     * @return FinanceAuditRun
     */
    public function run(string $period, int $limit = 200, array $scope = [], array $options = []): FinanceAuditRun
    {
        $auditScope = $options['audit_scope'] ?? 'deals';

        $run = FinanceAuditRun::create([
            'period'         => $period,
            'scope'          => empty($scope) ? null : $scope,
            'status'         => 'running',
            'engine_version' => FinanceEngine::ENGINE_VERSION,
            'started_at'     => now(),
        ]);

        try {
            if ($auditScope === 'deals' || $auditScope === 'all') {
                $this->auditPeriod($run, $period, $limit);
            }

            if ($auditScope === 'rollups' || $auditScope === 'all') {
                (new RollupService())->computeRollups($run, $period, $limit, [
                    'roles'     => $options['rollup_roles']  ?? ['agent', 'bm', 'admin'],
                    'stages'    => $options['rollup_stages'] ?? RollupService::ALL_STAGES,
                    'entity_id' => $options['entity_id']     ?? null,
                ]);
            }

            $run->update([
                'status'      => 'complete',
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status'      => 'failed',
                'finished_at' => now(),
            ]);
            throw $e;
        }

        return $run;
    }

    private function auditPeriod(FinanceAuditRun $run, string $period, int $limit): void
    {
        // Ensure all definitions exist and capture their objects for persistence
        $defs = [];
        $defs['deal.total_commission_ex_vat'] = FinanceEngine::ensureDefinition(
            'deal.total_commission_ex_vat', 'deal', 'money_ex_vat',
            'Total commission received, VAT removed (canonical ex-VAT pool basis)'
        );
        $defs['deal.total_commission_inc_vat'] = FinanceEngine::ensureDefinition(
            'deal.total_commission_inc_vat', 'deal', 'money_inc_vat',
            'Total commission as captured (incl VAT — bank reality)'
        );
        $defs['deal.company_income_ex_vat.side_listing'] = FinanceEngine::ensureDefinition(
            'deal.company_income_ex_vat.side_listing', 'deal', 'money_ex_vat',
            'Company income ex VAT — listing side'
        );
        $defs['deal.company_income_ex_vat.side_selling'] = FinanceEngine::ensureDefinition(
            'deal.company_income_ex_vat.side_selling', 'deal', 'money_ex_vat',
            'Company income ex VAT — selling side'
        );
        $defs['deal.company_retained_ex_vat'] = FinanceEngine::ensureDefinition(
            'deal.company_retained_ex_vat', 'deal', 'money_ex_vat',
            'Company retained ex VAT (company income minus agent income)'
        );
        $defs['deal.agent_income_ex_vat.by_agent'] = FinanceEngine::ensureDefinition(
            'deal.agent_income_ex_vat.by_agent', 'deal', 'json',
            'Agent income ex VAT keyed by agent_id'
        );

        // Load deals for the period with agent pivot (needed for retained / agent-income compute)
        $deals = Deal::where('period', $period)
            ->with('agents')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($deals->isEmpty()) {
            return;
        }

        $dealIds = $deals->pluck('id')->all();

        // Build legacy reader map once for all deals in this batch
        $legacyMap = (new CompanyPerformanceLegacyReader())->buildByDealMap($dealIds);

        foreach ($deals as $deal) {
            // Old definitions: actual from Deal model methods
            foreach (self::LEGACY_MODEL_DEFINITIONS as $key) {
                $this->auditDealNumericModel($run, $deal, $key, $period);
            }

            // New numeric definitions: actual from legacy reader map + persist computed value
            foreach (self::LEGACY_READER_DEFINITIONS_NUMERIC as $key) {
                $this->auditDealNumericReader($run, $deal, $key, $period, $legacyMap, $defs);
            }

            // JSON definitions: actual from legacy reader map
            foreach (self::LEGACY_READER_DEFINITIONS_JSON as $key) {
                $this->auditDealJsonReader($run, $deal, $key, $period, $legacyMap);
            }
        }
    }

    /** Audit a numeric definition using FinanceComputeService::legacy() as the actual source. */
    private function auditDealNumericModel(
        FinanceAuditRun $run,
        Deal $deal,
        string $key,
        string $period
    ): void {
        $expected = FinanceComputeService::compute($key, $deal);
        $actual   = FinanceComputeService::legacy($key, $deal);

        $this->writeNumericItem($run, $deal, $key, $period, $expected, $actual);
    }

    /**
     * Audit a numeric definition using the legacy reader map as the actual source.
     * Also persists the computed value to finance_computed_values.
     */
    private function auditDealNumericReader(
        FinanceAuditRun $run,
        Deal $deal,
        string $key,
        string $period,
        array $legacyMap,
        array $defs
    ): void {
        $expected = FinanceComputeService::compute($key, $deal);

        $entry  = $legacyMap[(int) $deal->id] ?? null;
        $actual = match ($key) {
            'deal.company_income_ex_vat.side_listing' => $entry !== null ? (float) $entry['company_income_listing'] : null,
            'deal.company_income_ex_vat.side_selling' => $entry !== null ? (float) $entry['company_income_selling'] : null,
            'deal.company_retained_ex_vat'            => $entry !== null ? (float) $entry['company_retained']       : null,
            default                                   => null,
        };

        // Persist computed value
        if ($expected !== null && isset($defs[$key])) {
            $def = $defs[$key];
            FinanceComputedValue::updateOrCreate(
                [
                    'definition_id' => $def->id,
                    'entity_type'   => 'deal',
                    'entity_id'     => $deal->id,
                    'period'        => $period,
                ],
                [
                    'definition_key'     => $key,
                    'definition_version' => $def->version,
                    'value_numeric'      => $expected,
                    'engine_version'     => FinanceEngine::ENGINE_VERSION,
                    'computed_at'        => now(),
                ]
            );
        }

        $this->writeNumericItem($run, $deal, $key, $period, $expected, $actual);
    }

    /** Audit a JSON definition using the legacy reader map as the actual source. */
    private function auditDealJsonReader(
        FinanceAuditRun $run,
        Deal $deal,
        string $key,
        string $period,
        array $legacyMap
    ): void {
        $entry = $legacyMap[(int) $deal->id] ?? null;

        $expected = match ($key) {
            'deal.agent_income_ex_vat.by_agent' => FinanceComputeService::dealAgentIncomeByAgentExVat($deal),
            default                             => null,
        };

        $actual = match ($key) {
            'deal.agent_income_ex_vat.by_agent' => $entry !== null ? $entry['agent_income_by_agent'] : null,
            default                             => null,
        };

        if ($expected === null && $actual === null) {
            return;
        }

        $severity = 'info';
        $message  = 'match';

        if ($expected === null || $actual === null) {
            $severity = 'warn';
            $message  = 'one side null';
        } else {
            // Compare each agent key within tolerance
            $allKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
            foreach ($allKeys as $agentId) {
                $ev = (float) ($expected[$agentId] ?? 0.0);
                $av = (float) ($actual[$agentId]   ?? 0.0);
                if (abs($ev - $av) > self::MATCH_TOLERANCE) {
                    $severity = 'error';
                    $message  = 'json agent income mismatch';
                    break;
                }
            }
        }

        FinanceAuditItem::create([
            'audit_run_id'  => $run->id,
            'definition_key'=> $key,
            'entity_type'   => 'deal',
            'entity_id'     => $deal->id,
            'period'        => $period,
            'expected_json' => $expected,
            'actual_json'   => $actual,
            'severity'      => $severity,
            'message'       => $message,
        ]);
    }

    private function writeNumericItem(
        FinanceAuditRun $run,
        Deal $deal,
        string $key,
        string $period,
        ?float $expected,
        ?float $actual
    ): void {
        if ($expected === null && $actual === null) {
            return;
        }

        $diff    = ($expected !== null && $actual !== null) ? round($expected - $actual, 6) : null;
        $absDiff = ($diff !== null) ? abs($diff) : null;

        $severity = 'info';
        $message  = 'match';

        if ($absDiff === null) {
            $severity = 'warn';
            $message  = 'one side null';
        } elseif ($absDiff > self::MATCH_TOLERANCE) {
            $severity = 'error';
            $message  = "diff={$diff}";
        }

        FinanceAuditItem::create([
            'audit_run_id'     => $run->id,
            'definition_key'   => $key,
            'entity_type'      => 'deal',
            'entity_id'        => $deal->id,
            'period'           => $period,
            'expected_numeric' => $expected,
            'actual_numeric'   => $actual,
            'diff_numeric'     => $diff,
            'severity'         => $severity,
            'message'          => $message,
        ]);
    }
}
