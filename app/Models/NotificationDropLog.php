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

    protected $fillable = [
        'agency_id', 'user_id', 'notification_event_type_id', 'event_key',
        'subject_type', 'subject_id', 'reason', 'threshold_hit_at', 'dropped_at',
    ];

    protected $casts = [
        'threshold_hit_at' => 'datetime',
        'dropped_at' => 'datetime',
    ];
}
