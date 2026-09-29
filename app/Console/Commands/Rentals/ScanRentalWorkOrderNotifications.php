<?php

namespace App\Console\Commands\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-work-orders.md §4/§6 — "a notification system and
 * tracking way to keep track of work orders," Johan's own words, the
 * "overdue" half. Scans every agency's own overdue_reminder_days window
 * (never hardcoded) and fires one internal notification per overdue work
 * order — NotificationDispatcher's own dedup (threshold_hit_at) keeps this
 * from re-notifying every scan tick for the same stale state.
 */
class ScanRentalWorkOrderNotifications extends Command
{
    protected $signature = 'notifications:scan-rental-work-orders';

    protected $description = 'Notify assigned agents of rental work orders overdue past their agency\'s reminder window';

    public function handle(RentalWorkOrderService $service): int
    {
        $agencyIds = RentalWorkOrder::query()
            ->whereIn('status', [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS])
            ->distinct()
            ->pluck('agency_id');

        $notified = 0;

        foreach ($agencyIds as $agencyId) {
            $days = RentalWorkOrderSetting::overdueReminderDaysFor($agencyId);

            $overdue = RentalWorkOrder::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->overdue($days)
                ->get();

            foreach ($overdue as $workOrder) {
                $service->notifyOverdue($workOrder);
                $notified++;
            }
        }

        $this->info("Scanned rental work orders — {$notified} overdue notification(s) fired.");

        return self::SUCCESS;
    }
}
