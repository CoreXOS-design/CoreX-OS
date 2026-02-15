<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingTarget extends Model
{
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
