<?php

namespace App\Console\Commands\CommandCenter;

use App\Mail\CommandCenter\TaskDueDigest;
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
use Illuminate\Support\Facades\Mail;

class ProcessReminders extends Command
{
    protected $signature = 'command-center:reminders';
    protected $description = 'Process calendar event and task reminders — sends notifications before due dates';

    public function handle(
        PushNotificationService $push,
        NotificationPreferenceService $prefs,
        \App\Services\CommandCenter\CalendarReminderService $reminders,
    ): int {
        $tasksSent  = 0;
        $eventsSent = 0;
        $overdue    = 0;

        // ── 1. Mark overdue events (actionable only) ──
        // Informational events (markers, time-blocks, and anything the user set
        // "No feedback needed") must NEVER go overdue. Effective nature =
        // per-event metadata override ?? class default, so only genuinely
        // actionable events are swept to 'overdue'.
        $informationalClasses = \App\Models\CommandCenter\CalendarEventClassSetting::withoutGlobalScopes()
            ->where('event_nature', 'informational')
            ->pluck('event_class')->unique()->values()->all();

        $overdueCount = CalendarEvent::where('status', 'pending')
            ->where('event_date', '<', now())
            ->where(function ($q) use ($informationalClasses) {
                // Explicit per-event override to actionable...
                $q->where('metadata->event_nature', 'actionable')
                  // ...OR no override and the class is not informational.
                  ->orWhere(function ($q2) use ($informationalClasses) {
                      $q2->whereNull('metadata->event_nature');
                      if (!empty($informationalClasses)) {
                          $q2->whereNotIn('category', $informationalClasses);
                      }
                  });
            })
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
                        // Per-task in-app notification only (database channel).
                        // Email is aggregated below into a single digest so a
                        // user with many due tasks gets one email, not one each.
                        $user->notify(new TaskDueReminderNotification($task));

                        // Mark as reminded so we don't send again
                        $meta = $task->metadata ?? [];
                        $meta['reminder_sent'] = now()->toIso8601String();
                        $task->update(['metadata' => $meta]);

                        $tasksSent++;
                    }

                    // One aggregated email per user per run, replacing the old
                    // one-email-per-task flood. Only when the user opted into
                    // email and at least one task crossed the reminder window.
                    if ($tasks->isNotEmpty() && $settings->notify_email && $user->email) {
                        try {
                            Mail::to($user->email)->send(new TaskDueDigest($user, $tasks));
                        } catch (\Throwable $e) {
                            Log::warning("Task digest email failed for user #{$user->id}: {$e->getMessage()}");
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Task reminder failed for user #{$user->id}: {$e->getMessage()}");
                }
            }
        });

        // ── 3. Send event due reminders (AT-178) ──
        // Delegated to the CalendarReminderService, which fires per-event lead-time
        // offsets on per-event channels (popup/email), to every user ON the event
        // (owner + agent attendees), per recurring OCCURRENCE, with exactly-once
        // delivery guarded by the calendar_reminders_log UNIQUE index. This replaces
        // the previous per-user, single-lead-time path whose event-level
        // metadata->reminder_sent flag reminded only the first user processed, once,
        // and never fired occurrences 2..N of a recurring series.
        try {
            $eventsSent = $reminders->processDue();
        } catch (\Throwable $e) {
            Log::error("Event reminder engine failed: {$e->getMessage()}");
        }

        $this->info("Done. Tasks: {$tasksSent} reminded. Events: {$eventsSent} reminded. Overdue: {$overdue} marked.");

        return self::SUCCESS;
    }
}
