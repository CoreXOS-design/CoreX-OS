<?php

namespace App\Models\CommandCenter;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationDispatchLog extends Model
{
    use SoftDeletes;

    protected $table = 'notification_dispatch_log';

    protected $fillable = [
        'user_id', 'notification_event_type_id',
        'subject_type', 'subject_id',
        'threshold_hit_at', 'dispatched_at', 'channel', 'dedupe_key',
    ];

    protected $casts = [
        'threshold_hit_at' => 'datetime',
        'dispatched_at'    => 'datetime',
    ];
}
