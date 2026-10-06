<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderQuote;

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
 *     Scheduled · In progress · Reported complete — please check · Not complete — reopened · Completed · Cancelled.
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

    /** The plain-words stage (§17.12) for this work order, as this audience should read it. */
    public function stageLabel(RentalWorkOrder $workOrder, string $audience = self::AUDIENCE_TENANT): string
    {
        $latest = $this->latestRound($workOrder);

        if ($workOrder->status === RentalWorkOrder::STATUS_CANCELLED) {
            return 'Cancelled';
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_DISPUTED) {
            return 'Not complete — reopened';
        }
        if ($latest && $latest->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT) {
            return 'Reported complete — please check';
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_COMPLETED) {
            return 'Completed';
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_IN_PROGRESS) {
            return 'In progress';
        }

        $card = $this->card($workOrder);
        if ($card && in_array($card->status, [RentalJobCard::STATUS_SCHEDULED, RentalJobCard::STATUS_IN_PROGRESS], true)) {
            return $card->status === RentalJobCard::STATUS_IN_PROGRESS ? 'In progress' : 'Scheduled';
        }
        if ($audience === self::AUDIENCE_LANDLORD && $workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING) {
            return 'Needs your decision';
        }
        if ($workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_APPROVED
            || ($card && $card->status === RentalJobCard::STATUS_APPROVED)) {
            return 'Approved';
        }
        if ($workOrder->status === RentalWorkOrder::STATUS_ORDERED || $card || $workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING) {
            return 'Being arranged';
        }

        return 'Reported';
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
        $external = $workOrder->assignment_type !== RentalWorkOrder::ASSIGNMENT_INTERNAL;

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
            'stage_label' => $this->stageLabel($workOrder, $audience),
            'who' => $external ? 'external_contractor' : 'our_team',
            'who_label' => $external ? 'External contractor' : 'Our maintenance team',
            // The contractor's NAME only — never their contact details, quote or rates.
            'contractor_name' => $external ? $workOrder->supplier()->withoutGlobalScopes()->withTrashed()->first()?->name : null,
            'scheduled_at' => $card?->scheduled_at?->toIso8601String(),
            'due_at' => $card?->due_at?->toIso8601String(),
            'completed_at' => $workOrder->completed_at?->toIso8601String(),
            'crew_completion' => $card ? $this->photoView->crewCompletion($card) : null,
            'photos' => $this->photoView->photosPayload($this->photoView->photosForWorkOrder($workOrder)),
            'rounds' => $rounds->map(fn (RentalWorkCompletionRound $r) => $this->roundPayload($r, $workOrder))->values()->all(),
            // The one open question for the tenant: "is this finished?" (null when nothing waits on them).
            'awaiting_answer' => $latest && $latest->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT
                && ! ($latest->window_ends_at && $latest->window_ends_at->isPast()) && $workOrder->status !== RentalWorkOrder::STATUS_CANCELLED
                ? [
                    'round_id' => $latest->id,
                    'round_no' => $latest->round_no,
                    'reported_by' => $latest->reported_by_label,
                    'reported_at' => $latest->opened_at?->toIso8601String(),
                    'answer_due' => $latest->window_ends_at?->toIso8601String(),
                ]
                : null,
        ];

        if ($audience === self::AUDIENCE_LANDLORD) {
            // The owner-facing amount only (§17.2): the selected quote's selling figure, else the final amount
            // once the work order is closed. Never cost, markup or margin.
            $selected = RentalWorkOrderQuote::withoutGlobalScopes()
                ->where('agency_id', $workOrder->agency_id)->whereNull('deleted_at')
                ->where('rental_work_order_id', $workOrder->id)->where('is_selected', true)->first();
            $payload['owner_facing_amount'] = $workOrder->status === RentalWorkOrder::STATUS_COMPLETED && $workOrder->cost_amount !== null
                ? (float) $workOrder->cost_amount
                : ($selected?->ownerFacingAmount());
            $payload['owner_approval_status'] = $workOrder->owner_approval_status;
        }

        return $payload;
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
            'reported_by' => $round->reported_by_label,
            'outcome' => $round->outcome,
            'outcome_label' => match ($round->outcome) {
                RentalWorkCompletionRound::OUTCOME_CONFIRMED => 'Confirmed done',
                RentalWorkCompletionRound::OUTCOME_DISPUTED => 'Reported not complete',
                RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE => 'Accepted — no response',
                RentalWorkCompletionRound::OUTCOME_NO_TENANT => 'No tenant check',
                default => 'Waiting for the tenant',
            },
            'answer_due' => $round->window_ends_at?->toIso8601String(),
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
