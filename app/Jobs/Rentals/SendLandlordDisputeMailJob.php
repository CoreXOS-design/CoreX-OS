<?php

declare(strict_types=1);

namespace App\Jobs\Rentals;

use App\Mail\Rentals\RentalLandlordDisputeMail;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §17.10.6 / §17.16 — tell the owner that the tenant says the finished work is not
 * complete and the office is arranging a fix. Gated by the agency setting `notify_landlord_on_dispute` (default on);
 * sent through the agency mailbox path as the property's responsible agent. Best-effort, never breaks the dispute.
 * Carries the tenant's note and photo links; NEVER an amount, the tenant's contact details, or any cost.
 */
class SendLandlordDisputeMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $roundId)
    {
    }

    public function handle(RentalMailDispatcher $dispatcher, RentalCompletionService $completion): void
    {
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->find($this->roundId);
        if (! $round || $round->outcome !== RentalWorkCompletionRound::OUTCOME_DISPUTED) {
            return;
        }
        if (! RentalWorkOrderSetting::notifyLandlordOnDisputeFor($round->agency_id)) {
            return;
        }

        $workOrder = RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($round->rental_work_order_id);
        if (! $workOrder || $workOrder->trashed()) {
            return;
        }

        $property = $workOrder->property()->withoutGlobalScopes()->withTrashed()->first();
        $landlord = $property?->landlordContact();
        if (! $landlord || ! $landlord->email || ! filter_var(trim((string) $landlord->email), FILTER_VALIDATE_EMAIL)) {
            $workOrder->updates()->create(['agency_id' => $workOrder->agency_id, 'update_type' => 'note', 'note' => 'Owner not emailed about the dispute — no landlord email on file']);

            return;
        }

        $photos = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->orderBy('id')->pluck('storage_path')->all();

        try {
            $dispatcher->send(trim((string) $landlord->email), new RentalLandlordDisputeMail(
                $workOrder, (string) $landlord->full_name, (string) $round->response_note, $photos, $completion->agentFor($workOrder),
            ));
            $workOrder->updates()->create(['agency_id' => $workOrder->agency_id, 'update_type' => 'note', 'note' => 'Owner emailed: the tenant reported the work as not complete']);
        } catch (\Throwable $e) {
            Log::warning('Landlord dispute email failed', ['round_id' => $round->id, 'error' => $e->getMessage()]);
            $workOrder->updates()->create(['agency_id' => $workOrder->agency_id, 'update_type' => 'note', 'note' => 'Owner email about the dispute could not be sent']);
        }
    }
}
