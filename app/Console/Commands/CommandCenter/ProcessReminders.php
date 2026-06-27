<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Notifications\EventDueReminderNotification;
use App\Notifications\TaskDueReminderNotification;
use App\Services\CommandCenter\NotificationPreferenceService;
use App\Services\Push\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessReminders extends Command
{
    protected $signature = 'command-center:reminders';
    protected $description = 'Process calendar event and task reminders — sends notifications before due dates';

    public function handle(PushNotificationService $push, NotificationPreferenceService $prefs): int
    {
        $tasksSent  = 0;
        $eventsSent = 0;
        $overdue    = 0;

        // ── 1. Mark overdue events ──
        $overdueCount = CalendarEvent::where('status', 'pending')
            ->where('event_date', '<', now())
            ->update(['status' => 'overdue']);
        $overdue += $overdueCount;

        // ── 2. Send task due reminders ──
        // Get all users with task reminder enabled
        User::where('is_active', 1)->chunk(50, function ($users) use (&$tasksSent) {
            foreach ($users as $user) {
                try {
                    $settings = UserDashboardSetting::getEffective($user);

                    if (!$settings->task_due_reminders || !$settings->notify_in_app) {
                        continue;
                    }

                    $hoursBefore = $settings->task_reminder_hours_before ?? 4;
                    $windowStart = now();
                    $windowEnd   = now()->addHours($hoursBefore);

                    // Find tasks due within the reminder window that haven't been reminded yet
                    $tasks = CommandTask::forUser($user->id)
                        ->whereNotIn('status', ['done', 'dismissed'])
                        ->where('send_reminder', true)
                        ->whereNotNull('due_date')
                        ->whereBetween('due_date', [$windowStart, $windowEnd])
                        ->whereNull('metadata->reminder_sent')
                        ->get();

                    foreach ($tasks as $task) {
                        $user->notify(new TaskDueReminderNotification($task));

                        // Mark as reminded so we don't send again
                        $meta = $task->metadata ?? [];
                        $meta['reminder_sent'] = now()->toIso8601String();
                        $task->update(['metadata' => $meta]);

                        $tasksSent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Task reminder failed for user #{$user->id}: {$e->getMessage()}");
                }
            }
        });

        // ── 3. Send event due reminders ──
        User::where('is_active', 1)->chunk(50, function ($users) use (&$eventsSent, $push, $prefs) {
            foreach ($users as $user) {
                try {
                    $settings = UserDashboardSetting::getEffective($user);

                    // In-app + email travel on the existing Laravel notification
                    // (db always; mail when notify_email). Push is resolved
                    // independently through the unified preference matrix so a
                    // user who silenced in-app can still get a device push, and
                    // one who silenced push doesn't. effective('agent.event_due')
                    // already ANDs the user's own notify_push master with the
                    // per-event channel_push and the event-type enabled flag.
                    $inAppOn  = (bool) $settings->notify_in_app;
                    $pushEff  = $prefs->effective($user, 'agent.event_due');
                    $pushOn   = $pushEff && $pushEff['enabled'] && $pushEff['channel_push'];

                    if (!$inAppOn && !$pushOn) {
                        continue; // user wants no event reminders on any channel
                    }

                    // Minutes is the canonical lead-time (mobile sends a total
                    // hours+minutes value). Fall back to the legacy hours column
                    // ×60 for any row written before the minutes migration.
                    $minutesBefore = $settings->event_reminder_minutes_before
                        ?? (($settings->event_reminder_hours_before ?? 24) * 60);
                    $windowStart = now();
                    $windowEnd   = now()->addMinutes($minutesBefore);

                    $events = CalendarEvent::forUser($user->id)
                        ->where('status', 'pending')
                        ->where('send_reminder', true)
                        ->whereBetween('event_date', [$windowStart, $windowEnd])
                        ->whereNull('metadata->reminder_sent')
                        ->get();

                    foreach ($events as $event) {
                        if ($inAppOn) {
                            $user->notify(new EventDueReminderNotification($event));
                        }

                        // FCM push through the storm-guarded funnel (mirrors
                        // PushNewPortalLeadToMobile). Failure-isolated so a push
                        // hiccup never blocks the in-app/email send or the
                        // reminder_sent dedup mark below.
                        if ($pushOn) {
                            try {
                                $push->sendToUser(
                                    $user,
                                    sprintf('user:%s|agent.event_due|CalendarEvent:%s', $user->id, $event->id),
                                    (new EventDueReminderNotification($event))->toFcmPayload(),
                                );
                            } catch (\Throwable $e) {
                                Log::warning("Event reminder push failed for user #{$user->id}, event #{$event->id}: {$e->getMessage()}");
                            }
                        }

                        $meta = $event->metadata ?? [];
                        $meta['reminder_sent'] = now()->toIso8601String();
                        $event->update(['metadata' => $meta]);

                        $eventsSent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Event reminder failed for user #{$user->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Done. Tasks: {$tasksSent} reminded. Events: {$eventsSent} reminded. Overdue: {$overdue} marked.");

        return self::SUCCESS;
    }
}
