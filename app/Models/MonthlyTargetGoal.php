<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyTargetGoal extends Model
{
    protected $fillable = [
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
