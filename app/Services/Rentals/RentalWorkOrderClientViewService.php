<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;

/**
 * .ai/specs/rental-work-orders.md §17.3.5 / §17.12 — what a TENANT or a LANDLORD may see of a WORK ORDER (the portal's
 * "Jobs" are work orders now, not job cards): who is doing it, its plain-words stage, when, the completion rounds and
 * their outcomes, and the photos the agency allows. ONE place decides it, so the portal API, the web shell and anything
 * added later agree. Built by extending {@see RentalJobCardClientViewService}'s rules, never by re-deriving them.
 *
 *   - Tenant: NEVER a price, a quote, an approval or a cost — not even "approved". Their own answers and photos, yes.
 *   - Landlord: the OWNER-FACING amount only (the selected quote's selling figure, or the final amount once closed);
 *     never cost, markup or margin.
 *   - Plain stage labels only, never a raw status: Reported · Being arranged · Needs your decision · Approved ·
 *     Scheduled · In progress · Reported finished (waiting for the agent) · Not complete — reopened · Completed · Cancelled.
 *
 * Callers pass work orders they have ALREADY resolved through RentalPortalScopeService (the caller's own lease /
 * property); this class decides what of an opened record is shown. Every query strips global scopes (a portal
 * request has no staff user) and pins `agency_id`.
 */
class RentalWorkOrderClientViewService
{
    public const AUDIENCE_TENANT = 'tenant';
    public const AUDIENCE_LANDLORD = 'landlord';

    public function __construct(private readonly RentalJobCardClientViewService $photoView)
    {
    }

    /**
     * W2 (8 Oct 2026) - the plain stage KEY of a work order: created, appointment_set, in_progress, check_requested,
     * reopened, completed, cancelled, needs_decision. The words for each live in config/rental-work-order-stages.php, per
     * audience; nothing here is a label.
     */
    public function stageKey(RentalWorkOrder $workOrder, string $audience = self::AUDIENCE_TENANT): string
    {
        $latest = $this->latestRound($workOrder);

        if ($workOrder->status === RentalWorkOrder::STATUS_CANCELLED) {
            return 'cancelled';
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_DISPUTED) {
            return 'reopened';
        }
        // Johan, 9 Oct 2026 (T1): no "reported complete - tenant check" holding stage. The work order is in progress until the agent closes it,
        // then it is completed; the tenant's check is an optional record beside that.
        if ($workOrder->status === RentalWorkOrder::STATUS_COMPLETED) {
            return 'completed';
        }
        // T1: reported finished (by the crew, the contractor or the owner) = waiting for the agent to close. Never a tenant hold.
        if ($this->reportedFinished($workOrder, $latest)) {
            return 'reported_finished';
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_IN_PROGRESS) {
            return 'in_progress';
        }

        $card = $this->card($workOrder);
        if ($card && $card->status === RentalJobCard::STATUS_IN_PROGRESS) {
            return 'in_progress';
        }
        if ($card && $card->status === RentalJobCard::STATUS_SCHEDULED) {
            return 'appointment_set';   // the crew is booked: that IS the appointment
        }
        if ($audience !== self::AUDIENCE_TENANT && $workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING) {
            return 'needs_decision';
        }
        // Q2: the owner declined the quote - not "Created" any more. (The tenant keeps the plain stage: it never learns the owner's decision.)
        if ($audience !== self::AUDIENCE_TENANT && $workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_DECLINED) {
            return 'quote_declined';
        }
        if ($this->appointmentAt($workOrder, $audience === 'agent')) {
            return 'appointment_set';
        }

        // The OFFICE's and the OWNER's badges follow the real position (the tenant keeps the plain 'created'): once approved it is ready to send, once
        // sent it is with the contractor - it must never keep saying "Created" after the work order has gone out.
        if ($audience !== self::AUDIENCE_TENANT) {
            if ($workOrder->status === RentalWorkOrder::STATUS_ORDERED) {
                return $workOrder->isOwnerContractor() ? 'with_owner_contractor' : 'sent_to_contractor';
            }
            // approved by the owner, or a quote within the no-approval limit (not_required with an approved amount on record)
            $authorised = $workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_APPROVED
                || ($workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_NOT_REQUIRED && $workOrder->approved_amount !== null);
            if ($workOrder->status === RentalWorkOrder::STATUS_REPORTED && $workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER && $authorised) {
                return 'approved_to_send';
            }
            // P3: no quote chosen yet on the agency-contractor route
            if ($workOrder->status === RentalWorkOrder::STATUS_REPORTED && $workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER && ! $workOrder->isOwnerContractor()
                && $audience === self::AUDIENCE_LANDLORD && ! $this->ownerVisibleQuote($workOrder)) {
                return 'awaiting_quote';
            }
        }

        return 'created';
    }

