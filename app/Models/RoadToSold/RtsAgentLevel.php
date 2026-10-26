<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\User;
use App\Services\RoadToSold\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 4 - the level an agent works at and where they are in it (road-to-sold.md sections 5.4, 8.1).
 * One row per (agency, track, agent). Written only by AgentLevelService; created the first time the agent's level is needed
 * for a write - never on read. Levels apply to agents and candidates only, never to an owner or principal (section 15, answer 8).
 */
class RtsAgentLevel extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_AWAITING = 'awaiting_signoff';
    public const STATUS_SIGNED   = 'signed_off';   // signed off at the top level: nothing above to move to

    public const STATUSES = [
        self::STATUS_ACTIVE   => 'Working on it',
        self::STATUS_AWAITING => 'Waiting for sign-off',
        self::STATUS_SIGNED   => 'Top level reached',
    ];

    protected $table = 'rts_agent_levels';

    protected $fillable = ['agency_id', 'branch_id', 'track', 'user_id', 'level_id', 'status', 'started_at', 'requested_signoff_at'];

    protected $casts = ['started_at' => 'datetime', 'requested_signoff_at' => 'datetime'];

    protected function branchParent(): array
    {
        return [User::class, 'user_id'];
    }

    /**
     * Own / branch / agency breadth in the query (the viewer's `road_to_sold.view` scope), never in the view. Branch is read from the
     * AGENT's present branch, not a stamp, so an agent who moves branch moves with their sign-off history. "Own" = the agent's own row.
     */
    public function scopeVisibleTo($query, User $viewer, ?string $requestedScope = null)
    {
        return self::narrowToViewer($query, $viewer, 'user_id', $requestedScope);
    }

    /** Shared by this model and RtsLevelSignoff: narrow a table that has a user_id (the agent) to the viewer's breadth. */
    public static function narrowToViewer($query, User $viewer, string $userColumn, ?string $requestedScope = null)
    {
        $level = Scope::level($viewer);
        if ($requestedScope !== null) {
            $level = \App\Services\PermissionService::clampScope($requestedScope, $level);
        }
        $query->where($query->getModel()->getTable() . '.agency_id', (int) ($viewer->effectiveAgencyId() ?: 0));
        if ($level === 'all') {
            return $query;
        }
        if ($level === 'branch' && $viewer->effectiveBranchId()) {
            return $query->whereIn($userColumn, User::query()->withoutGlobalScopes()->where('branch_id', $viewer->effectiveBranchId())->select('id'));
        }

        return $query->whereIn($userColumn, $viewer->dataIdentityIds());
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(RtsLevel::class, 'level_id')->withTrashed();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(RtsLevelSignoff::class, 'user_id', 'user_id')->where('track', $this->track)->orderBy('decided_at')->orderBy('id');
    }
}
