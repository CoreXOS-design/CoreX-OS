<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The last "Schedule by agent" pattern a user used in the Ad Manager (AT-456 D.9), per user per agency.
 * Written by ScheduledPostService when a batch is scheduled; read back as the Schedule window's defaults.
 */
class AdSchedulePattern extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const ORDER_ALPHA  = 'alpha';
    public const ORDER_CUSTOM = 'custom';

    protected $fillable = [
        'agency_id', 'user_id', 'weekdays', 'first_time', 'gap_minutes', 'agent_order', 'custom_agent_ids',
    ];

    protected $casts = [
        'weekdays'         => 'array',
        'custom_agent_ids' => 'array',
        'gap_minutes'      => 'int',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
