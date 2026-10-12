<?php

namespace App\Models\TitleDeeds;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/title-deed-register.md §14.4 - one place the agency keeps title deeds ("Safe 2", "Strongroom"). Defined by the
 * agency, soft delete only. A location may be restricted to one branch.
 */
class TitleDeedStorageLocation extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'title_deed_storage_locations';

    protected $fillable = ['agency_id', 'name', 'sort_order', 'branch_id'];

    protected $casts = ['sort_order' => 'integer'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id')->withTrashed();
    }

    /** Locations a deed of $branchId may be kept in: unrestricted ones plus that branch's own. */
    public function scopeUsableForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(function (Builder $q) use ($branchId) {
            $q->whereNull('branch_id');
            if ($branchId) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
