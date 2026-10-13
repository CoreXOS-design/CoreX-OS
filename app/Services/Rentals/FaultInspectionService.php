<?php

namespace App\Services\Rentals;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.4 - DECISION 1, the agent's two actions once someone is picked to go and look at a fault:
 *  - "Send <name> to inspect": allowed when the visit costs the owner nothing (no call-out fee, or our own crew) or when the owner's call-out permission covers the
 *    fee. A fee NOT KNOWN is treated as a fee and can never be covered (it has no amount). The authorisation record, the work order (and with it the contractor's job
 *    email and link, or the crew's job card) are made in ONE step, and the owner is told at once.
 *  - "Send to owner to authorise": the fault goes to the owner on their link with the proposed contractor.
 * Replaces cc5's FirstResponderDispatcher seam. Fees are read ONLY through the supplier's calloutFeeStatus() / calloutFeeAmount().
 */
class FaultInspectionService
{
    public const CREW_KEY = 'crew';
    public const NO_CONTACT_LINE = 'No email or mobile on file - they cannot be sent the job.';

    public function __construct(private RentalAuthorisationService $authorisations) {}

    /** The most the owner lets the agent pay for a call-out without asking - read ONLY through the owner-settings resolver (null = must ask the owner). */
    private function calloutAllowance(Property $property): ?float
    {
        return RentalOwnerFaultSettings::for($property)->calloutAllowedUpTo();
    }

    // ───────────────────────── can the agent send them on their own authority? ─────────────────────────

    /**
     * @return array{allowed:bool, basis:?string, status:string, fee:?float, allowance:?float, line:?string}
     */
    public function evaluate(Property $property, string $name, string $status, ?float $fee): array
    {
        $allowance = $this->calloutAllowance($property);
        $base = ['status' => $status, 'fee' => $fee, 'allowance' => $allowance];

        if ($status === AgencyServiceProvider::CALLOUT_NO) {
            return $base + ['allowed' => true, 'basis' => RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE, 'line' => null];
        }
        if ($status === AgencyServiceProvider::CALLOUT_YES && $fee !== null && $allowance !== null && round($fee * 100) <= round($allowance * 100)) {
            return $base + ['allowed' => true, 'basis' => RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING,
                'line' => $name . ' charges a call-out fee of ' . AgencyServiceProvider::formatRand($fee) . '. This is inside the owner\'s call-out permission of ' . AgencyServiceProvider::formatRand($allowance) . '. The owner will be told.'];
        }

        return $base + ['allowed' => false, 'basis' => null, 'line' => $status === AgencyServiceProvider::CALLOUT_YES && $fee !== null
            ? $name . ' charges a call-out fee of ' . AgencyServiceProvider::formatRand($fee) . '. The owner must authorise this first.'
            : 'We don\'t know whether ' . $name . ' charges a call-out fee. The owner must authorise this first - or set the fee on the contractor\'s card.'];
    }

    /** Our own crew: no call-out fee, so no cost to the owner for the visit. */
    public function evaluateCrew(): array
    {
        return ['allowed' => true, 'basis' => RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE, 'status' => AgencyServiceProvider::CALLOUT_NO, 'fee' => null, 'allowance' => null, 'line' => null];
    }

    /**
     * What the picker's shared notice block needs for each choosable row: id => {name, text, status, inspect_allowed}. The notice text is the call-out
     * sentence (cc4's wording when the visit is allowed, the "owner must authorise" line when it is not); a crew / a no-fee contractor shows none.
     *
     * @param Collection<int, array<string,mixed>> $pickerRows RentalFaultContractorService::pickerFor()
     * @return array<string, array{name:string, text:string, status:string, inspect_allowed:bool}>
     */
    public function noticeMapFor(RentalFaultReport $fault, Collection $pickerRows): array
    {
        $property = $fault->property;
        $map = [];
        foreach ($pickerRows as $row) {
            $e = $property ? $this->evaluate($property, (string) $row['name'], (string) $row['callout_status'], $row['callout_amount'] ?? null) : ['allowed' => false, 'line' => null];
            $noContact = ! ($row['has_contact'] ?? true);
            $map[(string) $row['id']] = [
                'name' => (string) $row['name'],
                // allowed + a fee: cc4's "...The owner will be told." (or the permission sentence); blocked: the "must authorise first" line; no fee: nothing.
                // A contractor with no email or mobile cannot be sent the job at all (the owner can still be asked).
                'text' => $noContact ? self::NO_CONTACT_LINE : (string) ($e['line'] ?? ($row['callout_notice'] ?? '')),
                'status' => (string) $row['callout_status'],
                'inspect_allowed' => ! $noContact && (bool) $e['allowed'],
            ];
        }
        $map[self::CREW_KEY] = ['name' => 'our own crew', 'text' => '', 'status' => AgencyServiceProvider::CALLOUT_NO, 'inspect_allowed' => true];

        return $map;
    }

    // ───────────────────────── the two actions ─────────────────────────

    /** The ways a fault cannot have someone sent to look at it right now (null = it can). */
    public function blockReason(RentalFaultReport $fault, bool $versionSavedByPress = false): ?string
    {
        if ($held = app(RentalOwnerHoldService::class)->holdReason($fault)) {   // section 22.7 - paused by the owner
            return $held;
        }
        if ($fault->isTenantSelfResolved()) {
            return 'The tenant closed this themselves - there is nothing to inspect.';
        }
        // the page's buttons save the owner version themselves (they send its fields with the press), so an unsaved draft is not a reason to refuse there
        if ($fault->owner_version_saved_at === null && ! $versionSavedByPress) {
            return 'Save what the owner will see first - it is what the owner will read.';
        }
        if ($fault->hasLiveWorkOrder()) {
            return 'Someone has already been sent - this fault already has a work order.';
        }
        if (in_array($fault->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED, RentalFaultReport::STATUS_DECLINED], true)) {
            return 'This fault is closed.';
        }
        if ($fault->decision() !== null) {
            return 'The owner has already decided on this - appoint the contractor in the box above.';
        }

        return null;
    }

    /**
     * "Send <name> to inspect". $choice: assignment_type (outside_supplier | internal), agency_service_provider_id, rental_crew_id.
     *
     * @param array<string,mixed> $choice
     * @return array{work_order: RentalWorkOrder, job_card: ?\App\Models\RentalJobCard, authorisation: RentalAuthorisation}
     */
    public function sendToInspect(RentalFaultReport $fault, User $by, array $choice): array
    {
        if ($reason = $this->blockReason($fault)) {
            throw new \LogicException($reason);
        }
        $property = $fault->property()->withoutGlobalScopes()->firstOrFail();
        $internal = ($choice['assignment_type'] ?? null) === RentalWorkOrder::ASSIGNMENT_INTERNAL;

        if ($internal) {
            $eval = $this->evaluateCrew();
            $label = 'our own maintenance crew';
            $supplierId = null;
        } else {
            $supplierId = (int) ($choice['agency_service_provider_id'] ?? 0);
            $supplier = AgencyServiceProvider::withoutGlobalScopes()
                ->where('agency_id', $fault->agency_id)->where('is_active', true)->whereNull('deleted_at')->maintenanceContractors()->find($supplierId);
            if (! $supplier) {
                throw new \LogicException('Choose who goes to look.');
            }
            $eval = $this->evaluate($property, $supplier->displayName(), $supplier->calloutFeeStatus(), $supplier->calloutFeeAmount());
            $label = $supplier->displayName();
            if (! app(RentalContractorJobService::class)->contactFor($supplier)['has_contact']) {
                throw new \LogicException(self::NO_CONTACT_LINE);
            }
            if (! $eval['allowed']) {
                throw new \LogicException((string) $eval['line']);
            }
        }
        $choice['assignment_type'] = $internal ? RentalWorkOrder::ASSIGNMENT_INTERNAL : RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER;

        $row = null;
        $result = app(RentalWorkOrderService::class)->createFromFaultDecision($fault, $by, $choice, function (RentalFaultReport $locked) use (&$row, $by, $eval, $label, $supplierId) {
            $row = $this->authorisations->recordAgentInspection($locked, $by, (string) $eval['basis'], $label, $supplierId, $eval['fee'], $eval['allowance']);
        });

        $fresh = RentalFaultReport::withoutGlobalScopes()->findOrFail($fault->id);
        $fresh->forceFill([
            'arranged_by_agent_at' => now(), 'arranged_by_user_id' => $by->id,
            'arranged_basis' => $eval['basis'] === RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING ? RentalFaultReport::ARRANGED_INSPECT_CALLOUT : RentalFaultReport::ARRANGED_INSPECT_NO_FEE,
            'arranged_limit_amount' => $eval['allowance'],
        ])->save();

        // the owner is told at once: who, why, the fee
        $this->authorisations->tellOwner($row);

        return $result + ['authorisation' => $row->fresh()];
    }

    /**
     * "Send to owner to authorise": the same send as "Save and send to owner", carrying the proposed contractor (and its fee) so the owner can see who
     * would come and what the visit costs before they choose "Send someone to look and quote" or "Decline".
     *
     * @param array<string,mixed> $choice
     */
    public function sendToOwnerToAuthorise(RentalFaultReport $fault, User $by, array $choice): void
    {
        if ($reason = $this->blockReason($fault)) {
            throw new \LogicException($reason);
        }
        if ($fault->sent_to_owner_at !== null) {
            throw new \LogicException('This has already been sent to the owner - record their decision if they have told you.');
        }
        $internal = ($choice['assignment_type'] ?? null) === RentalWorkOrder::ASSIGNMENT_INTERNAL;
        $label = 'our own maintenance crew';
        $supplierId = null;
        if (! $internal && ! empty($choice['agency_service_provider_id'])) {
            $supplier = AgencyServiceProvider::withoutGlobalScopes()
                ->where('agency_id', $fault->agency_id)->where('is_active', true)->whereNull('deleted_at')->maintenanceContractors()->find((int) $choice['agency_service_provider_id']);
            if (! $supplier) {
                throw new \LogicException('Choose who would go to look.');
            }
            $supplierId = (int) $supplier->id;
            $label = $supplier->displayName();
        } elseif (! $internal) {
            $label = null;   // nobody proposed: the owner is simply asked
        }

        $fault->forceFill([
            'proposed_assignment' => $label === null ? null : ($internal ? RentalWorkOrder::ASSIGNMENT_INTERNAL : RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER),
            'proposed_supplier_id' => $supplierId,
            'proposed_crew_id' => $internal && ! empty($choice['rental_crew_id']) ? (int) $choice['rental_crew_id'] : null,
        ])->save();

        $fault->requestApproval($by);
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_SENT_TO_OWNER_TO_AUTHORISE, $by,
            $by->name . ' sent this to the owner to authorise' . ($label ? ' - proposed: ' . $label : '') . '.');
    }

    /** What the owner reads about the contractor the agent proposed: name and the call-out line (a crew has none; null when nobody was proposed). */
    public function proposedFor(RentalFaultReport $fault): ?array
    {
        if ($fault->proposed_assignment === null) {
            return null;
        }
        if ($fault->proposed_assignment === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            return ['name' => 'our maintenance team', 'callout' => null];
        }
        $p = $fault->proposed_supplier_id ? AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->find($fault->proposed_supplier_id) : null;

        if (! $p) {
            return null;
        }
        // the owner is told what the visit costs BEFORE they choose: the fee, or plainly that there is none / that it is not known
        $callout = match ($p->calloutFeeStatus()) {
            AgencyServiceProvider::CALLOUT_YES => $p->calloutFeeLine(),
            AgencyServiceProvider::CALLOUT_NO => 'No call-out fee.',
            default => 'Call-out fee not known.',
        };

        return ['name' => $p->displayName(), 'callout' => $callout];
    }
}
