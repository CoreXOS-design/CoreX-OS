<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-451 — written by NotificationDispatcher when an alert is dropped because the recipient has every channel
 * switched off. Deliberately NOT agency-scoped via a global scope: it is written from console / scanner contexts
 * with no logged-in user, so agency_id is stamped explicitly from the recipient.
 */
class NotificationDropLog extends Model
{
    use SoftDeletes;

    protected $table = 'notification_drop_log';

    public const REASON_ALL_CHANNELS_OFF = 'all channels off';
    // AT-492 - every other way an alert can be dropped now leaves a row too (it used to be silent).
    public const REASON_EVENT_OFF = 'event switched off';
    public const REASON_UNKNOWN_EVENT = 'event type not registered';
    public const REASON_NO_CHANNEL = 'no channel can carry it';
    public const REASON_OPEN_HOURS = 'outside open hours';
    public const REASON_COOLDOWN = 'cooldown';

    protected $fillable = [
        'agency_id', 'user_id', 'notification_event_type_id', 'event_key',
        'subject_type', 'subject_id', 'reason', 'threshold_hit_at', 'dropped_at',
    ];

    protected $casts = [
        'threshold_hit_at' => 'datetime',
        'dropped_at' => 'datetime',
    ];
}
