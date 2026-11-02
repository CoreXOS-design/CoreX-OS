<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A property that is "not for" a buyer (spec 13.17). Written only by App\Services\Matching\BuyerPropertyExclusions. */
class BuyerPropertyExclusion extends Model
{
    protected $table = 'buyer_property_exclusions';

    protected $guarded = [];

    protected $casts = ['hidden_at' => 'datetime', 'restored_at' => 'datetime'];

    public function scopeActive($q)
    {
        return $q->whereNull('restored_at');
    }
}
