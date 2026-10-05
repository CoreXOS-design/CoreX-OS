<?php

namespace App\Console\Commands;

use App\Models\RentalInspection;
use App\Models\RentalInspectionNotification;
use App\Models\RentalInspectionSetting;
use App\Services\Rentals\RentalInspectionNotificationService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-inspections.md §43 — "a reminder N days before (default
 * 1, 0 = off), via a scheduled command." Per-agency offset
 * (RentalInspectionSetting::reminderDaysBeforeFor()) — an agency with the
 * reminder off (0) is simply never matched by its own window below.
 *
 * Idempotent per inspection-per-day: a reminder already logged today for
 * this inspection is never sent twice, even if the command runs more than
 * once (manual re-run, a missed/late cron tick) — checked against
 * RentalInspectionNotification rather than a new "reminder_sent_at" column
 * on the inspection, since the log is already the source of truth for
 * every notification this build sends.
 */
class SendRentalInspectionReminders extends Command
{
    protected $signature = 'rentals:send-inspection-reminders';

    protected $description = 'Send a reminder for scheduled rental inspections whose agency-configured reminder window has arrived.';

    public function handle(RentalInspectionNotificationService $notificationService): int
    {
        $sent = 0;

        RentalInspection::query()
            ->whereNotNull('scheduled_for')
            ->whereIn('status', [RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS, RentalInspection::STATUS_AWAITING_SIGNATURE])
            ->where('scheduled_for', '>=', now()->toDateString())
            ->chunk(100, function ($inspections) use ($notificationService, &$sent) {
                foreach ($inspections as $inspection) {
                    $reminderDays = RentalInspectionSetting::reminderDaysBeforeFor($inspection->agency_id);
                    if ($reminderDays <= 0) {
                        continue;
                    }

                    // Fires on the ONE calendar day that is exactly
                    // $reminderDays before the booked date — not "any day
                    // from then on," which would otherwise re-fire daily
                    // for every day between the offset and the inspection
                    // itself. Date granularity (not time-of-day): a
                    // reminder is a once-a-day cron concern, same as every
                    // other *_window_days setting on this model.
                    $reminderDate = $inspection->scheduled_for->copy()->subDays($reminderDays)->toDateString();
                    if (now()->toDateString() !== $reminderDate) {
                        continue;
                    }

                    $alreadySentToday = RentalInspectionNotification::where('rental_inspection_id', $inspection->id)
                        ->where('event', RentalInspectionNotification::EVENT_REMINDER)
                        ->whereDate('created_at', now()->toDateString())
                        ->exists();
                    if ($alreadySentToday) {
                        continue;
                    }

                    $notificationService->notifyReminder($inspection);
                    $this->line("  Reminder sent for inspection #{$inspection->id} ({$inspection->property?->buildDisplayAddress()})");
                    $sent++;
                }
            });

        $this->info("Done. Reminders sent: {$sent}.");

        return 0;
    }
}
