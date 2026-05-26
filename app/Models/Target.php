<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class Target extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
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
