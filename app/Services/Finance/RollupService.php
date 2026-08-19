<?php

namespace App\Services\Finance;

use App\Models\Deal;
use App\Models\FinanceAuditItem;
use App\Models\FinanceAuditRun;
use App\Models\FinanceComputedValue;
use App\Models\Scopes\AgencyScope;
use App\Services\Finance\Legacy\AgentRollupLegacyReader;
use App\Services\Finance\Legacy\BranchRollupLegacyReader;
use App\Services\Finance\Legacy\CompanyRollupLegacyReader;

/**
 * Finance Rollup Service — computes auditable period rollups for agent, branch, and company.
 *
 * Aggregates from already-computed deal-level values (FinanceComputeService) so no split/VAT
 * logic is duplicated here.
 *
 * Rollup types:
 *   agent_period   — entity_id = user_id
 *   branch_period  — entity_id = branch_id (agent's branch)
 *   company_period — entity_id = 1
 *
 * Per-stage definitions (pending|granted|registered|declined):
 *   {type}.deals.{stage}.count
 *   {type}.money.{stage}.agent_income_ex_vat          (agent_period)
 *   {type}.money.{stage}.team_agent_income_ex_vat     (branch_period / company_period)
 *   {type}.money.{stage}.breakdown_json
 *
 * Legacy comparison definitions (non-declined total vs legacy deal_date-based totals):
 *   {type}.money.total_nondeclined.agent_income_ex_vat          (agent_period)
 *   {type}.money.total_nondeclined.team_agent_income_ex_vat     (branch_period / company_period)
 */
class RollupService
{
    public const ALL_STAGES = ['pending', 'granted', 'registered', 'declined'];

    /** Difference threshold (absolute) above which we flag a rollup diff as error. */
    private const MATCH_TOLERANCE = 0.01;

    /**
     * Agency currently being rolled up, set at the top of computeRollups() from
     * $run->agency_id. Read by the write* / upsertComputedValue helpers so every
     * row this instance writes is stamped explicitly — never left to the
     * Auth-derived AgencyScope global scope. See computeRollups() docblock.
     */
    private int $agencyId = 0;