    /** T1: the work was reported finished and nobody has closed it yet (and it is not sent back). */
    public function reportedFinished(RentalWorkOrder $workOrder, ?RentalWorkCompletionRound $latest = null): bool
    {
        $latest ??= $this->latestRound($workOrder);

        return $latest && $latest->outcome !== RentalWorkCompletionRound::OUTCOME_DISPUTED
            && ! in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED, RentalWorkOrder::STATUS_DISPUTED], true);
    }

    /** The plain-words stage for this work order, as this audience should read it (words come from the config data). */
    public function stageLabel(RentalWorkOrder $workOrder, string $audience = self::AUDIENCE_TENANT): string
    {
        $key = $this->stageKey($workOrder, $audience);
        $who = match ($audience) {
            self::AUDIENCE_LANDLORD => 'owner',
            self::AUDIENCE_TENANT => 'tenant',
            default => 'agent',
        };

        return (string) (config("rental-work-order-stages.{$key}.{$who}")
            ?? config("rental-work-order-stages.{$key}.agent")
            ?? ucfirst(str_replace('_', ' ', $key)));
    }

    /**
     * W2 - the short work-order summary shown beside the fault it belongs to (tenant fault list/detail, owner fault detail):
     * the plain stage, who is doing the repair, the appointment. Never a price, never a job-card detail.
     *
     * @return array<string, mixed>
     */
    public function summary(RentalWorkOrder $workOrder, string $audience = self::AUDIENCE_TENANT): array
    {
        $internal = $workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL;
        $ownerContractor = $workOrder->isOwnerContractor();

        return [
            'id' => $workOrder->id,
            'stage' => $this->stageKey($workOrder, $audience),
            'stage_label' => $this->stageLabel($workOrder, $audience),
            'who_label' => $internal ? RentalWorkOrderSetting::internalTeamLabelFor($workOrder->agency_id) : ($ownerContractor ? ($audience === self::AUDIENCE_LANDLORD ? 'Your contractor' : "Owner's contractor") : 'Contractor arranged by the agency'),
            'contractor_name' => $this->contractorNameFor($workOrder, $audience),
            'appointment_at' => $this->appointmentAt($workOrder)?->toIso8601String(),
            'appointment_note' => $this->appointmentAt($workOrder) ? $workOrder->appointment_note : null,
            'completed_at' => $workOrder->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Johan, 9 Oct 2026: a job that has not been approved has no appointment as far as the tenant and the owner are concerned (an
     * appointment is set only once it is approved - RentalWorkOrderService::setAppointment - and an older one booked before that rule is not
     * shown until the approval is in). The office sees what is stored ($office = true). An older internal job falls back to its booking.
     */
    public function appointmentAt(RentalWorkOrder $workOrder, bool $office = false): ?\Illuminate\Support\Carbon
    {
        $own = $workOrder->appointment_at;
        if ($own !== null && ! $office && ! $this->jobApproved($workOrder)) {
            $own = null;
        }

        return $own ?? $this->card($workOrder)?->scheduled_at;
    }

    /** Has the job been approved to go ahead: within the owner's limit, approved by the owner, an emergency, or the owner's own contractor - or already out with the contractor? */
    public function jobApproved(RentalWorkOrder $workOrder): bool
    {
        return $this->approvalBlock($workOrder) === null;
    }

    /** The plain reason a job has NOT been approved yet, or null when it has. A work order already sent / under way / finished is, by definition, cleared. */
    public function approvalBlock(RentalWorkOrder $workOrder): ?string
    {
        if (in_array($workOrder->status, [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_DISPUTED, RentalWorkOrder::STATUS_COMPLETED], true)) {
            return null;
        }
        $decision = app(\App\Services\Rentals\RentalApprovalGateService::class)->authoriseToProceed($workOrder, false);

        return $decision->authorised ? null : $decision->note;
    }

    /** The contractor's name as THIS audience may read it: the tenant is not given an outside contractor's name before the job is approved. */
    private function contractorNameFor(RentalWorkOrder $workOrder, string $audience): ?string
    {
        if ($workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            return null;
        }
        if ($workOrder->isOwnerContractor()) {
            return $workOrder->contractor_name ?: null;
        }
        if ($audience === self::AUDIENCE_TENANT && ! $this->jobApproved($workOrder)) {
            return null;
        }

        return $workOrder->supplier()->withoutGlobalScopes()->withTrashed()->first()?->name;
    }

    /**
     * WHO reported the work done, as a tenant or owner may read it. On the agency's own crew that is the agency's team label
     * ("Our maintenance team" unless the agency words it otherwise) - NEVER the crew member's sign-off name, which stays with the office.
     * A contractor's reporting label (the business) is shown as captured.
     */
    public function reportedBy(RentalWorkCompletionRound $round, RentalWorkOrder $workOrder): string
    {
        if ($workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            return RentalWorkOrderSetting::internalTeamLabelFor($workOrder->agency_id);
        }

        return (string) $round->reported_by_label;
    }

    /**
     * One work order as a client sees it.
     *
     * @return array<string, mixed>
     */
    public function payload(RentalWorkOrder $workOrder, string $audience = self::AUDIENCE_TENANT): array
    {
        $card = $this->card($workOrder);
        $property = $workOrder->property()->withoutGlobalScopes()->withTrashed()->first();
        $rounds = $this->rounds($workOrder);
        $latest = $rounds->last();
        $internal = $workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL;
        $ownerContractor = $workOrder->isOwnerContractor();
        $appointment = $this->appointmentAt($workOrder);

        $payload = [
            'id' => $workOrder->id,
            'title' => $workOrder->title,
            'description' => $workOrder->description,
            'property_id' => $workOrder->property_id,
            'property_address' => $property?->buildDisplayAddress(),
            'lease_id' => $workOrder->lease_id,
            'rental_fault_report_id' => $workOrder->reported_fault_report_id,
            // The raw status stays for older consumers of this endpoint; people read `stage_label`.
            'status' => $workOrder->status,
            'stage' => $this->stageKey($workOrder, $audience),
            'stage_label' => $this->stageLabel($workOrder, $audience),
            // W2/W3: who is doing the repair. Never contact details for a tenant; the owner also sees their own contractor's phone.
            'who' => $internal ? 'our_team' : ($ownerContractor ? 'owner_contractor' : 'external_contractor'),
            'who_label' => $internal ? RentalWorkOrderSetting::internalTeamLabelFor($workOrder->agency_id) : ($ownerContractor ? ($audience === self::AUDIENCE_LANDLORD ? 'Your contractor' : "Owner's contractor") : 'Contractor arranged by the agency'),
            'contractor_name' => $this->contractorNameFor($workOrder, $audience),
            'contractor_phone' => ($ownerContractor && $audience === self::AUDIENCE_LANDLORD) ? ($workOrder->contractor_phone ?: null) : null,
            // W2: the appointment for the repair. `scheduled_at` is kept for older consumers of this endpoint.
            'appointment_at' => $appointment?->toIso8601String(),
            'appointment_note' => $appointment ? $workOrder->appointment_note : null,
            'scheduled_at' => $appointment?->toIso8601String(),
            'completed_at' => $workOrder->completed_at?->toIso8601String(),
            'photos' => $this->photoView->photosPayload($this->photoView->photosForWorkOrder($workOrder)),
            'rounds' => $rounds->map(fn (RentalWorkCompletionRound $r) => $this->roundPayload($r, $workOrder))->values()->all(),
            // The one open question for the tenant: "is this finished?" (null when nothing waits on them).
            'awaiting_answer' => $latest && $latest->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT
                && $workOrder->status !== RentalWorkOrder::STATUS_CANCELLED
                ? [
                    'round_id' => $latest->id,
                    'round_no' => $latest->round_no,
                    'reported_by' => $this->reportedBy($latest, $workOrder),
                    'reported_at' => $latest->opened_at?->toIso8601String(),
                ]
                : null,
        ];

        if ($audience === self::AUDIENCE_LANDLORD) {
            // The owner-facing amount only (§17.2): the selected quote's selling figure, else the final amount
            // once the work order is closed. Never cost, markup or margin.
            $selected = RentalWorkOrderQuote::withoutGlobalScopes()
                ->where('agency_id', $workOrder->agency_id)->whereNull('deleted_at')
                ->where('rental_work_order_id', $workOrder->id)->where('is_selected', true)->first();
            // P2: what the quote covers, so the owner has something to judge the figure by - the quote's details text and (when no agency fee
            // sits on top of it) a link to the contractor's document. Never the contractor's own total when a fee applies.
            $payload['quote'] = $selected ? [
                'details' => $selected->detail_text ?: null,
                'document_url' => ($selected->document_storage_path && $selected->ownerMayOpenDocument())
                    ? route('client.rentals.landlord.work-orders.quote-file', ['workOrder' => $workOrder->id], false) : null,
                'quote_date' => $selected->quote_date?->toDateString(),
            ] : null;
            $payload['owner_facing_amount'] = $workOrder->status === RentalWorkOrder::STATUS_COMPLETED && $workOrder->cost_amount !== null
                ? (float) $workOrder->cost_amount
                : ($selected?->ownerFacingAmount());
            $payload['owner_approval_status'] = $workOrder->owner_approval_status;
            // What the owner may PRESS on this work order's progress, decided here with the same rules the buttons are checked against on
            // the server (recordOwnerProgress / recordOwnerReportedDone) - so the portal shows only buttons that will work, and says why when none do.
            $reportedFinished = $this->reportedFinished($workOrder);
            $payload += $this->ownerProgressActions($workOrder, $reportedFinished);
            $latestRound = $this->latestRound($workOrder);
            $payload['reported_finished'] = $reportedFinished && $latestRound ? [
                'by' => $this->reportedBy($latestRound, $workOrder),
                'at' => $latestRound->opened_at?->toIso8601String(),
                'text' => 'Reported finished by ' . ($latestRound->reported_via === RentalWorkCompletionRound::VIA_OWNER_PORTAL ? 'you' : $this->reportedBy($latestRound, $workOrder))
                    . ($latestRound->opened_at ? ' on ' . $latestRound->opened_at->format('j M Y') : ''),
            ] : null;
            // the appointment box on the owner's card: only once the job is approved (the server refuses it before) and only until the work has started
            $workStarted = $reportedFinished || in_array($workOrder->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_DISPUTED], true);
            $payload['owner_work_started'] = $workStarted;
            $payload['owner_can_appoint'] = ! $workStarted && ! in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true) && $this->jobApproved($workOrder);
            // §17.31 — supplier invoices the agent has chosen to share. LANDLORD only: the tenant payload never carries this key.
            $payload['invoices'] = app(RentalWorkOrderInvoiceService::class)->ownerPayload($workOrder);
        }

        return $payload;
    }

    /** The quote the owner is being asked about / has approved: the live selected one. */
    public function ownerVisibleQuote(RentalWorkOrder $workOrder): ?RentalWorkOrderQuote
    {
        return RentalWorkOrderQuote::withoutGlobalScopes()
            ->where('agency_id', $workOrder->agency_id)->whereNull('deleted_at')
            ->where('rental_work_order_id', $workOrder->id)->where('is_selected', true)->first();
    }

    /**
     * Johan, 9 Oct 2026: the owner's progress buttons ("The work has started" / "The work is finished") on their work-order card must
     * only appear when pressing them works. Same rules as RentalWorkOrderService::recordOwnerProgress(): not for the agency's own team,
     * never on a closed work order, and only once the work has been given to the contractor (status ordered / in progress / disputed).
     *
     * @return array{owner_can_start:bool, owner_can_finish:bool, owner_progress_note:?string}
     */
    public function ownerProgressActions(RentalWorkOrder $workOrder, bool $checkWaiting = false): array
    {
        $none = fn (?string $note) => ['owner_can_start' => false, 'owner_can_finish' => false, 'owner_progress_note' => $note];

        if ($workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL
            || in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return $none(null);
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_REPORTED) {
            return $none('You can tell us when the work starts and finishes once the agency has sent this to the contractor.');
        }
        if ($checkWaiting) {
            return $none('The work has been reported finished. The agency will check it and close the job.');
        }

        return [
            'owner_can_start' => $workOrder->status === RentalWorkOrder::STATUS_ORDERED,
            'owner_can_finish' => true,
            'owner_progress_note' => null,
        ];
    }

    /**
     * What a client sees of ONE round: when it was reported done and by whom, how it ended, the tenant's own note and
     * their dispute photos. Dispute photos appear HERE and nowhere else (§17.24 item 4).
     *
     * @return array<string, mixed>
     */
    public function roundPayload(RentalWorkCompletionRound $round, RentalWorkOrder $workOrder): array
    {
        $photos = RentalWorkOrderPhoto::withoutGlobalScopes()
            ->where('agency_id', $workOrder->agency_id)
            ->where('rental_completion_round_id', $round->id)
            ->where('photo_type', RentalWorkOrder::PHOTO_DISPUTE)
            ->orderBy('id')->get();

        return [
            'id' => $round->id,
            'round_no' => $round->round_no,
            'reported_at' => $round->opened_at?->toIso8601String(),
            'reported_by' => $this->reportedBy($round, $workOrder),
            'outcome' => $round->outcome,
            'outcome_label' => match ($round->outcome) {
                RentalWorkCompletionRound::OUTCOME_CONFIRMED => 'Confirmed done',
                RentalWorkCompletionRound::OUTCOME_DISPUTED => 'Reported not complete',
                RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE => 'Accepted — no response',
                RentalWorkCompletionRound::OUTCOME_NO_TENANT => 'No tenant check',
                default => 'Not answered (optional)',
            },
            'responded_at' => $round->responded_at?->toIso8601String(),
            'response_note' => $round->response_note,
            'photos' => $photos->map(fn (RentalWorkOrderPhoto $p) => $this->photoView->photoPayload($p))->values()->all(),
        ];
    }

    private function card(RentalWorkOrder $workOrder): ?RentalJobCard
    {
        return RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $workOrder->agency_id)->whereNull('deleted_at')
            ->where('rental_work_order_id', $workOrder->id)->orderByDesc('id')->first();
    }

    /** @return \Illuminate\Support\Collection<int, RentalWorkCompletionRound> oldest first */
    private function rounds(RentalWorkOrder $workOrder)
    {
        return RentalWorkCompletionRound::withoutGlobalScopes()
            ->where('agency_id', $workOrder->agency_id)
            ->where('rental_work_order_id', $workOrder->id)
            ->orderBy('round_no')->get();
    }

    private function latestRound(RentalWorkOrder $workOrder): ?RentalWorkCompletionRound
    {
        return $this->rounds($workOrder)->last();
    }
}
