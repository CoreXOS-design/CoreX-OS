<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ListingTarget extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'period',
        'target_listings',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
