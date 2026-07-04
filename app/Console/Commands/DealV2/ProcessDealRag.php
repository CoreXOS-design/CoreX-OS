<?php

namespace App\Console\Commands\DealV2;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealPipelineService;
use App\Services\DealV2\NotificationService;
use Illuminate\Console\Command;

/**
 * WS0 (AT-158 / DR2) — the deal-pipeline RAG timer.
 *
 * The engine only computes RAG at activate/complete/override time, so a step
 * silently crossing green→amber→red→overdue by the mere passage of time left
 * the PERSISTED `current_rag` / deal `overall_rag` stale (the board reads the
 * cached value) and never repainted its calendar event. This command sweeps
 * active deal steps on a schedule, recomputes RAG, persists it, flips a step to
 * `overdue`, refreshes the deal's `overall_rag` (the previously-uncalled
 * DealPipelineService::updateDealOverallRag), and repaints the linked calendar
 * event's colour + status so the deal board and the calendar tile agree.
 *
 * Idempotent: only writes when the value actually changes. System-scope
 * (withoutGlobalScopes) so it runs across all agencies from the scheduler.
 */
class ProcessDealRag extends Command
{
    protected $signature = 'deals:process-rag {--deal= : Limit to a single deal id (debug)}';

    protected $description = 'Recompute + persist RAG for active deal-pipeline steps, flip overdue, and repaint their calendar events.';

    public function handle(DealPipelineService $svc, NotificationService $notifier): int
    {
        $steps = DealStepInstance::withoutGlobalScopes()
            ->whereIn('status', ['active', 'overdue'])
            ->when($this->option('deal'), fn ($q) => $q->where('deal_id', (int) $this->option('deal')))
            ->get();

        if ($steps->isEmpty()) {
            $this->info('deals:process-rag — no active steps.');
            return self::SUCCESS;
        }

        // Load owning deals once (system scope); only LIVE deals are swept.
        $deals = DealV2::withoutGlobalScopes()
            ->whereIn('id', $steps->pluck('deal_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $changed = 0;
        $touchedDealIds = [];

        foreach ($steps as $step) {
            $deal = $deals->get($step->deal_id);
            if (! $deal || $deal->status !== 'active') {
                continue; // on-hold / cancelled / completed deals don't accrue RAG
            }
            $touchedDealIds[$step->deal_id] = true;

            $newRag = $svc->calculateRag($step);
            $newStatus = $newRag === 'overdue' ? 'overdue' : 'active';

            if ($step->current_rag === $newRag && $step->status === $newStatus) {
                continue; // no change — idempotent
            }

            $oldRag = $step->current_rag;
            $step->update(['current_rag' => $newRag, 'status' => $newStatus]);
            $this->repaintEvent($step, $newRag);

            // WS6 — nudge the responsible agent on the RAG edge (amber/red/overdue).
            // Fired here (only on an actual change) so it fires once per transition;
            // NotificationService also guards per (step, target-RAG) for safety.
            $step->setRelation('deal', $deal); // avoid a per-step deal reload
            $notifier->notifyStepRagTransition($step, $oldRag, $newRag);

            $changed++;
        }

        // Refresh the cached deal-level RAG for every deal we touched.
        foreach (array_keys($touchedDealIds) as $dealId) {
            $svc->updateDealOverallRag($deals->get($dealId));
        }

        $this->info("deals:process-rag — swept {$steps->count()} step(s) across " . count($touchedDealIds) . " live deal(s), {$changed} RAG change(s).");

        return self::SUCCESS;
    }

    /**
     * Repaint the step's calendar event (deal_step_deadline) with the RAG colour
     * + status. Completed/dismissed events are left alone.
     */
    private function repaintEvent(DealStepInstance $step, string $rag): void
    {
        CalendarEvent::withoutGlobalScopes()
            ->where('source_type', DealStepInstance::class)
            ->where('source_id', $step->id)
            ->where('category', 'deal_step_deadline')
            ->whereNotIn('status', ['completed', 'dismissed'])
            ->update([
                'colour' => DealPipelineService::ragColour($rag),
                'status' => $rag === 'overdue' ? 'overdue' : 'pending',
                'updated_at' => now(),
            ]);
    }
}
