<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TvMessage extends Model
{
    protected $fillable = [
        'branch_id',
        'created_by_user_id',
        'title',
        'message',
        'display_area',
        'is_enabled',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActiveForBranch(Builder $q, int $branchId): Builder
    {
        $now = now();

        return $q->where('is_enabled', true)
            ->where(function ($x) use ($branchId) {
                $x->whereNull('branch_id')
                  ->orWhere('branch_id', $branchId);
            })
            ->where(function ($x) use ($now) {
                $x->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($x) use ($now) {
                $x->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            // branch messages first, then global
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->orderBy('id', 'desc');
    }
}
