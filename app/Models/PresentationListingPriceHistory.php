<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PresentationListingPriceHistory extends Model
{
    use SoftDeletes;

    protected $table = 'presentation_listing_price_history';

    protected $fillable = [
        'presentation_id',
        'active_listing_id',
        'price_inc',
        'captured_at',
        'source_snapshot_id',
    ];

    protected $casts = [
        'price_inc'   => 'integer',
        'captured_at' => 'datetime',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function activeListing()
    {
        return $this->belongsTo(PresentationActiveListing::class, 'active_listing_id');
    }
}
