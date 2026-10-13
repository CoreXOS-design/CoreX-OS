<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\RentalApproval;
use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - the AUTHORISATION RECORD. ONE place that writes it, reads it and puts it into the owner's
 * words. A record is written at each of two decisions (who goes to look; the cost) - by the owner on their link, by an agent capturing what the owner
 * told them, or by an agent under a limit the owner set. Records are append-only: a newer one supersedes an older one, nothing is edited or deleted.
 * The effect (the fault's status, the work order) is produced by the SAME writers the owner's own link uses, so the next steps are identical.
 */
class RentalAuthorisationService
{
    // ───────────────────────── reading ─────────────────────────

    /** @return Collection<int, RentalAuthorisation> oldest first */
    public function rowsFor(RentalFaultReport|RentalWorkOrder $subject): Collection
    {
        return RentalAuthorisation::withoutGlobalScopes()
            ->where($subject instanceof RentalFaultReport ? 'rental_fault_report_id' : 'rental_work_order_id', $subject->id)
            ->with(['byUser', 'byContact', 'ownerContact'])->orderBy('id')->get();
    }

    public function activeFor(RentalFaultReport|RentalWorkOrder $subject, string $point): ?RentalAuthorisation
    {
        return $this->rowsFor($subject)->where('point', $point)->whereNull('superseded_at')->last();
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

    private function tz(RentalFaultReport|RentalWorkOrder $subject): string
    {
        return $subject->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    }

    private function subjectOf(RentalAuthorisation $row): RentalFaultReport|RentalWorkOrder|null
    {
        return $row->rental_fault_report_id ? $row->faultReport : $row->workOrder;
    }

    private function rand(float $amount): string
    {
        return 'R' . number_format($amount, 2, '.', ' ');
    }

    /** When the owner said it / pressed the button / the agent authorised, in the agency's timezone. */
    public function whenText(RentalAuthorisation $row): string
    {
        $subject = $this->subjectOf($row);
        $tz = $subject ? $this->tz($subject) : (config('app.timezone') ?: 'Africa/Johannesburg');

        return ($row->told_at ?? $row->created_at)?->copy()->setTimezone($tz)->format('j M Y H:i') ?? '';
    }

    /** The sentence the OWNER reads (portal and email) - exactly the wording ruled for each basis. */
    public function sentenceFor(RentalAuthorisation $row): string
    {
        $when = $this->whenText($row);
        $name = $row->byUser?->name ?? 'your agent';
        $who = $row->inspector_label ?: 'a contractor';
        $ok = $row->isAuthorised();

        return match ($row->basis) {
            RentalAuthorisation::BASIS_OWNER_LINK => ($ok ? 'You authorised this on your link on ' : 'You declined this on your link on ') . $when . '.',
            RentalAuthorisation::BASIS_OWNER_INSTRUCTION => ($ok ? 'Authorised' : 'Declined') . " by your agent {$name} on your instruction, given by "
                . RentalAuthorisation::viaLabel($row->told_via) . " on {$when}."
                . ($ok && $row->amount_up_to !== null && $row->point === RentalAuthorisation::POINT_COST ? ' Approved up to ' . $this->rand((float) $row->amount_up_to) . '.' : ''),
            RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE => "Your agent {$name} sent {$who} to look at this and give a quote. {$who} charges no call-out fee, so the visit costs you nothing.",
            RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING => "Your agent {$name} sent {$who} to look at this and give a quote, under your call-out permission of "
                . $this->rand((float) $row->limit_amount) . '. The call-out fee is ' . $this->rand((float) $row->callout_fee) . '.',
            RentalAuthorisation::BASIS_AGENT_REPAIR_LIMIT => "Authorised by agent {$name} under your repair limit of " . $this->rand((float) $row->limit_amount)
                . ($row->amount_up_to !== null ? ' (' . $this->rand((float) $row->amount_up_to) . ').' : '.'),
            RentalAuthorisation::BASIS_AGENT_EMERGENCY_LIMIT => "Authorised by agent {$name} under your emergency limit of " . $this->rand((float) $row->limit_amount)
                . ($row->amount_up_to !== null ? ' (' . $this->rand((float) $row->amount_up_to) . ')' : '') . '.' . ($row->emergency_reason ? ' Emergency: ' . $row->emergency_reason : ''),
            default => 'Authorised by your agent ' . $name . ' on ' . $when . '.',
        };
    }

    /** The same record in the AGENT's words (third person: "Authorised by agent <name> under the owner's repair limit of R___"). */
    public function recordTextFor(RentalAuthorisation $row): string
    {
        $when = $this->whenText($row);
        $name = $row->byUser?->name ?? 'an agent';
        $owner = $row->ownerContact ? trim(($row->ownerContact->first_name ?? '') . ' ' . ($row->ownerContact->last_name ?? '')) : 'the owner';
        $who = $row->inspector_label ?: 'a contractor';
        $ok = $row->isAuthorised();

        return match ($row->basis) {
            RentalAuthorisation::BASIS_OWNER_LINK => ($ok ? 'Authorised' : 'Declined') . " by {$owner} on their link on {$when}.",
            RentalAuthorisation::BASIS_OWNER_INSTRUCTION => ($ok ? 'Authorised' : 'Declined') . " by agent {$name} on the instruction of {$owner}, given by "
                . RentalAuthorisation::viaLabel($row->told_via) . " on {$when}."
                . ($ok && $row->amount_up_to !== null && $row->point === RentalAuthorisation::POINT_COST ? ' Approved up to ' . $this->rand((float) $row->amount_up_to) . '.' : ''),
            RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE => "Authorised by agent {$name} on {$when}: {$who} charges no call-out fee.",
            RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING => "Authorised by agent {$name} on {$when} under the owner's call-out permission of " . $this->rand((float) $row->limit_amount)
                . " ({$who}, call-out " . $this->rand((float) $row->callout_fee) . ').',
            RentalAuthorisation::BASIS_AGENT_REPAIR_LIMIT => "Authorised by agent {$name} on {$when} under the owner's repair limit of " . $this->rand((float) $row->limit_amount)
                . ($row->amount_up_to !== null ? ' (' . $this->rand((float) $row->amount_up_to) . ').' : '.'),
            RentalAuthorisation::BASIS_AGENT_EMERGENCY_LIMIT => "Authorised by agent {$name} on {$when} under the owner's emergency limit of " . $this->rand((float) $row->limit_amount)
                . ($row->amount_up_to !== null ? ' (' . $this->rand((float) $row->amount_up_to) . ')' : '') . '.' . ($row->emergency_reason ? ' Emergency: ' . $row->emergency_reason : ''),
            default => "Authorised by {$name} on {$when}.",
        };
    }

    public static function pointLabel(string $point): string
    {
        return $point === RentalAuthorisation::POINT_INSPECT ? 'Who goes to look' : 'The cost';
    }

    /**
     * What the owner's screens read about a decision an agent RECORDED on their instruction: the standing Decision 1 record in the owner's words, the agent's note,
     * who, how, when - or null. (An owner's own link press is the decision summary; an agent sending someone under a limit is "Arranged by your agent".)
     */
    public function ownerPayload(RentalFaultReport $fault): ?array
    {
        $row = $this->activeFor($fault, RentalAuthorisation::POINT_INSPECT);
        if (! $row || $row->basis !== RentalAuthorisation::BASIS_OWNER_INSTRUCTION) {
            return null;
        }

        return [
            'id' => $row->id,
            'point' => $row->point,
            'sentence' => $this->sentenceFor($row),
            'note' => $row->note,
            'outcome' => $row->outcome,
            'basis' => $row->basis,
            'by' => $row->byUser?->name,
            'via' => $row->told_via ? RentalAuthorisation::viaLabel($row->told_via) : null,
            'at' => ($row->told_at ?? $row->created_at)?->toIso8601String(),
            'callout_fee' => $row->callout_fee !== null ? (float) $row->callout_fee : null,
        ];
    }

    // ───────────────────────── Decision 1 - recording what the owner told the agent ─────────────────────────

    /** The plain reason an agent cannot record the owner's Decision 1 here, or null when they can. */
    public function recordBlockReason(RentalFaultReport $fault): ?string
    {
        if ($held = app(RentalOwnerHoldService::class)->holdReason($fault)) {   // section 22.7 - while paused, the way forward is Resume
            return $held . ' Use Resume to record the owner\'s go-ahead.';
        }
        if ($this->ownersFor($fault)->isEmpty()) {
            return 'No owner is on file for this property, so there is no one to record a decision for. Add the owner to the property first.';
        }
        if (in_array($fault->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED], true)) {
            return 'This fault is closed.';
        }
        if ($fault->hasLiveWorkOrder()) {
            return 'A work order has already been created from this fault, so the owner\'s decision on it is locked. Any further decision is about the cost, on the work order.';
        }
        if ($this->decidedByOwnerOnLink($fault)) {
            return 'The owner has already decided on their own link.';
        }

        return null;
    }

    /** The fault's standing decision was pressed by the owner themselves (an agent's capture can be replaced; the owner's own press cannot). */
    private function decidedByOwnerOnLink(RentalFaultReport $fault): bool
    {
        $d = $fault->decision();

        return $d !== null && $d->evidence_type === RentalApproval::EVIDENCE_PORTAL;
    }

    /**
     * "Record the owner's decision" for Decision 1.
     *
     * @param array<string, mixed> $d owner_contact_id, decision (authorise|declined), told_via, told_at?, note
     */
    public function recordOwnerDecision(User $by, RentalFaultReport $fault, array $d, ?UploadedFile $proof = null): RentalAuthorisation
    {
        if ($reason = $this->recordBlockReason($fault)) {
            throw new \LogicException($reason);
        }

        $owner = $this->ownersFor($fault)->firstWhere('id', (int) ($d['owner_contact_id'] ?? 0));
        if (! $owner) {
            throw new \InvalidArgumentException('Pick which owner told you - choose one of the property\'s owners.');
        }
        $decision = (string) ($d['decision'] ?? '');
        if (! in_array($decision, ['authorise', 'declined'], true)) {
            throw new \InvalidArgumentException('Choose what the owner decided.');
        }
        $via = (string) ($d['told_via'] ?? '');
        if (! in_array($via, RentalAuthorisation::VIAS, true)) {
            throw new \InvalidArgumentException('Say how the owner told you: phone call, WhatsApp or email.');
        }
        $toldAt = $this->parseToldAt($fault, $d['told_at'] ?? null);
        $note = trim((string) ($d['note'] ?? ''));
        if ($note === '') {
            throw new \InvalidArgumentException($decision === 'declined' ? 'Say why the owner declined - what the owner said is required.' : 'Write down what the owner said - it is required.');
        }
        $proofPath = $proof ? app(RentalFaultReportService::class)->storeApprovalScreenshot($proof, (int) $fault->property_id) : null;

        $row = DB::transaction(function () use ($by, $fault, $owner, $decision, $via, $toldAt, $note, $proofPath) {
            $locked = RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->findOrFail($fault->id);
            if (in_array($locked->owner_approval_status, [RentalFaultReport::APPROVAL_APPROVED, RentalFaultReport::APPROVAL_DECLINED], true)) {
                if ($this->decidedByOwnerOnLink($locked) || $locked->hasLiveWorkOrder()) {
                    throw new \LogicException('The owner has already decided on their own link.');
                }
                $this->reopenFaultDecision($locked);   // replacing an earlier captured decision: back to waiting before the new one is written
            }
            $fault->setRawAttributes(RentalFaultReport::withoutGlobalScopes()->find($locked->id)->getAttributes(), true);

            $evidence = match ($via) {
                RentalAuthorisation::VIA_WHATSAPP => RentalApproval::EVIDENCE_WHATSAPP,
                RentalAuthorisation::VIA_EMAIL => RentalApproval::EVIDENCE_EMAIL,
                default => RentalApproval::EVIDENCE_VERBAL_NOTE,
            };
            // recordApproval() is the one writer of the decision; it also writes the authorisation record (mirrorApproval) and supersedes the old one
            $fault->recordApproval($by, [
                'decision' => $decision === 'authorise' ? RentalApproval::DECISION_APPROVED : RentalApproval::DECISION_DECLINED,
                // "Send someone to look and quote": the agency chooses who goes (the owner authorised a visit, not a person)
                'approval_route' => $decision === 'authorise' ? RentalFaultReport::ROUTE_AGENCY_APPOINTS : null,
                'evidence_type' => $evidence, 'evidence_text' => $note, 'evidence_file_path' => $proofPath, 'decided_at' => $toldAt,
                'owner_contact_id' => $owner->id,
            ]);

            return RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $fault->id)->where('point', RentalAuthorisation::POINT_INSPECT)->orderByDesc('id')->firstOrFail();
        });

        $this->tellOwner($row);

        return $row->fresh();
    }

    /** Put a fault whose (agent-captured) decision is being replaced back to waiting - only ever before a work order exists. */
    public function reopenFaultDecision(RentalFaultReport $fault): void
    {
        $wasDeclined = $fault->status === RentalFaultReport::STATUS_DECLINED;
        $sent = $fault->sent_to_owner_at !== null;
        $fault->forceFill([
            'status' => $sent ? RentalFaultReport::STATUS_AWAITING_APPROVAL : ($fault->owner_version_saved_at ? RentalFaultReport::STATUS_UNDER_REVIEW : RentalFaultReport::STATUS_REPORTED),
            'owner_approval_status' => $sent ? RentalFaultReport::APPROVAL_PENDING : RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'approval_route' => null,
        ]);
        // a decline closes the fault by itself with an automatic outcome - reopening it removes that automatic outcome again
        if ($wasDeclined && $fault->outcome_set_automatically && $fault->outcome === RentalFaultReport::OUTCOME_OWNER_DECLINED) {
            $fault->forceFill(['outcome' => null, 'outcome_note' => null, 'outcome_set_automatically' => false]);
        }
        $fault->save();
    }

    private function supersede(?RentalAuthorisation $previous, RentalAuthorisation $new, RentalFaultReport|RentalWorkOrder $subject, ?User $by): void
    {
        if (! $previous) {
            return;
        }
        $previous->forceFill(['superseded_at' => now(), 'superseded_by_id' => $new->id])->save();
        $text = 'This replaces the record made on ' . $previous->created_at?->copy()->setTimezone($this->tz($subject))->format('j M Y H:i') . ' by ' . ($previous->byUser?->name ?? 'an agent') . '. Both stay in the history.';
        if ($subject instanceof RentalFaultReport) {
            $subject->recordHistory(RentalFaultReportUpdate::TYPE_AUTHORISATION_SUPERSEDED, $by, $text);
        } else {
            $subject->updates()->create(['agency_id' => $subject->agency_id, 'update_type' => 'authorisation_superseded', 'note' => $text, 'created_by_user_id' => $by?->id]);
        }
    }

    private function parseToldAt(RentalFaultReport|RentalWorkOrder $subject, mixed $value): Carbon
    {
        try {
            $at = ! empty($value) ? Carbon::parse((string) $value, $this->tz($subject))->utc() : now();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('That date and time is not valid.');
        }
        if ($at->gt(now()->addMinutes(5))) {
            throw new \InvalidArgumentException('The time the owner told you cannot be in the future.');
        }

        return $at;
    }

    // ───────────────────────── Decision 1 - the other bases ─────────────────────────

    /**
     * Called by RentalFaultReport::recordApproval() (inside its transaction) for EVERY Decision 1 - the owner's press on their link, the owner's own work request,
     * or an agent capturing what the owner told them - so the ledger is complete whichever door the decision came through. A newer record supersedes the older.
     */
    public function mirrorApproval(RentalFaultReport $fault, RentalApproval $approval, User|Contact $by, ?int $ownerContactId = null): RentalAuthorisation
    {
        $previous = $this->activeFor($fault, RentalAuthorisation::POINT_INSPECT);
        $viaLink = $approval->evidence_type === RentalApproval::EVIDENCE_PORTAL;
        $row = RentalAuthorisation::withoutGlobalScopes()->create([
            'agency_id' => $fault->agency_id, 'rental_fault_report_id' => $fault->id, 'point' => RentalAuthorisation::POINT_INSPECT,
            'outcome' => $approval->decision === RentalApproval::DECISION_APPROVED ? RentalAuthorisation::OUTCOME_AUTHORISED : RentalAuthorisation::OUTCOME_DECLINED,
            'basis' => $viaLink ? RentalAuthorisation::BASIS_OWNER_LINK : RentalAuthorisation::BASIS_OWNER_INSTRUCTION,
            'stage' => $fault->status,
            'owner_contact_id' => $viaLink && $by instanceof Contact ? $by->id : $ownerContactId,
            'by_contact_id' => $by instanceof Contact ? $by->id : null,
            'by_user_id' => $by instanceof User ? $by->id : null,
            'told_via' => $viaLink ? RentalAuthorisation::VIA_LINK : match ($approval->evidence_type) {
                RentalApproval::EVIDENCE_WHATSAPP => RentalAuthorisation::VIA_WHATSAPP,
                RentalApproval::EVIDENCE_EMAIL => RentalAuthorisation::VIA_EMAIL,
                default => RentalAuthorisation::VIA_PHONE,
            },
            'told_at' => $approval->decided_at ?? now(), 'note' => $approval->evidence_text ? trim((string) $approval->evidence_text) : null,
            'proof_path' => $approval->evidence_file_path, 'owner_told_at' => $viaLink ? now() : null,
        ]);
        $this->supersede($previous, $row, $fault, $by instanceof User ? $by : null);
        if (! $viaLink && $by instanceof User) {
            $fault->recordHistory(RentalFaultReportUpdate::TYPE_AUTHORISATION_RECORDED, $by, $this->recordTextFor($row->load(['byUser', 'ownerContact'])) . ($row->note ? ' "' . $row->note . '"' : ''));
        }

        return $row;
    }

    /** The agent sent someone to look under their own authority (no call-out fee, or inside the owner's call-out permission). */
    public function recordAgentInspection(RentalFaultReport $fault, User $by, string $basis, string $label, ?int $supplierId, ?float $fee, ?float $allowance): RentalAuthorisation
    {
        $previous = $this->activeFor($fault, RentalAuthorisation::POINT_INSPECT);
        $row = RentalAuthorisation::withoutGlobalScopes()->create([
            'agency_id' => $fault->agency_id, 'rental_fault_report_id' => $fault->id, 'point' => RentalAuthorisation::POINT_INSPECT,
            'outcome' => RentalAuthorisation::OUTCOME_AUTHORISED, 'basis' => $basis, 'stage' => $fault->status,
            'by_user_id' => $by->id, 'told_at' => now(), 'inspector_label' => $label, 'agency_service_provider_id' => $supplierId,
            'callout_fee' => $fee, 'amount_up_to' => $fee, 'limit_amount' => $basis === RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING ? $allowance : null,
        ]);
        $this->supersede($previous, $row, $fault, $by);
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_AUTHORISATION_RECORDED, $by, $this->recordTextFor($row->load(['byUser', 'ownerContact'])));

        return $row;
    }

    // ───────────────────────── stage 3: a fresh authority after "Stop this" (added for RentalOwnerHoldService) ─────────────────────────

    /** The time an owner said it, in the agency's timezone -> UTC, never in the future (the validation `recordOwnerDecision` uses; public for the hold service). */
    public function toldAt(RentalFaultReport|RentalWorkOrder $subject, mixed $value): Carbon
    {
        return $this->parseToldAt($subject, $value);
    }

    /**
     * A NEW authorisation record that resumes a job the owner stopped: the owner's own "Go ahead after all" (basis owner_link) or an agent recording what the
     * owner told them (basis owner_instruction). It supersedes the standing record of the same point; both stay in the history. Changes nothing else - the job
     * is resumed by closing the hold ({@see RentalOwnerHoldService::resumeByOwner()} / resumeByAgent()).
     */
    public function recordFreshAuthority(RentalFaultReport|RentalWorkOrder $subject, string $point, string $basis, ?User $by, Contact $owner, ?string $proofPath, string $via, Carbon $toldAt, ?string $note): RentalAuthorisation
    {
        $isFault = $subject instanceof RentalFaultReport;
        $previous = $this->activeFor($subject, $point);
        $viaLink = $basis === RentalAuthorisation::BASIS_OWNER_LINK;
        $row = RentalAuthorisation::withoutGlobalScopes()->create([
            'agency_id' => $subject->agency_id, 'rental_fault_report_id' => $isFault ? $subject->id : null, 'rental_work_order_id' => $isFault ? null : $subject->id,
            'point' => $point, 'outcome' => RentalAuthorisation::OUTCOME_AUTHORISED, 'basis' => $basis, 'stage' => $subject->status,
            'owner_contact_id' => $owner->id, 'by_contact_id' => $viaLink ? $owner->id : null, 'by_user_id' => $viaLink ? null : $by?->id,
            'told_via' => $via, 'told_at' => $toldAt, 'note' => $note ? trim($note) : null, 'proof_path' => $proofPath, 'owner_told_at' => $viaLink ? now() : null,
        ]);
        $this->supersede($previous, $row, $subject, $by);

        return $row;
    }

    // ───────────────────────── telling the owner ─────────────────────────

    /**
     * The owner is told at once, in the same sentence the portal shows (safeguard). Under the existing owner-notice setting; to every owner on the
     * property that has an email address. A mail problem never undoes the record. Sets `owner_told_at` when at least one went.
     */
    public function tellOwner(RentalAuthorisation $row): void
    {
        $subject = $this->subjectOf($row);
        if (! $subject || ! RentalPortalSetting::notifyLandlordOnDecisionNeededFor($subject->agency_id)) {
            return;
        }
        $sent = 0;
        try {
            // addressed to ALL the owners on the property: a co-owner must know what was agreed on their behalf
            $owners = $this->ownersFor($subject);
            foreach ($owners as $owner) {
                if ($owner->email) {
                    Mail::to($owner->email)->send(new \App\Mail\Rentals\RentalAuthorisationToOwnerMail($subject, $row, $this->sentenceFor($row), $owner->first_name ?? '', $owner->email, $owner));
                    $sent++;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Authorisation: could not email the owner', ['row' => $row->id, 'error' => $e->getMessage()]);
        }
        if ($sent > 0) {
            $row->forceFill(['owner_told_at' => now()])->save();
        }
    }
}
