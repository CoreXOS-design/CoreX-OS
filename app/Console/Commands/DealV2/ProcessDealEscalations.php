<?php

namespace App\Console\Commands\DealV2;

use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\NotificationService;
use Illuminate\Console\Command;

/**
 * AT-158 DR2 WS6 — the overdue-escalation timer.
 *
 * deals:process-rag flips a step to `overdue` and nudges the responsible agent
 * on the RAG edge. This hourly sweep runs the ESCALATION LADDER on those overdue
 * steps: BM at +N days, admin at +M days (per the step's escalation_config or the
 * config default). Each rung fires exactly once — the NotificationService records
 * every fired rung in deal_step_escalations, so re-running this command hourly is
 * a no-op until the next threshold is crossed.
 *
 * System-scope (withoutGlobalScopes) so the scheduler runs it across all agencies.
 */
class ProcessDealEscalations extends Command
{
    protected $signature = 'deals:process-escalations {--deal= : Limit to a single deal id (debug)}';

    protected $description = 'Escalate overdue deal-pipeline steps (agent → BM → admin) per the configured ladder, exactly once per rung.';

    public function handle(NotificationService $notifier): int
    {
        $steps = DealStepInstance::withoutGlobalScopes()
            ->where('status', 'overdue')
            ->when($this->option('deal'), fn ($q) => $q->where('deal_id', (int) $this->option('deal')))
            ->with(['pipelineStep'])
            ->get();

        if ($steps->isEmpty()) {
            $this->info('deals:process-escalations — no overdue steps.');
            return self::SUCCESS;
        }

        // Load owning deals once (system scope); only ACTIVE deals escalate.
        $deals = DealV2::withoutGlobalScopes()
            ->whereIn('id', $steps->pluck('deal_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $fired = 0;
        foreach ($steps as $step) {
            $deal = $deals->get($step->deal_id);
            if (! $deal) {
                continue;
            }
            $step->setRelation('deal', $deal); // avoid a per-step deal query
            $fired += $notifier->escalateOverdueStep($step);
        }

        $this->info("deals:process-escalations — {$steps->count()} overdue step(s), {$fired} escalation(s) fired.");

        return self::SUCCESS;
    }
}
