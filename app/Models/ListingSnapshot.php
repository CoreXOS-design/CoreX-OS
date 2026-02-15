<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingSnapshot extends Model
{
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
