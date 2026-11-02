<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Road to Sold v4 (23.13): one press of Start / Pause / Resume. Append-only history; written only by RoadToSold\ActivationService.
 */
class RtsAgentStartEvent extends Model
{
    use BelongsToAgency;

    protected $table = 'rts_agent_start_events';

    protected $fillable = ['agency_id', 'branch_id', 'track', 'user_id', 'actor_user_id', 'kind', 'occurred_at', 'first_list_date', 'paused_from', 'paused_until', 'reason'];

    protected $casts = ['occurred_at' => 'datetime', 'first_list_date' => 'date', 'paused_from' => 'date', 'paused_until' => 'date'];
}
