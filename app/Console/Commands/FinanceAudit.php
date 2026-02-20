<?php

namespace App\Console\Commands;

use App\Services\Finance\AuditService;
use App\Services\Finance\RollupService;
use Illuminate\Console\Command;

class FinanceAudit extends Command
{
    protected $signature = 'finance:audit
                            {period : Period to audit in YYYY-MM format}
                            {--limit=200 : Maximum number of deals to process}
                            {--scope=all : Audit scope: deals|rollups|all}
                            {--role=all : Rollup roles, comma-separated: agent,bm,admin,all}
                            {--entity-id= : Filter to a specific entity ID (user_id or branch_id)}
                            {--stages=pending,granted,registered,declined : Comma-separated stages to compute in rollups}';

    protected $description = 'Run a Finance Engine shadow audit and/or period rollups for a given period.';

    public function handle(AuditService $auditService): int
    {
        $period     = (string) $this->argument('period');
        $limit      = (int) $this->option('limit');
        $auditScope = (string) $this->option('scope');
        $roleStr    = (string) $this->option('role');
        $entityId   = $this->option('entity-id') ? (int) $this->option('entity-id') : null;
        $stagesStr  = (string) $this->option('stages');

        // Validate period format
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Invalid period format. Expected YYYY-MM, got: {$period}");
            return self::FAILURE;
        }

        // Validate scope
        if (!in_array($auditScope, ['deals', 'rollups', 'all'], true)) {
            $this->error("Invalid scope. Use: deals|rollups|all");
            return self::FAILURE;
        }

        // Parse roles
        $roles = ($roleStr === 'all')
            ? ['agent', 'bm', 'admin']
            : array_values(array_filter(array_map('trim', explode(',', $roleStr))));

        $validRoles = ['agent', 'bm', 'admin'];
        foreach ($roles as $r) {
            if (!in_array($r, $validRoles, true)) {
                $this->error("Invalid role '{$r}'. Use: agent|bm|admin|all");
                return self::FAILURE;
            }
        }

        // Parse stages
        $stages = array_values(array_filter(array_map('trim', explode(',', $stagesStr))));
        foreach ($stages as $s) {
            if (!in_array($s, RollupService::ALL_STAGES, true)) {
                $this->error("Invalid stage '{$s}'. Use: pending,granted,registered,declined");
                return self::FAILURE;
            }
        }
        if (empty($stages)) {
            $stages = RollupService::ALL_STAGES;
        }

        // Build scope metadata to store in the run row
        $scopeData = ['audit_scope' => $auditScope];
        if ($auditScope !== 'deals') {
            $scopeData['roles']  = $roles;
            $scopeData['stages'] = $stages;
        }
        if ($entityId !== null) {
            $scopeData['entity_id'] = $entityId;
        }

        $this->info("Finance audit starting — period={$period}, limit={$limit}, scope={$auditScope}");
        if ($auditScope !== 'deals') {
            $this->line("  Rollup roles  : " . implode(', ', $roles));
            $this->line("  Rollup stages : " . implode(', ', $stages));
            if ($entityId !== null) {
                $this->line("  Entity ID     : {$entityId}");
            }
        }

        $run = $auditService->run($period, $limit, $scopeData, [
            'audit_scope'   => $auditScope,
            'rollup_roles'  => $roles,
            'rollup_stages' => $stages,
            'entity_id'     => $entityId,
        ]);

        $itemCount  = $run->items()->count();
        $errorCount = $run->items()->where('severity', 'error')->count();
        $warnCount  = $run->items()->where('severity', 'warn')->count();

        $this->info("Audit run #{$run->id} complete — status: {$run->status}");
        $this->line("  Total items written : {$itemCount}");

        if ($auditScope === 'deals' || $auditScope === 'all') {
            $dealCount = $run->items()
                ->where('entity_type', 'deal')
                ->distinct('entity_id')
                ->count('entity_id');
            $this->line("  Deals processed     : {$dealCount}");
            $this->line("  Errors              : {$errorCount}");
            $this->line("  Warnings            : {$warnCount}");
        }

        if ($auditScope === 'rollups' || $auditScope === 'all') {
            $agentItems   = $run->items()->where('entity_type', 'agent_period')->count();
            $branchItems  = $run->items()->where('entity_type', 'branch_period')->count();
            $companyItems = $run->items()->where('entity_type', 'company_period')->count();
            $this->line("  Rollup items:");
            $this->line("    agent_period   : {$agentItems}");
            $this->line("    branch_period  : {$branchItems}");
            $this->line("    company_period : {$companyItems}");
        }

        if ($errorCount > 0 && ($auditScope === 'deals' || $auditScope === 'all')) {
            $this->warn("Discrepancies found — review finance_audit_items for run #{$run->id}.");

            $top5 = $run->items()
                ->where('entity_type', 'deal')
                ->where('severity', 'error')
                ->whereNotNull('diff_numeric')
                ->orderByRaw('ABS(diff_numeric) DESC')
                ->limit(5)
                ->get(['definition_key', 'entity_id', 'expected_numeric', 'actual_numeric', 'diff_numeric']);

            if ($top5->isNotEmpty()) {
                $this->line('');
                $this->line('  Top deal diffs:');
                foreach ($top5 as $item) {
                    $this->line(sprintf(
                        '    %-45s deal_id=%-6s expected=%-12s actual=%-12s diff=%s',
                        $item->definition_key,
                        $item->entity_id,
                        $item->expected_numeric,
                        $item->actual_numeric,
                        $item->diff_numeric
                    ));
                }
            }
        } elseif ($auditScope === 'deals' || $auditScope === 'all') {
            $this->info("All deal values match (within tolerance).");
        }

        if ($auditScope === 'rollups' || $auditScope === 'all') {
            $rollupErrorCount = $run->items()
                ->whereIn('entity_type', ['agent_period', 'branch_period', 'company_period'])
                ->where('severity', 'error')
                ->count();

            if ($rollupErrorCount > 0) {
                $this->warn("Rollup discrepancies found — {$rollupErrorCount} error item(s) in run #{$run->id}.");

                $top10 = $run->items()
                    ->whereIn('entity_type', ['agent_period', 'branch_period', 'company_period'])
                    ->where('severity', 'error')
                    ->whereNotNull('diff_numeric')
                    ->orderByRaw('ABS(diff_numeric) DESC')
                    ->limit(10)
                    ->get(['definition_key', 'entity_type', 'entity_id', 'expected_numeric', 'actual_numeric', 'diff_numeric']);

                if ($top10->isNotEmpty()) {
                    $this->line('');
                    $this->line('  Top rollup diffs:');
                    foreach ($top10 as $item) {
                        $this->line(sprintf(
                            '    %-55s %-15s id=%-6s expected=%-12s actual=%-12s diff=%s',
                            $item->definition_key,
                            $item->entity_type,
                            $item->entity_id,
                            $item->expected_numeric,
                            $item->actual_numeric,
                            $item->diff_numeric
                        ));
                    }
                }
            } else {
                $this->info("All rollup totals match legacy (within tolerance).");
            }
        }

        return self::SUCCESS;
    }
}
