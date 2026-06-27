<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDashboardSetting extends Model
{
    protected $fillable = [
        'user_id',
        'idle_alerts_enabled', 'idle_threshold_days', 'idle_alert_day', 'idle_alert_time',
        'doc_reminders_enabled', 'doc_reminder_hours_before',
        'lease_expiry_reminders', 'lease_reminder_days_before',
        'fica_reminders', 'ffc_reminders',
        'task_due_reminders', 'task_reminder_hours_before',
        'event_reminder_hours_before', 'event_reminder_minutes_before',
        'auto_archive_done_days',
        'overdue_daily_digest', 'digest_time',
        'default_calendar_view', 'weekend_visible',
        'working_hours_start', 'working_hours_end',
        'notify_in_app', 'notify_email', 'notify_push',
        'open_hours_enabled', 'open_hours_start', 'open_hours_end', 'open_hours_day_windows',
        'min_minutes_between_same',
    ];

    protected $casts = [
        'idle_alerts_enabled'    => 'boolean',
        'doc_reminders_enabled'  => 'boolean',
        'lease_expiry_reminders' => 'boolean',
        'fica_reminders'         => 'boolean',
        'ffc_reminders'          => 'boolean',
        'task_due_reminders'     => 'boolean',
        'overdue_daily_digest'   => 'boolean',
        'weekend_visible'        => 'boolean',
        'notify_in_app'          => 'boolean',
        'notify_email'           => 'boolean',
        'notify_push'            => 'boolean',
        'open_hours_enabled'     => 'boolean',
        'open_hours_day_windows' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get settings for a user — returns user settings if mode=user,
     * or agency settings if mode=agency. Creates defaults if none exist.
     */
    public static function getEffective(User $user): self
    {
        $agency = $user->effectiveAgencyId()
            ? \App\Models\Agency::find($user->effectiveAgencyId())
            : null;

        // If agency is in "agency" mode, return agency settings as a UserDashboardSetting instance
        if ($agency && ($agency->dashboard_settings_mode ?? 'user') === 'agency') {
            $agencySetting = AgencyDashboardSetting::firstOrCreate(
                ['agency_id' => $agency->id],
                self::defaults()
            );

            // Map agency settings to a non-persisted UserDashboardSetting for uniform access
            $mapped = new self(array_merge(
                $agencySetting->only((new self)->getFillable()),
                ['user_id' => $user->id]
            ));
            $mapped->exists = false; // Not a real user record
            $mapped->setAttribute('is_agency_controlled', true);

            return $mapped;
        }

        return self::firstOrCreate(
            ['user_id' => $user->id],
            self::defaults()
        );
    }

    /**
     * Default settings values.
     */
    public static function defaults(): array
    {
        return [
            'idle_alerts_enabled'        => true,
            'idle_threshold_days'        => 14,
            'idle_alert_day'             => null,
            'idle_alert_time'            => '08:00',
            'doc_reminders_enabled'      => true,
            'doc_reminder_hours_before'  => 24,
            'lease_expiry_reminders'     => true,
            'lease_reminder_days_before' => 90,
            'fica_reminders'             => true,
            'ffc_reminders'              => true,
            'task_due_reminders'         => true,
            'task_reminder_hours_before' => 4,
            // Legacy hours column retained for back-compat; minutes is canonical
            // for event reminders (mobile sends a total-minutes lead-time). The
            // default lead-time is 60 minutes (1h) — the mobile contract default
            // for agent.event_due.
            'event_reminder_hours_before' => 24,
            'event_reminder_minutes_before' => 60,

            'auto_archive_done_days'     => null,
            'overdue_daily_digest'       => true,
            'digest_time'                => '08:00',
            'default_calendar_view'      => 'month',
            'weekend_visible'            => false,
            'working_hours_start'        => '08:00',
            'working_hours_end'          => '17:00',
            'notify_in_app'              => true,
            'notify_email'               => true,
            'notify_push'                => true,
            'open_hours_enabled'         => false,
            'open_hours_start'           => '07:00',
            'open_hours_end'             => '21:00',
            'min_minutes_between_same'   => 360,
        ];
    }
}
