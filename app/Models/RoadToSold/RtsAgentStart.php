<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Road to Sold v4 (23.13): one agent's start and pause. Written only by RoadToSold\ActivationService.
 */
class RtsAgentStart extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    protected $table = 'rts_agent_starts';

    protected $fillable = [
        'agency_id', 'branch_id', 'track', 'user_id', 'started_at', 'started_by_user_id', 'first_list_date',
        'paused_from', 'paused_until', 'pause_reason', 'paused_by_user_id',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'first_list_date' => 'date',
        'paused_from'     => 'date',
        'paused_until'    => 'date',
    ];

    protected function branchParent(): array
    {
        return [User::class, 'user_id'];
    }
}
