<?php

namespace App\Services\CommandCenter;

use App\Models\Agency;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\User;
use Carbon\Carbon;

class NotificationPreferenceService
{
    /**
     * Effective preference for a user + event-type key.
     * Returns an array with: enabled, threshold, channel_in_app, channel_email, channel_push.
     *
     * Channel resolution order, per agency-lock rules (2026-05-29):
     *   - When agency mode = 'agency': agency settings drive event toggles, master in_app/email,
     *     open hours, cooldown. The user's own `notify_push` master still applies (users may
     *     silence their own device push at any time).
     *   - Outside open hours: EMAIL channel is suppressed. Push and in-app continue.
     */
    public function effective(User $user, string $key): ?array
    {
        $type = NotificationEventType::where('key', $key)->first();
        if (! $type) {
            return null;
        }

        $ctx = $this->context($user);
        $effSettings = $ctx['settings'];

        $masterInApp = (bool) $effSettings->notify_in_app;
        $masterEmail = (bool) $effSettings->notify_email;
        // Push master is ALWAYS the user's own value, even under agency lock.
        $userOwn = $ctx['user_settings'];
        $masterPush  = (bool) ($userOwn->notify_push ?? true);

        // Open-hours email gate
        if (! $this->withinOpenHours($effSettings)) {
            $masterEmail = false;
        }

        if ($type->is_adapter && $type->adapter_column) {
            return $this->resolveAdapter($type, $effSettings, $masterInApp, $masterEmail, $masterPush);
        }

        // Under agency lock, per-event prefs come from the agency, not the user.
        $pref = $ctx['locked']
            ? null // agency does not currently expose a per-event matrix table; fall back to type defaults
            : UserNotificationPreference::where('user_id', $user->id)
                ->where('notification_event_type_id', $type->id)
                ->first();

        $enabled       = $pref?->enabled         ?? $type->default_enabled;
        $threshold     = $pref?->threshold       ?? $type->default_threshold;
        $channelInApp  = $pref?->channel_in_app  ?? true;
        $channelEmail  = $pref?->channel_email   ?? false;
        $channelPush   = $pref?->channel_push    ?? true;

        return [
            'enabled'        => (bool) $enabled,
            'threshold'      => $threshold,
            'channel_in_app' => $masterInApp && $channelInApp,
            'channel_email'  => $masterEmail && $channelEmail,
            'channel_push'   => $masterPush  && $channelPush,
            'event_type'     => $type,
        ];
    }

    public function shouldNotify(User $user, string $key): bool
    {
        $eff = $this->effective($user, $key);
        if (! $eff) return false;
        if (! $eff['enabled']) return false;
        return $eff['channel_in_app'] || $eff['channel_email'] || $eff['channel_push'];
    }

    /**
     * True when an agency administrator has locked notification settings for everyone in the agency.
     * Users retain control of their personal push master (their device).
     */
    public function isAgencyControlled(User $user): bool
    {
        return $this->context($user)['locked'];
    }

    public function cooldownMinutes(User $user): int
    {
        return (int) ($this->context($user)['settings']->min_minutes_between_same ?? 360);
    }

    public function withinOpenHours(UserDashboardSetting|AgencyDashboardSetting|null $settings = null, ?Carbon $at = null): bool
    {
        if (! $settings) return true;
        if (! ($settings->open_hours_enabled ?? false)) return true;

        $now   = $at ?? now();
        $start = substr((string) $settings->open_hours_start, 0, 5);
        $end   = substr((string) $settings->open_hours_end, 0, 5);
        $hhmm  = $now->format('H:i');

        if ($start === $end) return true; // degenerate; treat as always
        if ($start < $end) {
            return $hhmm >= $start && $hhmm < $end;
        }
        // window crosses midnight (e.g. 22:00 → 06:00)
        return $hhmm >= $start || $hhmm < $end;
    }

