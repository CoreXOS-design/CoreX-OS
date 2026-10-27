<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\RoadToSold\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Road to Sold v3 - one agent's relationship with one property: its stage, next action and due date (road-to-sold.md section 22).
 * The unique key (agency, track, tracked property) IS the allocation - a property belongs to one agent at a time. Written only by
 * RoadToSold\ProspectService.
 */
class RtsProspect extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    protected $table = 'rts_prospects';

    protected $fillable = [
        'agency_id', 'branch_id', 'track', 'user_id', 'tracked_property_id', 'prospecting_listing_id', 'property_id',
        'stage', 'stage_entered_at', 'selection', 'reason', 'reason_facts',
        'next_action_key', 'next_action_label', 'next_action_at', 'tries', 'last_outcome_id',
        'parked_at', 'parked_until', 'park_reason_id', 'park_reason_label', 'park_note', 'parked_from_stage',
        'closed_at', 'closed_reason', 'released_at', 'released_by_user_id',
    ];

    protected $casts = [
        'stage_entered_at' => 'datetime',
        'next_action_at'   => 'date',
        'parked_at'        => 'datetime',
        'parked_until'     => 'date',
        'closed_at'        => 'datetime',
        'released_at'      => 'datetime',
        'reason_facts'     => 'array',
        'tries'            => 'integer',
    ];

    protected function branchParent(): array
    {
        return [User::class, 'user_id'];
    }

    /** Own / branch / agency breadth (road_to_sold.view), applied in the query - never a hidden link. */
    public function scopeVisibleTo($query, User $viewer, ?string $requestedScope = null)
    {
        return Scope::apply($query, $viewer, $requestedScope);
    }

    /** Alive: not closed, not released. A parked prospect is still owned. */
    public function scopeOwned($query)
    {
        return $query->whereNull('closed_at')->whereNull('released_at');
    }

    public function scopeActiveStage($query)
    {
        return $query->owned()->whereNull('parked_at');
    }

    public function scopeParked($query)
    {
        return $query->owned()->whereNotNull('parked_at');
    }

    public function isParked(): bool
    {
        return $this->parked_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }

    public function parkReason(): BelongsTo
    {
        return $this->belongsTo(RtsParkReason::class, 'park_reason_id')->withTrashed();
    }
}
