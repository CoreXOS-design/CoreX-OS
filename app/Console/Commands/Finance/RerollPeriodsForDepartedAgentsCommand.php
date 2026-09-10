<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Models\FinanceAuditRun;
use App\Services\Finance\RollupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DR2 financial audit F2 (AT-408) — historical correction, half (b) of the
 * fix. Half (a) (Deal::agents()->withTrashed()) stops the bug recurring;
 * this command re-runs RollupService::refreshPeriod() for every (agency,
 * period) whose finance_computed_values cache was built while a departed
 * agent's real deal_user row was being silently dropped — a deal recorded
 * wrong stays wrong on screen until the cache that feeds the screen is
 * rebuilt.
 *
 * finance_computed_values is a 100% DERIVED cache table (never manually
 * entered), so "correcting" a row here is exactly the same operation as the
 * routine rebuild that already runs on every live deal save
 * (RollupService::refreshPeriod(), called from DealRegisterController) —
 * there is no manually-entered value this could ever clobber, and running
 * it twice with the same inputs produces the same output (idempotent by
 * construction, not by any special-casing here).
 *
 * Dry-run by default: computes the real rebuild inside a transaction,
 * diffs finance_computed_values before/after, prints the diff, then rolls
 * back. Pass --apply to keep it.
 */
class RerollPeriodsForDepartedAgentsCommand extends Command
{
    protected $signature = 'finance:reroll-departed-agent-periods
                            {--agency= : Agency ID to target (default: auto-discover every affected agency)}
                            {--period= : A single YYYY-MM period to target (default: auto-discover every affected period for the agency)}
                            {--apply : Persist the corrected values. Without this flag, nothing is written.}';

    protected $description = 'Re-roll finance_computed_values for periods whose numbers were built while a departed agent\'s share was silently dropped (DR2 financial audit F2 / AT-408).';

    public function handle(RollupService $rollupService): int
    {
        $pairs = $this->resolveAffectedPairs();

        if ($pairs->isEmpty()) {
            $this->info('No affected (agency, period) pairs found — nothing to re-roll.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLYING' : 'DRY RUN') . ' — ' . $pairs->count() . ' (agency, period) pair(s) to re-roll.');

        $totalChangedRows = 0;

        foreach ($pairs as $pair) {
            [$agencyId, $period] = [$pair['agency_id'], $pair['period']];
            $this->line("\n--- agency {$agencyId}, period {$period} ---");

            $before = $this->snapshot($agencyId, $period);

            DB::beginTransaction();
            try {
                $rollupService->refreshPeriod($period, $agencyId);

                // refreshPeriod() is void and swallows its own exceptions into
                // the FinanceAuditRun row it creates — check that row rather
                // than relying on an exception to bubble up.
                $run = FinanceAuditRun::where('agency_id', $agencyId)
                    ->where('period', $period)
                    ->orderByDesc('id')
                    ->first();

                if (! $run || $run->status !== 'complete') {
                    DB::rollBack();
                    $this->error("  Re-roll FAILED for agency {$agencyId} / {$period} (run status: " . ($run->status ?? 'none') . ') — rolled back, nothing changed.');

                    continue;
                }

                $after = $this->snapshot($agencyId, $period);
                $diff = $this->diff($before, $after);

                if (empty($diff)) {
                    $this->line('  No change — this period was already correct (or already re-rolled).');
                } else {
                    foreach ($diff as $row) {
                        $this->line(sprintf(
                            '  %s [%s #%d]: R %s -> R %s (%s%s)',
                            $row['definition_key'],
                            $row['entity_type'],
                            $row['entity_id'],
                            number_format($row['before'], 2),
                            number_format($row['after'], 2),
                            $row['after'] >= $row['before'] ? '+' : '',
                            number_format($row['after'] - $row['before'], 2),
                        ));
                    }
                    $totalChangedRows += count($diff);
                }

                if ($apply) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("  Re-roll THREW for agency {$agencyId} / {$period}: {$e->getMessage()} — rolled back, nothing changed.");
            }
        }

        $this->line('');
        $this->info(($apply ? 'Applied' : 'Would apply') . " corrections to {$totalChangedRows} finance_computed_values row(s) across {$pairs->count()} period(s).");
        if (! $apply && $totalChangedRows > 0) {
            $this->comment('Nothing was written. Re-run with --apply to persist these corrections.');
        }

        return self::SUCCESS;
    }

    /**
     * Every (agency, period) where a soft-deleted user has a real deal_user
     * row on a non-deleted deal — precisely the set Deal::agents() used to
     * silently exclude before the AT-408 fix.
     */
    private function resolveAffectedPairs()
    {
        $agencyOpt = $this->option('agency');
        $periodOpt = $this->option('period');

        $query = DB::table('deal_user')
            ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            ->join('users', 'users.id', '=', 'deal_user.user_id')
            ->whereNotNull('users.deleted_at')
            ->whereNull('deals.deleted_at')
            ->when($agencyOpt !== null, fn ($q) => $q->where('deals.agency_id', (int) $agencyOpt))
            ->when($periodOpt !== null, fn ($q) => $q->where('deals.period', (string) $periodOpt))
            ->select('deals.agency_id', 'deals.period')
            ->distinct();

        return collect($query->get())->map(fn ($r) => ['agency_id' => (int) $r->agency_id, 'period' => (string) $r->period]);
    }

    private function snapshot(int $agencyId, string $period): array
    {
        return DB::table('finance_computed_values')
            ->where('agency_id', $agencyId)
            ->where('period', $period)
            ->whereIn('entity_type', ['agent_period', 'branch_period', 'company_period'])
            ->whereNull('deleted_at')
            ->whereNotNull('value_numeric')
            ->get(['definition_key', 'entity_type', 'entity_id', 'value_numeric'])
            ->keyBy(fn ($r) => $r->definition_key . '|' . $r->entity_type . '|' . $r->entity_id)
            ->map(fn ($r) => (float) $r->value_numeric)
            ->all();
    }

    private function diff(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $out = [];

        foreach ($keys as $key) {
            $b = $before[$key] ?? 0.0;
            $a = $after[$key] ?? 0.0;
            if (abs($a - $b) < 0.005) {
                continue;
            }
            [$definitionKey, $entityType, $entityId] = explode('|', $key, 3);
            $out[] = [
                'definition_key' => $definitionKey,
                'entity_type' => $entityType,
                'entity_id' => (int) $entityId,
                'before' => $b,
                'after' => $a,
            ];
        }

        return $out;
    }
}
