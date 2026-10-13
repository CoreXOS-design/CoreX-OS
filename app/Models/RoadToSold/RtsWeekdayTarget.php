<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 — one agent's own target for one ISO weekday (1=Mon … 7=Sun).
 * Spec: .ai/specs/road-to-sold.md §3.3. No row = the agency default applies.
 */
class RtsWeekdayTarget extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    public const MAX_TARGET = 200;

    protected $table = 'rts_weekday_targets';

    protected $fillable = ['agency_id', 'branch_id', 'track', 'user_id', 'weekday', 'target', 'set_by_user_id'];

    protected $casts = ['weekday' => 'integer', 'target' => 'integer'];

    /** Same breadth rules as RtsAgentArea / DailyActivity, module 'road_to_sold'. */
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
