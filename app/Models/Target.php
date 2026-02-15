<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Target extends Model
{
    protected $fillable = [
        'period',
        'user_id',
        'branch_id',
        'listings_target',
        'deals_target',
        'value_target',
        'points_target',
        'notes',
        'created_by',
        'updated_by',
    ];
}
