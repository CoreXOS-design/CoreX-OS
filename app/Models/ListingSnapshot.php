<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ListingSnapshot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'period',
        'branch_id',
        'user_id',
        'listing_count',
        'avg_listing_price',
        'created_by',
        'updated_by',
    ];
}
