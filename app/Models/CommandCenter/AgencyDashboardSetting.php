<?php

namespace App\Models\CommandCenter;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class AgencyDashboardSetting extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'idle_alerts_enabled', 'idle_threshold_days', 'idle_alert_day', 'idle_alert_time',
        'doc_reminders_enabled', 'doc_reminder_hours_before',
        'lease_expiry_reminders', 'lease_reminder_days_before',
        'fica_reminders', 'ffc_reminders',
        'task_due_reminders', 'task_reminder_hours_before', 'event_reminder_hours_before',
        'auto_archive_done_days',
        'overdue_daily_digest', 'digest_time',
        'default_calendar_view', 'weekend_visible',
        'working_hours_start', 'working_hours_end',
        'notify_in_app', 'notify_email', 'notify_push',
        'open_hours_enabled', 'open_hours_start', 'open_hours_end',
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
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
