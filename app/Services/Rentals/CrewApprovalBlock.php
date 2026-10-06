<?php

namespace App\Services\Rentals;

use App\Models\RentalEmergencyApproval;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;

/**
 * .ai/specs/rental-work-orders.md §17.7 / §17.8.4 — Build 2's slot in the crew view: the approval STATE the crew needs.
 *   - "Approved to proceed (emergency)" — a chip, nothing more: never the owner's name, contact or any amount;
 *   - for extra work that became a variation, one line per added item with "Approved" / "Awaiting owner — do not start" /
 *     "Declined by owner" so the crew never does extra work the owner has not agreed to.
 * Plain values only (CrewJobService builds a flat payload so nothing the crew must not see can leak in). Never selling,
 * markup, margin or an approval amount — guarded by CrewPayloadNeverCarriesSellingTest.
 * Rendered by rentals/crew-link/_block-approval.blade.php. Returns [] when there is nothing to show.
 */
final class CrewApprovalBlock
{
    /** @return array<string, mixed> */
    public static function for(RentalJobCard $card, CrewViewContext $ctx): array
    {
        if (! $card->rental_work_order_id) {
            return [];
        }

        // A crew link has no staff user: the agency scope is off, soft deletes stay on, and the agency is pinned explicitly.
        $workOrder = RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $ctx->agencyId)->whereNull('deleted_at')->find($card->rental_work_order_id);
        if (! $workOrder) {
            return [];
        }

        $emergency = $workOrder->approval_basis === RentalWorkOrder::BASIS_EMERGENCY
            && RentalEmergencyApproval::withoutGlobalScopes()->where('rental_work_order_id', $workOrder->id)->whereNull('voided_at')->exists();

        $extras = [];
        $variations = RentalWorkOrderVariation::withoutGlobalScopes()
            ->where('rental_work_order_id', $workOrder->id)
            ->whereIn('status', [
                RentalWorkOrderVariation::STATUS_AWAITING_OWNER,
                RentalWorkOrderVariation::STATUS_AUTO_APPROVED,
                RentalWorkOrderVariation::STATUS_APPROVED,
                RentalWorkOrderVariation::STATUS_DECLINED,
            ])->orderBy('id')->get();

        foreach ($variations as $variation) {
            $lines = RentalJobCardLine::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('rental_work_order_variation_id', $variation->id)
                ->whereIn('office_status', [RentalJobCardLine::OFFICE_ACCEPTED, RentalJobCardLine::OFFICE_DECLINED_BY_OWNER])
                ->orderBy('id')->get();
            foreach ($lines as $line) {
                $state = match (true) {
                    $line->office_status === RentalJobCardLine::OFFICE_DECLINED_BY_OWNER => 'declined',
                    $variation->status === RentalWorkOrderVariation::STATUS_AWAITING_OWNER => 'awaiting',
                    default => 'approved',
                };
                $extras[] = [
                    'description' => (string) $line->description,
                    'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.'),
                    'unit' => (string) $line->unit,
                    'state' => $state,
                    'label' => match ($state) {
                        'declined' => 'Declined by owner',
                        'awaiting' => 'Awaiting owner — do not start',
                        default => 'Approved',
                    },
                ];
            }
        }

        if (! $emergency && $extras === []) {
            return [];
        }

        return [
            'emergency' => $emergency,
            'extras' => $extras,
            'hold' => collect($extras)->contains(fn ($e) => $e['state'] === 'awaiting'),
        ];
    }
}
