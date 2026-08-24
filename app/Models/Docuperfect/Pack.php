<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 2026-08-20 (HFC tenant-isolation, Wave 3) — added BelongsToAgency.
 * scopeVisibleTo()'s 'all' branch was fully unscoped (every agency's packs
 * visible) and the table had no agency_id column at all. Fixed "for free"
 * by the global scope; scopeVisibleTo()'s own is_global/branch check is
 * unchanged and now just layers on top. See the Wave 3 migration docblock
 * for why is_global no longer widens across agencies.
 */
class Pack extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'docuperfect_packs';

    protected $fillable = [
        'agency_id',
        'name',
        'description',
        'is_global',
        'creation_mode',
        'owner_id',
    ];

    protected $casts = [
        'is_global' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function templates()
    {
        return $this->belongsToMany(Template::class, 'docuperfect_pack_templates', 'pack_id', 'template_id')
            ->withPivot('sort_order')
            ->orderBy('docuperfect_pack_templates.sort_order');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(PackSlot::class, 'pack_id')->orderBy('sort_order');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'docuperfect_pack_branches', 'pack_id', 'branch_id');
    }

    public function usesSlots(): bool
    {
        return $this->slots()->exists();
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'packs');

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
