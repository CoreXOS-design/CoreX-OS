<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 — one suburb assigned to one agent for one track.
 * Spec: .ai/specs/road-to-sold.md §3.2.
 *
 * Visibility is the explicit scopeVisibleTo() below (own / branch / agency), not
 * the generic branch global scope, so the three levels are stated in one place.
 */
class RtsAgentArea extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'rts_agent_areas';

    protected $fillable = ['agency_id', 'branch_id', 'track', 'user_id', 'p24_suburb_id', 'assigned_by_user_id'];

    protected $casts = ['p24_suburb_id' => 'integer'];

    /** Same breadth rules as DailyActivity::scopeVisibleTo, module 'road_to_sold'. */
    public function scopeVisibleTo($query, User $user)
    {
        $scope = PermissionService::getDataScope($user, 'road_to_sold');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where($this->getTable() . '.branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            return $query->whereIn($this->getTable() . '.user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
