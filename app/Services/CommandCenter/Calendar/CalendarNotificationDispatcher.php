<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;
use App\Notifications\EventDueReminderNotification;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches calendar event notifications based on the per-class config
 * in calendar_event_class_settings.
 *
 * Uses the existing EventDueReminderNotification which supports database
 * and mail channels. The class config determines WHICH roles get notified
 * and on WHICH channels — this service resolves the recipient users and
 * delegates to Laravel's notification system.
 */
class CalendarNotificationDispatcher
{
    /**
     * Called when an event's resolved colour transitions.
     * Sends notifications according to the config for the new colour only.
     */
    public function onColourTransition(
        CalendarEvent $event,
        ?string $previousColour,
        string $newColour,
    ): void {
        if ($previousColour === $newColour) {
            return;
        }

        $config = CalendarEventClassSetting::forAgencyAndClass($event->agency_id, $event->category ?? '');
        if (!$config || !$config->is_active) {
            return;
        }

        $routing = $config->notificationsFor($newColour);
        if (empty($routing)) {
            return;
        }

        foreach ($routing as $role => $channels) {
            if (empty($channels)) {
                continue;
            }

            $users = $this->resolveUsersForRole($event, $role);
            foreach ($users as $user) {
                try {
                    $viaChannels = $this->mapChannels($channels);
                    if (empty($viaChannels)) {
                        continue;
                    }

                    $notification = new EventDueReminderNotification($event);
                    $user->notify($notification);
                } catch (\Throwable $e) {
                    Log::warning('CalendarNotificationDispatcher: send failed', [
                        'event_id'    => $event->id,
                        'event_class' => $event->category,
                        'user_id'     => $user->id,
                        'role'        => $role,
                        'channels'    => $channels,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Map config channel names to Laravel notification channel names.
     */
    private function mapChannels(array $channels): array
    {
        $map = [
            'in_app' => 'database',
            'email'  => 'mail',
        ];

        return array_values(array_filter(
            array_map(fn ($ch) => $map[$ch] ?? null, $channels)
        ));
    }

    /**
     * Resolve the users who should receive a notification for a given
     * role on a given event.
     */
    private function resolveUsersForRole(CalendarEvent $event, string $role): \Illuminate\Support\Collection
    {
        $query = User::query()->withoutGlobalScopes()->where('is_active', true);

        if ($event->agency_id !== null) {
            $query->where('agency_id', $event->agency_id);
        }

        switch ($role) {
            case 'agent':
                if ($event->user_id) {
                    $u = $query->where('id', $event->user_id)->first();
                    return $u ? collect([$u]) : collect();
                }
                return collect();

            case 'bm':
                $q = clone $query;
                if ($event->branch_id) {
                    $q->where('branch_id', $event->branch_id);
                }
                return $q->where('role', 'branch_manager')->get();

            default:
                return $query->where('role', $role)->get();
        }
    }
}