    /**
     * Lightweight period refresh — auto-triggered after deal changes.
     * Creates a minimal audit run and recomputes all rollups for the period.
     *
     * $agencyId MUST be the owning agency of the deal that triggered the refresh (e.g.
     * $deal->agency_id), NOT inferred from Auth::user(). The acting user may be an
     * unscoped owner-role account editing a deal that belongs to a different agency than
     * whatever the AgencyScope global scope would stamp, and finance_computed_values rows
     * are now partitioned per-agency (see fcv_agency_def_entity_period_unique) — an
     * agency_id mismatch here would silently roll a deal into the wrong tenant's numbers.
     */
    public function refreshPeriod(string $period, int $agencyId): void
    {
        $run = FinanceAuditRun::create([
            'agency_id'      => $agencyId,
            'period'         => $period,
            'scope'          => json_encode(['trigger' => 'auto']),
            'status'         => 'running',
            'engine_version' => FinanceEngine::ENGINE_VERSION,
            'started_at'     => now(),
        ]);

        try {
            $this->computeRollups($run, $period, 500, [
                'roles'  => ['agent', 'bm', 'admin'],
                'stages' => self::ALL_STAGES,
            ]);
            $run->update(['status' => 'complete', 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now()]);
            \Log::error('RollupService::refreshPeriod failed', [
                'period' => $period,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Canonical stage derivation — matches Deal::statusSummaryForBranch logic exactly.
     */
    public static function dealStage(Deal $deal): string
    {
        if ((string) ($deal->accepted_status ?? '') === 'D') {
            return 'declined';
        }
        if (!empty($deal->registration_date)) {
            return 'registered';
        }
        if (!empty($deal->granted_at)) {
            return 'granted';
        }
        return 'pending';
    }

    /**
     * Compute and write rollup audit items into the given run.
     *
     * @param FinanceAuditRun $run
     * @param string          $period   YYYY-MM
     * @param int             $limit    Max deals to load
     * @param array           $options  {
     *   roles     string[]  (agent|bm|admin) — which entity types to roll up
     *   stages    string[]  stages to compute
     *   entity_id int|null  optional single-entity filter (not yet scoping the query,
     *                       future extension — currently all deals are loaded)
     * }
     *
     * Deals are scoped to $run->agency_id (NOT NULL on every finance_audit_runs row).
     * Every finance_computed_values / finance_audit_items row this method writes is
     * stamped with that same agency_id, explicitly — not left to the Eloquent
     * AgencyScope global scope, since the acting Auth user (if any) may be an
     * unscoped owner-role account whose implicit scope would not match $run's agency.
     * A single company_period / branch_period / agent_period row is therefore always
     * ONE agency's numbers, never a cross-tenant aggregate.
     */
    public function computeRollups(
        FinanceAuditRun $run,
        string $period,
        int $limit,
        array $options = []
    ): void {
        $roles     = $options['roles']  ?? ['agent', 'bm', 'admin'];
        $stages    = $options['stages'] ?? self::ALL_STAGES;
        $agencyId  = (int) $run->agency_id;
        $this->agencyId = $agencyId;

        $doAgent   = in_array('agent', $roles, true);
        $doBranch  = in_array('bm', $roles, true);
        $doCompany = in_array('admin', $roles, true);

        // Load deals with eager-loaded agents (User models carry branch_id).
        // Scoped to this run's agency — see class-level note on computeRollups().
        $deals = Deal::where('period', $period)
            ->where('agency_id', $agencyId)
            ->with('agents')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($deals->isEmpty()) {
            return;
        }

        // ---- Accumulators ----
        // agentData[uid][stage]      = ['count' => int, 'income' => float, 'deals' => [deal_id => amount]]
        // branchData[bid][stage]     = ['count' => int, 'income' => float, 'deals' => [deal_id => amount]]
        // companyData[stage]         = ['count' => int, 'income' => float, 'deals' => [deal_id => amount]]
        $agentData   = [];
        $branchData  = [];
        $companyData = [];

        $ledgerData  = []; // Ledger view: keyed by deal.branch_id (accounting truth)

        foreach ($stages as $s) {
            $companyData[$s] = ['count' => 0, 'income' => 0.0, 'company_income' => 0.0, 'retained' => 0.0, 'property_value' => 0.0, 'deals' => []];
        }

        foreach ($deals as $deal) {
            $stage = self::dealStage($deal);

            if (!in_array($stage, $stages, true)) {
                continue;
            }

            // Compute agent income breakdown from the Finance Engine
            $byAgent = FinanceComputeService::dealAgentIncomeByAgentExVat($deal);

            // Declined deals: income = 0 to match legacy business rule
            // (commission captured may still be non-zero in DB, but declined = no payout)
            if ($stage === 'declined') {
                $byAgent = array_map(fn ($v) => 0.0, $byAgent);
            }

            $propertyValue      = (float)($deal->property_value ?? 0);
            $agentCount         = max(1, count($byAgent));
            $propertyValueShare = round($propertyValue / $agentCount, 4);

            // ---- Company rollup ----
            // income = agent_income (sum of agent shares), company_income = full side pool (CommissionCalculator)
            $dealFullCompanyIncome = (float) CommissionCalculator::companyIncomeExVat($deal);
            if ($stage === 'declined') {
                $dealFullCompanyIncome = 0.0;
            }
            $dealAgentIncomeTotal = round(array_sum($byAgent), 2);
            $dealRetainedTotal    = round($dealFullCompanyIncome - $dealAgentIncomeTotal, 2);

            if ($doCompany) {
                $companyData[$stage]['count']++;
                $companyData[$stage]['income']          = round($companyData[$stage]['income'] + $dealAgentIncomeTotal, 2);
                $companyData[$stage]['company_income']  = round($companyData[$stage]['company_income'] + $dealFullCompanyIncome, 2);
                $companyData[$stage]['retained']        = round($companyData[$stage]['retained'] + $dealRetainedTotal, 2);
                $companyData[$stage]['property_value']  = round($companyData[$stage]['property_value'] + $propertyValue, 2);
                $companyData[$stage]['deals'][$deal->id] = $dealAgentIncomeTotal;
            }

            // ---- Build uid→branch_id map (one lookup per agent, regardless of pivot row count) ----
            $agentBranchMap = [];
            foreach ($deal->agents as $agent) {
                $uid = (int) $agent->id;
                if (!isset($agentBranchMap[$uid])) {
                    $agentBranchMap[$uid] = (int) ($agent->branch_id ?? 0);
                }
            }

            // ---- Agent + branch rollup — iterate byAgent keys (already deduplicated per agent) ----
            // $byAgent[uid] = combined income across all sides for this deal; must only be added once.
            $branchDealIncome         = []; // [branch_id => agent_income for this deal]
            $branchDealRetained       = []; // [branch_id => retained for this deal]
            $branchDealCompanyIncome  = []; // [branch_id => company_income for this deal]
            $branchDealPropertyValue  = []; // [branch_id => property_value_share for this deal]

            // Retained per agent — canonical computation via CommissionCalculator.
            $byAgentRetained = ($doAgent || $doBranch) ? CommissionCalculator::dealRetainedByAgentExVat($deal) : [];
            if (($doAgent || $doBranch) && $stage === 'declined') {
                $byAgentRetained = array_map(fn($v) => 0.0, $byAgentRetained);
            }

            foreach ($byAgent as $uid => $agentInc) {
                $uid      = (int) $uid;
                $branchId = $agentBranchMap[$uid] ?? 0;

                // ---- Agent rollup ----
                if ($doAgent) {
                    if (!isset($agentData[$uid])) {
                        foreach ($stages as $s) {
                            $agentData[$uid][$s] = ['count' => 0, 'income' => 0.0, 'retained' => 0.0, 'property_value' => 0.0, 'deals' => []];
                        }
                    }
                    if (!isset($agentData[$uid][$stage]['deals'][$deal->id])) {
                        $agentData[$uid][$stage]['count']++;
                    }
                    $agentData[$uid][$stage]['deals'][$deal->id] = $agentInc;
                    $agentData[$uid][$stage]['income']         = round($agentData[$uid][$stage]['income'] + $agentInc, 2);
                    $agentData[$uid][$stage]['retained']       = round($agentData[$uid][$stage]['retained'] + ($byAgentRetained[$uid] ?? 0.0), 2);
                    $agentData[$uid][$stage]['property_value'] = round($agentData[$uid][$stage]['property_value'] + $propertyValueShare, 2);
                }

                // ---- Branch accumulation (aggregate all agents per branch per deal) ----
                if ($doBranch && $branchId > 0) {
                    $agentRetained = (float)($byAgentRetained[$uid] ?? 0.0);
                    $branchDealIncome[$branchId]        = round(($branchDealIncome[$branchId]        ?? 0.0) + $agentInc, 2);
                    $branchDealRetained[$branchId]      = round(($branchDealRetained[$branchId]      ?? 0.0) + $agentRetained, 2);
                    $branchDealCompanyIncome[$branchId]  = round(($branchDealCompanyIncome[$branchId]  ?? 0.0) + $agentInc + $agentRetained, 2);
                    $branchDealPropertyValue[$branchId] = round(($branchDealPropertyValue[$branchId] ?? 0.0) + $propertyValueShare, 2);
                }
            }

            // ---- Apply branch income for this deal ----
            if ($doBranch) {
                foreach ($branchDealIncome as $branchId => $income) {
                    if (!isset($branchData[$branchId])) {
                        foreach ($stages as $s) {
                            $branchData[$branchId][$s] = ['count' => 0, 'income' => 0.0, 'company_income' => 0.0, 'retained' => 0.0, 'property_value' => 0.0, 'deals' => []];
                        }
                    }
                    // Count each deal once per branch
                    if (!isset($branchData[$branchId][$stage]['deals'][$deal->id])) {
                        $branchData[$branchId][$stage]['count']++;
                    }
                    $prev = $branchData[$branchId][$stage]['deals'][$deal->id] ?? 0.0;
                    $branchData[$branchId][$stage]['deals'][$deal->id] = round($prev + $income, 2);
                    $branchData[$branchId][$stage]['income']          = round($branchData[$branchId][$stage]['income'] + $income, 2);
                    $branchData[$branchId][$stage]['company_income']  = round($branchData[$branchId][$stage]['company_income'] + ($branchDealCompanyIncome[$branchId] ?? 0.0), 2);
                    $branchData[$branchId][$stage]['retained']        = round($branchData[$branchId][$stage]['retained'] + ($branchDealRetained[$branchId] ?? 0.0), 2);
                    $branchData[$branchId][$stage]['property_value']  = round($branchData[$branchId][$stage]['property_value'] + ($branchDealPropertyValue[$branchId] ?? 0.0), 2);
                }
            }

            // ---- Ledger accumulation (by deal.branch_id — accounting truth) ----
            $dealBranchId = (int)($deal->branch_id ?? 0);
            if ($dealBranchId > 0) {
                if (!isset($ledgerData[$dealBranchId])) {
                    foreach ($stages as $s) {
                        $ledgerData[$dealBranchId][$s] = ['count' => 0, 'company_income' => 0.0, 'agent_income' => 0.0, 'deals' => []];
                    }
                }
                if (!isset($ledgerData[$dealBranchId][$stage]['deals'][$deal->id])) {
                    $ledgerData[$dealBranchId][$stage]['count']++;
                }
                $ledgerData[$dealBranchId][$stage]['company_income'] = round($ledgerData[$dealBranchId][$stage]['company_income'] + $dealFullCompanyIncome, 2);
                $ledgerData[$dealBranchId][$stage]['agent_income']   = round($ledgerData[$dealBranchId][$stage]['agent_income'] + $dealAgentIncomeTotal, 2);
                $ledgerData[$dealBranchId][$stage]['deals'][$deal->id] = $dealFullCompanyIncome;
            }
        }

        // ---- Write per-stage audit items + upsert computed_values for agent_period ----
        if ($doAgent) {
            $agentDefs = $this->ensureAgentPeriodDefinitions($stages);
            foreach ($agentData as $uid => $stageBuckets) {
                foreach ($stageBuckets as $stage => $data) {
                    $breakdown = $this->dealsToBreakdown($data['deals']);
                    $this->writeNumericItem($run, 'agent_period', $uid, $period,
                        "agent_period.deals.{$stage}.count", (float) $data['count']);
                    $this->writeNumericItem($run, 'agent_period', $uid, $period,
                        "agent_period.money.{$stage}.agent_income_ex_vat", $data['income']);
                    $this->writeJsonItem($run, 'agent_period', $uid, $period,
                        "agent_period.money.{$stage}.breakdown_json", $breakdown);

                    $this->upsertComputedValue($run, $agentDefs, 'agent_period', $uid, $period,
                        "agent_period.deals.{$stage}.count", (float) $data['count'], null);
                    $this->upsertComputedValue($run, $agentDefs, 'agent_period', $uid, $period,
                        "agent_period.money.{$stage}.agent_income_ex_vat", $data['income'], null);
                    $this->upsertComputedValue($run, $agentDefs, 'agent_period', $uid, $period,
                        "agent_period.money.{$stage}.breakdown_json", null, $breakdown);

                    // company_income = income + retained (full side pool before agent/company split)
                    $stageCompanyIncome = round($data['income'] + ($data['retained'] ?? 0.0), 2);
                    $this->writeNumericItem($run, 'agent_period', $uid, $period,
                        "agent_period.money.{$stage}.company_income_ex_vat", $stageCompanyIncome);
                    $this->upsertComputedValue($run, $agentDefs, 'agent_period', $uid, $period,
                        "agent_period.money.{$stage}.company_income_ex_vat", $stageCompanyIncome, null);
                }
            }
        }

        if ($doBranch) {
            $branchDefs = $this->ensureBranchPeriodDefinitions($stages);
            foreach ($branchData as $branchId => $stageBuckets) {
                foreach ($stageBuckets as $stage => $data) {
                    $breakdown = $this->dealsToBreakdown($data['deals']);
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.deals.{$stage}.count", (float) $data['count']);
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.team_agent_income_ex_vat", $data['income']);
                    $this->writeJsonItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.breakdown_json", $breakdown);

                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.deals.{$stage}.count", (float) $data['count'], null);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.team_agent_income_ex_vat", $data['income'], null);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.breakdown_json", null, $breakdown);

                    // Team company_income and retained
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.team_company_income_ex_vat", $data['company_income']);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.team_company_income_ex_vat", $data['company_income'], null);
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.team_company_retained_ex_vat", $data['retained']);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.team_company_retained_ex_vat", $data['retained'], null);
                }
            }
        }

        // ---- Write per-stage ledger keys (by deal.branch_id) into branch_period ----
        if ($doBranch && !empty($ledgerData)) {
            $branchDefs = $branchDefs ?? $this->ensureBranchPeriodDefinitions($stages);
            foreach ($ledgerData as $branchId => $stageBuckets) {
                foreach ($stageBuckets as $stage => $data) {
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.ledger_company_income_ex_vat", $data['company_income']);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.ledger_company_income_ex_vat", $data['company_income'], null);
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.ledger_agent_income_ex_vat", $data['agent_income']);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.ledger_agent_income_ex_vat", $data['agent_income'], null);

                    $ledgerRetained = round($data['company_income'] - $data['agent_income'], 2);
                    $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.ledger_company_retained_ex_vat", $ledgerRetained);
                    $this->upsertComputedValue($run, $branchDefs, 'branch_period', $branchId, $period,
                        "branch_period.money.{$stage}.ledger_company_retained_ex_vat", $ledgerRetained, null);
                }
            }
        }

