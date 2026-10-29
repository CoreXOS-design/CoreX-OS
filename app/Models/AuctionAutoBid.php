<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A bidder's standing maximum on a lot: the engine bids for them, one increment at a time, up to this amount. */
class AuctionAutoBid extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'lot_id', 'registration_id', 'max_amount', 'active'];

    protected $casts = ['max_amount' => 'decimal:2', 'active' => 'boolean'];

    public function registration()
    {
        return $this->belongsTo(AuctionRegistration::class, 'registration_id')->withTrashed();
    }
}
