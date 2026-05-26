<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationListingPriceHistory extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'presentation_listing_price_history';

    protected $fillable = [
        'agency_id',
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
