<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyActivity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'activity_date' => 'date',
    ];

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        if ($user->isEffectiveAdmin()) {
            return $query;
        }

        if ($user->isEffectiveBranchManager()) {
            return $query->where('branch_id', $user->effectiveBranchId());
        }

        return $query->where('user_id', $user->id);
    }
}