    /**
     * Snapshot for the settings UI / API: returns every event type with the
     * user's effective preference resolved, plus masters and open-hours.
     */
    public function snapshot(User $user): array
    {
        $types = NotificationEventType::orderBy('pillar')->orderBy('sort_order')->get();
        $ctx = $this->context($user);
        $effSettings = $ctx['settings'];
        $userOwn     = $ctx['user_settings'];

        $groups = [];
        foreach ($types as $type) {
            $eff = $this->effective($user, $type->key);
            $groups[$type->pillar] ??= [
                'pillar' => $type->pillar,
                'label'  => ucfirst($type->pillar),
                'items'  => [],
            ];

            $groups[$type->pillar]['items'][] = [
                'key'             => $type->key,
                'label'           => $type->label,
                'description'     => $type->description,
                'group'           => $type->group_label,
                'threshold_unit'  => $type->threshold_unit,
                'threshold'       => $eff['threshold'],
                'threshold_min'   => $type->threshold_min,
                'threshold_max'   => $type->threshold_max,
                'enabled'         => $eff['enabled'],
                'channel_in_app'  => $eff['channel_in_app'],
                'channel_email'   => $eff['channel_email'],
                'channel_push'    => $eff['channel_push'],
                'is_adapter'      => (bool) $type->is_adapter,
            ];
        }

        return [
            'mode'   => $ctx['locked'] ? 'agency' : 'user',
            'locked' => $ctx['locked'],
            'master' => [
                'in_app' => (bool) $effSettings->notify_in_app,
                'email'  => (bool) $effSettings->notify_email,
                // Push master is the user's own value (always editable).
                'push'   => (bool) ($userOwn->notify_push ?? true),
            ],
            'open_hours' => [
                'enabled' => (bool) ($effSettings->open_hours_enabled ?? false),
                'start'   => substr((string) ($effSettings->open_hours_start ?? '07:00'), 0, 5),
                'end'     => substr((string) ($effSettings->open_hours_end   ?? '21:00'), 0, 5),
            ],
            'cooldown_minutes'   => (int) ($effSettings->min_minutes_between_same ?? 360),
            'agency_controlled'  => $ctx['locked'],
            'groups' => array_values($groups),
        ];
    }

