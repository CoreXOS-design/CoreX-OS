<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class MonthlyTargetGoal extends Model
{
    use BelongsToAgency, SoftDeletes;

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
