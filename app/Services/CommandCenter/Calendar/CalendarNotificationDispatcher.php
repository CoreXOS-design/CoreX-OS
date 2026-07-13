<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Notifications\EventDueReminderNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

                    // AT-235 (R2) — the agency's per-class channel config was INERT.
                    //
                    // $viaChannels is resolved from calendar_event_class_settings
                    // (green/amber/red_notifications: role → channels) — and was then
                    // thrown away: the call was `$user->notify($notification)`, so
                    // delivery fell through to EventDueReminderNotification::via(),
                    // which returns `database` + `mail`-if-notify_email regardless.
                    //
                    // So a class the agency configured as "in-app only" still sent
                    // email, and one configured "email only" still wrote an in-app
                    // row. The admin set the channel and the code ignored it — a
                    // silent lie in a settings screen.
                    //
                    // Forcing the channel list bypasses via() — which is ALSO where the
                    // user's notify_email master switch was being checked. So the class
                    // config must not be able to override a user who turned email off.
                    //
                    // The rule: the agency's class config decides which channels are
                    // ELIGIBLE; the user's master switches can still VETO. Same
                    // intersection CalendarReminderService::channelsForUser() applies.
                    $viaChannels = $this->applyUserMasters($user, $viaChannels);
                    if (empty($viaChannels)) {
                        continue; // the user has muted every channel this class wanted
                    }

                    Notification::sendNow($user, new EventDueReminderNotification($event), $viaChannels);
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
     * AT-235 (R2) — the user's master switches VETO the agency's class config.
     *
     * The agency's per-class routing says which channels are ELIGIBLE for this
     * colour. It must not be able to force a channel a user has muted: an agency
     * turning on "email" for a class cannot un-mute a user who set email off.
     *
     * Previously this veto happened by accident, inside
     * EventDueReminderNotification::via(). Now that the channel list is passed
     * explicitly (so the class config actually takes effect at all), via() is
     * bypassed — so the veto has to be applied here, deliberately. Same
     * intersection as CalendarReminderService::channelsForUser().
     */
    private function applyUserMasters(User $user, array $viaChannels): array
    {
        $settings = UserDashboardSetting::getEffective($user);

        return array_values(array_filter($viaChannels, function (string $channel) use ($settings) {
            return match ($channel) {
                'database' => (bool) ($settings->notify_in_app ?? true),
                'mail'     => (bool) ($settings->notify_email ?? true),
                default    => false,
            };
        }));
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
