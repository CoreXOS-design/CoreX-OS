<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class ListingSnapshot extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'period',
        'branch_id',
        'user_id',
        'listing_count',
        'avg_listing_price',
        'created_by',
        'updated_by',
    ];
}
