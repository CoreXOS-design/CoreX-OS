<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class ListingTarget extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'user_id',
        'period',
        'target_listings',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
