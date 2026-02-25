<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pack extends Model
{
    protected $table = 'docuperfect_packs';

    protected $fillable = [
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
