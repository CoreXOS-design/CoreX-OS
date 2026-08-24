<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
class Target extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /**
     * A target's branch is its owning agent's — set from user_id, not the acting
     * user, so an admin/BM editing another branch's target does not mis-stamp it.
     * A branch-level target (null user_id) keeps whatever branch_id is set explicitly.
     */
    protected function branchParent(): array
    {
        return [\App\Models\User::class, 'user_id'];
    }

    protected $fillable = [
        'agency_id',
        'period',
        'user_id',
        'branch_id',
        'listings_target',
        'deals_target',
        'value_target',
        'points_target',
        'notes',
        'created_by',
        'updated_by',
    ];
}
