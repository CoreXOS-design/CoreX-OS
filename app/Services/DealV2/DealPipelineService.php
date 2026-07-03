<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealPipelineTemplate;
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
            $template = DealPipelineTemplate::with('steps')->find($data['pipeline_template_id']);
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
     * Complete a step — handles status triggers, BM approval, and chain reactions.
     */
    public function completeStep(DealStepInstance $step, User $user, array $completionData): void
    {
        DB::transaction(function () use ($step, $user, $completionData) {
            $outcome = $completionData['outcome'] ?? 'positive';
            $isNegative = $outcome === 'negative';

            $statusTrigger = $isNegative ? $step->negative_status_trigger : $step->status_trigger;
            $needsApproval = $step->requires_bm_approval && $statusTrigger;

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
            if (!empty($completionData['notes'])) {
                $description .= " — {$completionData['notes']}";
            }
            $this->logActivity($step->deal, $step, $user->id, 'step_completed', $description);

            // Handle file upload
            if (!empty($completionData['file_path'])) {
                $step->documents()->create([
                    'file_path' => $completionData['file_path'],
                    'file_name' => $completionData['file_name'] ?? basename($completionData['file_path']),
                    'uploaded_by_id' => $user->id,
                ]);
            }

            // Negative + no approval → cancel immediately
            if ($isNegative && !$needsApproval && $step->negative_status_trigger) {
                $this->changeDealStatus($step->deal, $step->negative_status_trigger, $step, $user);
                $this->cancelDownstreamSteps($step);
                return;
            }

            // Positive + no approval → change status + activate downstream
            if (!$isNegative && !$needsApproval) {
                if ($statusTrigger) {
                    $this->changeDealStatus($step->deal, $statusTrigger, $step, $user);
                }
                $this->activateDownstreamSteps($step);
                return;
            }

            // Needs approval — wait for BM
            if ($needsApproval) {
                $this->logActivity($step->deal, $step, null, 'approval_pending',
                    "Status change to \"{$statusTrigger}\" pending BM approval");
                // TODO: Fire notification to BM (Phase 5)
            }
        });
    }

    /**
     * BM approves a pending step.
     */
    public function approveStep(DealStepInstance $step, User $approver, ?string $notes = null): void
    {
        DB::transaction(function () use ($step, $approver, $notes) {
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

            if ($statusTrigger) {
                $this->changeDealStatus($step->deal, $statusTrigger, $step, $approver);
            }

            if ($isNegative) {
                $this->cancelDownstreamSteps($step);
            } else {
                $this->activateDownstreamSteps($step);
            }
        });
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
            // TODO: Notify agent (Phase 5)
        });
    }

    /**
     * Activate all steps that depend on the completed step.
     */
    public function activateDownstreamSteps(DealStepInstance $completedStep): void
    {
        $dependents = DealStepInstance::where('trigger_step_instance_id', $completedStep->id)
            ->where('status', 'not_started')
            ->get();

        foreach ($dependents as $dependent) {
            $fromDate = $completedStep->completed_at ?? now();
            $this->activateStep($dependent, $fromDate->format('Y-m-d'));
        }

        $this->recalculateExpectedRegistration($completedStep->deal);
        $this->updateDealOverallRag($completedStep->deal); // WS0: keep the deal board RAG fresh on advance
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
