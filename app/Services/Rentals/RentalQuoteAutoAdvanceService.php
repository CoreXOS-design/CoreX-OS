<?php

namespace App\Services\Rentals;

use App\Models\RentalAuthorisation;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31.4 (S4) - cost authority stays, but the agent no longer walks the chain by hand. When a quote comes in
 * (the contractor's own link, or typed by the agent) it is SELECTED if nothing live is selected yet; when the selected quote is inside the owner's repair limit
 * (RentalAuthorisationService::costEvaluation - the same limit the agent's "Authorise" button uses) the cost is AUTHORISED at once and the go-ahead goes out by
 * itself (RentalGoAheadService::onCostAuthorised). Above the limit nothing is authorised: the owner decides on their link, or the agent presses "Owner authorised
 * by phone/WhatsApp" (RentalAuthorisationService::recordOwnerAuthorisedQuick). Never raises a limit, never authorises more than the limit allows, and never
 * overrides a decision a person already made.
 */
final class RentalQuoteAutoAdvanceService
{
    public function __construct(private RentalAuthorisationService $auth) {}

    /**
     * @return array{selected: bool, authorised: bool, why: string}
     */
    public function afterQuote(RentalWorkOrder $wo, ?RentalWorkOrderQuote $quote = null, ?User $actor = null): array
    {
        $out = ['selected' => false, 'authorised' => false, 'why' => ''];
        try {
            $wo = RentalWorkOrder::withoutGlobalScopes()->with(['quotes', 'property'])->find($wo->id);
            if (! $wo || in_array($wo->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true) || $wo->isOwnerContractor()) {
                return $out + ['why' => 'closed or the owner arranges it'];
            }
            $actor ??= $this->responsibleUser($wo);
            if (! $actor) {
                return $out + ['why' => 'nobody to act for'];
            }

            // 1. select: nothing live is selected yet, or the selected one is this supplier's earlier quote (a revised price replaces it)
            $selected = $wo->quotes->whereNull('declined_at')->firstWhere('is_selected', true);
            if ($quote && ! $quote->is_selected && ! $quote->isSuperseded()
                && (! $selected || ((int) $selected->agency_service_provider_id === (int) $quote->agency_service_provider_id && ! $this->decided($wo)))) {
                $wo->selectQuote($quote, $actor);
                $out['selected'] = true;
                $wo = RentalWorkOrder::withoutGlobalScopes()->with('property')->find($wo->id);
            }
            if (! $wo->quotes()->where('is_selected', true)->whereNull('declined_at')->exists()) {
                return $out + ['why' => 'no quote is selected'];
            }

            // 2. authorise, only inside the owner's repair limit and only when no cost decision stands
            return $this->authoriseInsideLimit($wo, $actor, $out);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Quote auto-advance stopped', ['work_order' => $wo->id, 'error' => $e->getMessage()]);

            return $out + ['why' => 'stopped: ' . $e->getMessage()];
        }
    }

    /** The cost step on its own (the crew route, once the card's price is confirmed). */
    public function authoriseInsideLimit(RentalWorkOrder $wo, User $actor, array $out = ['selected' => false, 'authorised' => false, 'why' => '']): array
    {
        if ($this->auth->hasCostAuthority($wo) || $this->auth->costBlockReason($wo) !== null) {
            return $out + ['why' => 'already decided or blocked'];
        }
        if ($wo->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING || $wo->owner_approval_status === RentalWorkOrder::APPROVAL_DECLINED) {
            return $out + ['why' => 'with the owner'];
        }
        $eval = $this->auth->costEvaluation($wo);
        if (! $eval['agent_can']) {
            return $out + ['why' => 'above the repair limit - the owner decides'];
        }
        $this->auth->authoriseCost($wo, $actor, ['auto' => true]);

        return ['authorised' => true, 'why' => 'inside the repair limit'] + $out;
    }

    private function decided(RentalWorkOrder $wo): bool
    {
        return $this->auth->hasCostAuthority($wo);
    }

    /** Whoever sent the job or made the work order answers for what is done on their behalf; else the property's agent. */
    private function responsibleUser(RentalWorkOrder $wo): ?User
    {
        $id = \App\Models\RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->whereNotNull('sent_by_user_id')->orderByDesc('id')->value('sent_by_user_id')
            ?? $wo->created_by_user_id
            ?? $wo->property?->agent_id;

        return $id ? User::withoutGlobalScopes()->find($id) : null;
    }
}