    /**
     * Apply an inbound preferences payload (idempotent upsert).
     */
    public function applyUpdates(User $user, array $payload): int
    {
        $ctx = $this->context($user);
        $locked = $ctx['locked'];
        $userSettings = $ctx['user_settings'];

        $saved = 0;
        $master = $payload['master'] ?? null;
        if (is_array($master)) {
            // Push master is always writable; in_app/email locked under agency mode.
            $userSettings->fill(array_filter([
                'notify_in_app' => $locked ? null : (isset($master['in_app']) ? (bool) $master['in_app'] : null),
                'notify_email'  => $locked ? null : (isset($master['email'])  ? (bool) $master['email']  : null),
                'notify_push'   => isset($master['push']) ? (bool) $master['push'] : null,
            ], fn ($v) => ! is_null($v)))->save();
        }

        if (! $locked && is_array($payload['open_hours'] ?? null)) {
            $oh = $payload['open_hours'];
            $userSettings->fill([
                'open_hours_enabled' => (bool) ($oh['enabled'] ?? false),
                'open_hours_start'   => substr((string) ($oh['start'] ?? '07:00'), 0, 5),
                'open_hours_end'     => substr((string) ($oh['end']   ?? '21:00'), 0, 5),
            ])->save();
        }

        if (! $locked && isset($payload['cooldown_minutes'])) {
            $userSettings->fill([
                'min_minutes_between_same' => max(0, (int) $payload['cooldown_minutes']),
            ])->save();
        }

        // Per-event-type matrix — only when not locked.
        if (! $locked) {
            foreach ($payload['preferences'] ?? [] as $row) {
                if (empty($row['key'])) continue;
                $type = NotificationEventType::where('key', $row['key'])->first();
                if (! $type) continue;

                if ($type->is_adapter && $type->adapter_column) {
                    $this->writeAdapter($user, $type, $row);
                    $saved++;
                    continue;
                }

                UserNotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'notification_event_type_id' => $type->id],
                    [
                        'enabled'        => (bool) ($row['enabled'] ?? true),
                        'threshold'      => $row['threshold'] ?? $type->default_threshold,
                        'channel_in_app' => (bool) ($row['channel_in_app'] ?? true),
                        'channel_email'  => (bool) ($row['channel_email']  ?? false),
                        'channel_push'   => (bool) ($row['channel_push']   ?? true),
                    ]
                );
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Resolve the effective settings record + agency-lock flag for a user.
     * Returns ['settings' => effective row used for masters/open-hours,
     *          'user_settings' => the user's own row (for push master),
     *          'locked' => bool].
     */
    private function context(User $user): array
    {
        $userSettings = UserDashboardSetting::firstOrCreate(
            ['user_id' => $user->id],
            UserDashboardSetting::defaults()
        );

        $agency = $user->effectiveAgencyId() ? Agency::find($user->effectiveAgencyId()) : null;
        $locked = $agency && ($agency->dashboard_settings_mode ?? 'user') === 'agency';

        $effSettings = $userSettings;
        if ($locked) {
            $effSettings = AgencyDashboardSetting::firstOrCreate(
                ['agency_id' => $agency->id],
                UserDashboardSetting::defaults()
            );
        }

        return ['settings' => $effSettings, 'user_settings' => $userSettings, 'locked' => $locked];
    }

    private function resolveAdapter(NotificationEventType $type, $dashboard, bool $masterInApp, bool $masterEmail, bool $masterPush): array
    {
        $col = $type->adapter_column;
        $enabled = true;
        $threshold = $type->default_threshold;

        $toggleMap = [
            'task_reminder_hours_before' => 'task_due_reminders',
            'event_reminder_hours_before' => null,
            'lease_reminder_days_before' => 'lease_expiry_reminders',
            'idle_threshold_days' => 'idle_alerts_enabled',
            'overdue_daily_digest' => 'overdue_daily_digest',
            'ffc_reminders' => 'ffc_reminders',
        ];

        $toggleCol = $toggleMap[$col] ?? null;
        if ($toggleCol) {
            $enabled = (bool) $dashboard->{$toggleCol};
        } elseif ($col === 'overdue_daily_digest' || $col === 'ffc_reminders') {
            $enabled = (bool) $dashboard->{$col};
        }

        if ($type->threshold_unit !== 'none' && in_array($col, [
            'task_reminder_hours_before','event_reminder_hours_before',
            'lease_reminder_days_before','idle_threshold_days',
        ], true)) {
            $threshold = (int) $dashboard->{$col};
        }

        return [
            'enabled'        => $enabled,
            'threshold'      => $threshold,
            'channel_in_app' => $masterInApp,
            'channel_email'  => $masterEmail,
            'channel_push'   => $masterPush,
            'event_type'     => $type,
        ];
    }

    private function writeAdapter(User $user, NotificationEventType $type, array $row): void
    {
        $dashboard = UserDashboardSetting::firstOrCreate(
            ['user_id' => $user->id],
            UserDashboardSetting::defaults()
        );

        $col = $type->adapter_column;
        $toggleMap = [
            'task_reminder_hours_before' => 'task_due_reminders',
            'lease_reminder_days_before' => 'lease_expiry_reminders',
            'idle_threshold_days' => 'idle_alerts_enabled',
        ];
        if ($tCol = ($toggleMap[$col] ?? null)) {
            $dashboard->{$tCol} = (bool) ($row['enabled'] ?? true);
        }

        if (in_array($col, ['overdue_daily_digest', 'ffc_reminders'], true)) {
            $dashboard->{$col} = (bool) ($row['enabled'] ?? true);
        }

        if ($type->threshold_unit !== 'none' && isset($row['threshold']) && in_array($col, [
            'task_reminder_hours_before','event_reminder_hours_before',
            'lease_reminder_days_before','idle_threshold_days',
        ], true)) {
            $dashboard->{$col} = (int) $row['threshold'];
        }

        $dashboard->save();
    }
}
