<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
class MonthlyTargetGoal extends Model
{
    // Branch-level goals: branch_id is set explicitly at the firstOrCreate callsites
    // (keyed by branch), so BelongsToBranch scopes reads without needing a parent stamp.
    use BelongsToBranch, BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'user_id',
        'branch_id',
        'period',
        'listings_target',
        'deals_target',
        'value_target',
        'branch_budget',
        'notes',
        'created_by',
        'updated_by',
    ];
}
