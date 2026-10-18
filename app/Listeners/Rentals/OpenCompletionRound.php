<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalCompletionService;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §17.10.1 — "work reported done" opens a completion round and asks the tenant to check
 * it. Hears every crew completion — the crew link, the crew page and an uploaded signed copy all dispatch
 * RentalJobCardCrewCompleted — so one listener covers all three; the office's own "Worker — done" button and
 * "Contractor reports done" call RentalCompletionService::openRound() directly (they dispatch no such event).
 *
 * Synchronous on purpose (domain events carry readonly state and cannot be queued); the tenant mail it triggers is
 * queued by openRound(). It must NEVER break the crew's completion — any failure is logged and the crew still sees
 * "completed".
 */
class OpenCompletionRound
{
    public function handle(RentalJobCardCrewCompleted $event): void
    {
        $card = $event->jobCard;
        if (! $card->rental_work_order_id) {
            return; // a card that predates "no card without its work order" has nothing to check against
        }

        $workOrder = RentalWorkOrder::withoutGlobalScopes()->find($card->rental_work_order_id);
        if (! $workOrder) {
            return;
        }

        try {
            app(RentalCompletionService::class)->openRound($workOrder, [
                'reported_by_label' => $event->signedByName,
                'reported_via' => match ($event->via) {
                    'crew_page' => RentalWorkCompletionRound::VIA_CREW_PAGE,
                    'signed_copy' => RentalWorkCompletionRound::VIA_SIGNED_COPY,
                    'office' => RentalWorkCompletionRound::VIA_OFFICE,
                    default => RentalWorkCompletionRound::VIA_CREW_LINK,
                },
                'reported_by_user_id' => $event->actorUserId,
                'rental_job_card_id' => $card->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Completion round could not be opened', ['job_card_id' => $card->id, 'error' => $e->getMessage()]);
        }
    }
}
