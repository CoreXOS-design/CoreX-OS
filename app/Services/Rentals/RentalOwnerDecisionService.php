<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalPortalSetting;
use App\Models\RentalRecordedOwnerDecision;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/rentals-faults-work-orders.md §20 (AT-452 round 3) - authority C: the agent records the decision the owner gave them by phone,
 * WhatsApp or email. ONE place for the rules (who may, at which step, with what options), ONE ledger, and the effect is produced by the
 * SAME writers the owner's own link uses (RentalFaultReport::recordApproval / RentalWorkOrder::recordApproval), so the next steps and the
 * notifications are identical. A recorded decision is never edited - it is only superseded (by a newer recorded one, or by the owner's own)
 * until a work order exists; the full history stays.
 */
class RentalOwnerDecisionService
{
    // ───────────────────────── switch, owners, state ─────────────────────────

    public function enabled(?int $agencyId): bool
    {
        return RentalWorkOrderSetting::agentsMayRecordOwnerDecisionsFor($agencyId);
    }

    /** The lease's owners (else the property's landlords) - the people an agent can record a decision FOR. */
    public function ownersFor(RentalFaultReport|RentalWorkOrder $subject): Collection
    {
        $lease = $subject->lease;
        $owners = $lease ? $lease->landlordContacts() : collect();
        $property = $subject->property;
        if ($owners->isEmpty() && $property) {
            $owners = $property->contactsForRole('landlord')->merge($property->contactsForRole('lessor'));
        }

        return $owners->unique('id')->values();
    }

    public function stepFor(RentalFaultReport|RentalWorkOrder $subject): string
    {
        return $subject instanceof RentalFaultReport ? RentalRecordedOwnerDecision::STEP_FAULT : RentalRecordedOwnerDecision::STEP_QUOTE;
    }

    /** The decisions offered at this step - the same ones the owner's own link offers. */
    public function optionsFor(RentalFaultReport|RentalWorkOrder $subject): array
    {
        return $subject instanceof RentalFaultReport
            ? [RentalRecordedOwnerDecision::DECISION_APPROVED, RentalRecordedOwnerDecision::DECISION_GET_QUOTE_FIRST, RentalRecordedOwnerDecision::DECISION_DECLINED]
            : [RentalRecordedOwnerDecision::DECISION_APPROVED, RentalRecordedOwnerDecision::DECISION_DECLINED];
    }

    /** @return Collection<int, RentalRecordedOwnerDecision> oldest first */
    public function rowsFor(RentalFaultReport|RentalWorkOrder $subject): Collection
    {
        return RentalRecordedOwnerDecision::withoutGlobalScopes()
            ->where($subject instanceof RentalFaultReport ? 'rental_fault_report_id' : 'rental_work_order_id', $subject->id)
            ->with(['recordedBy', 'ownerContact'])->orderBy('id')->get();
    }

    public function activeFor(RentalFaultReport|RentalWorkOrder $subject): ?RentalRecordedOwnerDecision
    {
        return $this->rowsFor($subject)->whereNull('superseded_at')->last();
    }

    /** The plain reason an agent cannot record a decision for the owner here, or null when they can. */
    public function blockReason(RentalFaultReport|RentalWorkOrder $subject): ?string
    {
        if (! $this->enabled($subject->agency_id)) {
            return "Recording an owner's decision is switched off for this agency.";
        }
        if ($this->ownersFor($subject)->isEmpty()) {
            return 'No owner is on file for this property, so there is no one to record a decision for. Add the owner to the property first.';
        }
        $active = $this->activeFor($subject);

        if ($subject instanceof RentalFaultReport) {
            if (in_array($subject->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED], true)) {
                return 'This fault is closed.';
            }
            if ($subject->hasLiveWorkOrder()) {
                return 'A work order has already been created from this fault, so the owner\'s decision on it is locked. Any further decision is on the work order.';
            }
            if ($subject->sent_to_owner_at === null) {
                return 'This fault has not been sent to the owner yet, so no decision is awaited. Send it to the owner first (or use "Go ahead now and tell the owner" if it is within your limit).';
            }
            if ($subject->owner_approval_status !== RentalFaultReport::APPROVAL_PENDING && ! $active) {
                return 'The owner has already decided on their own link.';
            }

            return null;
        }

        if (in_array($subject->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return 'This work order is closed.';
        }
        $awaited = $subject->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING
            || ($subject->isDeclaredEmergency() && ! app(RentalApprovalGateService::class)->authoriseToProceed($subject, false)->authorised);
        if (! $awaited && ! $active) {
            return $subject->owner_approval_status === RentalWorkOrder::APPROVAL_APPROVED && $subject->approval_basis === RentalWorkOrder::BASIS_OWNER_DECISION
                ? 'The owner has already decided on their own link.'
                : 'No decision from the owner is awaited on this work order.';
        }
        if ($active && $subject->status === RentalWorkOrder::STATUS_IN_PROGRESS) {
            return 'The work has already started, so the owner\'s decision can no longer be replaced.';
        }

        return null;
    }

    // ───────────────────────── emergency declared (authority A) ─────────────────────────

    /** The agent declares this job an emergency - a reason is required. Only then does the emergency limit apply. */
    public function declareEmergency(RentalWorkOrder $workOrder, User $by, string $reason): RentalWorkOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Say why this is an emergency - the reason is required.');
        }
        if (in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        DB::transaction(function () use ($workOrder, $by, $reason) {
            $workOrder->forceFill([
                'emergency_declared_at' => now(), 'emergency_declared_by_user_id' => $by->id, 'emergency_declared_reason' => $reason,
            ])->save();
            $note = $by->name . ' declared this an emergency: ' . $reason;
            $workOrder->updates()->create(['agency_id' => $workOrder->agency_id, 'update_type' => 'emergency_declared', 'note' => $note, 'created_by_user_id' => $by->id]);
            $fault = RentalFaultReport::withoutGlobalScopes()->where('rental_work_order_id', $workOrder->id)->first();
            $fault?->recordHistory(RentalFaultReportUpdate::TYPE_EMERGENCY_DECLARED, $by, $note);

            // a quote already waiting for the owner may now sit inside the emergency limit
            $selected = $workOrder->quotes()->where('is_selected', true)->first();
            if ($selected && $workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING && $workOrder->approvals()->doesntExist()) {
                app(RentalApprovalGateService::class)->evaluateQuote($workOrder->fresh(), $selected->ownerFacingAmount(), $by, $selected);
            }
        });

        return $workOrder->fresh();
    }

    // ───────────────────────── recording ─────────────────────────

    /**
     * @param array<string, mixed> $d owner_contact_id, decision, approved_up_to?, told_via, told_at?, note, handled_by? (own|list|agency),
     *                                contractor_name?, contractor_phone?, agency_service_provider_id?
     */
    public function record(User $by, RentalFaultReport|RentalWorkOrder $subject, array $d, ?UploadedFile $proof = null): RentalRecordedOwnerDecision
    {
        if ($reason = $this->blockReason($subject)) {
            throw new \LogicException($reason);
        }

        $owners = $this->ownersFor($subject);
        $owner = $owners->firstWhere('id', (int) ($d['owner_contact_id'] ?? 0));
        if (! $owner) {
            throw new \InvalidArgumentException('Pick which owner told you - choose one of the property\'s owners.');
        }
        $decision = (string) ($d['decision'] ?? '');
        if (! in_array($decision, $this->optionsFor($subject), true)) {
            throw new \InvalidArgumentException('Choose what the owner decided.');
        }
        $via = (string) ($d['told_via'] ?? '');
        if (! in_array($via, RentalRecordedOwnerDecision::VIAS, true)) {
            throw new \InvalidArgumentException('Say how the owner told you: email, WhatsApp or phone call.');
        }
        $tz = $subject->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        try {
            $toldAt = ! empty($d['told_at']) ? Carbon::parse($d['told_at'], $tz)->utc() : now();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('That date and time is not valid.');
        }
        if ($toldAt->gt(now()->addMinutes(5))) {
            throw new \InvalidArgumentException('The time the owner told you cannot be in the future.');
        }
        $note = trim((string) ($d['note'] ?? ''));
        if ($note === '') {
            throw new \InvalidArgumentException($decision === RentalRecordedOwnerDecision::DECISION_DECLINED
                ? 'Say why the owner declined - what the owner said is required.'
                : 'Write down what the owner said - it is required.');
        }
        $upTo = isset($d['approved_up_to']) && $d['approved_up_to'] !== '' ? round((float) $d['approved_up_to'], 2) : null;
        if ($upTo !== null && $upTo < 0) {
            throw new \InvalidArgumentException('The amount cannot be negative.');
        }
        if ($decision !== RentalRecordedOwnerDecision::DECISION_APPROVED) {
            $upTo = null;
        }

        $proofPath = null;
        if ($proof) {
            $proofPath = app(RentalFaultReportService::class)->storeApprovalScreenshot($proof, (int) $subject->property_id);
        }

        $row = DB::transaction(function () use ($by, $subject, $d, $owner, $decision, $via, $toldAt, $note, $upTo, $proofPath) {
            $previous = $this->activeFor($subject);

            if ($subject instanceof RentalFaultReport) {
                $this->applyToFault($by, $subject, $decision, $via, $toldAt, $note, $upTo, $proofPath, $d, (bool) $previous);
            } else {
                $upTo = $this->applyToWorkOrder($by, $subject, $decision, $via, $toldAt, $note, $upTo, $proofPath);
            }

            $row = RentalRecordedOwnerDecision::withoutGlobalScopes()->create([
                'agency_id' => $subject->agency_id,
                'rental_fault_report_id' => $subject instanceof RentalFaultReport ? $subject->id : null,
                'rental_work_order_id' => $subject instanceof RentalWorkOrder ? $subject->id : null,
                'step' => $this->stepFor($subject),
                'owner_contact_id' => $owner->id,
                'decision' => $decision,
                'approved_up_to' => $upTo,
                'told_via' => $via,
                'told_at' => $toldAt,
                'note' => $note,
                'proof_path' => $proofPath,
                'recorded_by_user_id' => $by->id,
            ]);

            if ($previous) {
                $previous->forceFill(['superseded_at' => now(), 'superseded_by' => 'recorded:' . $row->id])->save();
            }

            $tz = $subject->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
            $when = $toldAt->copy()->setTimezone($tz)->format('j M Y H:i');
            $ownerName = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
            $text = $by->name . " recorded the owner's decision (" . $ownerName . '): ' . RentalRecordedOwnerDecision::decisionLabel($decision)
                . ($upTo !== null ? ' up to R' . number_format($upTo, 2, '.', ' ') : '')
                . ', told to them by ' . RentalRecordedOwnerDecision::viaLabel($via) . ' on ' . $when . ' ("' . $note . '")'
                . ($previous ? ". This replaced the decision recorded on " . $previous->created_at?->setTimezone($tz)->format('j M Y H:i') . '.' : '.');
            if ($subject instanceof RentalFaultReport) {
                $subject->recordHistory(RentalFaultReportUpdate::TYPE_OWNER_DECISION_RECORDED, $by, $text);
            } else {
                $subject->updates()->create(['agency_id' => $subject->agency_id, 'update_type' => 'owner_decision_recorded', 'note' => $text, 'created_by_user_id' => $by->id]);
            }

            return $row;
        });

        // after the commit: the owner is emailed the same sentence at once (safeguard), the lease agents are told like for any owner decision
        $this->notifyOwner($subject, $row);
        $this->notifyAgents($subject, $row);

        return $row;
    }

    private function applyToFault(User $by, RentalFaultReport $fault, string $decision, string $via, Carbon $toldAt, string $note, ?float $upTo, ?string $proofPath, array $d, bool $hadRecorded): void
    {
        $locked = RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->findOrFail($fault->id);
        // replacing an earlier decision: the fault goes back to waiting before the new one is written (the F7 "one decision" rule guards the owner / agent race)
        if ($hadRecorded) {
            $this->reopenFaultDecision($locked);
        }
        $fault->setRawAttributes($locked->fresh()->getAttributes(), true);

        $evidence = match ($via) {
            RentalRecordedOwnerDecision::VIA_WHATSAPP => RentalApproval::EVIDENCE_WHATSAPP,
            RentalRecordedOwnerDecision::VIA_EMAIL => RentalApproval::EVIDENCE_EMAIL,
            default => RentalApproval::EVIDENCE_VERBAL_NOTE,
        };

        if ($decision === RentalRecordedOwnerDecision::DECISION_GET_QUOTE_FIRST) {
            $fault->markQuoteFirst($by, 'The owner asked for a quote first, told to ' . $by->name . ' by ' . RentalRecordedOwnerDecision::viaLabel($via) . '.', $toldAt);

            return;
        }

        $attrs = [
            'decision' => $decision === RentalRecordedOwnerDecision::DECISION_APPROVED ? RentalApproval::DECISION_APPROVED : RentalApproval::DECISION_DECLINED,
            'evidence_type' => $evidence, 'evidence_text' => $note, 'evidence_file_path' => $proofPath, 'decided_at' => $toldAt,
        ];
        if ($decision === RentalRecordedOwnerDecision::DECISION_APPROVED) {
            $handledBy = (string) ($d['handled_by'] ?? 'agency');
            if (! in_array($handledBy, ['own', 'list', 'agency'], true)) {
                throw new \InvalidArgumentException('Say who arranges the repair: our agency, a contractor from our list, or the owner\'s own contractor.');
            }
            $attrs['approval_route'] = $handledBy === 'own' ? RentalFaultReport::ROUTE_OWNER_HANDLES : RentalFaultReport::ROUTE_AGENCY_APPOINTS;
            if ($handledBy === 'own') {
                $attrs['contractor_name'] = trim((string) ($d['contractor_name'] ?? '')) ?: null;
                $attrs['contractor_phone'] = trim((string) ($d['contractor_phone'] ?? '')) ?: null;
            } elseif ($handledBy === 'list') {
                $supplierId = (int) ($d['agency_service_provider_id'] ?? 0);
                if ($supplierId <= 0 || ! app(RentalFaultContractorService::class)->isOption($fault, $supplierId)) {
                    throw new \InvalidArgumentException('Choose a contractor from the list for this type of work.');
                }
                $attrs['agency_service_provider_id'] = $supplierId;
            }
        }
        $fault->recordApproval($by, $attrs);
        if ($decision === RentalRecordedOwnerDecision::DECISION_APPROVED && $upTo !== null) {
            $fault->forceFill(['owner_approved_up_to' => $upTo])->save();
        }
    }

    /** Put a fault whose decision is being replaced back to "waiting for the owner" (only ever before a work order exists). */
    public function reopenFaultDecision(RentalFaultReport $fault): void
    {
        $wasDeclined = $fault->status === RentalFaultReport::STATUS_DECLINED;
        $fault->forceFill([
            'status' => RentalFaultReport::STATUS_AWAITING_APPROVAL,
            'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING,
            'approval_route' => null,
            'owner_approved_up_to' => null,
            'quote_first_at' => null,
        ]);
        // a decline closes the fault by itself with an automatic outcome - reopening it removes that automatic outcome again
        if ($wasDeclined && $fault->outcome_set_automatically && $fault->outcome === RentalFaultReport::OUTCOME_OWNER_DECLINED) {
            $fault->forceFill(['outcome' => null, 'outcome_note' => null, 'outcome_set_automatically' => false]);
        }
        $fault->save();
    }

    private function applyToWorkOrder(User $by, RentalWorkOrder $wo, string $decision, string $via, Carbon $toldAt, string $note, ?float $upTo, ?string $proofPath): ?float
    {
        $evidence = match ($via) {
            RentalRecordedOwnerDecision::VIA_WHATSAPP => RentalApproval::EVIDENCE_WHATSAPP,
            RentalRecordedOwnerDecision::VIA_EMAIL => RentalApproval::EVIDENCE_EMAIL,
            default => RentalApproval::EVIDENCE_VERBAL_NOTE,
        };
        $selected = $wo->quotes()->where('is_selected', true)->first();
        $quoteAmount = $selected ? (float) $selected->ownerFacingAmount() : null;

        if ($decision === RentalRecordedOwnerDecision::DECISION_APPROVED) {
            if ($quoteAmount !== null) {
                $upTo ??= $quoteAmount;
                if ($upTo + 0.001 < $quoteAmount) {
                    throw new \InvalidArgumentException('That is less than the quote of R' . number_format($quoteAmount, 2, '.', ' ') . '. Approve at least the quote, or record that the owner declined it and get a new quote.');
                }
            } elseif ($upTo === null || $upTo <= 0) {
                throw new \InvalidArgumentException('Say the most the owner agreed to spend ("Approved up to") - nothing can be authorised without an amount.');
            }
        }

        $wo->recordApproval($by, [
            'decision' => $decision === RentalRecordedOwnerDecision::DECISION_APPROVED ? RentalWorkOrder::APPROVAL_APPROVED : RentalWorkOrder::APPROVAL_DECLINED,
            'evidence_type' => $evidence, 'evidence_text' => $note, 'evidence_file_path' => $proofPath, 'decided_at' => $toldAt,
        ]);
        if ($decision === RentalRecordedOwnerDecision::DECISION_APPROVED) {
            $wo->forceFill(['owner_approved_up_to' => $upTo])->save();
        }

        return $upTo;
    }

    // ───────────────────────── the owner's own decision replaces a recorded one ─────────────────────────

    /**
     * The owner presses a button on their own link while an agent-recorded decision stands. Allowed until a work order exists (fault) / the work
     * starts (work order). Marks the recorded decision superseded and, for a fault, puts it back to waiting so the owner's own writer can run.
     * Returns false when the owner's decision cannot replace it any more.
     */
    public function releaseForOwnerDecision(RentalFaultReport|RentalWorkOrder $subject): bool
    {
        $active = $this->activeFor($subject);
        if (! $active) {
            return false;
        }
        if ($subject instanceof RentalFaultReport) {
            if ($subject->hasLiveWorkOrder() || in_array($subject->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED], true)) {
                return false;
            }
        } elseif (in_array($subject->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return false;
        }

        DB::transaction(function () use ($subject, $active) {
            if ($subject instanceof RentalFaultReport) {
                $locked = RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->findOrFail($subject->id);
                $this->reopenFaultDecision($locked);
                $subject->setRawAttributes($locked->fresh()->getAttributes(), true);
            }
            $active->forceFill(['superseded_at' => now(), 'superseded_by' => 'owner'])->save();
            $tz = $subject->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
            $text = 'The owner decided on their own link, replacing the decision recorded by ' . ($active->recordedBy?->name ?? 'an agent') . ' on '
                . $active->created_at?->setTimezone($tz)->format('j M Y H:i') . '.';
            if ($subject instanceof RentalFaultReport) {
                $subject->recordHistory(RentalFaultReportUpdate::TYPE_OWNER_DECISION_SUPERSEDED, null, $text);
            } else {
                $subject->updates()->create(['agency_id' => $subject->agency_id, 'update_type' => 'owner_decision_superseded', 'note' => $text]);
            }
        });

        return true;
    }

    // ───────────────────────── words (the owner's side) ─────────────────────────

    public function sentenceFor(RentalRecordedOwnerDecision $row): string
    {
        $subject = $row->rental_fault_report_id ? $row->faultReport : $row->workOrder;
        $tz = $subject?->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $when = $row->told_at?->copy()->setTimezone($tz)->format('j M Y H:i');
        $name = $row->recordedBy?->name ?? 'your agent';
        $via = RentalRecordedOwnerDecision::viaLabel($row->told_via);

        return match ($row->decision) {
            RentalRecordedOwnerDecision::DECISION_APPROVED => "Authorised by your agent {$name} on your instruction, given by {$via} on {$when}."
                . ($row->approved_up_to !== null ? ' Approved up to R' . number_format((float) $row->approved_up_to, 2, '.', ' ') . '.' : ''),
            RentalRecordedOwnerDecision::DECISION_GET_QUOTE_FIRST => "Your agent {$name} recorded that you asked for a quote first, given by {$via} on {$when}. The quote will come to you to approve.",
            default => "Declined by your agent {$name} on your instruction, given by {$via} on {$when}.",
        };
    }

    /** What the owner's screens read: the sentence, the agent's note, who, how and when - or null when nothing recorded stands. */
    public function ownerPayload(RentalFaultReport|RentalWorkOrder $subject): ?array
    {
        $row = $this->activeFor($subject);
        if (! $row) {
            return null;
        }
        $locked = $subject instanceof RentalFaultReport
            ? ($subject->hasLiveWorkOrder() || in_array($subject->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED], true))
            : in_array($subject->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true);

        return [
            'id' => $row->id,
            'sentence' => $this->sentenceFor($row),
            'note' => $row->note,
            'decision' => $row->decision,
            'by' => $row->recordedBy?->name,
            'via' => RentalRecordedOwnerDecision::viaLabel($row->told_via),
            'told_at' => $row->told_at?->toIso8601String(),
            'approved_up_to' => $row->approved_up_to !== null ? (float) $row->approved_up_to : null,
            'can_override' => ! $locked,   // "Decide for myself instead" - only until a work order exists / the work starts
        ];
    }

    // ───────────────────────── telling people ─────────────────────────

    private function notifyOwner(RentalFaultReport|RentalWorkOrder $subject, RentalRecordedOwnerDecision $row): void
    {
        if (! RentalPortalSetting::notifyLandlordOnDecisionNeededFor($subject->agency_id)) {
            return;
        }
        try {
            foreach ($this->ownersFor($subject) as $owner) {
                if ($owner->email) {
                    Mail::to($owner->email)->send(new \App\Mail\Rentals\RentalOwnerDecisionRecordedMail($subject, $row, $this->sentenceFor($row), $owner->first_name ?? '', $owner->email, $owner));
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Recorded owner decision: could not email the owner', ['row' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    /** Both lease agents hear it like any owner decision - the effect writers already told them for approve / decline; "quote first" and the record itself are told here. */
    private function notifyAgents(RentalFaultReport|RentalWorkOrder $subject, RentalRecordedOwnerDecision $row): void
    {
        if ($subject instanceof RentalWorkOrder) {
            $fresh = $subject->fresh();
            $quote = $fresh->quotes()->where('is_selected', true)->first();
            $money = $quote ? ' (R' . number_format((float) $quote->ownerFacingAmount(), 2) . ')' : '';
            $approved = $row->decision === RentalRecordedOwnerDecision::DECISION_APPROVED;
            app(RentalWorkOrderService::class)->notifyAgentOfOwnerAction(
                $fresh, 'rental_work_order.owner_decided',
                $approved ? 'Owner approved the quote' . $money . ' - send it to the contractor' : 'Owner declined the quote' . $money,
                $fresh->title . ' (recorded by ' . ($row->recordedBy?->name ?? 'an agent') . ')', now()
            );
        }
    }
}
