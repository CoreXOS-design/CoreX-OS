<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 / §17.10.6 — Build 3's plug-in slot in the crew view: while a job is
 * DISPUTED, the banner with the tenant's note and photos, and "Report fixed" (the existing "Mark work completed"
 * re-labelled by _job-body). [] for any job that is not disputed.
 *
 * Plain values only — never a model, never a price, an owner amount, or the tenant's name or contact details (the
 * crew sees what is wrong, not who said it). Guarded by CrewPayloadNeverCarriesSellingTest. The tenant's DISPUTE photos
 * (RentalWorkOrder::PHOTO_DISPUTE) appear HERE and nowhere else in the crew payload. Rendered by
 * rentals/crew-link/_block-dispute.blade.php.
 */
final class CrewDisputeBlock
{
    /** @return array<string, mixed> */
    public static function for(RentalJobCard $card, CrewViewContext $ctx): array
    {
        if ($card->status !== RentalJobCard::STATUS_DISPUTED || ! $card->rental_work_order_id) {
            return [];
        }

        $round = RentalWorkCompletionRound::withoutGlobalScopes()
            ->where('agency_id', $ctx->agencyId)
            ->where('rental_work_order_id', $card->rental_work_order_id)
            ->where('outcome', RentalWorkCompletionRound::OUTCOME_DISPUTED)
            ->orderByDesc('round_no')
            ->first();
        if (! $round) {
            return [];
        }

        $tz = $card->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $photos = RentalWorkOrderPhoto::withoutGlobalScopes()
            ->where('agency_id', $ctx->agencyId)
            ->where('rental_completion_round_id', $round->id)
            ->where('photo_type', RentalWorkOrder::PHOTO_DISPUTE)
            ->orderBy('id')
            ->get()
            ->map(fn (RentalWorkOrderPhoto $p) => ['url' => $p->storage_path])
            ->all();

        return [
            'round_no' => (int) $round->round_no,
            'note' => (string) $round->response_note,
            'raised_on' => $round->responded_at?->copy()->setTimezone($tz)->format('j M Y, H:i'),
            'photos' => $photos,
        ];
    }
}
