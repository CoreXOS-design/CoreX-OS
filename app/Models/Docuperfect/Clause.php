<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Clause extends Model
{
    protected $table = 'docuperfect_clauses';

    protected $fillable = [
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
        if ($user->isAdmin()) {
            return $query;
        }

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
