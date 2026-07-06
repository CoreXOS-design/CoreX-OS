<?php

namespace App\Services\DealV2;

use App\Events\DealV2\DealStepCompleted;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStageMove;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DealPipelineService
{
    /**
     * Create a new deal with all step instances from the template.
     */
    public function createDeal(array $data): DealV2
    {
        return DB::transaction(function () use ($data) {
            $reference = DealV2::generateReference();

            $deal = DealV2::create([
                'reference' => $reference,
                'deal_type' => $data['deal_type'],
                'status' => 'active',
                'property_id' => $data['property_id'],
                'listing_agent_id' => $data['listing_agent_id'],
                'selling_agent_id' => $data['selling_agent_id'] ?? null,
                'pipeline_template_id' => $data['pipeline_template_id'],
                'linked_deal_id' => $data['linked_deal_id'] ?? null,
                'purchase_price' => $data['purchase_price'],
                'commission_percentage' => $data['commission_percentage'] ?? null,
                'commission_amount' => $data['commission_amount'],
                'commission_vat' => $data['commission_vat'],
                'listing_split_percent' => $data['listing_split_percent'] ?? 50,
                'selling_split_percent' => $data['selling_split_percent'] ?? 50,
                'listing_external' => $data['listing_external'] ?? false,
                'listing_our_share_percent' => $data['listing_our_share_percent'] ?? 100,
                'listing_external_agency' => $data['listing_external_agency'] ?? null,
                'selling_external' => $data['selling_external'] ?? false,
                'selling_our_share_percent' => $data['selling_our_share_percent'] ?? 100,
                'selling_external_agency' => $data['selling_external_agency'] ?? null,
                'commission_status' => 'Not Paid',
                'offer_date' => $data['offer_date'],
                'overall_rag' => 'grey',
                'notes' => $data['notes'] ?? null,
                'branch_id' => $data['branch_id'],
                'created_by_id' => $data['created_by_id'],
            ]);

            // Attach contacts
            foreach ($data['contacts'] ?? [] as $contact) {
                $deal->contacts()->attach($contact['contact_id'], ['role' => $contact['role']]);
            }

            // Attach agents per side with snapshotted defaults
            foreach (['listing', 'selling'] as $side) {
                if ($deal->{$side . '_external'}) {
                    continue;
                }

                $sideAgents = collect($data['agents'] ?? [])->where('side', $side);
                $count = $sideAgents->count();
                $autoSplit = $count > 0 ? (100.0 / $count) : 0;

                foreach ($sideAgents as $agentData) {
                    $user = \App\Models\User::find($agentData['user_id']);

                    $defaultCut = ($user && $user->agent_cut_percent !== null) ? (float) $user->agent_cut_percent : 50;
                    $defaultPayeMethod = ($user && $user->paye_method) ? $user->paye_method : 'percentage';
                    $defaultPayeValue = ($user && $user->paye_value !== null) ? (float) $user->paye_value : 0;

                    $split = $agentData['split_percent'] ?? $autoSplit;

                    $deal->agents()->attach($agentData['user_id'], [
                        'side' => $side,
                        'agent_split_percent' => $split,
                        'agent_cut_percent' => $defaultCut,
                        'paye_method' => $defaultPayeMethod,
                        'paye_value' => $defaultPayeValue,
                    ]);
                }
            }

            // Create step instances from template
            $template = DealPipelineTemplate::with('steps.dependencies')->find($data['pipeline_template_id']);
            $stepMap = []; // template_step_id => instance_id

            foreach ($template->steps as $templateStep) {
                $overrides = $data['step_overrides'][$templateStep->id] ?? [];

                $instance = DealStepInstance::create([
                    'deal_id' => $deal->id,
                    'pipeline_step_id' => $templateStep->id,
                    'name' => $templateStep->name,
                    'description' => $templateStep->description,
                    'position' => $templateStep->position,
                    'is_locked' => $templateStep->is_locked,
                    'is_milestone' => $templateStep->is_milestone,
                    'is_suspensive' => $templateStep->is_suspensive, // WS-V2 suspensive-condition flag
                    'completion_type' => $templateStep->completion_type,
                    'completion_config' => $templateStep->completion_config,
                    'status' => 'not_started',
                    'trigger_type' => $templateStep->trigger_type,
                    'days_offset' => $overrides['days_offset'] ?? $templateStep->days_offset,
                    'rag_green_days' => $templateStep->rag_green_days,
                    'rag_amber_days' => $templateStep->rag_amber_days,
                    'rag_red_days' => $templateStep->rag_red_days,
                    'current_rag' => 'grey',
                    'notify_agent' => $templateStep->notify_agent,
                    'notify_bm' => $templateStep->notify_bm,
                    'notify_admin' => $templateStep->notify_admin,
                    'status_trigger' => $templateStep->status_trigger,
                    'negative_status_trigger' => $templateStep->negative_status_trigger,
                    'negative_outcome_label' => $templateStep->negative_outcome_label,
                    'requires_bm_approval' => $templateStep->requires_bm_approval,
                    'approval_status' => 'not_required',
                ]);

                $stepMap[$templateStep->id] = $instance->id;
            }

            // Resolve trigger_step_instance_id references
            foreach ($template->steps as $templateStep) {
                if ($templateStep->trigger_step_id && isset($stepMap[$templateStep->trigger_step_id])) {
                    DealStepInstance::where('id', $stepMap[$templateStep->id])->update([
                        'trigger_step_instance_id' => $stepMap[$templateStep->trigger_step_id],
                    ]);
                }
            }

            // WS-V1: resolve additional AND-gate dependencies (template → instance).
            // These are predecessors BEYOND the single primary trigger; a step
            // activates only when its primary trigger AND all of these complete.
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
                        continue; // unknown or self — skip (never gate a step on itself)
                    }
                    $dependencyRows[] = [
                        'agency_id' => $deal->agency_id,
                        'deal_step_instance_id' => $dependentInstanceId,
                        'depends_on_step_instance_id' => $depInstanceId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            if ($dependencyRows) {
                DB::table('deal_step_instance_dependencies')->insert($dependencyRows);
            }

            // Apply manual overrides (due_date and/or days_offset)
            foreach ($data['step_overrides'] ?? [] as $templateStepId => $override) {
                if (!isset($stepMap[$templateStepId])) {
                    continue;
                }
                $updates = [];
                if (!empty($override['due_date'])) {
                    $updates['due_date'] = $override['due_date'];
                }
                if (isset($override['days_offset'])) {
                    $updates['days_offset'] = (int) $override['days_offset'];
                }
                if (!empty($updates)) {
                    DealStepInstance::where('id', $stepMap[$templateStepId])->update($updates);
                }
            }

            // Activate steps triggered by on_creation
            $deal->load('stepInstances');
            foreach ($deal->stepInstances as $instance) {
                if ($instance->trigger_type === 'on_creation') {
                    $this->activateStep($instance, $deal->offer_date);
                }
            }

            $this->recalculateExpectedRegistration($deal);
            $this->updateDealOverallRag($deal); // WS0: seed the deal board RAG at creation

            $this->logActivity($deal, null, $data['created_by_id'], 'deal_created',
                "Deal {$reference} created");

            return $deal->fresh(['stepInstances', 'contacts', 'agents', 'property']);
        });
    }

    /**
     * Activate a step — set status to active, calculate due date, update RAG.
     */
    public function activateStep(DealStepInstance $step, $fromDate = null): void
    {
        $baseDate = $fromDate ? Carbon::parse($fromDate) : now();
        $dueDate = $step->due_date ?? $baseDate->copy()->addDays($step->days_offset);

        $step->update([
            'status' => 'active',
            'activated_at' => now(),
            'due_date' => $dueDate,
            'current_rag' => $this->calculateRag($step, $dueDate),
        ]);

        $this->logActivity($step->deal, $step, null, 'step_activated',
            "Step \"{$step->name}\" activated — due " . Carbon::parse($dueDate)->format('d M Y'));
    }

    /**
     * AT-158 WS-R3 (Ruling 2) — is the DR2 pipeline BM-approval gate switched on
     * for this deal's agency? Off by default (agency-configurable).
     */
    private function bmApprovalEnabled(DealV2 $deal): bool
    {
        return (bool) optional(\App\Models\Agency::find($deal->agency_id))->deal_v2_bm_approval_enabled;
    }

    /**
     * Complete a step — handles status triggers, BM approval, and chain reactions.
     */
    public function completeStep(DealStepInstance $step, User $user, array $completionData): void
    {
        $ticked = DB::transaction(function () use ($step, $user, $completionData) {
            $outcome = $completionData['outcome'] ?? 'positive';
            $isNegative = $outcome === 'negative';

            $statusTrigger = $isNegative ? $step->negative_status_trigger : $step->status_trigger;
            // AT-158 WS-R3 (Ruling 2): the BM-approval hold applies ONLY when the
            // agency has opted in. Off by default → the pipeline is a tracking
            // overlay and the status trigger applies immediately (no held step,
            // no "process I didn't ask for").
            $needsApproval = $step->requires_bm_approval && $statusTrigger && $this->bmApprovalEnabled($step->deal);

            $step->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by_id' => $user->id,
                'completion_data' => $completionData,
                'current_rag' => 'grey',
                'approval_status' => $needsApproval ? 'pending' : 'not_required',
            ]);

            $description = $isNegative
                ? "Step \"{$step->name}\" completed with negative outcome: {$step->negative_outcome_label}"
                : "Step \"{$step->name}\" completed";
            // Anti-gaming: a step completed WITHOUT its requirement is stamped with
            // the reason in the deal timeline (who/when = completed_by_id/at above).
            if (! $isNegative && ! empty($completionData['completed_with_reason'])) {
                $cat = $completionData['reason_category'] ?? 'other';
                $catLabel = config("deals.completion.override_reasons.{$cat}", $cat);
                $description .= " WITHOUT its requirement — reason: {$catLabel}"
                    . (! empty($completionData['reason']) ? " ({$completionData['reason']})" : '');
            } elseif (!empty($completionData['notes'])) {
                $description .= " — {$completionData['notes']}";
            }
            $this->logActivity($step->deal, $step, $user->id, 'step_completed', $description);

            // Handle file upload. WS3 (D4): a step file may now be backed by a
            // unified Document (document_id) so the same file is reachable from
            // the deal, property and contacts — not just this step. Legacy
            // callers pass only file_path; both shapes create one row.
            if (!empty($completionData['file_path']) || !empty($completionData['document_id'])) {
                $step->documents()->create([
                    'document_id' => $completionData['document_id'] ?? null,
                    'file_path' => $completionData['file_path'] ?? null,
                    'file_name' => $completionData['file_name']
                        ?? (!empty($completionData['file_path']) ? basename($completionData['file_path']) : null),
                    'uploaded_by_id' => $user->id,
                ]);
            }

            // Negative + no approval → decline/cancel immediately (voiding downstream).
            // WS-V2: a negative on a SUSPENSIVE step is a DECLINE (distinct terminal
            // state); a negative on an ordinary step keeps its configured trigger.
            if ($isNegative && !$needsApproval && $step->negative_status_trigger) {
                $this->applyNegativeStageEffect($step, $user);
                return false;
            }

            // Positive + no approval → activate downstream + evaluate the stage gate.
            if (!$isNegative && !$needsApproval) {
                $this->activateDownstreamSteps($step);
                $this->applyPositiveStageEffects($step, $user); // WS-V2 suspensive resolver / status trigger
                return true; // stage ticked (positive)
            }

            // Needs BM approval — the step is held (approval_status='pending' set
            // above); the deal status does NOT change until a BM approves. Log it
            // in the reachable path; the BM notification fires AFTER commit (WS6),
            // same doctrine as the DealStepCompleted event below.
            if ($needsApproval) {
                $this->logActivity($step->deal, $step, null, 'approval_pending',
                    "Status change to \"{$statusTrigger}\" pending BM approval");
            }

            return false;
        });

        // WS4 — a positive stage tick reacts across pillars via the
        // event/listener pattern (non-negotiable #9). Emitted AFTER the
        // transaction commits so the distribution engine (email + PDF) never
        // runs inside the completion transaction, and a distribution failure
        // can never roll back a completed step.
        if ($ticked) {
            event(new DealStepCompleted($step->fresh(), $user->id));
        }

        // WS6 — a step held for BM approval notifies the BM (after commit).
        $fresh = $step->fresh();
        if ($fresh && $fresh->approval_status === 'pending') {
            app(NotificationService::class)->notifyBmApprovalPending($fresh);
        }
    }

    /**
     * BM approves a pending step.
     */
    public function approveStep(DealStepInstance $step, User $approver, ?string $notes = null): void
    {
        $ticked = DB::transaction(function () use ($step, $approver, $notes) {
            $completionData = $step->completion_data ?? [];
            $isNegative = ($completionData['outcome'] ?? 'positive') === 'negative';
            $statusTrigger = $isNegative ? $step->negative_status_trigger : $step->status_trigger;

            $step->update([
                'approval_status' => 'approved',
                'approved_by_id' => $approver->id,
                'approved_at' => now(),
                'approval_notes' => $notes,
            ]);

            $this->logActivity($step->deal, $step, $approver->id, 'step_approved',
                "BM {$approver->name} approved status change to \"{$statusTrigger}\"" .
                ($notes ? " — {$notes}" : ''));

            if ($isNegative) {
                $this->applyNegativeStageEffect($step, $approver);
                return false;
            }

            $this->activateDownstreamSteps($step);
            $this->applyPositiveStageEffects($step, $approver); // WS-V2 same gate as an un-gated completion
            return true; // BM-approved positive completion ticks the stage
        });

        // WS4 — emit after commit (same doctrine as completeStep).
        if ($ticked) {
            event(new DealStepCompleted($step->fresh(), $approver->id));
        }
    }

    /**
     * BM rejects a pending step — reverts to active.
     */
    public function rejectStep(DealStepInstance $step, User $rejector, string $reason): void
    {
        DB::transaction(function () use ($step, $rejector, $reason) {
            $step->update([
                'status' => 'active',
                'completed_at' => null,
                'completed_by_id' => null,
                'completion_data' => null,
                'approval_status' => 'rejected',
                'approved_by_id' => $rejector->id,
                'approved_at' => now(),
                'approval_notes' => $reason,
                'current_rag' => $this->calculateRag($step),
            ]);

            $this->logActivity($step->deal, $step, $rejector->id, 'step_rejected',
                "BM {$rejector->name} rejected: {$reason}");
        });

        // WS6 — tell the responsible agent the step was sent back (after commit).
        app(NotificationService::class)->notifyAgentStepRejected($step->fresh(), $reason);
    }

    /**
     * Activate all steps whose FULL set of predecessors is now complete.
     *
     * WS-V1 (AND-gate): a candidate is any not-started step that names the
     * just-completed step as its primary trigger OR as an additional dependency.
     * A candidate activates ONLY when its primary trigger AND every additional
     * dependency are complete; its relative clock then starts from the LATEST of
     * those completions (the last blocker to clear). Steps with no additional
     * dependencies behave exactly as before (fast linear path).
     */
    public function activateDownstreamSteps(DealStepInstance $completedStep): void
    {
        // Steps that name the completed step as their single primary trigger.
        $primaryDependentIds = DealStepInstance::where('trigger_step_instance_id', $completedStep->id)
            ->where('status', 'not_started')
            ->pluck('id');

        // Steps that name the completed step as an additional AND-gate dependency.
        $andGateDependentIds = DB::table('deal_step_instance_dependencies')
            ->where('depends_on_step_instance_id', $completedStep->id)
            ->pluck('deal_step_instance_id');

        $candidateIds = $primaryDependentIds->merge($andGateDependentIds)->unique()->values();

        if ($candidateIds->isNotEmpty()) {
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

        $this->recalculateExpectedRegistration($completedStep->deal);
        $this->updateDealOverallRag($completedStep->deal); // WS0: keep the deal board RAG fresh on advance
    }

    /**
     * WS-V1 — is every predecessor of $step complete?
     *
     * @return array{0:bool,1:?string}  [met, fromDate] where fromDate is the
     *         LATEST predecessor completion (Y-m-d) — the anchor for this step's
     *         relative clock. [false, null] when a predecessor is still open or
     *         the step has no predecessors at all.
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
            return [false, null]; // not a triggered/gated step — nothing to advance from here
        }

        if ($preds->contains(fn ($p) => $p->status !== 'completed')) {
            return [false, null]; // at least one blocker still open
        }

        $latest = $preds->map(fn ($p) => $p->completed_at)->filter()->max();
        $fromDate = $latest ? Carbon::parse($latest)->format('Y-m-d') : now()->format('Y-m-d');

        return [true, $fromDate];
    }

    /**
     * Cancel/skip all remaining steps when deal is cancelled.
     */
    public function cancelDownstreamSteps(DealStepInstance $fromStep): void
    {
        $deal = $fromStep->deal;
        $deal->stepInstances()
            ->whereIn('status', ['not_started', 'active'])
            ->where('id', '!=', $fromStep->id)
            ->update(['status' => 'skipped', 'current_rag' => 'grey']);

        $this->logActivity($deal, null, null, 'steps_cancelled',
            "All remaining steps skipped due to negative outcome on \"{$fromStep->name}\"");
    }

    /**
     * Change deal status and log it.
     */
    public function changeDealStatus(DealV2 $deal, string $newStatus, DealStepInstance $triggerStep, User $actor): void
    {
        $oldStatus = $deal->status;
        $deal->update(['status' => $newStatus]);

        if ($newStatus === 'completed') {
            $deal->update(['actual_registration' => now()]);
        }

        $this->logActivity($deal, $triggerStep, $actor->id, 'status_changed',
            "Deal status changed from \"{$oldStatus}\" to \"{$newStatus}\" via \"{$triggerStep->name}\"");
    }

    // ── WS-V2 — suspensive conditions + auto-move stage gate ────────────────

    /** The agency's stage-gate mode: 'auto' (default) or 'prompt'. */
    private function stageGateMode(DealV2 $deal): string
    {
        $mode = optional(\App\Models\Agency::find($deal->agency_id))->deal_v2_stage_gate_mode;

        return $mode === 'prompt' ? 'prompt' : 'auto';
    }

    /** Are ALL of this deal's suspensive-condition steps complete? False when it has none. */
    public function allSuspensiveComplete(DealV2 $deal): bool
    {
        $suspensive = $deal->stepInstances()->where('is_suspensive', true)->get();
        if ($suspensive->isEmpty()) {
            return false; // this deal isn't modelled with suspensive conditions
        }

        return $suspensive->every(fn ($s) => $s->status === 'completed');
    }

    /**
     * Positive completion → decide whether the deal advances a stage.
     *   • suspensive step  → move to Granted only when EVERY suspensive step is done (AND-gate)
     *   • ordinary step with a status_trigger (e.g. Registration→completed) → advance
     * Both go through advanceStage (mode-aware: auto applies, prompt queues).
     */
    private function applyPositiveStageEffects(DealStepInstance $step, User $actor): void
    {
        if ($step->is_suspensive) {
            if ($this->allSuspensiveComplete($step->deal)) {
                $target = $step->status_trigger ?: 'granted';
                $this->advanceStage($step->deal, $target, $step, $actor, 'suspensive_conditions_met');
            }
            return;
        }

        if ($step->status_trigger) {
            $reason = match ($step->status_trigger) {
                'granted'   => 'suspensive_conditions_met', // legacy single-condition granted step
                'completed' => 'registration',
                default     => 'manual',
            };
            $this->advanceStage($step->deal, $step->status_trigger, $step, $actor, $reason);
        }
    }

    /**
     * Negative completion → decline (suspensive) or the configured negative status,
     * voiding the remaining pipeline in both cases (audit, never hard-delete).
     */
    private function applyNegativeStageEffect(DealStepInstance $step, User $actor): void
    {
        if ($step->is_suspensive) {
            $this->advanceStage($step->deal, 'declined', $step, $actor, 'declined', voidDownstream: true);
            return;
        }

        $target = $step->negative_status_trigger ?: 'cancelled';
        $this->advanceStage($step->deal, $target, $step, $actor, 'manual', voidDownstream: true);
    }

    /**
     * The single entry point for a deal stage advance. AUTO mode applies the move
     * immediately (notify + undoable record); PROMPT mode queues a pending move
     * for a one-click confirmation (declines/manual moves always apply — you never
     * "confirm" a decline). Records a DealStageMove either way.
     */
    private function advanceStage(
        DealV2 $deal, string $toStatus, ?DealStepInstance $trigger, User $actor,
        string $reason, bool $voidDownstream = false
    ): void {
        $from = $deal->status;
        if ($from === $toStatus) {
            return; // idempotent — no-op if already there
        }

        $promptable = in_array($reason, ['suspensive_conditions_met', 'registration'], true);
        if ($promptable && $this->stageGateMode($deal) === 'prompt') {
            // Do NOT change status; queue a pending prompt (one per deal at a time).
            $deal->stageMoves()->where('state', 'pending')->update(['state' => 'dismissed']);
            DealStageMove::create([
                'agency_id' => $deal->agency_id, 'deal_id' => $deal->id,
                'from_status' => $from, 'to_status' => $toStatus, 'reason' => $reason,
                'trigger_step_instance_id' => $trigger?->id, 'mode' => 'prompt', 'state' => 'pending',
            ]);
            $this->logActivity($deal, $trigger, $actor->id, 'stage_prompt',
                "All conditions met — deal ready to move to \"{$toStatus}\" (awaiting confirmation)");
            app(NotificationService::class)->notifyStagePrompt($deal, $from, $toStatus, $trigger);
            return;
        }

        // AUTO — apply now and record the (undoable) move.
        $this->commitStatusChange($deal, $from, $toStatus, $trigger, $actor, $reason, $voidDownstream);
        DealStageMove::create([
            'agency_id' => $deal->agency_id, 'deal_id' => $deal->id,
            'from_status' => $from, 'to_status' => $toStatus, 'reason' => $reason,
            'trigger_step_instance_id' => $trigger?->id, 'mode' => 'auto', 'state' => 'applied',
            'moved_by_id' => $actor->id, 'moved_at' => now(),
        ]);
    }

    /**
     * The status-change effects of a stage move (no DealStageMove record):
     * change status, optionally void downstream, log it, notify the parties.
     * Shared by an auto-advance and a confirmed prompt.
     */
    private function commitStatusChange(
        DealV2 $deal, string $from, string $toStatus, ?DealStepInstance $trigger, User $actor,
        string $reason, bool $voidDownstream
    ): void {
        $updates = ['status' => $toStatus];
        if ($toStatus === 'completed') {
            $updates['actual_registration'] = now();
        }
        $deal->update($updates);

        if ($voidDownstream && $trigger) {
            $this->cancelDownstreamSteps($trigger);
        }

        $note = match ($reason) {
            'suspensive_conditions_met' => ' (all suspensive conditions met)',
            'registration'             => ' (registered)',
            'declined'                 => ' (declined — remaining steps voided)',
            default                    => '',
        };
        $this->logActivity($deal, $trigger, $actor->id, 'stage_advanced',
            "Deal moved from \"{$from}\" to \"{$toStatus}\"{$note}");
        app(NotificationService::class)->notifyStageAdvanced($deal, $from, $toStatus, $trigger);
    }

    /** Confirm a pending prompt-mode stage move — applies it (notify + undoable). */
    public function confirmStageMove(DealStageMove $move, User $actor): void
    {
        if (! $move->isPending()) {
            return; // already confirmed / dismissed / stale — idempotent
        }
        DB::transaction(function () use ($move, $actor) {
            $deal = $move->deal;
            $this->commitStatusChange(
                $deal, $deal->status, $move->to_status, $move->triggerStep, $actor, $move->reason, false
            );
            $move->update([
                'state' => 'confirmed', 'moved_by_id' => $actor->id, 'moved_at' => now(),
            ]);
        });
    }

    /** Dismiss a pending prompt without moving the deal. */
    public function dismissStageMove(DealStageMove $move, User $actor): void
    {
        if (! $move->isPending()) {
            return;
        }
        $move->update(['state' => 'dismissed', 'moved_by_id' => $actor->id]);
        $this->logActivity($move->deal, $move->triggerStep, $actor->id, 'stage_prompt_dismissed',
            "Stage move to \"{$move->to_status}\" dismissed");
    }

    /** One-click UNDO of an applied/confirmed stage move — reverts the status, logs why. */
    public function undoStageMove(DealStageMove $move, User $actor, ?string $reason = null): void
    {
        if (! $move->isUndoable()) {
            return; // pending / already-undone — idempotent
        }
        DB::transaction(function () use ($move, $actor, $reason) {
            $deal = $move->deal;
            $revertTo = $move->from_status;
            $updates = ['status' => $revertTo];
            // Undoing a registration clears the stamped registration date.
            if ($move->to_status === 'completed') {
                $updates['actual_registration'] = null;
            }
            $deal->update($updates);

            $move->update([
                'state' => 'undone', 'undone_by_id' => $actor->id, 'undone_at' => now(),
                'note' => $reason,
            ]);
            $this->logActivity($deal, $move->triggerStep, $actor->id, 'stage_undone',
                "Stage move to \"{$move->to_status}\" undone — deal returned to \"{$revertTo}\""
                . ($reason ? " ({$reason})" : ''));
            app(NotificationService::class)->notifyStageAdvanced($deal, $move->to_status, $revertTo, $move->triggerStep);
        });
    }

    /**
     * Calculate RAG status for a step.
     */
    public function calculateRag(DealStepInstance $step, $dueDate = null): string
    {
        $due = $dueDate ? Carbon::parse($dueDate) : ($step->due_date ? Carbon::parse($step->due_date) : null);
        if (!$due) {
            return 'grey';
        }
        if ($step->status === 'completed') {
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

    /**
     * WS0 — canonical RAG → hex colour map. Used to paint deal calendar events
     * so the deal board (persisted current_rag) and the calendar tile agree.
     * Values track the design tokens (ds-green/amber/red).
     */
    public static function ragColour(string $rag): string
    {
        return match ($rag) {
            'overdue' => '#dc2626', // deeper red — past due
            'red'     => '#ef4444',
            'amber'   => '#f59e0b',
            'green'   => '#10b981',
            default   => '#9ca3af', // grey / not-yet-tracked
        };
    }

    /**
     * Update overall RAG on the deal (worst RAG across active steps).
     */
    public function updateDealOverallRag(DealV2 $deal): void
    {
        $ragPriority = ['overdue' => 5, 'red' => 4, 'amber' => 3, 'green' => 2, 'grey' => 1];

        $worstRag = $deal->stepInstances()
            ->whereIn('status', ['active', 'overdue'])
            ->get()
            ->map(fn ($s) => $this->calculateRag($s))
            ->sortByDesc(fn ($r) => $ragPriority[$r] ?? 0)
            ->first() ?? 'grey';

        $deal->update(['overall_rag' => $worstRag]);
    }

    /**
     * Recalculate expected registration date from the pipeline chain.
     * If the registration step has a due date, use it.
     * Otherwise, walk the trigger chain backwards to project a date from the offer date.
     */
    public function recalculateExpectedRegistration(DealV2 $deal): void
    {
        $deal->loadMissing('stepInstances');

        // Find registration step (last milestone)
        $registrationStep = $deal->stepInstances
            ->where('is_milestone', true)
            ->sortByDesc('position')
            ->first();

        if (!$registrationStep) {
            return;
        }

        // If it has a due date (already activated), use it
        if ($registrationStep->due_date) {
            $deal->update(['expected_registration' => $registrationStep->due_date]);
            return;
        }

        // Walk the trigger chain backwards to sum days_offset
        $totalDays = $this->calculateChainDays($registrationStep, $deal->stepInstances);
        $expectedDate = Carbon::parse($deal->offer_date)->addDays($totalDays);
        $deal->update(['expected_registration' => $expectedDate]);
    }

    private function calculateChainDays(DealStepInstance $step, $allSteps): int
    {
        $days = $step->days_offset;
        if ($step->trigger_step_instance_id) {
            $parent = $allSteps->firstWhere('id', $step->trigger_step_instance_id);
            if ($parent) {
                $days += $this->calculateChainDays($parent, $allSteps);
            }
        }
        return $days;
    }

    /**
     * Put a deal on hold.
     */
    public function holdDeal(DealV2 $deal, User $user, string $reason): void
    {
        $deal->update(['status' => 'on_hold']);
        $this->logActivity($deal, null, $user->id, 'deal_on_hold', "Deal placed on hold: {$reason}");
    }

    /**
     * Resume a deal from hold.
     */
    public function resumeDeal(DealV2 $deal, User $user): void
    {
        $deal->update(['status' => 'active']);
        $this->logActivity($deal, null, $user->id, 'deal_resumed', 'Deal resumed from hold');
    }

    protected function logActivity(DealV2 $deal, ?DealStepInstance $step, ?int $userId, string $action, string $description): void
    {
        DealActivityLog::create([
            'deal_id' => $deal->id,
            'deal_step_instance_id' => $step ? $step->id : null,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'created_at' => now(),
        ]);
    }
}