        if ($doCompany) {
            $companyDefs = $this->ensureCompanyPeriodDefinitions($stages);
            foreach ($companyData as $stage => $data) {
                $breakdown = $this->dealsToBreakdown($data['deals']);
                $this->writeNumericItem($run, 'company_period', 1, $period,
                    "company_period.deals.{$stage}.count", (float) $data['count']);
                $this->writeNumericItem($run, 'company_period', 1, $period,
                    "company_period.money.{$stage}.team_agent_income_ex_vat", $data['income']);
                $this->writeJsonItem($run, 'company_period', 1, $period,
                    "company_period.money.{$stage}.breakdown_json", $breakdown);

                $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                    "company_period.deals.{$stage}.count", (float) $data['count'], null);
                $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                    "company_period.money.{$stage}.team_agent_income_ex_vat", $data['income'], null);
                $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                    "company_period.money.{$stage}.breakdown_json", null, $breakdown);

                // Company-level company_income and retained per-stage
                $this->writeNumericItem($run, 'company_period', 1, $period,
                    "company_period.money.{$stage}.team_company_income_ex_vat", $data['company_income']);
                $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                    "company_period.money.{$stage}.team_company_income_ex_vat", $data['company_income'], null);
                $this->writeNumericItem($run, 'company_period', 1, $period,
                    "company_period.money.{$stage}.team_company_retained_ex_vat", $data['retained']);
                $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                    "company_period.money.{$stage}.team_company_retained_ex_vat", $data['retained'], null);
            }

            // ---- Company-level ledger per-stage (aggregate ledgerData across all branches) ----
            if (!empty($ledgerData)) {
                foreach ($stages as $stage) {
                    $compLedgerCompanyIncome = 0.0;
                    $compLedgerAgentIncome   = 0.0;
                    foreach ($ledgerData as $branchId => $lBuckets) {
                        $compLedgerCompanyIncome = round($compLedgerCompanyIncome + ($lBuckets[$stage]['company_income'] ?? 0.0), 2);
                        $compLedgerAgentIncome   = round($compLedgerAgentIncome + ($lBuckets[$stage]['agent_income'] ?? 0.0), 2);
                    }
                    $this->writeNumericItem($run, 'company_period', 1, $period,
                        "company_period.money.{$stage}.ledger_company_income_ex_vat", $compLedgerCompanyIncome);
                    $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                        "company_period.money.{$stage}.ledger_company_income_ex_vat", $compLedgerCompanyIncome, null);
                    $this->writeNumericItem($run, 'company_period', 1, $period,
                        "company_period.money.{$stage}.ledger_agent_income_ex_vat", $compLedgerAgentIncome);
                    $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                        "company_period.money.{$stage}.ledger_agent_income_ex_vat", $compLedgerAgentIncome, null);

                    $compLedgerRetained = round($compLedgerCompanyIncome - $compLedgerAgentIncome, 2);
                    $this->writeNumericItem($run, 'company_period', 1, $period,
                        "company_period.money.{$stage}.ledger_company_retained_ex_vat", $compLedgerRetained);
                    $this->upsertComputedValue($run, $companyDefs, 'company_period', 1, $period,
                        "company_period.money.{$stage}.ledger_company_retained_ex_vat", $compLedgerRetained, null);
                }
            }
        }

        // ---- Legacy comparison items (non-declined total vs legacy deal_date-based totals) ----
        // Legacy does not track per-stage — compare aggregate of non-declined stages to legacy total.

        if ($doAgent && !empty($agentData)) {
            $legacyAgentMap = (new AgentRollupLegacyReader())->buildForPeriod($period);
            $agentDefsForTotal = $agentDefs ?? $this->ensureAgentPeriodDefinitions($stages);
            foreach ($agentData as $uid => $stageBuckets) {
                $engineTotal   = 0.0;
                $retainedTotal = 0.0;
                foreach ($stageBuckets as $stage => $data) {
                    if ($stage !== 'declined') {
                        $engineTotal   = round($engineTotal   + $data['income'],                   2);
                        $retainedTotal = round($retainedTotal + ($data['retained'] ?? 0.0),        2);
                    }
                }
                $legacyEntry = $legacyAgentMap[$uid] ?? null;
                $legacyTotal = $legacyEntry !== null ? (float) $legacyEntry['agent_income_ex_vat'] : null;
                $this->writeComparisonItem($run, 'agent_period', $uid, $period,
                    'agent_period.money.total_nondeclined.agent_income_ex_vat',
                    $engineTotal, $legacyTotal);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.money.total_nondeclined.agent_income_ex_vat', $engineTotal, null);
                $this->writeNumericItem($run, 'agent_period', $uid, $period,
                    'agent_period.money.total_nondeclined.retained_ex_vat', $retainedTotal);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.money.total_nondeclined.retained_ex_vat', $retainedTotal, null);

                // total_nondeclined company_income (income + retained)
                $companyIncomeTotal = round($engineTotal + $retainedTotal, 2);
                $this->writeNumericItem($run, 'agent_period', $uid, $period,
                    'agent_period.money.total_nondeclined.company_income_ex_vat', $companyIncomeTotal);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.money.total_nondeclined.company_income_ex_vat', $companyIncomeTotal, null);

                // total_nondeclined deal count
                $nondeclinedDealCount = 0.0;
                foreach ($stageBuckets as $cntStage => $cntData) {
                    if ($cntStage !== 'declined') {
                        $nondeclinedDealCount += $cntData['count'];
                    }
                }
                $this->writeNumericItem($run, 'agent_period', $uid, $period,
                    'agent_period.deals.total_nondeclined.count', $nondeclinedDealCount);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.deals.total_nondeclined.count', $nondeclinedDealCount, null);

                $regGrantedTotal = round(
                    ($stageBuckets['registered']['income'] ?? 0.0) +
                    ($stageBuckets['granted']['income']    ?? 0.0),
                    2
                );
                $this->writeNumericItem($run, 'agent_period', $uid, $period,
                    'agent_period.money.registered_granted.agent_income_ex_vat', $regGrantedTotal);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.money.registered_granted.agent_income_ex_vat', $regGrantedTotal, null);

                // Property value compound totals (inc VAT, shared attribution)
                $nondeclinedPv = 0.0;
                foreach ($stageBuckets as $pvStage => $pvData) {
                    if ($pvStage !== 'declined') {
                        $nondeclinedPv = round($nondeclinedPv + ($pvData['property_value'] ?? 0.0), 2);
                    }
                }
                $regGrantedPv = round(
                    ($stageBuckets['registered']['property_value'] ?? 0.0) +
                    ($stageBuckets['granted']['property_value']    ?? 0.0),
                    2
                );
                $this->writeNumericItem($run, 'agent_period', $uid, $period,
                    'agent_period.value.total_nondeclined.property_value_inc_vat', $nondeclinedPv);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.value.total_nondeclined.property_value_inc_vat', $nondeclinedPv, null);
                $this->writeNumericItem($run, 'agent_period', $uid, $period,
                    'agent_period.value.registered_granted.property_value_inc_vat', $regGrantedPv);
                $this->upsertComputedValue($run, $agentDefsForTotal, 'agent_period', $uid, $period,
                    'agent_period.value.registered_granted.property_value_inc_vat', $regGrantedPv, null);
            }
        }

        if ($doBranch && !empty($branchData)) {
            $legacyBranchMap = (new BranchRollupLegacyReader())->buildForPeriod($period);
            $branchDefsForTotal = $branchDefs ?? $this->ensureBranchPeriodDefinitions($stages);
            foreach ($branchData as $branchId => $stageBuckets) {
                $engineTotal = 0.0;
                foreach ($stageBuckets as $stage => $data) {
                    if ($stage !== 'declined') {
                        $engineTotal = round($engineTotal + $data['income'], 2);
                    }
                }
                $legacyEntry = $legacyBranchMap[$branchId] ?? null;
                $legacyTotal = $legacyEntry !== null ? (float) $legacyEntry['team_agent_income_ex_vat'] : null;
                $this->writeComparisonItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.team_agent_income_ex_vat',
                    $engineTotal, $legacyTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.team_agent_income_ex_vat', $engineTotal, null);
                $regGrantedTotal = round(
                    ($stageBuckets['registered']['income'] ?? 0.0) +
                    ($stageBuckets['granted']['income']    ?? 0.0),
                    2
                );
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.registered_granted.team_agent_income_ex_vat', $regGrantedTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.registered_granted.team_agent_income_ex_vat', $regGrantedTotal, null);

                // Property value compound totals (inc VAT)
                $nondeclinedPvBranch = 0.0;
                foreach ($stageBuckets as $pvStage => $pvData) {
                    if ($pvStage !== 'declined') {
                        $nondeclinedPvBranch = round($nondeclinedPvBranch + ($pvData['property_value'] ?? 0.0), 2);
                    }
                }
                $regGrantedPvBranch = round(
                    ($stageBuckets['registered']['property_value'] ?? 0.0) +
                    ($stageBuckets['granted']['property_value']    ?? 0.0),
                    2
                );
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.value.total_nondeclined.property_value_inc_vat', $nondeclinedPvBranch);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.value.total_nondeclined.property_value_inc_vat', $nondeclinedPvBranch, null);
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.value.registered_granted.property_value_inc_vat', $regGrantedPvBranch);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.value.registered_granted.property_value_inc_vat', $regGrantedPvBranch, null);

                // total_nondeclined team company_income and retained
                $branchCompanyIncomeTotal = 0.0;
                $branchRetainedTotal      = 0.0;
                $branchNondeclinedCount   = 0.0;
                foreach ($stageBuckets as $ndStage => $ndData) {
                    if ($ndStage !== 'declined') {
                        $branchCompanyIncomeTotal = round($branchCompanyIncomeTotal + ($ndData['company_income'] ?? 0.0), 2);
                        $branchRetainedTotal      = round($branchRetainedTotal + ($ndData['retained'] ?? 0.0), 2);
                        $branchNondeclinedCount  += $ndData['count'];
                    }
                }
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.team_company_income_ex_vat', $branchCompanyIncomeTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.team_company_income_ex_vat', $branchCompanyIncomeTotal, null);
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.team_company_retained_ex_vat', $branchRetainedTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.team_company_retained_ex_vat', $branchRetainedTotal, null);
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.deals.total_nondeclined.count', $branchNondeclinedCount);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.deals.total_nondeclined.count', $branchNondeclinedCount, null);

                // total_nondeclined ledger keys for this branch
                $ledgerBranchBuckets = $ledgerData[$branchId] ?? [];
                $branchLedgerCompanyIncomeTotal = 0.0;
                $branchLedgerAgentIncomeTotal   = 0.0;
                foreach ($ledgerBranchBuckets as $lStage => $lData) {
                    if ($lStage !== 'declined') {
                        $branchLedgerCompanyIncomeTotal = round($branchLedgerCompanyIncomeTotal + ($lData['company_income'] ?? 0.0), 2);
                        $branchLedgerAgentIncomeTotal   = round($branchLedgerAgentIncomeTotal + ($lData['agent_income'] ?? 0.0), 2);
                    }
                }
                $branchLedgerRetainedTotal = round($branchLedgerCompanyIncomeTotal - $branchLedgerAgentIncomeTotal, 2);
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.ledger_company_income_ex_vat', $branchLedgerCompanyIncomeTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.ledger_company_income_ex_vat', $branchLedgerCompanyIncomeTotal, null);
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.ledger_agent_income_ex_vat', $branchLedgerAgentIncomeTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.ledger_agent_income_ex_vat', $branchLedgerAgentIncomeTotal, null);
                $this->writeNumericItem($run, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.ledger_company_retained_ex_vat', $branchLedgerRetainedTotal);
                $this->upsertComputedValue($run, $branchDefsForTotal, 'branch_period', $branchId, $period,
                    'branch_period.money.total_nondeclined.ledger_company_retained_ex_vat', $branchLedgerRetainedTotal, null);
            }
        }

        if ($doCompany) {
            $legacyCompany = (new CompanyRollupLegacyReader())->buildForPeriod($period);
            $companyDefsForTotal = $companyDefs ?? $this->ensureCompanyPeriodDefinitions($stages);
            $engineTotal   = 0.0;
            foreach ($companyData as $stage => $data) {
                if ($stage !== 'declined') {
                    $engineTotal = round($engineTotal + $data['income'], 2);
                }
            }
            $legacyTotal = isset($legacyCompany['team_agent_income_ex_vat'])
                ? (float) $legacyCompany['team_agent_income_ex_vat']
                : null;
            $this->writeComparisonItem($run, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.team_agent_income_ex_vat',
                $engineTotal, $legacyTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.team_agent_income_ex_vat', $engineTotal, null);
            $regGrantedTotal = round(
                ($companyData['registered']['income'] ?? 0.0) +
                ($companyData['granted']['income']    ?? 0.0),
                2
            );
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.money.registered_granted.team_agent_income_ex_vat', $regGrantedTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.registered_granted.team_agent_income_ex_vat', $regGrantedTotal, null);

            // Property value compound totals (inc VAT)
            $nondeclinedPvCompany = 0.0;
            foreach ($companyData as $pvStage => $pvData) {
                if ($pvStage !== 'declined') {
                    $nondeclinedPvCompany = round($nondeclinedPvCompany + ($pvData['property_value'] ?? 0.0), 2);
                }
            }
            $regGrantedPvCompany = round(
                ($companyData['registered']['property_value'] ?? 0.0) +
                ($companyData['granted']['property_value']    ?? 0.0),
                2
            );
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.value.total_nondeclined.property_value_inc_vat', $nondeclinedPvCompany);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.value.total_nondeclined.property_value_inc_vat', $nondeclinedPvCompany, null);
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.value.registered_granted.property_value_inc_vat', $regGrantedPvCompany);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.value.registered_granted.property_value_inc_vat', $regGrantedPvCompany, null);

            // total_nondeclined company_income, retained, count
            $companyCompanyIncomeTotal = 0.0;
            $companyRetainedTotal      = 0.0;
            $companyNondeclinedCount   = 0.0;
            foreach ($companyData as $ndStage => $ndData) {
                if ($ndStage !== 'declined') {
                    $companyCompanyIncomeTotal = round($companyCompanyIncomeTotal + ($ndData['company_income'] ?? 0.0), 2);
                    $companyRetainedTotal      = round($companyRetainedTotal + ($ndData['retained'] ?? 0.0), 2);
                    $companyNondeclinedCount  += $ndData['count'];
                }
            }
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.team_company_income_ex_vat', $companyCompanyIncomeTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.team_company_income_ex_vat', $companyCompanyIncomeTotal, null);
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.team_company_retained_ex_vat', $companyRetainedTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.team_company_retained_ex_vat', $companyRetainedTotal, null);
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.deals.total_nondeclined.count', $companyNondeclinedCount);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.deals.total_nondeclined.count', $companyNondeclinedCount, null);

            // total_nondeclined ledger keys (aggregate across all branches)
            $companyLedgerCompanyIncomeTotal = 0.0;
            $companyLedgerAgentIncomeTotal   = 0.0;
            foreach ($ledgerData as $lBranchId => $lBuckets) {
                foreach ($lBuckets as $lStage => $lData) {
                    if ($lStage !== 'declined') {
                        $companyLedgerCompanyIncomeTotal = round($companyLedgerCompanyIncomeTotal + ($lData['company_income'] ?? 0.0), 2);
                        $companyLedgerAgentIncomeTotal   = round($companyLedgerAgentIncomeTotal + ($lData['agent_income'] ?? 0.0), 2);
                    }
                }
            }
            $companyLedgerRetainedTotal = round($companyLedgerCompanyIncomeTotal - $companyLedgerAgentIncomeTotal, 2);
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.ledger_company_income_ex_vat', $companyLedgerCompanyIncomeTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.ledger_company_income_ex_vat', $companyLedgerCompanyIncomeTotal, null);
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.ledger_agent_income_ex_vat', $companyLedgerAgentIncomeTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.ledger_agent_income_ex_vat', $companyLedgerAgentIncomeTotal, null);
            $this->writeNumericItem($run, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.ledger_company_retained_ex_vat', $companyLedgerRetainedTotal);
            $this->upsertComputedValue($run, $companyDefsForTotal, 'company_period', 1, $period,
                'company_period.money.total_nondeclined.ledger_company_retained_ex_vat', $companyLedgerRetainedTotal, null);
        }
    }

    /** Convert [deal_id => amount] map to the breakdown array format. */
    private function dealsToBreakdown(array $deals): array
    {
        $breakdown = [];
        foreach ($deals as $dealId => $amount) {
            $breakdown[] = ['deal_id' => (int) $dealId, 'amount_ex_vat' => (float) $amount];
        }
        return $breakdown;
    }

    private function writeNumericItem(
        FinanceAuditRun $run,
        string $entityType,
        int $entityId,
        string $period,
        string $definitionKey,
        float $value
    ): void {
        FinanceAuditItem::create([
            'agency_id'        => $this->agencyId,
            'audit_run_id'     => $run->id,
            'definition_key'   => $definitionKey,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'period'           => $period,
            'expected_numeric' => $value,
            'actual_numeric'   => null,
            'diff_numeric'     => null,
            'severity'         => 'info',
            'message'          => 'no per-stage legacy comparison',
        ]);
    }

    private function writeJsonItem(
        FinanceAuditRun $run,
        string $entityType,
        int $entityId,
        string $period,
        string $definitionKey,
        array $breakdown
    ): void {
        FinanceAuditItem::create([
            'agency_id'      => $this->agencyId,
            'audit_run_id'   => $run->id,
            'definition_key' => $definitionKey,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'period'         => $period,
            'expected_json'  => $breakdown,
            'severity'       => 'info',
            'message'        => 'breakdown',
        ]);
    }

    /**
     * Ensure finance_definitions rows exist for all agent_period rollup keys.
     * Returns [definition_key => FinanceDefinition] cache for this run.
     */
    private function ensureAgentPeriodDefinitions(array $stages): array
    {
        $defs = [];
        foreach ($stages as $stage) {
            $defs["agent_period.deals.{$stage}.count"] = FinanceEngine::ensureDefinition(
                "agent_period.deals.{$stage}.count", 'agent_period', 'count',
                "Agent deals in {$stage} stage — count"
            );
            $defs["agent_period.money.{$stage}.agent_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "agent_period.money.{$stage}.agent_income_ex_vat", 'agent_period', 'money_ex_vat',
                "Agent income ex VAT — {$stage} stage"
            );
            $defs["agent_period.money.{$stage}.breakdown_json"] = FinanceEngine::ensureDefinition(
                "agent_period.money.{$stage}.breakdown_json", 'agent_period', 'json',
                "Agent income breakdown by deal — {$stage} stage"
            );
            $defs["agent_period.money.{$stage}.company_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "agent_period.money.{$stage}.company_income_ex_vat", 'agent_period', 'money_ex_vat',
                "Agent company income ex VAT (income + retained) — {$stage} stage"
            );
        }
        $defs['agent_period.money.total_nondeclined.agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'agent_period.money.total_nondeclined.agent_income_ex_vat', 'agent_period', 'money_ex_vat',
            'Agent income ex VAT — total non-declined'
        );
        $defs['agent_period.money.total_nondeclined.retained_ex_vat'] = FinanceEngine::ensureDefinition(
            'agent_period.money.total_nondeclined.retained_ex_vat', 'agent_period', 'money_ex_vat',
            'Company retained ex VAT from agent deals — total non-declined'
        );
        $defs['agent_period.money.total_nondeclined.company_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'agent_period.money.total_nondeclined.company_income_ex_vat', 'agent_period', 'money_ex_vat',
            'Agent company income ex VAT (income + retained) — total non-declined'
        );
        $defs['agent_period.deals.total_nondeclined.count'] = FinanceEngine::ensureDefinition(
            'agent_period.deals.total_nondeclined.count', 'agent_period', 'count',
            'Agent deals — total non-declined count'
        );
        $defs['agent_period.money.registered_granted.agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'agent_period.money.registered_granted.agent_income_ex_vat', 'agent_period', 'money_ex_vat',
            'Agent income ex VAT — registered + granted stages combined'
        );
        $defs['agent_period.value.total_nondeclined.property_value_inc_vat'] = FinanceEngine::ensureDefinition(
            'agent_period.value.total_nondeclined.property_value_inc_vat', 'agent_period', 'money_inc_vat',
            'Agent property value share (inc VAT) — total non-declined'
        );
        $defs['agent_period.value.registered_granted.property_value_inc_vat'] = FinanceEngine::ensureDefinition(
            'agent_period.value.registered_granted.property_value_inc_vat', 'agent_period', 'money_inc_vat',
            'Agent property value share (inc VAT) — registered + granted stages combined'
        );
        return $defs;
    }

    /**
     * Ensure finance_definitions rows exist for all branch_period rollup keys.
     * Returns [definition_key => FinanceDefinition] cache for this run.
     */
    private function ensureBranchPeriodDefinitions(array $stages): array
    {
        $defs = [];
        foreach ($stages as $stage) {
            $defs["branch_period.deals.{$stage}.count"] = FinanceEngine::ensureDefinition(
                "branch_period.deals.{$stage}.count", 'branch_period', 'count',
                "Branch deals in {$stage} stage — count"
            );
            $defs["branch_period.money.{$stage}.team_agent_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.team_agent_income_ex_vat", 'branch_period', 'money_ex_vat',
                "Branch team agent income ex VAT — {$stage} stage"
            );
            $defs["branch_period.money.{$stage}.breakdown_json"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.breakdown_json", 'branch_period', 'json',
                "Branch income breakdown by deal — {$stage} stage"
            );
            $defs["branch_period.money.{$stage}.team_company_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.team_company_income_ex_vat", 'branch_period', 'money_ex_vat',
                "Branch team company income ex VAT — {$stage} stage"
            );
            $defs["branch_period.money.{$stage}.team_company_retained_ex_vat"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.team_company_retained_ex_vat", 'branch_period', 'money_ex_vat',
                "Branch team company retained ex VAT — {$stage} stage"
            );
            $defs["branch_period.money.{$stage}.ledger_company_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.ledger_company_income_ex_vat", 'branch_period', 'money_ex_vat',
                "Branch ledger company income ex VAT — {$stage} stage"
            );
            $defs["branch_period.money.{$stage}.ledger_agent_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.ledger_agent_income_ex_vat", 'branch_period', 'money_ex_vat',
                "Branch ledger agent income ex VAT — {$stage} stage"
            );
            $defs["branch_period.money.{$stage}.ledger_company_retained_ex_vat"] = FinanceEngine::ensureDefinition(
                "branch_period.money.{$stage}.ledger_company_retained_ex_vat", 'branch_period', 'money_ex_vat',
                "Branch ledger company retained ex VAT — {$stage} stage"
            );
        }
        $defs['branch_period.money.total_nondeclined.team_agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.total_nondeclined.team_agent_income_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch team agent income ex VAT — total non-declined'
        );
        $defs['branch_period.money.registered_granted.team_agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.registered_granted.team_agent_income_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch team agent income ex VAT — registered + granted stages combined'
        );
        $defs['branch_period.money.total_nondeclined.team_company_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.total_nondeclined.team_company_income_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch team company income ex VAT — total non-declined'
        );
        $defs['branch_period.money.total_nondeclined.team_company_retained_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.total_nondeclined.team_company_retained_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch team company retained ex VAT — total non-declined'
        );
        $defs['branch_period.deals.total_nondeclined.count'] = FinanceEngine::ensureDefinition(
            'branch_period.deals.total_nondeclined.count', 'branch_period', 'count',
            'Branch deals — total non-declined count'
        );
        $defs['branch_period.money.total_nondeclined.ledger_company_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.total_nondeclined.ledger_company_income_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch ledger company income ex VAT — total non-declined'
        );
        $defs['branch_period.money.total_nondeclined.ledger_agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.total_nondeclined.ledger_agent_income_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch ledger agent income ex VAT — total non-declined'
        );
        $defs['branch_period.money.total_nondeclined.ledger_company_retained_ex_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.money.total_nondeclined.ledger_company_retained_ex_vat', 'branch_period', 'money_ex_vat',
            'Branch ledger company retained ex VAT — total non-declined'
        );
        $defs['branch_period.value.total_nondeclined.property_value_inc_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.value.total_nondeclined.property_value_inc_vat', 'branch_period', 'money_inc_vat',
            'Branch property value (inc VAT) — total non-declined'
        );
        $defs['branch_period.value.registered_granted.property_value_inc_vat'] = FinanceEngine::ensureDefinition(
            'branch_period.value.registered_granted.property_value_inc_vat', 'branch_period', 'money_inc_vat',
            'Branch property value (inc VAT) — registered + granted stages combined'
        );
        return $defs;
    }

    /**
     * Ensure finance_definitions rows exist for all company_period rollup keys.
     * Returns [definition_key => FinanceDefinition] cache for this run.
     */
    private function ensureCompanyPeriodDefinitions(array $stages): array
    {
        $defs = [];
        foreach ($stages as $stage) {
            $defs["company_period.deals.{$stage}.count"] = FinanceEngine::ensureDefinition(
                "company_period.deals.{$stage}.count", 'company_period', 'count',
                "Company deals in {$stage} stage — count"
            );
            $defs["company_period.money.{$stage}.team_agent_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.team_agent_income_ex_vat", 'company_period', 'money_ex_vat',
                "Company team agent income ex VAT — {$stage} stage"
            );
            $defs["company_period.money.{$stage}.breakdown_json"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.breakdown_json", 'company_period', 'json',
                "Company income breakdown by deal — {$stage} stage"
            );
            $defs["company_period.money.{$stage}.team_company_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.team_company_income_ex_vat", 'company_period', 'money_ex_vat',
                "Company team company income ex VAT — {$stage} stage"
            );
            $defs["company_period.money.{$stage}.team_company_retained_ex_vat"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.team_company_retained_ex_vat", 'company_period', 'money_ex_vat',
                "Company team company retained ex VAT — {$stage} stage"
            );
            $defs["company_period.money.{$stage}.ledger_company_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.ledger_company_income_ex_vat", 'company_period', 'money_ex_vat',
                "Company ledger company income ex VAT — {$stage} stage"
            );
            $defs["company_period.money.{$stage}.ledger_agent_income_ex_vat"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.ledger_agent_income_ex_vat", 'company_period', 'money_ex_vat',
                "Company ledger agent income ex VAT — {$stage} stage"
            );
            $defs["company_period.money.{$stage}.ledger_company_retained_ex_vat"] = FinanceEngine::ensureDefinition(
                "company_period.money.{$stage}.ledger_company_retained_ex_vat", 'company_period', 'money_ex_vat',
                "Company ledger company retained ex VAT — {$stage} stage"
            );
        }
        $defs['company_period.money.total_nondeclined.team_agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.total_nondeclined.team_agent_income_ex_vat', 'company_period', 'money_ex_vat',
            'Company team agent income ex VAT — total non-declined'
        );
        $defs['company_period.money.registered_granted.team_agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.registered_granted.team_agent_income_ex_vat', 'company_period', 'money_ex_vat',
            'Company team agent income ex VAT — registered + granted stages combined'
        );
        $defs['company_period.money.total_nondeclined.team_company_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.total_nondeclined.team_company_income_ex_vat', 'company_period', 'money_ex_vat',
            'Company team company income ex VAT — total non-declined'
        );
        $defs['company_period.money.total_nondeclined.team_company_retained_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.total_nondeclined.team_company_retained_ex_vat', 'company_period', 'money_ex_vat',
            'Company team company retained ex VAT — total non-declined'
        );
        $defs['company_period.deals.total_nondeclined.count'] = FinanceEngine::ensureDefinition(
            'company_period.deals.total_nondeclined.count', 'company_period', 'count',
            'Company deals — total non-declined count'
        );
        $defs['company_period.money.total_nondeclined.ledger_company_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.total_nondeclined.ledger_company_income_ex_vat', 'company_period', 'money_ex_vat',
            'Company ledger company income ex VAT — total non-declined'
        );
        $defs['company_period.money.total_nondeclined.ledger_agent_income_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.total_nondeclined.ledger_agent_income_ex_vat', 'company_period', 'money_ex_vat',
            'Company ledger agent income ex VAT — total non-declined'
        );
        $defs['company_period.money.total_nondeclined.ledger_company_retained_ex_vat'] = FinanceEngine::ensureDefinition(
            'company_period.money.total_nondeclined.ledger_company_retained_ex_vat', 'company_period', 'money_ex_vat',
            'Company ledger company retained ex VAT — total non-declined'
        );
        $defs['company_period.value.total_nondeclined.property_value_inc_vat'] = FinanceEngine::ensureDefinition(
            'company_period.value.total_nondeclined.property_value_inc_vat', 'company_period', 'money_inc_vat',
            'Company property value (inc VAT) — total non-declined'
        );
        $defs['company_period.value.registered_granted.property_value_inc_vat'] = FinanceEngine::ensureDefinition(
            'company_period.value.registered_granted.property_value_inc_vat', 'company_period', 'money_inc_vat',
            'Company property value (inc VAT) — registered + granted stages combined'
        );
        return $defs;
    }

    /**
     * Upsert a single computed value row for agent_period into finance_computed_values.
     * Idempotent on (definition_id, entity_type, entity_id, period).
     *
     * @param array $defs  [definition_key => FinanceDefinition]
     */
    private function upsertComputedValue(
        FinanceAuditRun $run,
        array $defs,
        string $entityType,
        int $entityId,
        string $period,
        string $key,
        ?float $numeric,
        ?array $json
    ): void {
        if (!isset($defs[$key])) {
            return;
        }
        $def = $defs[$key];
        FinanceComputedValue::withoutGlobalScope(AgencyScope::class)->updateOrCreate(
            [
                'agency_id'     => $this->agencyId,
                'definition_id' => $def->id,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'period'        => $period,
            ],
            [
                'definition_key'     => $key,
                'definition_version' => $def->version,
                'value_numeric'      => $numeric,
                'value_json'         => $json,
                'audit_run_id'       => $run->id,
                'engine_version'     => FinanceEngine::ENGINE_VERSION,
                'computed_at'        => now(),
            ]
        );
    }

    /**
     * Write a comparison item with expected (engine) vs actual (legacy).
     * Severity: info=match, error=diff > tolerance, warn=actual not available.
     */
    private function writeComparisonItem(
        FinanceAuditRun $run,
        string $entityType,
        int $entityId,
        string $period,
        string $definitionKey,
        float $expected,
        ?float $actual
    ): void {
        $diff    = $actual !== null ? round($expected - $actual, 6) : null;
        $absDiff = $diff !== null ? abs($diff) : null;

        if ($absDiff === null) {
            $severity = 'warn';
            $message  = 'entity not in legacy (deal_date range may differ from period)';
        } elseif ($absDiff <= self::MATCH_TOLERANCE) {
            $severity = 'info';
            $message  = 'match';
        } else {
            $severity = 'error';
            $message  = "diff={$diff} (engine uses period field; legacy uses deal_date)";
        }

        FinanceAuditItem::create([
            'agency_id'        => $this->agencyId,
            'audit_run_id'     => $run->id,
            'definition_key'   => $definitionKey,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'period'           => $period,
            'expected_numeric' => $expected,
            'actual_numeric'   => $actual,
            'diff_numeric'     => $diff,
            'severity'         => $severity,
            'message'          => $message,
        ]);
    }
}
