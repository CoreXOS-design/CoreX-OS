<?php

namespace App\Services\Deal;

use App\Models\Deal;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-216 (DR2 · WS-PIPELINE) — the DR1-anchored pipeline overlay.
 *
 * DR2 doctrine (Johan): DR2 is an exact duplicate of DR1 on the SAME tables, plus a
 * pipeline. The AT-158 engine (App\Services\DealV2\DealPipelineService) is bound to
 * `deals_v2` and its status vocabulary; it SUNSETS with the deals-v2 module. Rather than
 * entangle that engine with two models, this is a parallel overlay that anchors a pipeline
 * to a DR1 `deals` row via `deal_step_instances.dr1_deal_id`.
 *
 * ── What this overlay OWNS (this increment) ──────────────────────────────────────────
 *   • createPipeline()  — instantiate a template's steps against a DR1 deal, resolve the
 *                         trigger + AND-gate dependency graph, activate on_creation steps,
 *                         stamp the deal's pipeline pointer, and audit it.
 *   • activateStep()    — set a step active, compute its due date + RAG.
 *   • activateDownstreamSteps() / dependencyReadiness() — advance the chain as steps complete.
 *   • completeStep()    — mark a step done and cascade to its successors (step-level only).
 *   • calculateRag() / ragColour() — pure, model-agnostic RAG maths (identical to DR2).
 *
 * ── DEAL-STATUS COUPLING (Sweep #2 — the pipeline now drives the deal's accepted_status) ──
 *   A completed step with a configured `status_trigger` advances the DR1 deal's
 *   `accepted_status` (P/G/R/D — the field the DR2 register reads) via applyStatusTrigger():
 *   'granted'→G, 'completed'/'registered'→R, 'declined'/'cancelled'→D. Forward-only (never
 *   downgrades), stamps granted_at / registration_date. Steps WITHOUT a status_trigger stay
 *   pure tracking (never touch the deal). This fires from EVERY completion path because they
 *   all route through completeStep().
 *
 * ── STILL DEFERRED (needs a DR1 status-model design pass — do NOT fake it) ────
 *   The rest of the DR2 engine's deal-level machinery — DealStageMove auto|prompt queueing,
 *   `overall_rag`, `expected_registration`, BM-approval holds, suspensive AND-gate → granted,
 *   and cross-pillar distribution (DealStepCompleted) — is NOT ported. DR1 has no status enum
 *   / overall_rag / expected_registration columns and models its lifecycle through settlements.
 */
class Dr1PipelineService
{
    /**
     * AT-244 — the pipeline lock gate. Every MUTATING method below opens with an
     * assert, so the rule ("the pipeline is live only on the proceeding offer") holds
     * for every caller, not just the ones that go through PipelineController.
     *
     * The gate lives HERE and not in the controller on purpose: CalendarController
     * completes DR1-anchored steps by calling completeStep() directly, so a
     * controller-only gate would be trivially bypassable from the calendar.
     */
    public function __construct(private readonly DealPipelineLockService $lock)
    {
    }

    /**
     * Attach a pipeline to an existing DR1 deal: materialise the template's steps,
     * wire their trigger + AND-gate dependencies, activate the on-creation steps, and
     * stamp the deal's pipeline pointer. Idempotent guard: refuses if the deal already
     * carries a pipeline (call detach/replace in a later increment, never silently double).
     *
     * @param  array  $opts  ['from_date' => Y-m-d anchor for on_creation steps, defaults to now]
     */
    public function createPipeline(Deal $deal, int $templateId, array $opts = []): Deal
    {
        if ($deal->deal_pipeline_template_id) {
            throw new \RuntimeException(
                "DR1 deal {$deal->id} already has pipeline template {$deal->deal_pipeline_template_id}; refusing to double-attach."
            );
        }

        $template = DealPipelineTemplate::with('steps.dependencies')->findOrFail($templateId);
        $fromDate = $opts['from_date'] ?? null;

        $attached = DB::transaction(function () use ($deal, $template, $fromDate) {
            $stepMap = []; // template_step_id => instance_id

            foreach ($template->steps as $templateStep) {
                $instance = DealStepInstance::create([
                    'agency_id'                => $deal->agency_id,
                    'deal_id'                  => null,          // DR1-anchored: legacy deals_v2 pointer stays null
                    'dr1_deal_id'              => $deal->id,
                    'pipeline_step_id'         => $templateStep->id,
                    'name'                     => $templateStep->name,
                    'description'              => $templateStep->description,
                    'position'                 => $templateStep->position,
                    'is_locked'                => $templateStep->is_locked,
                    'is_milestone'             => $templateStep->is_milestone,
                    'is_suspensive'            => $templateStep->is_suspensive,
                    'completion_type'          => $templateStep->completion_type,
                    'completion_config'        => $templateStep->completion_config,
                    'status'                   => 'not_started',
                    'trigger_type'             => $templateStep->trigger_type,
                    'days_offset'              => $templateStep->days_offset,
                    'rag_green_days'           => $templateStep->rag_green_days,
                    'rag_amber_days'           => $templateStep->rag_amber_days,
                    'rag_red_days'             => $templateStep->rag_red_days,
                    'current_rag'              => 'grey',
                    'notify_agent'             => $templateStep->notify_agent,
                    'notify_bm'                => $templateStep->notify_bm,
                    'notify_admin'             => $templateStep->notify_admin,
                    'status_trigger'           => $templateStep->status_trigger,
                    'negative_status_trigger'  => $templateStep->negative_status_trigger,
                    'negative_outcome_label'   => $templateStep->negative_outcome_label,
                    'requires_bm_approval'     => $templateStep->requires_bm_approval,
                    'approval_status'          => 'not_required',
                ]);

                $stepMap[$templateStep->id] = $instance->id;
            }

            // Resolve the single primary trigger (template step → instance).
            foreach ($template->steps as $templateStep) {
                if ($templateStep->trigger_step_id && isset($stepMap[$templateStep->trigger_step_id])) {
                    DealStepInstance::where('id', $stepMap[$templateStep->id])->update([
                        'trigger_step_instance_id' => $stepMap[$templateStep->trigger_step_id],
                    ]);
                }
            }

            // Resolve additional AND-gate dependencies (predecessors beyond the primary trigger).
            $dependencyRows = [];
            foreach ($template->steps as $templateStep) {
                if ($templateStep->dependencies->isEmpty()) {
                    continue;
                }
                $dependentInstanceId = $stepMap[$templateStep->id] ?? null;
                if (! $dependentInstanceId) {
                    continue;
                }
                foreach ($templateStep->dependencies as $depTemplateStep) {
                    $depInstanceId = $stepMap[$depTemplateStep->id] ?? null;
                    if (! $depInstanceId || $depInstanceId === $dependentInstanceId) {
                        continue; // unknown or self — never gate a step on itself
                    }
                    $dependencyRows[] = [
                        'agency_id'                    => $deal->agency_id,
                        'deal_step_instance_id'        => $dependentInstanceId,
                        'depends_on_step_instance_id'  => $depInstanceId,
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ];
                }
            }
            if ($dependencyRows) {
                DB::table('deal_step_instance_dependencies')->insert($dependencyRows);
            }

            // Stamp the DR1 deal's pipeline pointer (the ONLY mutation this overlay makes
            // to the deal — see the deferred-status note in the class docblock).
            $deal->forceFill([
                'deal_pipeline_template_id' => $template->id,
                'pipeline_started_at'       => now(),
            ])->save();

            // R2 — anchor the schedule to the DEAL DATE (real life: a bond's 30 days run from
            // the signature/deal date, not when the DR entry was captured days later). Project
            // EVERY step's due date from deal_date + its cumulative offset up the trigger chain,
            // so RAG warnings apply across the whole pipeline from day one. Agents can then edit
            // any due date inline; activateStep preserves an already-set due_date.
            $deal->load('pipelineSteps');
            $anchor    = ($fromDate ? Carbon::parse($fromDate) : ($deal->deal_date ? Carbon::parse($deal->deal_date) : now()))->startOfDay();
            $byId      = $deal->pipelineSteps->keyBy('id');
            $chainDays = function ($id, $seen = []) use (&$chainDays, $byId) {
                $s = $byId->get($id);
                if (! $s || in_array($id, $seen, true)) {
                    return 0; // missing or cyclic — clamp
                }
                $base = (int) $s->days_offset;
                return $s->trigger_step_instance_id
                    ? $base + $chainDays($s->trigger_step_instance_id, array_merge($seen, [$id]))
                    : $base;
            };
            foreach ($deal->pipelineSteps as $instance) {
                $due = $anchor->copy()->addDays($chainDays($instance->id));
                $instance->update(['due_date' => $due, 'current_rag' => $this->calculateRag($instance, $due)]);
            }

            // Activate the on-creation steps (their projected due_date is preserved by activateStep).
            foreach ($deal->pipelineSteps->fresh() as $instance) {
                if ($instance->trigger_type === 'on_creation') {
                    $this->activateStep($instance, $anchor->toDateString());
                }
            }

            $this->logActivity($deal, null, null, 'pipeline_started',
                "Pipeline \"{$template->name}\" attached ({$template->steps->count()} steps)");

            return $deal->fresh('pipelineSteps');
        });

        // Fire the calendar sync so EVERY deal-side agent's entries appear immediately
        // (after the outer transaction commits), not only on the next batch run.
        $this->queueCalendarSync();

        return $attached;
    }

    /**
     * Queue a calendar sync (after commit) so pipeline step deadlines materialise onto the
     * agents' calendars promptly. Async on the queue worker; the command is idempotent.
     */
    private function queueCalendarSync(): void
    {
        dispatch(function () {
            \Illuminate\Support\Facades\Artisan::call('deals:sync-calendar');
        })->afterCommit();
    }

    /**
     * Activate a step — set it active, compute its due date (relative to $fromDate or now,
     * unless an explicit due_date was set) and its RAG.
     */
    public function activateStep(DealStepInstance $step, $fromDate = null): void
    {
        $baseDate = $fromDate ? Carbon::parse($fromDate) : now();

        // AT-216 — RE-ANCHOR on real activation. createPipeline() fills every step's
        // due_date at attach with a day-one PROJECTION (deal_date + cumulative offset
        // up the PRIMARY trigger chain) so RAG shows from day one. That projection is
        // an ESTIMATE: it ignores when predecessors actually complete and it ignores
        // AND-gate dependencies. When the step truly activates, its clock must run
        // from the REAL anchor — $fromDate is the LATEST predecessor completion
        // (dependencyReadiness) for an after_step/AND-gate step, or the on_creation
        // anchor. The old `$step->due_date ??` kept the stale estimate (e.g. Lodgement
        // showed deal_date+12 = 03-13 instead of latest-completion 03-25 + 7 = 04-01).
        // Only a genuine AGENT EDIT (due_date_manual) is preserved — never the estimate.
        $dueDate = ($step->due_date_manual && $step->due_date)
            ? $step->due_date
            : $baseDate->copy()->addDays((int) $step->days_offset);

        $step->update([
            'status'       => 'active',
            'activated_at' => now(),
            'due_date'     => $dueDate,
            'current_rag'  => $this->calculateRag($step, $dueDate),
        ]);

        $this->logActivity($step->dr1Deal, $step, null, 'step_activated',
            "Step \"{$step->name}\" activated — due " . Carbon::parse($dueDate)->format('d M Y'));
    }

    /**
     * Complete a step: mark it done, cascade to ready successors, and — if the step is
     * CONFIGURED with a status_trigger — advance the DR1 deal's accepted_status accordingly.
     *
     * This is the single choke point for EVERY DR1 completion path (pipeline board, My Deals,
     * calendar) so the status_trigger fires from all of them, not just one. Steps WITHOUT a
     * status_trigger stay pure tracking (never touch the deal). See applyStatusTrigger().
     */
    public function completeStep(DealStepInstance $step, ?int $userId = null, array $completionData = []): void
    {
        // AT-244 — asserted BEFORE the transaction, against the deal's status as it
        // stands now. A step whose own status_trigger DECLINES the deal is therefore
        // still legal (the deal is not yet 'D' when we check); it is every step AFTER
        // that decline which is refused. That is exactly the intent: the pipeline may
        // kill a deal, it may never resurrect one.
        $this->lock->assertStepUnlocked($step, "Complete step \"{$step->name}\"");

        DB::transaction(function () use ($step, $userId, $completionData) {
            $step->update([
                'status'          => 'completed',
                'completed_at'    => now(),
                'completed_by_id' => $userId,
                'completion_data' => $completionData ?: null,
                'current_rag'     => 'grey',
            ]);

            $outcome    = $completionData['outcome'] ?? 'positive';
            $isNegative = $outcome === 'negative';

            $label = $isNegative ? (' — ' . ($step->negative_outcome_label ?: 'negative outcome')) : '';
            $notes = ! empty($completionData['notes']) ? " — {$completionData['notes']}" : '';
            $this->logActivity($step->dr1Deal, $step, $userId, 'step_completed',
                "Step \"{$step->name}\" completed{$label}{$notes}");

            // AT-229 6b — a DECISION step fires its forward chain ONLY on the positive outcome.
            // A negative outcome (e.g. "Bond Declined") completes the step and applies its
            // negative status trigger, but never activates the positive-path successors.
            if (! $isNegative) {
                $this->activateDownstreamSteps($step);
            }

            // Sweep #2 — fire the step's configured status_trigger (positive or negative) onto the deal.
            $this->applyStatusTrigger($step, $userId, $isNegative);

            // AT-229 §17 — trigger-driven supplier work orders. When the configured
            // trigger step (default "Bond Granted") completes POSITIVELY, every pending
            // work order that points at it is sent (PDF + AT-228 filing + email, agents
            // CC'd). Never on a negative outcome. Each send is isolated so one bad
            // recipient never rolls back the step completion.
            if (! $isNegative) {
                $this->fireSupplierWorkOrders($step, $userId);
            }
        });
    }

    /** §17 — send every pending work order whose trigger step is the one just completed. */
    private function fireSupplierWorkOrders(DealStepInstance $step, ?int $userId): void
    {
        // AT-329 — retry a previously-FAILED order too (e.g. once its supplier's email is
        // added and the trigger fires again), not just untouched 'pending' ones. 'sent' orders
        // are never re-sent.
        $orders = \App\Models\DealV2\DealStepWorkOrder::where('trigger_step_instance_id', $step->id)
            ->whereIn('status', ['pending', 'failed'])->get();
        if ($orders->isEmpty()) {
            return;
        }
        $coc  = app(\App\Services\DealV2\CocWorkOrderService::class);
        $user = $userId ? \App\Models\User::withoutGlobalScopes()->find($userId) : null;
        foreach ($orders as $order) {
            try {
                // send() sets status='sent' + clears send_error on success.
                $coc->send($order, $user);
            } catch (\Throwable $e) {
                // AT-329 — NEVER swallow: record the failure ON this order (status='failed' +
                // the reason, surfaced to the agent in the COC panel) and continue, so one
                // order failing does NOT skip or abort the rest.
                $order->forceFill([
                    'status'     => 'failed',
                    'send_error' => $e->getMessage(),
                ])->save();
                \Log::warning('AT-229 §17 trigger send failed', [
                    'work_order_id' => $order->id, 'deal' => $step->dr1_deal_id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** DR2 status_trigger vocabulary → DR1 `accepted_status` code (the field the register reads). */
    private const STATUS_TRIGGER_MAP = [
        'granted'      => 'G',
        'accepted'     => 'G',
        'completed'    => 'R',
        'registered'   => 'R',
        'registration' => 'R',
        'declined'     => 'D',
        'cancelled'    => 'D',
    ];

    /** Forward-only rank so a late trigger never downgrades an already-advanced deal. */
    private const ACCEPTED_STATUS_RANK = ['P' => 1, 'G' => 2, 'R' => 3];

    /**
     * Sweep #2 — apply a completed step's status_trigger to the DR1 deal's `accepted_status`
     * (P/G/R/D — the one truth the register reads; NOT commission_status). Only steps with a
     * status_trigger configured drive the deal state; forward-only (a late 'granted' never
     * downgrades a 'Registered' deal), 'D' (declined) always applies. Stamps granted_at /
     * registration_date on first reach. Audited to the deal timeline.
     */
    private function applyStatusTrigger(DealStepInstance $step, ?int $userId, bool $isNegative = false): void
    {
        // AT-229 6b — a negative outcome drives the deal by the step's NEGATIVE trigger
        // (typically declined/cancelled → 'D'), never the positive one.
        $trigger = $isNegative ? $step->negative_status_trigger : $step->status_trigger;
        $code    = $trigger ? (self::STATUS_TRIGGER_MAP[$trigger] ?? null) : null;
        $deal    = $step->dr1Deal;
        if (! $code || ! $deal) {
            return;
        }

        $current = (string) $deal->accepted_status;
        if ($code !== 'D' && (self::ACCEPTED_STATUS_RANK[$code] ?? 0) <= (self::ACCEPTED_STATUS_RANK[$current] ?? 0)) {
            return; // never downgrade / re-fire
        }

        // Wave 2 granted-uniqueness — a pipeline trigger may NOT silently create a
        // second granted deal on a property that already carries one. Throws
        // DuplicateGrantException; completeStep()'s transaction rolls the step
        // completion back and the controller surfaces the block to the user.
        if ($code === 'G') {
            app(\App\Services\Deal\DealPropertyStatusService::class)->assertCanGrant($deal);
        }

        $updates = ['accepted_status' => $code];
        if ($code === 'G' && empty($deal->granted_at)) {
            $updates['granted_at'] = now();
        }
        if ($code === 'R' && empty($deal->registration_date)) {
            $updates['registration_date'] = now()->toDateString();
        }
        $deal->forceFill($updates)->save();

        $label = ['P' => 'Pending', 'G' => 'Granted', 'R' => 'Registered', 'D' => 'Declined'][$code] ?? $code;
        $this->logActivity($deal, $step, $userId, 'deal_status_advanced',
            "Deal status → {$label} (pipeline step \"{$step->name}\" completed, trigger \"{$trigger}\")");
    }

    /**
     * Activate every not-started step whose FULL predecessor set is now complete.
     * AND-gate: a candidate names the completed step as its primary trigger OR an additional
     * dependency; it activates only when its primary trigger AND every dependency are done,
     * with its relative clock anchored to the LATEST of those completions.
     */
    public function activateDownstreamSteps(DealStepInstance $completedStep): void
    {
        $primaryDependentIds = DealStepInstance::where('trigger_step_instance_id', $completedStep->id)
            ->where('status', 'not_started')
            ->pluck('id');

        $andGateDependentIds = DB::table('deal_step_instance_dependencies')
            ->where('depends_on_step_instance_id', $completedStep->id)
            ->pluck('deal_step_instance_id');

        $candidateIds = $primaryDependentIds->merge($andGateDependentIds)->unique()->values();

        if ($candidateIds->isEmpty()) {
            return;
        }

        $candidates = DealStepInstance::whereIn('id', $candidateIds)
            ->where('status', 'not_started')
            ->get();

        foreach ($candidates as $candidate) {
            [$met, $fromDate] = $this->dependencyReadiness($candidate);
            if ($met) {
                $this->activateStep($candidate, $fromDate);
            }
        }
    }

    /**
     * Is every predecessor of $step complete?
     *
     * @return array{0:bool,1:?string} [met, fromDate] — fromDate is the LATEST predecessor
     *         completion (Y-m-d), the anchor for this step's relative clock. [false, null]
     *         when a predecessor is still open or the step has no predecessors at all.
     */
    private function dependencyReadiness(DealStepInstance $step): array
    {
        $preds = collect();

        if ($step->trigger_step_instance_id) {
            $primary = DealStepInstance::find($step->trigger_step_instance_id);
            if ($primary) {
                $preds->push($primary);
            }
        }
        foreach ($step->dependencies()->get() as $dep) {
            $preds->push($dep);
        }

        $preds = $preds->unique('id');

        if ($preds->isEmpty()) {
            return [false, null];
        }
        // A predecessor is "resolved" when it's completed, marked N/A, or skipped — any of
        // these clears the gate (an excused step must not block its successors). Removed
        // steps are soft-deleted, so they never appear here at all.
        if ($preds->contains(fn ($p) => ! self::isResolved($p->status))) {
            return [false, null];
        }

        $latest   = $preds->map(fn ($p) => $p->completed_at)->filter()->max();
        $fromDate = $latest ? Carbon::parse($latest)->format('Y-m-d') : now()->format('Y-m-d');

        return [true, $fromDate];
    }

    /**
     * RAG for a step — days-remaining against its due date. Model-agnostic; identical maths
     * to App\Services\DealV2\DealPipelineService::calculateRag.
     */
    public function calculateRag(DealStepInstance $step, $dueDate = null): string
    {
        $due = $dueDate ? Carbon::parse($dueDate) : ($step->due_date ? Carbon::parse($step->due_date) : null);
        if (! $due || $step->status === 'completed') {
            return 'grey';
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);

        if ($daysRemaining < 0) {
            return 'overdue';
        }
        if ($daysRemaining <= $step->rag_red_days) {
            return 'red';
        }
        if ($daysRemaining <= $step->rag_amber_days) {
            return 'amber';
        }
        return 'green';
    }

    /** Canonical RAG → hex map (mirrors the DR2 engine so tiles agree across DR1/DR2). */
    public static function ragColour(string $rag): string
    {
        return match ($rag) {
            'overdue' => '#dc2626',
            'red'     => '#ef4444',
            'amber'   => '#f59e0b',
            'green'   => '#10b981',
            default   => '#9ca3af',
        };
    }

    /** A step whose outcome no longer blocks its successors. */
    public static function isResolved(?string $status): bool
    {
        return in_array($status, ['completed', 'not_applicable', 'skipped'], true);
    }

    /**
     * V1.1 — mark a step Not Applicable: it's KEPT (visibly excused, e.g. gas CoC on a
     * property with no gas) with a recorded reason, and no longer blocks its successors.
     * Audited on the step.
     */
    public function markNotApplicable(DealStepInstance $step, ?int $userId, ?string $reason): void
    {
        $this->lock->assertStepUnlocked($step, "Mark step \"{$step->name}\" N/A");

        DB::transaction(function () use ($step, $userId, $reason) {
            // N/A rides the existing 'skipped' status (kept, not deleted) distinguished by a
            // non-null na_reason — no status-enum change. It resolves the gate like a skip.
            $step->update([
                'status'      => 'skipped',
                'na_reason'   => $reason ?: 'Not applicable',
                'current_rag' => 'grey',
            ]);
            $this->logActivity($step->dr1Deal, $step, $userId, 'step_not_applicable',
                "Step \"{$step->name}\" marked N/A" . ($reason ? " — {$reason}" : ''));

            // Successors gated on this step can now proceed.
            $this->activateDownstreamSteps($step);
        });
    }

    /**
     * V1.1 — remove a step (soft-delete per no-hard-delete doctrine). Audited on the step;
     * any successors gated on it are re-evaluated so removal never strands the chain.
     */
    public function removeStep(DealStepInstance $step, ?int $userId): void
    {
        $this->lock->assertStepUnlocked($step, "Remove step \"{$step->name}\"");

        DB::transaction(function () use ($step, $userId) {
            $this->logActivity($step->dr1Deal, $step, $userId, 'step_removed',
                "Step \"{$step->name}\" removed");
            // Advance anything that was waiting on this step BEFORE it disappears from queries.
            $this->activateDownstreamSteps($step);
            $step->delete(); // soft-delete
        });
    }

    /**
     * V1.1 — add a custom (agent-authored) step to an attached pipeline: name + due date +
     * position (insert after $afterStep, or at the end). Immediately active + RAG-tracked.
     */
    public function addCustomStep(Deal $deal, string $name, ?string $dueDate, ?DealStepInstance $afterStep, ?int $userId): DealStepInstance
    {
        $this->lock->assertUnlocked($deal, "Add custom step \"{$name}\"");

        return DB::transaction(function () use ($deal, $name, $dueDate, $afterStep, $userId) {
            $position = $afterStep
                ? $afterStep->position
                : ((int) DealStepInstance::where('dr1_deal_id', $deal->id)->max('position') + 1);

            $step = DealStepInstance::create([
                'agency_id'     => $deal->agency_id,
                'deal_id'       => null,
                'dr1_deal_id'   => $deal->id,
                'name'          => $name,
                'position'      => $position,
                'is_custom'     => true,
                'is_locked'     => false,
                'is_milestone'  => false,
                'completion_type' => 'manual_tick',
                'status'        => 'active',
                'trigger_type'  => 'manual',
                'due_date'      => $dueDate ?: null,
                'due_date_manual' => !empty($dueDate), // agent-authored date — never system-overwritten
                'activated_at'  => now(),
                'rag_green_days' => 7,
                'rag_amber_days' => 3,
                'rag_red_days'   => 1,
                'current_rag'   => 'grey',
                'notify_agent'  => true,
                'approval_status' => 'not_required',
            ]);
            $step->update(['current_rag' => $this->calculateRag($step)]);

            $this->logActivity($deal, $step, $userId, 'step_added',
                "Custom step \"{$name}\" added" . ($dueDate ? " (due {$dueDate})" : '')
                . ($afterStep ? " after \"{$afterStep->name}\"" : ''));

            return $step;
        });
    }

    /**
     * R2 — agent edits a step's due date inline. RAG recomputes off the edited date (accuracy
     * here drives the warnings). Audited.
     */
    public function updateStepDueDate(DealStepInstance $step, ?string $date, ?int $userId): void
    {
        $this->lock->assertStepUnlocked($step, "Re-date step \"{$step->name}\"");

        $old = optional($step->due_date)->format('Y-m-d');
        $due = $date ? Carbon::parse($date)->startOfDay() : null;
        $step->update([
            'due_date'        => $due,
            // AT-216 — an inline agent edit is authoritative: mark it so activateStep
            // never overwrites it with the system anchor. Clearing the date reverts to
            // system anchoring (the step re-anchors on its next activation).
            'due_date_manual' => $due !== null,
            'current_rag'     => $due ? $this->calculateRag($step, $due) : 'grey',
        ]);
        $this->logActivity($step->dr1Deal, $step, $userId, 'step_due_edited',
            "Step \"{$step->name}\" due date " . ($old ? "changed from {$old}" : 'set')
            . ($due ? " to {$due->format('Y-m-d')}" : ' (cleared)'));

        $this->queueCalendarSync(); // reflect the edited date on the agents' calendars
    }

    /**
     * R2 — restore a soft-deleted (removed) step to its original position. Nobody should be
     * able to permanently strand a deal's pipeline. Audited.
     */
    public function restoreRemovedStep(Deal $deal, int $stepId, ?int $userId): ?DealStepInstance
    {
        $this->lock->assertUnlocked($deal, 'Restore a removed step');

        $step = DealStepInstance::withTrashed()->where('dr1_deal_id', $deal->id)->find($stepId);
        if (! $step || ! $step->trashed()) {
            return null;
        }
        $step->restore(); // position is preserved on the row → it returns where it was
        $this->logActivity($deal, $step, $userId, 'step_restored', "Step \"{$step->name}\" restored");

        return $step;
    }

    /**
     * R2 — reinstate an N/A'd step (skipped + na_reason) back to a live, workable step.
     * Audited. Comes back active with its projected/edited due date + fresh RAG.
     */
    public function reinstateStep(DealStepInstance $step, ?int $userId): void
    {
        // NB: this reinstates a STEP (un-N/A), not a DEAL. Reviving a declined DEAL is a
        // status write on the register, never a pipeline click — so this is gated too.
        $this->lock->assertStepUnlocked($step, "Reinstate step \"{$step->name}\"");

        $step->update([
            'status'       => 'active',
            'na_reason'    => null,
            'activated_at' => $step->activated_at ?? now(),
            'current_rag'  => $this->calculateRag($step),
        ]);
        $this->logActivity($step->dr1Deal, $step, $userId, 'step_reinstated',
            "Step \"{$step->name}\" reinstated (was N/A)");
    }

    /**
     * Append an audit row anchored to the DR1 deal. Writes to the shared `deal_activity_log`
     * via dr1_deal_id (deal_id → deals_v2 stays null for DR1-anchored pipelines).
     */
    protected function logActivity(?Deal $deal, ?DealStepInstance $step, ?int $userId, string $action, string $description): void
    {
        if (! $deal) {
            return; // a detached step with no DR1 deal — nothing to anchor the audit to
        }

        DealActivityLog::create([
            'agency_id'             => $deal->agency_id,
            'deal_id'               => null,
            'dr1_deal_id'           => $deal->id,
            'deal_step_instance_id' => $step?->id,
            'user_id'               => $userId,
            'action'                => $action,
            'description'           => $description,
            'created_at'            => now(),
        ]);
    }
}
