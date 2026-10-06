<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit row for an agency timeline ("the timeline is recorded").
 * Never updated or deleted. Spec §7.3.
 */
class AgencyTimelineEvent extends Model
{
    public $timestamps = false;

    protected $table = 'agency_timeline_events';

    protected $fillable = [
        'timeline_id', 'item_id', 'event', 'summary', 'before', 'after',
        'actor_user_id', 'source',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];
}
