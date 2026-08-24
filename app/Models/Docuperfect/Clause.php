<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 2026-08-20 (HFC tenant-isolation, Wave 3) — added BelongsToAgency.
 * scopeVisibleTo()'s 'all' branch was fully unscoped (every agency's
 * clauses visible) and the table had no agency_id column at all. Fixed
 * "for free" by the global scope; scopeVisibleTo()'s own is_global/branch
 * check is unchanged and now just layers on top. See the Wave 3 migration
 * docblock for why is_global no longer widens across agencies.
 */
class Clause extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'docuperfect_clauses';

    protected $fillable = [
        'agency_id',
        'name',
        'text',
        'is_global',
        'owner_id',
    ];

    protected $casts = [
        'is_global' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'docuperfect_clause_branches', 'clause_id', 'branch_id');
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'clauses');

        if ($scope === 'all') return $query;

        $branchId = $user->effectiveBranchId();

        return $query->where(function ($q) use ($branchId) {
            $q->where('is_global', true);
            if ($branchId) {
                $q->orWhereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                });
            }
        });
    }
}
