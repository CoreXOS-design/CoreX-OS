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
     * This is the user's CONFIGURED preference — master switches + per-event matrix
     * (or adapter columns) resolved against the agency-lock rules below. It is the
     * source of truth for the settings UI, the API snapshot, and the read-only
     * overdue snapshot, so it deliberately does NOT apply the open-hours time gate.
     *
     * Open-hours enforcement now happens once, at dispatch time, in
     * NotificationDispatcher::fire() via withinOpenHours() — and it suppresses ALL
     * channels (in-app, email and push), not just email. Putting it here would make
     * the settings page and the badge snapshot read as "off" outside open hours,
     * which is wrong: the schedule controls delivery, not configuration.
     *
     * Channel resolution order, per agency-lock rules (2026-05-29):
     *   - When agency mode = 'agency': agency settings drive event toggles, master in_app/email,
     *     open hours, cooldown. The user's own `notify_push` master still applies (users may
     *     silence their own device push at any time).
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

    /**
     * Dispatch-time open-hours gate for a user. Returns true when a notification
     * may be delivered "now" (or at $at). When open hours are disabled, always true.
     *
     * The schedule is per-ISO-weekday (Mon=1 … Sun=7). "now" is evaluated in the
     * USER'S timezone (see resolveTimezone) so an agent in a different region is
     * gated against their wall clock, matching how the mobile client evaluates the
     * device-local time. Used by NotificationDispatcher to suppress ALL channels
     * (in-app, email, push) outside the window.
     */
    public function withinOpenHours(User $user, ?Carbon $at = null): bool
    {
        $settings = $this->context($user)['settings'];
        if (! ($settings->open_hours_enabled ?? false)) return true;

        $tz    = $this->resolveTimezone($user);
        $local = ($at ? $at->copy() : now())->setTimezone($tz);

        return $this->windowsAllowAt($this->resolveDayWindows($settings), $local);
    }

    /**
     * Pure weekday/midnight-wrap predicate — mirrors the mobile client's
     * OpenHours.allowsAt logic so server and device agree:
     *   - start  <  end : same-day window  [start, end)
     *   - start  >  end : split window — evening tail [start, 24:00) on the day the
     *                     window opens, and the early-morning tail [00:00, end) is
     *                     attributed to the PREVIOUS day's window.
     *   - start === end : full-day (always open on that weekday).
     * A day with enabled:false is fully quiet.
     *
     * @param array<string,array{enabled:bool,start:string,end:string}> $dayWindows keyed "1".."7"
     */
    public function windowsAllowAt(array $dayWindows, Carbon $at): bool
    {
        $iso  = (int) $at->isoWeekday(); // 1=Mon … 7=Sun
        $hhmm = $at->format('H:i');

        // Today's window (same-day, full-day, or the evening tail of a wrap).
        $today = $dayWindows[(string) $iso] ?? null;
        if ($today && ($today['enabled'] ?? false)) {
            $start = $today['start'];
            $end   = $today['end'];
            if ($start === $end) return true;                       // full day
            if ($start < $end)   { if ($hhmm >= $start && $hhmm < $end) return true; } // same-day
            else                 { if ($hhmm >= $start) return true; }                 // wrap: evening tail
        }

        // Early-morning tail [00:00, end) belongs to the PREVIOUS day's wrap window.
        $prevIso = $iso === 1 ? 7 : $iso - 1;
        $prev    = $dayWindows[(string) $prevIso] ?? null;
        if ($prev && ($prev['enabled'] ?? false)) {
            if ($prev['start'] > $prev['end'] && $hhmm < $prev['end']) return true;
        }

        return false;
    }

    /**
     * The timezone "now" is evaluated in for the open-hours gate.
     *
     * Order: the user's own stored timezone (forward-compatible — honoured
     * automatically if a `users.timezone` column/accessor is ever added), then the
     * application timezone fallback. There is no per-user timezone column today, so
     * in practice this resolves to config('app.timezone') = 'Africa/Johannesburg'
     * (UTC+2), which matches both the server clock and every current agency's region.
     */
    public function resolveTimezone(User $user): string
    {
        $tz = $user->timezone ?? null;

        return (is_string($tz) && $tz !== '') ? $tz : config('app.timezone');
    }

    /**
     * Resolve the canonical 7-key day_windows map for a settings row.
     *
     * Prefers the stored open_hours_day_windows JSON; falls back to synthesising it
     * from the legacy single-window open_hours_start/open_hours_end applied to all
     * seven weekdays (the single-window approximation older clients persisted).
     *
     * @return array<string,array{enabled:bool,start:string,end:string}>
     */
    public function resolveDayWindows(UserDashboardSetting|AgencyDashboardSetting|null $settings): array
    {
        if (! $settings) return $this->emptyDayWindows();

        $stored = $settings->open_hours_day_windows;
        if (is_array($stored) && $stored !== []) {
            return $this->normalizeDayWindows($stored);
        }

        // Legacy fallback: one window for every day.
        $start = $this->validHHMM(substr((string) ($settings->open_hours_start ?? '07:00'), 0, 5)) ?? '07:00';
        $end   = $this->validHHMM(substr((string) ($settings->open_hours_end   ?? '21:00'), 0, 5)) ?? '21:00';

        $out = [];
        foreach (range(1, 7) as $iso) {
            $out[(string) $iso] = ['enabled' => true, 'start' => $start, 'end' => $end];
        }
        return $out;
    }

    /**
     * Coerce any inbound shape into a complete 7-key map. Missing/invalid days
     * default to disabled; invalid times default to a safe window.
     *
     * @return array<string,array{enabled:bool,start:string,end:string}>
     */
    public function normalizeDayWindows(array $raw): array
    {
        $out = [];
        foreach (range(1, 7) as $iso) {
            $d = $raw[(string) $iso] ?? $raw[$iso] ?? null;
            if (! is_array($d)) {
                $out[(string) $iso] = ['enabled' => false, 'start' => '00:00', 'end' => '00:00'];
                continue;
            }
            $out[(string) $iso] = [
                'enabled' => (bool) ($d['enabled'] ?? false),
                'start'   => $this->validHHMM($d['start'] ?? null) ?? '07:00',
                'end'     => $this->validHHMM($d['end'] ?? null) ?? '21:00',
            ];
        }
        return $out;
    }

    /**
     * Build a day_windows map from a legacy single-window payload
     * { enabled, start, end, days? }. `days` is an optional list of ISO weekday
     * ints the window applies to; absent means all seven days.
     *
     * @return array<string,array{enabled:bool,start:string,end:string}>
     */
    private function windowsFromLegacy(array $oh): array
    {
        $start = $this->validHHMM($oh['start'] ?? null) ?? '07:00';
        $end   = $this->validHHMM($oh['end']   ?? null) ?? '21:00';
        $days  = isset($oh['days']) && is_array($oh['days'])
            ? array_map('intval', $oh['days'])
            : null;

        $out = [];
        foreach (range(1, 7) as $iso) {
            $enabled = $days === null ? true : in_array($iso, $days, true);
            $out[(string) $iso] = ['enabled' => $enabled, 'start' => $start, 'end' => $end];
        }
        return $out;
    }

    /**
     * Derive the legacy single-window approximation (start/end/days) from a
     * day_windows map, for the GET response and to keep the legacy columns coherent
     * for older readers. The representative window is the first enabled day's window.
     *
     * @param array<string,array{enabled:bool,start:string,end:string}> $windows
     * @return array{start:string,end:string,days:int[]}
     */
    private function legacyApproxFromWindows(array $windows, UserDashboardSetting|AgencyDashboardSetting|null $settings = null): array
    {
        $days = [];
        $repStart = null;
        $repEnd   = null;
        foreach (range(1, 7) as $iso) {
            $w = $windows[(string) $iso] ?? null;
            if ($w && ($w['enabled'] ?? false)) {
                $days[] = $iso;
                if ($repStart === null) {
                    $repStart = $w['start'];
                    $repEnd   = $w['end'];
                }
            }
        }

        $repStart ??= $this->validHHMM(substr((string) ($settings->open_hours_start ?? '07:00'), 0, 5)) ?? '07:00';
        $repEnd   ??= $this->validHHMM(substr((string) ($settings->open_hours_end   ?? '21:00'), 0, 5)) ?? '21:00';

        return ['start' => $repStart, 'end' => $repEnd, 'days' => $days];
    }

    /** Validate/normalise a time to "HH:MM" (24h). Accepts "HH:MM" or "HH:MM:SS". */
    private function validHHMM(mixed $v): ?string
    {
        if (! is_string($v)) return null;
        $v = trim($v);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v)) return $v;
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d):[0-5]\d$/', $v)) return substr($v, 0, 5);
        return null;
    }

    /** @return array<string,array{enabled:bool,start:string,end:string}> */
    private function emptyDayWindows(): array
    {
        $out = [];
        foreach (range(1, 7) as $iso) {
            $out[(string) $iso] = ['enabled' => false, 'start' => '00:00', 'end' => '00:00'];
        }
        return $out;
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
            'open_hours' => (function () use ($effSettings, $user) {
                $windows = $this->resolveDayWindows($effSettings);
                $legacy  = $this->legacyApproxFromWindows($windows, $effSettings);
                return [
                    'enabled'     => (bool) ($effSettings->open_hours_enabled ?? false),
                    'timezone'    => $this->resolveTimezone($user),
                    // Per-weekday schedule — always all seven keys ("1"=Mon … "7"=Sun).
                    'day_windows' => $windows,
                    // Legacy single-window approximation for older clients.
                    'start'       => $legacy['start'],
                    'end'         => $legacy['end'],
                    'days'        => $legacy['days'],
                ];
            })(),
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

            // Prefer the per-weekday schedule; fall back to the legacy single-window
            // shape (enabled/start/end/days) for older clients.
            $windows = (isset($oh['day_windows']) && is_array($oh['day_windows']))
                ? $this->normalizeDayWindows($oh['day_windows'])
                : $this->windowsFromLegacy($oh);

            // Keep the legacy columns coherent for any reader still on the old shape.
            $rep = $this->legacyApproxFromWindows($windows, $userSettings);

            $userSettings->fill([
                'open_hours_enabled'     => (bool) ($oh['enabled'] ?? false),
                'open_hours_day_windows' => $windows,
                'open_hours_start'       => $rep['start'],
                'open_hours_end'         => $rep['end'],
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
