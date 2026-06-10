<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clause extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_clauses';

    /**
     * ES-9 — clause grouping categories (single source of truth). The picker
     * UIs and the create/edit form read this list so the set is defined once.
     * `general` is the default bucket for an uncategorised clause.
     */
    public const CATEGORIES = [
        'bond'       => 'Bond / Finance',
        'occupation' => 'Occupation',
        'fittings'   => 'Fittings & Voetstoots',
        'compliance' => 'Compliance Certificates',
        'fees'       => 'Fees & Commission',
        'notice'     => 'Notice & Termination',
        'general'    => 'General',
    ];

    protected $fillable = [
        'name',
        'text',
        'category',
        'is_global',
        'is_system',
        'owner_id',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'is_system' => 'boolean',
    ];

    /**
     * Normalise a free/absent category to a valid key, defaulting to 'general'.
     */
    public static function normaliseCategory(?string $category): string
    {
        $key = strtolower(trim((string) $category));
        return array_key_exists($key, self::CATEGORIES) ? $key : 'general';
    }

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
