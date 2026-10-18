<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\Rentals\RentalWorkOrderClosed;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §17.16 / §17.23 defect 2 — the owner gets the FINAL STATEMENT (selling figures only; "Approved as
 * emergency work on {date}" when it was) whenever a work order closes, whichever route closed it: the office Complete form, the job
 * card's close (which used to leave the owner unmailed) or a contractor link. Fired from inside RentalWorkOrder::complete() via
 * RentalWorkOrderClosed, which can only happen once per work order (a closed work order refuses a second close).
 *
 * Synchronous and best-effort on purpose: domain events carry state and cannot be queued, QA has no queue worker, and a failing
 * mailbox must never stop the office from closing a job — a failure is logged and noted on the work order's history instead.
 */
class SendOwnerFinalStatement
{
    public function handle(RentalWorkOrderClosed $event): void
    {
        try {
            $workOrder = RentalWorkOrder::withoutGlobalScopes()->find($event->workOrder->getKey());
            if (! $workOrder) {
                return;
            }
            app(RentalWorkOrderService::class)->sendOwnerFinalStatement($workOrder);
        } catch (\Throwable $e) {
            Log::warning('Owner final statement failed', ['work_order_id' => $event->workOrder->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
